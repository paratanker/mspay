<?php

namespace App\Domain\Payments\Repository;

use App\Domain\Payments\Model\Payment;
use App\Domain\Payments\Model\PaymentState;

interface PaymentRepository
{
    public function find(string $paymentId): ?Payment;

    public function save(Payment $payment): void;

    /**
     * @return list<Payment>
     */
    public function all(): array;

    /**
     * @return list<Payment>
     */
    public function byState(string $state): array;

    public function exists(string $paymentId): bool;
}
