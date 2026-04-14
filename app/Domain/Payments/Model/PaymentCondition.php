<?php

namespace App\Domain\Payments\Model;

use App\Domain\Payments\Model\PaymentState;
use App\Domain\Payments\Model\PaymentCommand;

final class PaymentCondition
{
    public static function getConditions(string $command): array
    {
        switch ($command) {
            case PaymentCommand::AUTHORIZE:
                return [
                    PaymentState::INITIATED,
                ];
            case PaymentCommand::CAPTURE:
                return [
                    PaymentState::AUTHORIZED,
                    PaymentState::PRE_SETTLEMENT_REVIEW,
                ];
            case PaymentCommand::VOID:
                return [
                    PaymentState::INITIATED,
                    PaymentState::AUTHORIZED,
                    PaymentState::PRE_SETTLEMENT_REVIEW,
                ];
            case PaymentCommand::REFUND:
                return [
                    PaymentState::CAPTURED,
                    PaymentState::SETTLED,
                ];
            case PaymentCommand::SETTLE:
                return [
                    PaymentState::CAPTURED,
                    PaymentState::SETTLED,
                ];
            default:
                return [];
        }
    }
}