<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Checkout\Payment\Service;

use Kommandhub\PaystackSW\Setting\Service\Config;
use Kommandhub\PaystackSW\Util\PaystackConstants;
use Kommandhub\PaystackSW\Util\PaystackCurrencyHelper;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;

readonly class PayloadBuilder
{
    public function __construct(
        private Config $config
    ) {
    }

    /**
     * Build the transaction initialization payload for Paystack.
     *
     * @param OrderTransactionEntity $orderTransaction The Shopware order transaction entity.
     * @param PaymentTransactionStruct $transaction The payment transaction struct from Shopware.
     *
     * @return array The prepared payload for Paystack's transaction initialization API.
     *
     * @throws \RuntimeException If required, order, customer, or currency information is missing.
     */
    public function build(OrderTransactionEntity $orderTransaction, PaymentTransactionStruct $transaction): array
    {
        // 1. Retrieve the parent order entity from the transaction.
        $order = $orderTransaction->getOrder();

        if ($order === null) {
            throw new \RuntimeException('Order information is missing for the payment transaction.');
        }

        // 2. Extract customer information. Paystack requires an email for transaction initialization.
        $customer = $order->getOrderCustomer();

        if ($customer === null) {
            throw new \RuntimeException('Customer information is missing for the order.');
        }

        // 3. Get the currency details to ensure the ISO code is available.
        $currency = $order->getCurrency();

        if ($currency === null) {
            throw new \RuntimeException('Currency information is missing for the order.');
        }

        // 4. Retrieve the return URL where Paystack should redirect the user after payment.
        $returnUrl = $transaction->getReturnUrl();

        if ($returnUrl === null) {
            throw new \RuntimeException('Return URL is missing in the payment transaction struct.');
        }

        // 5. Fetch sales channel-specific configuration for payment options.
        $salesChannelId = $order->getSalesChannelId();

        $paymentOptions = $this->config->get(
            key: 'paymentOptions',
            default: [],
            salesChannelId: $salesChannelId
        );

        /** @var array $selectedMetaData */
        $selectedMetaData = $this->config->get(
            key: 'metaData',
            default: [],
            salesChannelId: $salesChannelId
        ) ?? [];

        // 6. Build the final payload array according to Paystack API specifications.
        // The amount is converted to the minor unit (e.g., kobo) as expected by Paystack
        // using the PaystackCurrencyHelper.
        $payload = [
            'amount' => PaystackCurrencyHelper::toMinorUnit(
                $orderTransaction->getAmount()->getTotalPrice(),
                $currency->getIsoCode()
            ),
            'currency' => $currency->getIsoCode(),
            'email' => $customer->getEmail(),
            'callback_url' => $returnUrl,
            'metadata' => $this->buildMetadata($order, $selectedMetaData, (string)$returnUrl, $orderTransaction->getId()),
        ];

        // Only restrict channels when the merchant actually configured a list;
        // sending null tells Paystack nothing and risks a validation error.
        if (is_array($paymentOptions) && $paymentOptions !== []) {
            $payload['channels'] = $paymentOptions;
        }

        // 7. Add split payment parameters if enabled.
        $enableSplitPayment = $this->config->getBool('enableSplitPayment', $salesChannelId);

        if ($enableSplitPayment) {
            $subaccountCode = $this->config->getString('subaccountCode', $salesChannelId);
            $splitCode = $this->config->getString('splitCode', $salesChannelId);

            if ($splitCode !== '') {
                $payload['split_code'] = $splitCode;
            } elseif ($subaccountCode !== '') {
                $payload['subaccount'] = $subaccountCode;
            }

            if (isset($payload['split_code']) || isset($payload['subaccount'])) {
                $transactionCharge = $this->config->get('splitPaymentTransactionCharge', null, $salesChannelId);

                if ($transactionCharge !== null && (int)$transactionCharge > 0) {
                    $payload['transaction_charge'] = PaystackCurrencyHelper::toMinorUnit(
                        (float)$transactionCharge,
                        $currency->getIsoCode()
                    );
                }

                $bearer = $this->config->getString('paystackChargesBearer', $salesChannelId);

                if ($bearer !== '') {
                    $payload['bearer'] = $bearer;
                }
            }
        }

        return $payload;
    }

    /**
     * Build the metadata object for Paystack.
     *
     * @param OrderEntity $order
     * @param array $selectedMetaData
     * @param string $returnUrl
     *
     * @return array
     */
    private function buildMetadata(OrderEntity $order, array $selectedMetaData, string $returnUrl, string $orderTransactionId): array
    {
        // The order transaction id is always sent. Paystack echoes metadata back in
        // webhooks, so a late charge.success for an earlier attempt (whose reference
        // has since been overwritten by a retry) can still be matched to its order.
        $metadata = [
            'cancel_action' => $returnUrl,
            PaystackConstants::METADATA_ORDER_TRANSACTION_ID => $orderTransactionId,
            'custom_fields' => [],
        ];

        if (empty($selectedMetaData)) {
            unset($metadata['custom_fields']);

            return $metadata;
        }

        foreach ($selectedMetaData as $id) {
            switch ($id) {
                case 'orderId':
                    $orderNumber = $order->getOrderNumber();

                    if ($orderNumber !== null) {
                        $metadata['custom_fields'][] = [
                            'display_name' => 'Order ID',
                            'variable_name' => 'order_id',
                            'value' => $orderNumber,
                        ];
                    }
                    break;
                case 'customerName':
                    $customer = $order->getOrderCustomer();

                    if ($customer) {
                        $metadata['custom_fields'][] = [
                            'display_name' => 'Customer Name',
                            'variable_name' => 'customer_name',
                            'value' => sprintf('%s %s', $customer->getFirstName(), $customer->getLastName()),
                        ];
                    }
                    break;
                case 'customerEmail':
                    $customer = $order->getOrderCustomer();

                    if ($customer) {
                        $metadata['custom_fields'][] = [
                            'display_name' => 'Customer Email',
                            'variable_name' => 'customer_email',
                            'value' => $customer->getEmail(),
                        ];
                    }
                    break;
                case 'customerPhone':
                    $billing = $order->getBillingAddress();

                    if ($billing && $billing->getPhoneNumber()) {
                        $metadata['custom_fields'][] = [
                            'display_name' => 'Customer Phone',
                            'variable_name' => 'customer_phone',
                            'value' => $billing->getPhoneNumber(),
                        ];
                    }
                    break;
                case 'billingAddress':
                    $billing = $order->getBillingAddress();

                    if ($billing) {
                        $metadata['custom_fields'][] = [
                            'display_name' => 'Order Billing Address',
                            'variable_name' => 'order_billing_address',
                            'value' => $this->formatAddress($billing),
                        ];
                    }
                    break;
                case 'shippingAddress':
                    $shipping = null;
                    $deliveries = $order->getDeliveries();

                    if ($deliveries && $deliveries->first()) {
                        $shipping = $deliveries->first()->getShippingOrderAddress();
                    }

                    if ($shipping) {
                        $metadata['custom_fields'][] = [
                            'display_name' => 'Order Shipping Address',
                            'variable_name' => 'order_shipping_address',
                            'value' => $this->formatAddress($shipping),
                        ];
                    }
                    break;
                case 'products':
                    $lineItems = $order->getLineItems();

                    if ($lineItems && $lineItems->count() > 0) {
                        $metadata['custom_fields'][] = [
                            'display_name' => 'Product(s) Purchased',
                            'variable_name' => 'products_purchased',
                            'value' => $this->formatLineItems($lineItems),
                        ];
                    }
                    break;
            }
        }

        if (empty($metadata['custom_fields'])) {
            unset($metadata['custom_fields']);
        }

        return $metadata;
    }

    /**
     * Format an address as a single line string.
     *
     * @param OrderAddressEntity $address
     *
     * @return string
     */
    private function formatAddress(OrderAddressEntity $address): string
    {
        return sprintf(
            '%s %s, %s, %s %s, %s, %s',
            $address->getFirstName(),
            $address->getLastName(),
            $address->getStreet(),
            $address->getZipcode(),
            $address->getCity(),
            $address->getCountryState() ? $address->getCountryState()->getName() : '',
            $address->getCountry() ? $address->getCountry()->getName() : ''
        );
    }

    /**
     * Format line items as a single line string.
     *
     * @param OrderLineItemCollection $lineItems
     *
     * @return string
     */
    private function formatLineItems(OrderLineItemCollection $lineItems): string
    {
        $items = [];

        foreach ($lineItems as $lineItem) {
            $items[] = sprintf('%dx %s', $lineItem->getQuantity(), $lineItem->getLabel());
        }

        return implode(', ', $items);
    }
}
