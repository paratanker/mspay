<?php

namespace Tests\Unit\Domain\Payments;

use App\Domain\Payments\Model\Payment;
use App\Domain\Payments\Model\PaymentState;
use App\Domain\Payments\Service\PaymentDomainException;
use App\Domain\Payments\Service\PaymentStateMachine;
use PHPUnit\Framework\TestCase;

class PaymentStateMachineTest extends TestCase
{
    public function test_happy_path_capture_settle_reflects_expected_states(): void
    {
        $machine = new PaymentStateMachine();
        $payment = new Payment('P1001', 1000, 'MYR', 'M01');

        $machine->authorize($payment, false);
        $this->assertSame(PaymentState::AUTHORIZED, $payment->state());

        $machine->capture($payment);
        $this->assertSame(PaymentState::CAPTURED, $payment->state());

        $transitioned = $machine->settle($payment);
        $this->assertTrue($transitioned);
        $this->assertSame(PaymentState::SETTLED, $payment->state());

        $idempotent = $machine->settle($payment);
        $this->assertFalse($idempotent);
        $this->assertSame(PaymentState::SETTLED, $payment->state());
    }

    public function test_invalid_transitions_raise_domain_error(): void
    {
        $machine = new PaymentStateMachine();
        $payment = new Payment('P1002', 1250, 'MYR', 'M01');

        $this->expectException(PaymentDomainException::class);
        $machine->refund($payment, 100);
    }

    public function test_void_after_capture_is_rejected(): void
    {
        $machine = new PaymentStateMachine();
        $payment = new Payment('P1003', 1500, 'MYR', 'M01');

        $machine->authorize($payment, false);
        $machine->capture($payment);

        $this->expectException(PaymentDomainException::class);
        $machine->void($payment, 'LATE_VOID');
    }
}
