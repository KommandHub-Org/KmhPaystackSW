<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Webhook\Subscriber;

use Kommandhub\PaystackSW\Checkout\Payment\Service\FinalizeProcessor;
use Kommandhub\PaystackSW\Checkout\Payment\Service\OrderTransactionService;
use Kommandhub\PaystackSW\Webhook\Event\ChargeSuccessEvent;
use Kommandhub\PaystackSW\Logging\ConfigurableLogger;
use Kommandhub\PaystackSW\Util\PaystackConstants;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;

final readonly class ChargeSuccessSubscriber
{
    public function __construct(
        private OrderTransactionService $orderTransactionService,
        private FinalizeProcessor $finalizeProcessor,
        private ConfigurableLogger $logger,
    ) {
    }

    #[AsEventListener(ChargeSuccessEvent::class)]
    public function onChargeSuccessEvent(ChargeSuccessEvent $event): void
    {
        $data = $event->getData();
        $context = $event->getContext();
        $reference = (string)($data['reference'] ?? '');

        if ($reference === '') {
            $this->logger->warning('[Paystack] Charge success webhook is missing a reference.');

            return;
        }

        $transaction = $this->orderTransactionService->findOneByPaystackReference($reference, $context)
            ?? $this->findByMetadata($data, $context);

        if ($transaction === null) {
            $this->logger->warning('[Paystack] Could not find transaction for charge success webhook.', [
                'reference' => $reference,
            ]);

            return;
        }

        $this->finalizeProcessor->process(
            new Request(['reference' => $reference]),
            new PaymentTransactionStruct($transaction->getId()),
            $context,
            $transaction
        );
    }

    /**
     * Resolves the order transaction from the metadata Paystack echoes back.
     *
     * Used when no transaction carries the reported reference any more: a retry
     * overwrites the stored reference, so a late webhook for an earlier attempt
     * would otherwise be unmatched. The id is only a lookup hint — the payment is
     * still verified against Paystack (status, amount and currency) before the
     * order is marked paid.
     *
     * @param array<string, mixed> $data
     */
    private function findByMetadata(array $data, Context $context): ?OrderTransactionEntity
    {
        $metadata = $data['metadata'] ?? null;

        if (!is_array($metadata)) {
            return null;
        }

        $transactionId = $metadata[PaystackConstants::METADATA_ORDER_TRANSACTION_ID] ?? null;

        if (!is_string($transactionId) || $transactionId === '') {
            return null;
        }

        return $this->orderTransactionService->findOneById($transactionId, $context);
    }
}
