<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Tests\Unit\Checkout\Payment\Service;

use Kommandhub\PaystackSW\Setting\Service\Config;
use Kommandhub\PaystackSW\Checkout\Payment\Service\PayloadBuilder;
use Kommandhub\PaystackSW\Util\PaystackCurrencyHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\Country\CountryEntity;

#[CoversClass(PayloadBuilder::class)]
#[UsesClass(PaystackCurrencyHelper::class)]
class PayloadBuilderTest extends TestCase
{
    private Config $config;
    private PayloadBuilder $payloadBuilder;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->payloadBuilder = new PayloadBuilder($this->config);
    }

    public function testBuildBasicPayload(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $customer = $this->createMock(OrderCustomerEntity::class);
        $currency = $this->createMock(CurrencyEntity::class);
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);

        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getOrderCustomer')->willReturn($customer);
        $order->method('getCurrency')->willReturn($currency);
        $transactionStruct->method('getReturnUrl')->willReturn('https://return.url');
        $order->method('getSalesChannelId')->willReturn('sales-channel-id');

        $price = $this->createMock(CalculatedPrice::class);
        $price->method('getTotalPrice')->willReturn(100.50);
        $orderTransaction->method('getAmount')->willReturn($price);

        $currency->method('getIsoCode')->willReturn('NGN');
        $customer->method('getEmail')->willReturn('test@example.com');

        $this->config->method('get')->willReturnMap([
            ['paymentOptions', [], 'sales-channel-id', ['card', 'bank']],
            ['metaData', [], 'sales-channel-id', []],
        ]);

        $payload = $this->payloadBuilder->build($orderTransaction, $transactionStruct);

        $this->assertEquals(10050, $payload['amount']);
        $this->assertEquals('NGN', $payload['currency']);
        $this->assertEquals('test@example.com', $payload['email']);
        $this->assertEquals('https://return.url', $payload['callback_url']);
        $this->assertEquals(['card', 'bank'], $payload['channels']);
    }

    public function testBuildWithMetadata(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $customer = $this->createMock(OrderCustomerEntity::class);
        $currency = $this->createMock(CurrencyEntity::class);
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $billingAddress = $this->createMock(OrderAddressEntity::class);
        $country = $this->createMock(CountryEntity::class);

        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getOrderCustomer')->willReturn($customer);
        $order->method('getCurrency')->willReturn($currency);
        $transactionStruct->method('getReturnUrl')->willReturn('https://return.url');
        $order->method('getSalesChannelId')->willReturn('sales-channel-id');
        $order->method('getOrderNumber')->willReturn('ORDER-123');
        $order->method('getBillingAddress')->willReturn($billingAddress);

        $price = $this->createMock(CalculatedPrice::class);
        $price->method('getTotalPrice')->willReturn(10.0);
        $orderTransaction->method('getAmount')->willReturn($price);
        $currency->method('getIsoCode')->willReturn('NGN');
        $customer->method('getEmail')->willReturn('test@example.com');
        $customer->method('getFirstName')->willReturn('John');
        $customer->method('getLastName')->willReturn('Doe');

        $billingAddress->method('getFirstName')->willReturn('John');
        $billingAddress->method('getLastName')->willReturn('Doe');
        $billingAddress->method('getStreet')->willReturn('Main St');
        $billingAddress->method('getZipcode')->willReturn('12345');
        $billingAddress->method('getCity')->willReturn('Lagos');
        $billingAddress->method('getCountry')->willReturn($country);
        $country->method('getName')->willReturn('Nigeria');

        $this->config->method('get')->willReturnMap([
            ['paymentOptions', [], 'sales-channel-id', null],
            ['metaData', [], 'sales-channel-id', ['orderId', 'customerName', 'billingAddress']],
        ]);

        $payload = $this->payloadBuilder->build($orderTransaction, $transactionStruct);

        $this->assertArrayHasKey('metadata', $payload);
        $customFields = $payload['metadata']['custom_fields'];
        $this->assertCount(3, $customFields);
        $this->assertEquals('ORDER-123', $customFields[0]['value']);
        $this->assertEquals('John Doe', $customFields[1]['value']);
        $this->assertStringContainsString('Main St', $customFields[2]['value']);
    }

    public function testBuildWithSplitPayment(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $order = $this->createMock(OrderEntity::class);
        $customer = $this->createMock(OrderCustomerEntity::class);
        $currency = $this->createMock(CurrencyEntity::class);

        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getOrderCustomer')->willReturn($customer);
        $order->method('getCurrency')->willReturn($currency);
        $transactionStruct->method('getReturnUrl')->willReturn('https://return.url');
        $order->method('getSalesChannelId')->willReturn('sales-channel-id');

        $price = $this->createMock(CalculatedPrice::class);
        $price->method('getTotalPrice')->willReturn(100.0);
        $orderTransaction->method('getAmount')->willReturn($price);
        $currency->method('getIsoCode')->willReturn('NGN');
        $customer->method('getEmail')->willReturn('test@example.com');

        $this->config->method('getBool')->with('enableSplitPayment', 'sales-channel-id')->willReturn(true);
        $this->config->method('getString')->willReturnMap([
            ['subaccountCode', 'sales-channel-id', 'SUB_123'],
            ['splitCode', 'sales-channel-id', 'SPLIT_123'],
            ['paystackChargesBearer', 'sales-channel-id', 'account'],
        ]);
        $this->config->method('get')->willReturnMap([
            ['metaData', [], 'sales-channel-id', []],
            ['splitPaymentTransactionCharge', null, 'sales-channel-id', 50],
        ]);

        $payload = $this->payloadBuilder->build($orderTransaction, $transactionStruct);

        $this->assertEquals('SPLIT_123', $payload['split_code']);
        $this->assertEquals(5000, $payload['transaction_charge']);
        $this->assertEquals('account', $payload['bearer']);
    }

    public function testBuildThrowsExceptionWhenOrderMissing(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $orderTransaction->method('getOrder')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order information is missing');

        $this->payloadBuilder->build($orderTransaction, $transactionStruct);
    }

    public function testBuildThrowsExceptionWhenCustomerMissing(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $order = $this->createMock(OrderEntity::class);
        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getOrderCustomer')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Customer information is missing');

        $this->payloadBuilder->build($orderTransaction, $transactionStruct);
    }

    public function testBuildThrowsExceptionWhenCurrencyMissing(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $order = $this->createMock(OrderEntity::class);
        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getOrderCustomer')->willReturn($this->createMock(OrderCustomerEntity::class));
        $order->method('getCurrency')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Currency information is missing');

        $this->payloadBuilder->build($orderTransaction, $transactionStruct);
    }

    public function testBuildThrowsExceptionWhenReturnUrlMissing(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $order = $this->createMock(OrderEntity::class);
        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getOrderCustomer')->willReturn($this->createMock(OrderCustomerEntity::class));
        $order->method('getCurrency')->willReturn($this->createMock(CurrencyEntity::class));
        $transactionStruct->method('getReturnUrl')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Return URL is missing');

        $this->payloadBuilder->build($orderTransaction, $transactionStruct);
    }
    public function testBuildWithAllMetadataOptions(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $customer = $this->createMock(OrderCustomerEntity::class);
        $currency = $this->createMock(CurrencyEntity::class);
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $billingAddress = $this->createMock(OrderAddressEntity::class);
        $shippingAddress = $this->createMock(OrderAddressEntity::class);
        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $deliveries = new OrderDeliveryCollection([$delivery]);
        $lineItem = $this->createMock(OrderLineItemEntity::class);
        $lineItems = new OrderLineItemCollection([$lineItem]);
        $country = $this->createMock(CountryEntity::class);

        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getOrderCustomer')->willReturn($customer);
        $order->method('getCurrency')->willReturn($currency);
        $transactionStruct->method('getReturnUrl')->willReturn('https://return.url');
        $order->method('getSalesChannelId')->willReturn('sales-channel-id');
        $order->method('getOrderNumber')->willReturn('ORDER-123');
        $order->method('getBillingAddress')->willReturn($billingAddress);
        $order->method('getDeliveries')->willReturn($deliveries);
        $order->method('getLineItems')->willReturn($lineItems);

        $price = $this->createMock(CalculatedPrice::class);
        $price->method('getTotalPrice')->willReturn(10.0);
        $orderTransaction->method('getAmount')->willReturn($price);
        $currency->method('getIsoCode')->willReturn('NGN');

        $customer->method('getEmail')->willReturn('test@example.com');
        $customer->method('getFirstName')->willReturn('John');
        $customer->method('getLastName')->willReturn('Doe');

        $billingAddress->method('getFirstName')->willReturn('John');
        $billingAddress->method('getLastName')->willReturn('Doe');
        $billingAddress->method('getStreet')->willReturn('Main St');
        $billingAddress->method('getZipcode')->willReturn('12345');
        $billingAddress->method('getCity')->willReturn('Lagos');
        $billingAddress->method('getCountry')->willReturn($country);
        $billingAddress->method('getPhoneNumber')->willReturn('1234567890');
        $country->method('getName')->willReturn('Nigeria');

        $delivery->method('getShippingOrderAddress')->willReturn($shippingAddress);
        $shippingAddress->method('getFirstName')->willReturn('Jane');
        $shippingAddress->method('getLastName')->willReturn('Doe');
        $shippingAddress->method('getStreet')->willReturn('Second St');
        $shippingAddress->method('getZipcode')->willReturn('54321');
        $shippingAddress->method('getCity')->willReturn('Abuja');
        $shippingAddress->method('getCountry')->willReturn($country);

        $lineItem->method('getQuantity')->willReturn(2);
        $lineItem->method('getLabel')->willReturn('Product A');

        $this->config->method('get')->willReturnMap([
            ['paymentOptions', [], 'sales-channel-id', null],
            ['metaData', [], 'sales-channel-id', [
                'orderId',
                'customerName',
                'customerEmail',
                'customerPhone',
                'billingAddress',
                'shippingAddress',
                'products',
            ]],
        ]);

        $payload = $this->payloadBuilder->build($orderTransaction, $transactionStruct);

        $this->assertArrayHasKey('metadata', $payload);
        $customFields = $payload['metadata']['custom_fields'];

        $fieldsMap = [];

        foreach ($customFields as $field) {
            $fieldsMap[$field['variable_name']] = $field['value'];
        }

        $this->assertEquals('ORDER-123', $fieldsMap['order_id']);
        $this->assertEquals('John Doe', $fieldsMap['customer_name']);
        $this->assertEquals('test@example.com', $fieldsMap['customer_email']);
        $this->assertEquals('1234567890', $fieldsMap['customer_phone']);
        $this->assertStringContainsString('Main St', $fieldsMap['order_billing_address']);
        $this->assertStringContainsString('Second St', $fieldsMap['order_shipping_address']);
        $this->assertStringContainsString('Product A', $fieldsMap['products_purchased']);
    }

    public function testBuildWithEmptyMetadata(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $order = $this->createMock(OrderEntity::class);
        $customer = $this->createMock(OrderCustomerEntity::class);
        $currency = $this->createMock(CurrencyEntity::class);

        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getOrderCustomer')->willReturn($customer);
        $order->method('getCurrency')->willReturn($currency);
        $transactionStruct->method('getReturnUrl')->willReturn('https://return.url');
        $order->method('getSalesChannelId')->willReturn('sales-channel-id');

        $price = $this->createMock(CalculatedPrice::class);
        $price->method('getTotalPrice')->willReturn(10.0);
        $orderTransaction->method('getAmount')->willReturn($price);
        $currency->method('getIsoCode')->willReturn('NGN');
        $customer->method('getEmail')->willReturn('test@example.com');

        $this->config->method('get')->willReturnMap([
            ['paymentOptions', [], 'sales-channel-id', null],
            ['metaData', [], 'sales-channel-id', []],
        ]);

        $payload = $this->payloadBuilder->build($orderTransaction, $transactionStruct);

        $this->assertArrayHasKey('metadata', $payload);
        $this->assertArrayNotHasKey('custom_fields', $payload['metadata']);
        $this->assertEquals('https://return.url', $payload['metadata']['cancel_action']);
    }

    /**
     * The order transaction id always travels in the Paystack metadata so a late
     * webhook for an older retry attempt can still be matched to its order.
     */
    public function testMetadataAlwaysCarriesTheOrderTransactionId(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getId')->willReturn('order-transaction-123');
        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $order = $this->createMock(OrderEntity::class);
        $customer = $this->createMock(OrderCustomerEntity::class);
        $currency = $this->createMock(CurrencyEntity::class);

        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getOrderCustomer')->willReturn($customer);
        $order->method('getCurrency')->willReturn($currency);
        $transactionStruct->method('getReturnUrl')->willReturn('https://return.url');
        $order->method('getSalesChannelId')->willReturn('sales-channel-id');

        $price = $this->createMock(CalculatedPrice::class);
        $price->method('getTotalPrice')->willReturn(10.0);
        $orderTransaction->method('getAmount')->willReturn($price);
        $currency->method('getIsoCode')->willReturn('NGN');
        $customer->method('getEmail')->willReturn('test@example.com');

        $this->config->method('get')->willReturnMap([
            ['paymentOptions', [], 'sales-channel-id', null],
            ['metaData', [], 'sales-channel-id', []],
        ]);

        $payload = $this->payloadBuilder->build($orderTransaction, $transactionStruct);

        $this->assertSame('order-transaction-123', $payload['metadata']['order_transaction_id']);
    }

    public function testBuildWithInvalidMetadataOptions(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $order = $this->createMock(OrderEntity::class);
        $customer = $this->createMock(OrderCustomerEntity::class);
        $currency = $this->createMock(CurrencyEntity::class);

        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getOrderCustomer')->willReturn($customer);
        $order->method('getCurrency')->willReturn($currency);
        $transactionStruct->method('getReturnUrl')->willReturn('https://return.url');
        $order->method('getSalesChannelId')->willReturn('sales-channel-id');

        $price = $this->createMock(CalculatedPrice::class);
        $price->method('getTotalPrice')->willReturn(10.0);
        $orderTransaction->method('getAmount')->willReturn($price);
        $currency->method('getIsoCode')->willReturn('NGN');
        $customer->method('getEmail')->willReturn('test@example.com');

        $this->config->method('get')->willReturnMap([
            ['paymentOptions', [], 'sales-channel-id', null],
            ['metaData', [], 'sales-channel-id', ['invalidOption']],
        ]);

        $payload = $this->payloadBuilder->build($orderTransaction, $transactionStruct);

        $this->assertArrayHasKey('metadata', $payload);
        $this->assertArrayNotHasKey('custom_fields', $payload['metadata']);
    }

    public function testBuildMetadataWithMissingEntities(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);

        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getCurrency')->willReturn($this->createMock(CurrencyEntity::class));
        $transactionStruct->method('getReturnUrl')->willReturn('https://return.url');
        $order->method('getSalesChannelId')->willReturn('sales-channel-id');

        $price = $this->createMock(CalculatedPrice::class);
        $price->method('getTotalPrice')->willReturn(10.0);
        $orderTransaction->method('getAmount')->willReturn($price);

        // Mock missing entities for metadata
        $order->method('getBillingAddress')->willReturn(null);
        $order->method('getDeliveries')->willReturn(null);
        $order->method('getLineItems')->willReturn(null);
        $order->method('getOrderNumber')->willReturn(null);

        $this->config->method('get')->willReturnMap([
            ['metaData', [], 'sales-channel-id', [
                'orderId',
                'customerName',
                'customerEmail',
                'customerPhone',
                'billingAddress',
                'shippingAddress',
                'products',
            ]],
            ['paymentOptions', [], 'sales-channel-id', null],
        ]);

        $this->config->method('getBool')->with('enableSplitPayment', 'sales-channel-id')->willReturn(false);
        $this->config->method('getString')->willReturn('');

        $customer = $this->createMock(OrderCustomerEntity::class);
        $customer->method('getEmail')->willReturn('test@example.com');

        // build() calls getOrderCustomer once. buildMetadata() calls it twice (name and email).
        $order->method('getOrderCustomer')->willReturnOnConsecutiveCalls($customer, null, null);

        $payload = $this->payloadBuilder->build($orderTransaction, $transactionStruct);

        $this->assertArrayNotHasKey('custom_fields', $payload['metadata']);
    }

    public function testBuildWithSplitPaymentEnabledButNoCodes(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $order = $this->createMock(OrderEntity::class);
        $customer = $this->createMock(OrderCustomerEntity::class);
        $currency = $this->createMock(CurrencyEntity::class);

        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getOrderCustomer')->willReturn($customer);
        $order->method('getCurrency')->willReturn($currency);
        $transactionStruct->method('getReturnUrl')->willReturn('https://return.url');
        $order->method('getSalesChannelId')->willReturn('sales-channel-id');

        $price = $this->createMock(CalculatedPrice::class);
        $price->method('getTotalPrice')->willReturn(100.0);
        $orderTransaction->method('getAmount')->willReturn($price);
        $currency->method('getIsoCode')->willReturn('NGN');
        $customer->method('getEmail')->willReturn('test@example.com');

        $this->config->method('getBool')->with('enableSplitPayment', 'sales-channel-id')->willReturn(true);
        $this->config->method('getString')->willReturn(''); // Both subaccount and split code empty

        $payload = $this->payloadBuilder->build($orderTransaction, $transactionStruct);

        $this->assertArrayNotHasKey('split_code', $payload);
        $this->assertArrayNotHasKey('subaccount', $payload);
        $this->assertArrayNotHasKey('transaction_charge', $payload);
    }

    public function testBuildWithSplitPaymentEnabledAndSubaccountCodeOnly(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $transactionStruct = $this->createMock(PaymentTransactionStruct::class);
        $order = $this->createMock(OrderEntity::class);
        $customer = $this->createMock(OrderCustomerEntity::class);
        $currency = $this->createMock(CurrencyEntity::class);

        $orderTransaction->method('getOrder')->willReturn($order);
        $order->method('getOrderCustomer')->willReturn($customer);
        $order->method('getCurrency')->willReturn($currency);
        $transactionStruct->method('getReturnUrl')->willReturn('https://return.url');
        $order->method('getSalesChannelId')->willReturn('sales-channel-id');

        $price = $this->createMock(CalculatedPrice::class);
        $price->method('getTotalPrice')->willReturn(100.0);
        $orderTransaction->method('getAmount')->willReturn($price);
        $currency->method('getIsoCode')->willReturn('NGN');
        $customer->method('getEmail')->willReturn('test@example.com');

        $this->config->method('getBool')->with('enableSplitPayment', 'sales-channel-id')->willReturn(true);
        $this->config->method('getString')->willReturnMap([
            ['subaccountCode', 'sales-channel-id', 'SUB_123'],
            ['splitCode', 'sales-channel-id', ''],
            ['paystackChargesBearer', 'sales-channel-id', 'account'],
        ]);
        $this->config->method('get')->willReturnMap([
            ['splitPaymentTransactionCharge', null, 'sales-channel-id', 50],
            ['paymentOptions', [], 'sales-channel-id', null],
        ]);

        $payload = $this->payloadBuilder->build($orderTransaction, $transactionStruct);

        $this->assertArrayNotHasKey('split_code', $payload);
        $this->assertEquals('SUB_123', $payload['subaccount']);
        $this->assertEquals(5000, $payload['transaction_charge']);
        $this->assertEquals('account', $payload['bearer']);
    }
}
