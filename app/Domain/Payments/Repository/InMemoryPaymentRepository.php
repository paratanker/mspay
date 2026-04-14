<?php

namespace App\Domain\Payments\Repository;

use App\Domain\Payments\Model\Payment;

final class InMemoryPaymentRepository implements PaymentRepository
{
    /**
     * @var array<string, Payment>
     */
    private array $payments = [];

    public function find(string $paymentId): ?Payment
    {
        return $this->payments[$paymentId] ?? null;
    }

    public function exists(string $paymentId): bool
    {
        return array_key_exists($paymentId, $this->payments);
    }

    public function save(Payment $payment): void
    {
        $this->payments[$payment->id()] = $payment;
    }

    /**
     * @return list<Payment>
     */
    public function all(): array
    {
        $payments = array_values($this->payments);
        usort($payments, static fn (Payment $a, Payment $b): int => strcmp($a->id(), $b->id()));

        return $payments;
    }

    /**
     * @return list<Payment>
     */
    public function byState(string $state): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (Payment $payment): bool => $payment->state() === $state
        ));
    }
}
