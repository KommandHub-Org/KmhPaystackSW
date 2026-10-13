<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Tests\Unit\Checkout\Payment\Service;

use Kommandhub\PaystackSW\Checkout\Payment\Service\FinalizeProcessor;
use Kommandhub\PaystackSW\Checkout\Payment\Service\TransactionMetadataProcessorInterface;
use Kommandhub\PaystackSW\Checkout\Payment\Service\TransactionVerificationProcessorInterface;
use Kommandhub\PaystackSW\Checkout\Payment\Service\OrderTransactionService;
use Kommandhub\PaystackSW\Checkout\Payment\Service\PaymentFinalizedEventService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Kommandhub\PaystackSW\Logging\ConfigurableLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Kommandhub\PaystackSW\Exception\PaymentException;
use Shopware\Core\Checkout\Payment\PaymentException as ShopwarePaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(FinalizeProcessor::class)]
#[UsesClass(PaymentException::class)]
class FinalizeProcessorTest extends TestCase
{
    private OrderTransactionService&MockObject $orderTransactionService;
    private OrderTransactionStateHandler&MockObject $transactionStateHandler;
    private TransactionVerificationProcessorInterface&MockObject $verificationProcessor;
    private TransactionMetadataProcessorInterface&MockObject $metadataProcessor;
    private PaymentFinalizedEventService&MockObject $paymentFinalizedEventService;
    private ConfigurableLogger&MockObject $logger;
    private Context $context;
    private FinalizeProcessor $processor;

    protected function setUp(): void
    {
        $this->orderTransactionService = $this->createMock(OrderTransactionService::class);
        $this->transactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->verificationProcessor = $this->createMock(TransactionVerificationProcessorInterface::class);
        $this->metadataProcessor = $this->createMock(TransactionMetadataProcessorInterface::class);
        $this->paymentFinalizedEventService = $this->createMock(PaymentFinalizedEventService::class);
        $this->logger = $this->createMock(ConfigurableLogger::class);
        $this->context = Context::createDefaultContext();

        $this->processor = new FinalizeProcessor(
            $this->orderTransactionService,
            $this->transactionStateHandler,
            $this->verificationProcessor,
            $this->metadataProcessor,
            $this->paymentFinalizedEventService,
            $this->logger
        );
    }

    public function testProcessSuccess(): void
    {
        $transactionId = 'test-transaction-id';
        $reference = 'test-reference';
        $request = new Request(['reference' => $reference]);

        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $transactionStruct->method('getOrderTransactionId')->willReturn($transactionId);

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $order = $this->createMock(OrderEntity::class);
        $orderTransaction->method('getOrder')->willReturn($order);

        $state = $this->createMock(StateMachineStateEntity::class);
        $state->method('getTechnicalName')->willReturn('open');
        $orderTransaction->method('getStateMachineState')->willReturn($state);

        $this->orderTransactionService->expects($this->once())
            ->method('readOneById')
            ->with($transactionId, $this->context)
            ->willReturn($orderTransaction);

        $verificationData = [
            'data' => [
                'status' => 'success',
                'id' => 'paystack-id',
            ],
        ];
        $this->verificationProcessor->expects($this->once())
            ->method('verify')
            ->with($reference, $orderTransaction, $this->context)
            ->willReturn($verificationData);

        $this->metadataProcessor->expects($this->once())
            ->method('persist')
            ->with($transactionId, $reference, $verificationData, $this->context);

        $this->transactionStateHandler->expects($this->once())
            ->method('paid')
            ->with($transactionId, $this->context);

        $this->paymentFinalizedEventService->expects($this->once())
            ->method('fireEvent')
            ->with($order, $orderTransaction, $transactionStruct, $this->context);

        $this->processor->process($request, $transactionStruct, $this->context);
    }

    public function testProcessThrowsOnMissingReference(): void
    {
        $transactionId = 'test-transaction-id';
        $request = new Request(); // No reference

        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $transactionStruct->method('getOrderTransactionId')->willReturn($transactionId);

        $this->orderTransactionService->method('readOneById')->willReturn($this->createMock(OrderTransactionEntity::class));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('[Paystack] Missing transaction reference.', $this->isType('array'));

        $this->expectException(ShopwarePaymentException::class);
        $this->expectExceptionMessage('Payment reference is missing from request.');

        $this->processor->process($request, $transactionStruct, $this->context);
    }

