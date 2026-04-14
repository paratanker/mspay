<?php

namespace App\Domain\Payments\Model;

const PAYMENT_CURRENCIES = [
    'MYR' => [
        'name' => 'Malaysian Ringgit',
        'code' => 'MYR',
        'decimal_places' => 2,
    ],
    'USD' => [
        'name' => 'United States Dollar',
        'code' => 'USD',
        'decimal_places' => 2,
    ],
    'IDR' => [
        'name' => 'Indonesian Rupiah',
        'code' => 'IDR',
        'decimal_places' => 0,
    ],
];

class PaymentCurrency
{
    public static function getPaymentCurrencies(): array
    {
        return PAYMENT_CURRENCIES;
    }
}
