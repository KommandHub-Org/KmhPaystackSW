<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Checkout\Payment\Service;

use Kommandhub\PaystackSW\Checkout\Payment\Enum\PaystackTransactionStatus;
use Kommandhub\PaystackSW\Exception\PaymentException;
use Kommandhub\PaystackSW\Logging\ConfigurableLogger;
use Kommandhub\PaystackSW\Util\PaystackConstants;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;

readonly class FinalizeProcessor
{
    /**
     * @param OrderTransactionService $orderTransactionService
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param TransactionVerificationProcessorInterface $verificationProcessor
     * @param TransactionMetadataProcessorInterface $metadataProcessor
     * @param PaymentFinalizedEventService $paymentFinalizedEventService
     * @param ConfigurableLogger $logger
     */
    public function __construct(
        private OrderTransactionService $orderTransactionService,
        private OrderTransactionStateHandler $transactionStateHandler,
        private TransactionVerificationProcessorInterface $verificationProcessor,
        private TransactionMetadataProcessorInterface $metadataProcessor,
        private PaymentFinalizedEventService $paymentFinalizedEventService,
        private ConfigurableLogger $logger,
    ) {
    }

    /**
     * Processes the finalization of a Paystack payment.
     *
     * @param Request $request
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     *
     * @throws PaymentException
     * @throws \Throwable
     */
    public function process(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?OrderTransactionEntity $orderTransaction = null
    ): void {
        $transactionId = $transaction->getOrderTransactionId();

        // Reuse a caller-supplied entity (e.g. the webhook listener already
        // loaded it) to avoid re-hydrating the full association graph.
        $orderTransaction ??= $this->orderTransactionService->readOneById($transactionId, $context);

        [$reference, $returnedWithoutReference] = $this->resolveReference($request, $orderTransaction, $transactionId);

        if ($this->isAlreadyProcessed($orderTransaction)) {
            $this->logger->info('[Paystack] Payment already processed.', [
                ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $orderTransaction->getOrder()?->getSalesChannelId(),
                'transaction_id' => $transactionId,
                'reference' => $reference,
            ]);

            return;
        }

        $verification = $this->verificationProcessor->verify(
            $reference,
            $orderTransaction,
            $context
        );

        if (!$this->isSuccessfulVerification($verification)) {
            // A redirect that carries no reference is the customer pressing
            // cancel/back on Paystack's page; Paystack reports that attempt as
            // abandoned. Treat it as a cancellation (the order stays retryable).
            // Pending-type statuses are left open, and declined/failed payments
            // were already rejected by the verification step above.
            if ($returnedWithoutReference
                && $this->getVerificationStatus($verification) === PaystackTransactionStatus::ABANDONED->value
            ) {
                throw PaymentException::customerCanceled(
                    $transactionId,
                    'The payment was abandoned on the Paystack page.'
                );
            }

            return;
        }

        $this->metadataProcessor->persist(
            $transactionId,
            $reference,
            $verification,
            $context
        );

        $this->transactionStateHandler->paid($transactionId, $context);

        $this->dispatchFinalizedEvent($orderTransaction, $transaction, $context);

        $data = $verification['data'] ?? [];
        $paystackTransactionId = '';

        if (is_array($data)) {
            $id = $data['id'] ?? '';
            $paystackTransactionId = is_scalar($id) ? (string)$id : '';
        }

        $this->logger->info('[Paystack] Payment finalized.', [
            ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $orderTransaction->getOrder()?->getSalesChannelId(),
            'transaction_id' => $transactionId,
            'reference' => $reference,
            'paystack_transaction_id' => $paystackTransactionId,
            'status' => PaystackTransactionStatus::SUCCESS->value,
        ]);
    }

    /**
     * Resolves the Paystack reference to verify.
     *
     * Paystack appends `reference` when the customer pays, but the return after
     * pressing cancel/back (and after some failures) arrives without one. In that
     * case the reference stored when the payment was initialized is used, so the
     * outcome is decided by Paystack's real status rather than guessed.
     *
     * @return array{0: string, 1: bool} The reference, and whether the redirect carried none.
     *
     * @throws PaymentException When neither the request nor the transaction has a reference.
     */
    private function resolveReference(
        Request $request,
        OrderTransactionEntity $orderTransaction,
        string $transactionId
    ): array {
        $reference = $request->query->getString('reference');

        if ($reference !== '') {
            return [$reference, false];
        }

        $customFields = $orderTransaction->getCustomFields() ?? [];
        $stored = $customFields[PaystackConstants::FIELD_REFERENCE] ?? '';

        if (is_string($stored) && $stored !== '') {
            $this->logger->info('[Paystack] Redirect carried no reference; verifying the stored one.', [
                ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $orderTransaction->getOrder()?->getSalesChannelId(),
                'transaction_id' => $transactionId,
                'reference' => $stored,
            ]);

            return [$stored, true];
        }

        $this->logger->error('[Paystack] Missing transaction reference.', [
            ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $orderTransaction->getOrder()?->getSalesChannelId(),
            'transaction_id' => $transactionId,
            'query' => $request->query->all(),
        ]);

        throw PaymentException::customerCanceled(
            $transactionId,
            'Payment reference is missing from request.'
        );
    }

    /**
     * Dispatches the payment finalized event.
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     */
    private function dispatchFinalizedEvent(
        OrderTransactionEntity $orderTransaction,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $order = $orderTransaction->getOrder();

        if (!$order instanceof OrderEntity) {
            return; // @codeCoverageIgnore
        }

        $this->paymentFinalizedEventService->fireEvent(
            $order,
            $orderTransaction,
            $transaction,
            $context
        );
    }

    /**
     * Checks if the transaction is already marked as paid.
     *
     * @param OrderTransactionEntity $transaction
     *
     * @return bool
     */
    private function isAlreadyProcessed(OrderTransactionEntity $transaction): bool
    {
        return $transaction->getStateMachineState()?->getTechnicalName() === 'paid';
    }

    /**
     * @param array<string, mixed> $verification
     */
    private function isSuccessfulVerification(array $verification): bool
    {
        return $this->getVerificationStatus($verification) === PaystackTransactionStatus::SUCCESS->value;
    }

    /**
     * @param array<string, mixed> $verification
     */
    private function getVerificationStatus(array $verification): string
    {
        $data = $verification['data'] ?? [];

        if (!is_array($data)) {
            return ''; // @codeCoverageIgnore
        }

        $status = $data['status'] ?? '';

        return is_scalar($status) ? (string)$status : '';
    }
}
