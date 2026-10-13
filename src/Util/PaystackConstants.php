<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Util;

/**
 * PaystackConstants contains all fixed keys and string constants used throughout the Paystack plugin.
 */
class PaystackConstants
{
    public const MINIMUM_REFUND_AMOUNT = 50;
    public const METADATA_ORDER_TRANSACTION_ID = 'order_transaction_id';
    public const FIELD_REFERENCE = 'paystack_reference';
    public const FIELD_TRANSACTION_ID = 'paystack_transaction_id';
    public const FIELD_PAYMENT_TYPE = 'paystack_payment_type';
    public const FIELD_TRANSACTION_FEE = 'paystack_transaction_fee';
    public const FIELD_AMOUNT = 'paystack_amount';
    public const FIELD_CURRENCY = 'paystack_currency';
    public const FIELD_VERIFIED_AT = 'paystack_verified_at';
}
