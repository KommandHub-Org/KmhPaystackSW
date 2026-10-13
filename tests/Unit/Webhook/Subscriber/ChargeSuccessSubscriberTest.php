<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Tests\Unit\Webhook\Subscriber;

use Kommandhub\PaystackSW\Logging\ConfigurableLogger;
use Kommandhub\PaystackSW\Checkout\Payment\Service\FinalizeProcessor;
use Kommandhub\PaystackSW\Checkout\Payment\Service\OrderTransactionService;
use Kommandhub\PaystackSW\Webhook\Event\ChargeSuccessEvent;
use Kommandhub\PaystackSW\Webhook\Subscriber\ChargeSuccessSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(ChargeSuccessSubscriber::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\Kommandhub\PaystackSW\Util\PaystackConstants::class)]
class ChargeSuccessSubscriberTest extends TestCase
{
    private OrderTransactionService $orderTransactionService;
    private FinalizeProcessor $finalizeProcessor;
    private ConfigurableLogger $logger;
    private ChargeSuccessSubscriber $listener;

    protected function setUp(): void
    {
        $this->orderTransactionService = $this->createMock(OrderTransactionService::class);
        $this->finalizeProcessor = $this->createMock(FinalizeProcessor::class);
        $this->logger = $this->createMock(ConfigurableLogger::class);

        $this->listener = new ChargeSuccessSubscriber(
            $this->orderTransactionService,
            $this->finalizeProcessor,
            $this->logger
        );
    }

    public function testOnChargeSuccessEventFinalizesMatchingTransaction(): void
    {
        $context = Context::createDefaultContext();
        $data = ['reference' => 'paystack-ref-123'];
        $event = $this->createMock(ChargeSuccessEvent::class);
        $event->method('getData')->willReturn($data);
        $event->method('getContext')->willReturn($context);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-id');

        $this->orderTransactionService->expects($this->once())
            ->method('findOneByPaystackReference')
            ->with('paystack-ref-123', $context)
            ->willReturn($transaction);

        $this->finalizeProcessor->expects($this->once())
            ->method('process')
            ->with(
                $this->callback(fn (Request $request): bool => $request->query->getString('reference') === 'paystack-ref-123'),
                $this->callback(fn (PaymentTransactionStruct $struct): bool => $struct->getOrderTransactionId() === 'transaction-id'),
                $context,
                $transaction
            );

        $this->listener->onChargeSuccessEvent($event);
    }

    public function testOnChargeSuccessEventLogsWhenReferenceMissing(): void
    {
        $event = $this->createMock(ChargeSuccessEvent::class);
        $event->method('getData')->willReturn([]);
        $event->method('getContext')->willReturn(Context::createDefaultContext());

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('missing a reference'));

        $this->orderTransactionService->expects($this->never())->method('findOneByPaystackReference');
        $this->finalizeProcessor->expects($this->never())->method('process');

        $this->listener->onChargeSuccessEvent($event);
    }

    public function testOnChargeSuccessEventLogsWhenTransactionMissing(): void
    {
        $context = Context::createDefaultContext();
        $event = $this->createMock(ChargeSuccessEvent::class);
        $event->method('getData')->willReturn(['reference' => 'paystack-ref-123']);
        $event->method('getContext')->willReturn($context);

        $this->orderTransactionService->expects($this->once())
            ->method('findOneByPaystackReference')
            ->with('paystack-ref-123', $context)
            ->willReturn(null);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Could not find transaction'));

        $this->finalizeProcessor->expects($this->never())->method('process');

        $this->listener->onChargeSuccessEvent($event);
    }

    /**
     * A retry overwrites the stored reference, so a late webhook for an earlier
     * attempt matches no transaction by reference. The order transaction id that
     * was sent in the Paystack metadata is used to find it, and the payment is
     * still verified (status, amount, currency) by the finalize step.
     */
    public function testFallsBackToTheOrderTransactionIdInTheMetadata(): void
    {
        $context = Context::createDefaultContext();
        $event = $this->createMock(ChargeSuccessEvent::class);
        $event->method('getData')->willReturn([
            'reference' => 'older-attempt-ref',
            'metadata' => ['order_transaction_id' => 'transaction-id'],
        ]);
        $event->method('getContext')->willReturn($context);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-id');

        $this->orderTransactionService->method('findOneByPaystackReference')->willReturn(null);
        $this->orderTransactionService->expects($this->once())
            ->method('findOneById')
            ->with('transaction-id', $context)
            ->willReturn($transaction);

        $this->logger->expects($this->never())->method('warning');

        $this->finalizeProcessor->expects($this->once())
            ->method('process')
            ->with(
                $this->callback(fn (Request $request): bool => $request->query->getString('reference') === 'older-attempt-ref'),
                $this->callback(fn (PaymentTransactionStruct $struct): bool => $struct->getOrderTransactionId() === 'transaction-id'),
                $context,
                $transaction
            );

        $this->listener->onChargeSuccessEvent($event);
    }

    public function testMetadataFallbackIsIgnoredWhenTheTransactionIdIsMissingOrInvalid(): void
    {
        foreach ([
            'no transaction id' => ['other' => 'x'],
            'empty transaction id' => ['order_transaction_id' => ''],
            'non-string transaction id' => ['order_transaction_id' => 123],
        ] as $label => $metadata) {
            $context = Context::createDefaultContext();
            $event = $this->createMock(ChargeSuccessEvent::class);
            $event->method('getData')->willReturn(['reference' => 'unknown-ref', 'metadata' => $metadata]);
            $event->method('getContext')->willReturn($context);

            $service = $this->createMock(OrderTransactionService::class);
            $service->method('findOneByPaystackReference')->willReturn(null);
            $service->expects($this->never())->method('findOneById');

            $logger = $this->createMock(ConfigurableLogger::class);
            $logger->expects($this->once())->method('warning')->with($this->stringContains('Could not find transaction'));

            $finalize = $this->createMock(FinalizeProcessor::class);
            $finalize->expects($this->never())->method('process');

            (new ChargeSuccessSubscriber($service, $finalize, $logger))->onChargeSuccessEvent($event);

            $this->addToAssertionCount(1);
            unset($label);
        }
    }

    public function testMetadataFallbackLogsWhenTheTransactionDoesNotExist(): void
    {
        $context = Context::createDefaultContext();
        $event = $this->createMock(ChargeSuccessEvent::class);
        $event->method('getData')->willReturn([
            'reference' => 'unknown-ref',
            'metadata' => ['order_transaction_id' => 'deleted-transaction'],
        ]);
        $event->method('getContext')->willReturn($context);

        $this->orderTransactionService->method('findOneByPaystackReference')->willReturn(null);
        $this->orderTransactionService->method('findOneById')->willReturn(null);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Could not find transaction'));
        $this->finalizeProcessor->expects($this->never())->method('process');

        $this->listener->onChargeSuccessEvent($event);
    }
}
