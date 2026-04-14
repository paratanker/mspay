<?php

namespace App\Domain\Payments\Service;

use App\Domain\Payments\Model\Payment;
use App\Domain\Payments\Model\PaymentState;
use App\Domain\Payments\Model\PaymentCommand;
use App\Domain\Payments\Model\PaymentCondition;

final class PaymentStateMachine
{
    public function authorize(Payment $payment, bool $requiresReview): void
    {
        $this->ensureState($payment, PaymentCondition::getConditions(PaymentCommand::AUTHORIZE), PaymentCommand::AUTHORIZE);

        $payment->transitionTo($requiresReview ? PaymentState::PRE_SETTLEMENT_REVIEW : PaymentState::AUTHORIZED);
    }

    public function capture(Payment $payment): void
    {
        $this->ensureState($payment, PaymentCondition::getConditions(PaymentCommand::CAPTURE), PaymentCommand::CAPTURE);

        $payment->transitionTo(PaymentState::CAPTURED);
    }

    public function void(Payment $payment, ?string $reasonCode): void
    {
        $this->ensureState($payment, PaymentCondition::getConditions(PaymentCommand::VOID), PaymentCommand::VOID);

        $payment->markVoided($reasonCode);
    }

    public function refund(Payment $payment, ?int $amountMinor = null): void
    {
        $this->ensureState($payment, PaymentCondition::getConditions(PaymentCommand::REFUND), PaymentCommand::REFUND);

        $payment->markRefunded($amountMinor);
    }

    public function settle(Payment $payment): bool
    {
        if ($payment->state() === PaymentState::SETTLED) {
            return false;
        }

        $this->ensureState($payment, PaymentCondition::getConditions(PaymentCommand::SETTLE), PaymentCommand::SETTLE);
        $payment->transitionTo(PaymentState::SETTLED);

        return true;
    }

    /**
     * @param list<string> $expectedStates
     */
    private function ensureState(Payment $payment, array $expectedStates, string $command): void
    {
        if (in_array($payment->state(), $expectedStates, true)) {

            // if capture command and payment state is pre settlement review, check if the payment is authorized
            if($command === PaymentCommand::CAPTURE && $payment->state() === PaymentState::PRE_SETTLEMENT_REVIEW) {

                // Auto review the payment
                if(!$this->selfReview($payment)) {
                    throw new PaymentDomainException(sprintf(
                        '%s not allowed from %s because self review failed',
                        $command,
                        $payment->state()
                    ));
                }
            }

            return;
        }

        throw new PaymentDomainException(sprintf(
            '%s not allowed from %s',
            $command,
            $payment->state()        ));
    }

    private function selfReview(Payment $payment): bool
    {
        // TODO: Implement self review logic
        return true;
    }
}
