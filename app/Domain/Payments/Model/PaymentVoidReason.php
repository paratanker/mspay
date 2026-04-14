<?php

namespace App\Domain\Payments\Model;

final class PaymentVoidReason
{
    public const CUSTOMER_REQUEST = 'CUSTOMER_REQUEST';
    public const FRAUD_SUSPECTED = 'FRAUD_SUSPECTED';
    public const INVALID_CARD = 'INVALID_CARD';
    public const LATE_VOID = 'LATE_VOID';

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::CUSTOMER_REQUEST,
            self::FRAUD_SUSPECTED,
            self::INVALID_CARD,
            self::LATE_VOID,
        ];
    }
}