    public function testProcessThrowsOnVerificationError(): void
    {
        $transactionId = 'test-transaction-id';
        $reference = 'test-reference';
        $request = new Request(['reference' => $reference]);

        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $transactionStruct->method('getOrderTransactionId')->willReturn($transactionId);

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $this->orderTransactionService->method('readOneById')->willReturn($orderTransaction);

        $this->verificationProcessor->method('verify')
            ->willThrowException(new \Exception('Verification failed'));

        $this->logger->expects($this->never())
            ->method('error');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Verification failed');

        $this->processor->process($request, $transactionStruct, $this->context);
    }

    public function testProcessDoesNotTransitionIfAlreadyPaid(): void
    {
        $transactionId = 'test-transaction-id';
        $reference = 'test-reference';
        $request = new Request(['reference' => $reference]);

        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $transactionStruct->method('getOrderTransactionId')->willReturn($transactionId);

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $state = $this->createMock(StateMachineStateEntity::class);
        $state->method('getTechnicalName')->willReturn('paid');
        $orderTransaction->method('getStateMachineState')->willReturn($state);

        $this->orderTransactionService->method('readOneById')->willReturn($orderTransaction);

        $this->verificationProcessor->expects($this->never())->method('verify');
        $this->metadataProcessor->expects($this->never())->method('persist');
        $this->paymentFinalizedEventService->expects($this->never())->method('fireEvent');

        $this->transactionStateHandler->expects($this->never())->method('paid');
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                '[Paystack] Payment already processed.',
                $this->callback(fn (array $context): bool => isset($context['reference'], $context['transaction_id']))
            );

