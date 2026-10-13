<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Tests\Unit\Checkout\Payment\Service;

use Kommandhub\PaystackSW\DataAbstractionLayer\OrderTransaction\OrderTransactionReader;
use Kommandhub\PaystackSW\DataAbstractionLayer\OrderTransaction\OrderTransactionWriter;
use Kommandhub\PaystackSW\Util\PaystackConstants;
use Kommandhub\PaystackSW\Checkout\Payment\Service\OrderTransactionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;

#[CoversClass(OrderTransactionService::class)]
#[UsesClass(PaystackConstants::class)]
class OrderTransactionServiceTest extends TestCase
{
    private OrderTransactionReader $reader;
    private OrderTransactionWriter $writer;
    private OrderTransactionService $service;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(OrderTransactionReader::class);
        $this->writer = $this->createMock(OrderTransactionWriter::class);
        $this->service = new OrderTransactionService($this->reader, $this->writer);
    }

    public function testGetSuccess(): void
    {
        $context = Context::createDefaultContext();
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $this->reader->method('readOneById')->willReturn($transaction);

        $result = $this->service->readOneById('id', $context);
        $this->assertSame($transaction, $result);
    }

    public function testGetThrowsExceptionOnNotFound(): void
    {
        $context = Context::createDefaultContext();
        $this->reader->method('readOneById')->willReturn(null);

        $this->expectException(PaymentException::class);
        $this->service->readOneById('id', $context);
    }

    public function testUpdateCustomFields(): void
    {
        $context = Context::createDefaultContext();
        $this->writer->expects($this->once())->method('write')->with([
            'id' => 'id',
            'customFields' => ['foo' => 'bar'],
        ], $context);

        $this->service->updateCustomFields('id', ['foo' => 'bar'], $context);
    }

    public function testSearch(): void
    {
        $context = Context::createDefaultContext();
        $criteria = new Criteria();
        $collection = $this->createMock(OrderTransactionCollection::class);

        $this->reader->expects($this->once())
            ->method('readAll')
            ->with($context, $criteria)
            ->willReturn($collection);

        $result = $this->service->search($criteria, $context);

        $this->assertSame($collection, $result);
    }

    public function testFindOneByPaystackReference(): void
    {
        $context = Context::createDefaultContext();
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $collection = $this->createMock(OrderTransactionCollection::class);
        $collection->method('first')->willReturn($transaction);

        $this->reader->expects($this->once())
            ->method('readAll')
            ->with(
                $context,
                $this->callback(function (Criteria $criteria): bool {
                    $serialized = serialize($criteria);

                    return str_contains($serialized, PaystackConstants::FIELD_REFERENCE) && str_contains($serialized, 'paystack-ref');
                })
            )
            ->willReturn($collection);

        $result = $this->service->findOneByPaystackReference('paystack-ref', $context);

        $this->assertSame($transaction, $result);
    }

    public function testFindOneByIdReturnsTheTransaction(): void
    {
        $context = Context::createDefaultContext();
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $this->reader->method('readOneById')->willReturn($transaction);

        $this->assertSame($transaction, $this->service->findOneById('id', $context));
    }

    public function testFindOneByIdReturnsNullWhenMissing(): void
    {
        $this->reader->method('readOneById')->willReturn(null);

        $this->assertNull($this->service->findOneById('missing', Context::createDefaultContext()));
    }
}
