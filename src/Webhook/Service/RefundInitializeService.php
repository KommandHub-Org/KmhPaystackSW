<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Webhook\Service;

use Kommandhub\PaystackSW\Util\OrderCurrencyResolver;
use Kommandhub\PaystackSW\Util\PaystackConstants;
use Kommandhub\PaystackSW\Util\PaystackCurrencyHelper;
use Kommandhub\PaystackSW\Logging\ConfigurableLogger;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Loader\InitialStateIdLoader;

/**
 * @final
 */
readonly class RefundInitializeService
{
    public function __construct(
        private EntityRepository $orderTransactionRepository,
        private EntityRepository $orderTransactionCaptureRepository,
        private EntityRepository $orderTransactionCaptureRefundRepository,
        private InitialStateIdLoader $initialStateIdLoader,
        private ConfigurableLogger $logger,
    ) {
    }

    /**
     * Handles the Paystack refund event data and initializes Shopware refund records.
     *
     * @param array<string, mixed> $data
     */
    public function handle(array $data, Context $context): void
    {
        $transactionReference = isset($data['transaction_reference']) && is_scalar($data['transaction_reference']) ? (string)$data['transaction_reference'] : '';
        $paystackRefundId = isset($data['id']) && is_scalar($data['id']) ? (string)$data['id'] : '';

        if (!$this->isValidRefundData($transactionReference, $paystackRefundId, $data)) {
            return;
        }

        $externalReference = $this->buildExternalReference($transactionReference, $paystackRefundId);

        $transaction = $this->findTransaction($transactionReference, $context);

        if ($transaction === null) {
            $this->logger->error('[Paystack] Could not find transaction for reference: ' . $transactionReference);

            return;
        }

        // Fail closed: never convert a refund with an assumed currency.
        $currencyCode = OrderCurrencyResolver::resolve($transaction);

        if ($currencyCode === null) {
            $this->logger->error('[Paystack] Unable to resolve the order currency; refusing to initialize refund.', [
                'transactionReference' => $transactionReference,
                'transactionId' => $transaction->getId(),
            ]);

            return;
        }

        $rawAmount = $data['amount'] ?? 0;
        $refundAmount = PaystackCurrencyHelper::fromMinorUnit(
            is_numeric($rawAmount) ? (int)$rawAmount : 0,
            $currencyCode
        );

        if (!$this->isValidRefundAmount($refundAmount, $transactionReference)) {
            return;
        }

        // Deduplicate before validating the balance: a redelivery of a refund we
        // already stored must be skipped quietly, not reported as an over-refund.
        if ($this->refundExists($externalReference, $context)) {
            $this->logger->info('[Paystack] Refund already exists: ' . $externalReference);

            return;
        }

        if (!$this->isWithinRefundableAmount($transaction, $refundAmount, $currencyCode, $externalReference)) {
            return;
        }

        $captureId = $this->getOrCreateCapture($externalReference, $transaction, $refundAmount, $currencyCode, $context);

        if ($captureId === '') {
            return;
        }

        if (!$this->createRefund($captureId, $externalReference, $refundAmount, $data, $context)) {
            return;
        }
        $this->logger->info('[Paystack] Successfully initialized refund: ' . $externalReference);
    }

    /**
     * Validates the refund data has required fields.
     */
    private function isValidRefundData(string $transactionReference, string $paystackRefundId, array $data): bool
    {
        if ($transactionReference !== '' && $paystackRefundId !== '') {
            return true;
        }

        $this->logger->error('[Paystack] Invalid refund data: missing transaction_reference or id', [
            'data' => $data,
        ]);

        return false;
    }

    /**
     * Validates the refund amount is positive.
     */
    private function isValidRefundAmount(float $refundAmount, string $transactionReference): bool
    {
        if ($refundAmount > 0.0) {
            return true;
        }

        $this->logger->warning('[Paystack] Invalid refund amount: ' . $refundAmount, [
            'transactionReference' => $transactionReference,
        ]);

        return false;
    }

    /**
     * Ensures the refund reported by Paystack fits within what is still
     * refundable on the order transaction.
     *
     * The refundable base is the transaction total (NOT the sum of captures:
     * this integration synthesises one capture per refund, so captures grow with
     * every refund and can never bound it). Refunds that are not failed or
     * cancelled are already committed against that total.
     *
     * Without this, a webhook could create a capture/refund for any amount —
     * e.g. a 700.00 refund against a 60.00 transaction.
     */
    private function isWithinRefundableAmount(
        OrderTransactionEntity $transaction,
        float $refundAmount,
        string $currencyCode,
        string $externalReference
    ): bool {
        $transactionTotal = PaystackCurrencyHelper::toMinorUnit(
            $transaction->getAmount()->getTotalPrice(),
            $currencyCode
        );

        $alreadyRefunded = $this->sumCommittedRefunds($transaction, $currencyCode);
        $remaining = max(0, $transactionTotal - $alreadyRefunded);
        $requested = PaystackCurrencyHelper::toMinorUnit($refundAmount, $currencyCode);

        if ($requested <= $remaining) {
            return true;
        }

        $this->logger->error('[Paystack] Refund amount exceeds the refundable balance of the transaction.', [
            'externalReference' => $externalReference,
            'transactionId' => $transaction->getId(),
            'transactionTotal' => $transactionTotal,
            'alreadyRefunded' => $alreadyRefunded,
            'remaining' => $remaining,
            'requested' => $requested,
            'currency' => $currencyCode,
        ]);

        return false;
    }

    /**
     * Sums refunds already committed against the transaction, in minor units.
     * Failed and cancelled refunds release their amount and are excluded.
     */
    private function sumCommittedRefunds(OrderTransactionEntity $transaction, string $currencyCode): int
    {
        $captures = $transaction->getCaptures();

        if ($captures === null) {
            return 0;
        }

        $total = 0;

        foreach ($captures as $capture) {
            $refunds = $capture->getRefunds();

            if ($refunds === null) {
                continue;
            }

            foreach ($refunds as $refund) {
                $state = $refund->getStateMachineState()?->getTechnicalName();

                if ($state === OrderTransactionCaptureRefundStates::STATE_FAILED
                    || $state === OrderTransactionCaptureRefundStates::STATE_CANCELLED
                ) {
                    continue;
                }

                $total += PaystackCurrencyHelper::toMinorUnit(
                    $refund->getAmount()->getTotalPrice(),
                    $currencyCode
                );
            }
        }

        return $total;
    }

    /**
     * Finds the order transaction by the Paystack reference.
     */
    private function findTransaction(string $reference, Context $context): ?OrderTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter(
                sprintf('customFields.%s', PaystackConstants::FIELD_REFERENCE),
                $reference
            )
        );
        $criteria->addAssociation('captures');
        // Needed to resolve the order currency and to sum what has already been
        // refunded when validating the requested refund amount.
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('captures.refunds.stateMachineState');

        /** @var OrderTransactionEntity|null $transaction */
        $transaction = $this->orderTransactionRepository->search($criteria, $context)->getEntities()->first();

        return $transaction;
    }

    /**
     * Builds a unique external reference from transaction and refund IDs.
     */
    public static function buildExternalReference(string $paystackTransactionReference, string $paystackTransactionId): string
    {
        return $paystackTransactionId . '-' . $paystackTransactionReference;
    }

    /**
     * Retrieves an existing capture or creates a new one for the transaction.
     * Supports multiple captures per transaction (important for partial refunds).
     */
    private function getOrCreateCapture(
        string $externalReference,
        OrderTransactionEntity $transaction,
        float $captureAmount,
        string $currencyCode,
        Context $context
    ): string {
        $captureId = Uuid::fromStringToHex('paystack-capture-' . $externalReference);

        $existingCapture = $this->findCaptureById($captureId, $context)
            ?? $this->findExistingCaptureInTransaction($externalReference, $transaction);

        if ($existingCapture !== null) {
            // The webhook payload is attacker-controllable and a refund event can
            // be redelivered; never attach a refund to a capture it does not match.
            if (!$this->captureAmountMatches($existingCapture, $captureAmount, $currencyCode)) {
                $this->logger->error('[Paystack] Refund amount does not match the capture amount.', [
                    'externalReference' => $externalReference,
                    'captureId' => $existingCapture->getId(),
                    'captureAmount' => $existingCapture->getAmount()->getTotalPrice(),
                    'refundAmount' => $captureAmount,
                    'currency' => $currencyCode,
                ]);

                return '';
            }

            return $existingCapture->getId();
        }

        return $this->createNewCapture($captureId, $externalReference, $transaction, $captureAmount, $context);
    }

    /**
     * Loads a capture by its deterministic id.
     */
    private function findCaptureById(string $captureId, Context $context): ?OrderTransactionCaptureEntity
    {
        /** @var OrderTransactionCaptureEntity|null $capture */
        $capture = $this->orderTransactionCaptureRepository
            ->search(new Criteria([$captureId]), $context)
            ->getEntities()
            ->first();

        return $capture;
    }

    /**
     * Finds an existing capture in the transaction's captures.
     */
    private function findExistingCaptureInTransaction(string $externalReference, OrderTransactionEntity $transaction): ?OrderTransactionCaptureEntity
    {
        $captures = $transaction->getCaptures();

        if ($captures === null || $captures->count() === 0) {
            return null;
        }

        foreach ($captures as $capture) {
            if ($capture->getExternalReference() === $externalReference) {
                return $capture;
            }
        }

        return null;
    }

    /**
     * Whether the refund amount from the webhook matches the capture being refunded.
     *
     * Compared in minor units so float representation cannot cause a false
     * mismatch, and per-currency decimals are respected.
     */
    private function captureAmountMatches(
        OrderTransactionCaptureEntity $capture,
        float $refundAmount,
        string $currencyCode
    ): bool {
        return PaystackCurrencyHelper::toMinorUnit($capture->getAmount()->getTotalPrice(), $currencyCode)
            === PaystackCurrencyHelper::toMinorUnit($refundAmount, $currencyCode);
    }

    /**
     * Creates a new capture record.
     */
    private function createNewCapture(
        string $captureId,
        string $externalReference,
        OrderTransactionEntity $transaction,
        float $captureAmount,
        Context $context
    ): string {
        try {
            $this->orderTransactionCaptureRepository->create([
                [
                    'id' => $captureId,
                    'orderTransactionId' => $transaction->getId(),
                    'externalReference' => $externalReference,
                    'stateId' => $this->initialStateIdLoader->get(OrderTransactionCaptureStates::STATE_MACHINE),
                    'amount' => $this->createPrice($captureAmount),
                ],
            ], $context);

            return $captureId;
        } catch (\Throwable $exception) {
            $this->logger->error('[Paystack] Failed to create capture', [
                'exception' => $exception,
                'transactionId' => $transaction->getId(),
                'captureId' => $captureId,
            ]);

            throw new \RuntimeException(
                sprintf('Failed to create capture for transaction "%s".', $transaction->getId()),
                0,
                $exception
            );
        }
    }

    /**
     * Creates a CalculatedPrice object for the given amount.
     */
    private function createPrice(float $amount): CalculatedPrice
    {
        return new CalculatedPrice(
            $amount,
            $amount,
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        );
    }

    /**
     * Checks if an entity exists with the given ID in the repository.
     */
    private function entityExists(EntityRepository $repository, string $id, Context $context): bool
    {
        $criteria = new Criteria([$id]);

        return $repository->searchIds($criteria, $context)->getTotal() > 0;
    }

    /**
     * Checks if a refund with the given external reference already exists.
     */
    private function refundExists(string $externalReference, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('externalReference', $externalReference));

        return $this->orderTransactionCaptureRefundRepository
                ->searchIds($criteria, $context)
                ->getTotal() > 0;
    }

    /**
     * Creates a new refund record.
     */
    private function createRefund(
        string $captureId,
        string $externalReference,
        float $refundAmount,
        array $data,
        Context $context
    ): bool {
        $refundId = Uuid::fromStringToHex('paystack-refund-' . $externalReference);

        if ($this->entityExists($this->orderTransactionCaptureRefundRepository, $refundId, $context)) {
            $this->logger->info('[Paystack] Refund with ID already exists: ' . $refundId);

            return false;
        }

        $this->orderTransactionCaptureRefundRepository->create([
            [
                'id' => $refundId,
                'captureId' => $captureId,
                'externalReference' => $externalReference,
                'stateId' => $this->initialStateIdLoader->get(OrderTransactionCaptureRefundStates::STATE_MACHINE),
                'amount' => $this->createPrice($refundAmount),
                'reason' => $data['merchant_note']
                    ?? $data['customer_note']
                        ?? 'Paystack refund',
            ],
        ], $context);

        return true;
    }
}
