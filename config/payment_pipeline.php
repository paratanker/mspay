<?php

use App\Domain\Payments\Model\PaymentCurrency;
use App\Domain\Payments\Model\PaymentThreshold;

/**
 * @return list<string>|null null => use PaymentCurrency defaults
 */
$parseSupportedCurrencyCodes = static function (?string $raw): ?array {
    if ($raw === null) {
        return null;
    }
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return null;
    }
    if ($trimmed[0] !== '[') {
        return null;
    }
    $decoded = json_decode($trimmed, true);
    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
        return null;
    }
    $codes = [];
    foreach ($decoded as $item) {
        if (! is_string($item)) {
            continue;
        }
        $code = strtoupper(trim($item));
        if (preg_match('/^[A-Z]{3}$/', $code) === 1) {
            $codes[] = $code;
        }
    }

    return $codes === [] ? null : array_values(array_unique($codes));
};

$fromEnvCurrencies = $parseSupportedCurrencyCodes(env('PAYMENT_PIPELINE_SUPPORTED_CURRENCIES'));

if ($fromEnvCurrencies !== null) {
    $base = PaymentCurrency::getPaymentCurrencies();
    $supportedCurrencies = [];
    foreach ($fromEnvCurrencies as $code) {
        $supportedCurrencies[$code] = $base[$code] ?? [
            'name' => $code,
            'code' => $code,
            'decimal_places' => 2,
        ];
    }
} else {
    $supportedCurrencies = PaymentCurrency::getPaymentCurrencies();
}

$supportedCodes = array_keys($supportedCurrencies);

$constantThresholds = PaymentThreshold::getPaymentThreshold();
$pre_settlement_review_thresholds = array_intersect_key($constantThresholds, array_flip($supportedCodes));

$envThresholdRaw = env('PAYMENT_PIPELINE_REVIEW_THRESHOLD', '');
$trimmedThreshold = trim((string) $envThresholdRaw);
if ($trimmedThreshold !== '' && $trimmedThreshold[0] === '{') {
    $decoded = json_decode($trimmedThreshold, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        foreach ($decoded as $code => $minor) {
            if (! is_string($code) && ! is_int($code)) {
                continue;
            }
            $code = strtoupper((string) $code);
            if (! in_array($code, $supportedCodes, true)) {
                continue;
            }
            if (is_numeric($minor)) {
                $pre_settlement_review_thresholds[$code] = (int) $minor;
            }
        }
    }
}

return [
    'storage_driver' => env('PAYMENT_PIPELINE_STORAGE_DRIVER', 'database'),

    'supported_currencies' => $supportedCurrencies,

    /*
    |--------------------------------------------------------------------------
    | Pre-Settlement Review Threshold (minor units per currency)
    |--------------------------------------------------------------------------
    |
    | Defaults come from PaymentThreshold, intersected with configured currencies.
    | Optional env: JSON object {"MYR":1000000,"USD":1000000} merged over those defaults.
    |
    */
    'pre_settlement_review_thresholds' => $pre_settlement_review_thresholds,

    'log_level' => env('PAYMENT_PIPELINE_LOG_LEVEL', 'info'),
];
