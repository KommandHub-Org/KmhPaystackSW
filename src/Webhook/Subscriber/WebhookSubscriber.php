<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Webhook\Subscriber;

use Kommandhub\PaystackSW\Webhook\Service\RefundInitializeService;
use Kommandhub\PaystackSW\Webhook\Event\RefundPendingEvent;
use Kommandhub\PaystackSW\Webhook\Event\RefundProcessedEvent;
use Kommandhub\PaystackSW\Logging\ConfigurableLogger;
use Kommandhub\PaystackSW\Util\OrderCurrencyResolver;
use Kommandhub\PaystackSW\Util\PaystackCurrencyHelper;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStates;
use Shopware\Core\Checkout\Payment\Cart\PaymentRefundProcessor;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class WebhookSubscriber
{
    public function __construct(
        private RefundInitializeService $refundInitializeService,
        private PaymentRefundProcessor $paymentRefundProcessor,
        private EntityRepository $orderTransactionCaptureRefundRepository,
        private ConfigurableLogger $logger,
    ) {
    }

    /**
     * Creates the Shopware capture/refund records when Paystack
     * reports a refund has been queued.
     */
    #[AsEventListener(RefundPendingEvent::class)]
    public function onRefundPendingEvent(
        RefundPendingEvent $event
    ): void {
        $this->refundInitializeService->handle(
            $event->getData(),
            $event->getContext()
        );
    }

    /**
     * Finalizes a previously initialized refund once Paystack
     * confirms the refund has been processed.
     *
     * @throws \Throwable
     */
    #[AsEventListener(RefundProcessedEvent::class)]
    public function onRefundProcessedEvent(
        RefundProcessedEvent $event
    ): void {
        $data = $event->getData();
        $context = $event->getContext();

        $transactionReference = (string)($data['transaction_reference'] ?? '');

        if ($transactionReference === '') {
            return;
        }

        $refundId = (string)($data['id'] ?? '');

        if ($refundId === '') {
            return;
        }

        $refundReference = RefundInitializeService::buildExternalReference(
            $transactionReference,
            $refundId
        );

        $refund = $this->findRefund($refundReference, $context);

        /**
         * Safety net:
         * Some merchants may receive refund.processed before
         * refund.pending. Ensure the refund entity exists.
         */
        if ($refund === null) {
            $this->refundInitializeService->handle(
                $data,
                $context
            );

            $refund = $this->findRefund($refundReference, $context);
        }

        if ($refund === null) {
            $this->logger->warning(
                '[Paystack] Unable to locate refund entity after initialization.',
                [
                    'paystack_refund_id' => $refundReference,
                ]
            );

            return;
        }

        if (!$this->refundAmountMatches($refund, $data, $refundReference)) {
            return;
        }

        if ($this->isAlreadyProcessed($refund)) {
            $this->logger->info('[Paystack] Refund already processed.', [
                'refund_id' => $refund->getId(),
                'paystack_refund_id' => $refundReference,
            ]);

            return;
        }

        try {
            $this->paymentRefundProcessor->processRefund(
                $refund->getId(),
                $context
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                '[Paystack] Failed to process Shopware refund.',
                [
                    'refund_id' => $refund->getId(),
                    'paystack_refund_id' => $refundReference,
                    'exception' => $exception,
                ]
            );

            throw $exception;
        }
    }

    private function findRefund(
        string $externalRefundId,
        Context $context
    ): ?OrderTransactionCaptureRefundEntity {
        $criteria = new Criteria();

        $criteria->addFilter(
            new EqualsFilter(
                'externalReference',
                $externalRefundId
            )
        );
        $criteria->addAssociation('stateMachineState');
        // Needed to resolve the order currency when verifying the refund amount.
        $criteria->addAssociation('transactionCapture.transaction.order.currency');
        $criteria->setLimit(1);

        $refund = $this->orderTransactionCaptureRefundRepository->search($criteria, $context)->getEntities()->first();

        return $refund instanceof OrderTransactionCaptureRefundEntity ? $refund : null;
    }

    /**
     * Ensures the amount Paystack reports as processed matches the Shopware
     * refund we are about to finalize.
     *
     * The webhook payload is attacker-controllable and may be redelivered, so a
     * refund is never finalized on an amount we cannot confirm. Compared in
     * minor units so float representation cannot cause a false mismatch and
     * per-currency decimals are respected.
     *
     * @param array<string, mixed> $data
     */
    private function refundAmountMatches(
        OrderTransactionCaptureRefundEntity $refund,
        array $data,
        string $refundReference
    ): bool {
        $transaction = $refund->getTransactionCapture()?->getTransaction();
        $currencyIso = $transaction !== null ? OrderCurrencyResolver::resolve($transaction) : null;

        // Fail closed: without a known currency the amounts are not comparable,
        // so the refund must not be finalized.
        if ($currencyIso === null) {
            $this->logger->error('[Paystack] Unable to resolve the order currency; refusing to process refund.', [
                'refund_id' => $refund->getId(),
                'paystack_refund_id' => $refundReference,
            ]);

            return false;
        }

        $expectedMinor = PaystackCurrencyHelper::toMinorUnit($refund->getAmount()->getTotalPrice(), $currencyIso);
        $rawAmount = $data['amount'] ?? null;
        $receivedMinor = is_numeric($rawAmount) ? (int)$rawAmount : null;

        if ($receivedMinor === $expectedMinor) {
            return true;
        }

        $this->logger->error('[Paystack] Refund amount does not match the refund being processed.', [
            'refund_id' => $refund->getId(),
            'paystack_refund_id' => $refundReference,
            'expected' => $expectedMinor,
            'received' => $receivedMinor,
            'currency' => $currencyIso,
        ]);

        return false;
    }

    private function isAlreadyProcessed(?OrderTransactionCaptureRefundEntity $refund): bool
    {
        return $refund?->getStateMachineState()?->getTechnicalName() === OrderTransactionCaptureRefundStates::STATE_COMPLETED;
    }
}
