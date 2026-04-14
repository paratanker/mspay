<?php

namespace App\Domain\Payments\Model;

const PAYMENT_THRESHOLD = [
    'MYR' => 1000000, // RM 10,000.00
    'USD' => 1000000, // $ 10,000.00
    'IDR' => 1000000, // Rp 1.000.000
];

class PaymentThreshold
{
    public static function getPaymentThreshold(): array
    {
        return PAYMENT_THRESHOLD;
    }
}
