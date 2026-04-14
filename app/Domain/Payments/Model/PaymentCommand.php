<?php

namespace App\Domain\Payments\Model;

final class PaymentCommand
{
    public const CREATE = 'CREATE';
    public const AUTHORIZE = 'AUTHORIZE';
    public const CAPTURE = 'CAPTURE';
    public const VOID = 'VOID';
    public const REFUND = 'REFUND';
    public const SETTLE = 'SETTLE';
    public const SETTLEMENT = 'SETTLEMENT';
    public const STATUS = 'STATUS';
    public const AUDIT = 'AUDIT';
    public const LIST = 'LIST';
    public const EXIT = 'EXIT';

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::CREATE,
            self::AUTHORIZE,
            self::CAPTURE,
            self::VOID,
            self::REFUND,
            self::SETTLE,
            self::SETTLEMENT,
            self::STATUS,
            self::AUDIT,
            self::LIST,
            self::EXIT,
        ];
    }
}
