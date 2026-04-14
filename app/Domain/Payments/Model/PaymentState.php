<?php

namespace App\Domain\Payments\Model;

final class PaymentState
{
    public const INITIATED = 'INITIATED';
    public const AUTHORIZED = 'AUTHORIZED';
    public const PRE_SETTLEMENT_REVIEW = 'PRE_SETTLEMENT_REVIEW';
    public const CAPTURED = 'CAPTURED';
    public const SETTLED = 'SETTLED';
    public const VOIDED = 'VOIDED';
    public const REFUNDED = 'REFUNDED';
    public const FAILED = 'FAILED';

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::INITIATED,
            self::AUTHORIZED,
            self::PRE_SETTLEMENT_REVIEW,
            self::CAPTURED,
            self::SETTLED,
            self::VOIDED,
            self::REFUNDED,
            self::FAILED,
        ];
    }
}