        $this->processor->process($request, $transactionStruct, $this->context);
    }

    public function testProcessThrowsOnUnsuccessfulStatus(): void
    {
        $transactionId = 'test-transaction-id';
        $reference = 'test-reference';
        $request = new Request(['reference' => $reference]);

        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $transactionStruct->method('getOrderTransactionId')->willReturn($transactionId);

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $this->orderTransactionService->method('readOneById')->willReturn($orderTransaction);

        $this->verificationProcessor->method('verify')
            ->willThrowException(PaymentException::asyncFinalizeInterrupted($transactionId, 'Payment failed with status: failed'));

        $this->transactionStateHandler->expects($this->never())->method('paid');

        $this->expectException(ShopwarePaymentException::class);
        $this->expectExceptionMessage('Payment failed with status: failed');

        $this->processor->process($request, $transactionStruct, $this->context);
    }

    public function testProcessReturnsWhenVerificationIsNotFinal(): void
    {
        $transactionId = 'test-transaction-id';
        $reference = 'test-reference';
        $request = new Request(['reference' => $reference]);

        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $transactionStruct->method('getOrderTransactionId')->willReturn($transactionId);

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $state = $this->createMock(StateMachineStateEntity::class);
        $state->method('getTechnicalName')->willReturn('open');
        $orderTransaction->method('getStateMachineState')->willReturn($state);

        $this->orderTransactionService->method('readOneById')->willReturn($orderTransaction);

        $verificationData = [
            'status' => true,
            'data' => [
                'status' => 'processing',
                'id' => 'paystack-id',
            ],
        ];

        $this->verificationProcessor->expects($this->once())
            ->method('verify')
            ->willReturn($verificationData);

        $this->metadataProcessor->expects($this->never())->method('persist');
        $this->transactionStateHandler->expects($this->never())->method('paid');
        $this->paymentFinalizedEventService->expects($this->never())->method('fireEvent');
        $this->logger->expects($this->never())->method('info');

        $this->processor->process($request, $transactionStruct, $this->context);
    }

    /**
     * An open order transaction that carries the reference stored at pay time.
     */
    private function transactionWithStoredReference(string $reference = 'stored-reference'): OrderTransactionEntity&MockObject
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $order = $this->createMock(OrderEntity::class);
        $orderTransaction->method('getOrder')->willReturn($order);
        $orderTransaction->method('getCustomFields')->willReturn(['paystack_reference' => $reference]);

        $state = $this->createMock(StateMachineStateEntity::class);
        $state->method('getTechnicalName')->willReturn('open');
        $orderTransaction->method('getStateMachineState')->willReturn($state);

        return $orderTransaction;
    }

    private function structFor(string $transactionId): PaymentTransactionStruct&MockObject
    {
        $struct = $this->createMock(PaymentTransactionStruct::class);
        $struct->method('getOrderTransactionId')->willReturn($transactionId);

        return $struct;
    }

    /**
     * Paystack drops `reference` when the customer returns after cancelling or
     * after some failures. The stored reference is verified instead, so a genuine
     * payment is still recognised rather than discarded.
     */
    public function testRedirectWithoutReferenceVerifiesTheStoredReference(): void
    {
        $orderTransaction = $this->transactionWithStoredReference();
        $this->orderTransactionService->method('readOneById')->willReturn($orderTransaction);

        $verification = ['status' => true, 'data' => ['status' => 'success', 'id' => 'paystack-id']];

        $this->verificationProcessor->expects($this->once())
            ->method('verify')
            ->with('stored-reference', $orderTransaction, $this->context)
            ->willReturn($verification);

        $this->metadataProcessor->expects($this->once())
            ->method('persist')
            ->with('tx-1', 'stored-reference', $verification, $this->context);
        $this->transactionStateHandler->expects($this->once())->method('paid')->with('tx-1', $this->context);

        $this->processor->process(new Request(), $this->structFor('tx-1'), $this->context);
    }

    /**
     * A declined card must end as a failed payment, not a cancelled one: the
     * verification step rejects it and Shopware records the failure.
     */
    public function testRedirectWithoutReferenceAndDeclinedPaymentFails(): void
    {
        $this->orderTransactionService->method('readOneById')->willReturn($this->transactionWithStoredReference());

        $this->verificationProcessor->method('verify')
            ->willThrowException(PaymentException::asyncFinalizeInterrupted('tx-1', 'Payment failed with status: failed'));

        $this->transactionStateHandler->expects($this->never())->method('paid');

        $this->expectException(ShopwarePaymentException::class);
        $this->expectExceptionMessage('Payment failed with status: failed');

        $this->processor->process(new Request(), $this->structFor('tx-1'), $this->context);
    }

    /**
     * Pressing cancel/back leaves the Paystack attempt abandoned: that is a
     * customer cancellation and the order stays retryable.
     */
    public function testRedirectWithoutReferenceAndAbandonedPaymentIsCancelled(): void
    {
        $this->orderTransactionService->method('readOneById')->willReturn($this->transactionWithStoredReference());

        $this->verificationProcessor->method('verify')
            ->willReturn(['status' => true, 'data' => ['status' => 'abandoned']]);

        $this->transactionStateHandler->expects($this->never())->method('paid');
        $this->metadataProcessor->expects($this->never())->method('persist');

        $this->expectException(ShopwarePaymentException::class);
        $this->expectExceptionMessage('The payment was abandoned on the Paystack page.');

        $this->processor->process(new Request(), $this->structFor('tx-1'), $this->context);
    }

    /**
     * A still-pending payment (e.g. bank transfer, USSD) is left open untouched.
     */
    public function testRedirectWithoutReferenceAndPendingPaymentStaysOpen(): void
    {
        $this->orderTransactionService->method('readOneById')->willReturn($this->transactionWithStoredReference());

        $this->verificationProcessor->method('verify')
            ->willReturn(['status' => true, 'data' => ['status' => 'ongoing']]);

        $this->transactionStateHandler->expects($this->never())->method('paid');

        $this->processor->process(new Request(), $this->structFor('tx-1'), $this->context);

        $this->addToAssertionCount(1);
    }

    /**
     * Unchanged behaviour: when the redirect (or a webhook) supplies a reference,
     * an abandoned status only returns quietly — the cancellation outcome applies
     * solely to a redirect that carried no reference.
     */
    public function testAbandonedStatusWithReferenceReturnsQuietly(): void
    {
        $this->orderTransactionService->method('readOneById')->willReturn($this->transactionWithStoredReference());

        $this->verificationProcessor->method('verify')
            ->willReturn(['status' => true, 'data' => ['status' => 'abandoned']]);

        $this->transactionStateHandler->expects($this->never())->method('paid');

        $this->processor->process(new Request(['reference' => 'from-webhook']), $this->structFor('tx-1'), $this->context);

        $this->addToAssertionCount(1);
    }
}
