<?php

namespace App\Domain\Payments\Repository;

use App\Domain\Payments\Model\Payment;
use App\Domain\Payments\Model\PaymentState;
use App\Models\PaymentPipelinePayment;

final class DatabasePaymentRepository implements PaymentRepository
{
    public function find(string $paymentId): ?Payment
    {
        $record = PaymentPipelinePayment::query()
            ->where('payment_id', $paymentId)
            ->first();

        if ($record === null) {
            return null;
        }

        return $this->toDomain($record);
    }

    public function save(Payment $payment): void
    {
        PaymentPipelinePayment::query()->updateOrCreate(
            ['payment_id' => $payment->id()],
            [
                'amount_minor' => $payment->amountMinor(),
                'currency' => $payment->currency(),
                'merchant_id' => $payment->merchantId(),
                'state' => $payment->state(),
                'void_reason_code' => $payment->voidReasonCode(),
                'failed_reason' => $payment->failedReason(),
                'refund_amount_minor' => $payment->refundAmountMinor(),
            ]
        );
    }

    public function all(): array
    {
        return PaymentPipelinePayment::query()
            ->orderBy('payment_id')
            ->get()
            ->map(fn (PaymentPipelinePayment $record): Payment => $this->toDomain($record))
            ->values()
            ->all();
    }

    public function byState(string $state): array
    {
        return PaymentPipelinePayment::query()
            ->where('state', $state)
            ->orderBy('payment_id')
            ->get()
            ->map(fn (PaymentPipelinePayment $record): Payment => $this->toDomain($record))
            ->values()
            ->all();
    }

    private function toDomain(PaymentPipelinePayment $record): Payment
    {
        return Payment::reconstitute(
            $record->payment_id,
            (int) $record->amount_minor,
            $record->currency,
            $record->merchant_id,
            (string) $record->state,
            $record->void_reason_code,
            $record->failed_reason,
            $record->refund_amount_minor !== null ? (int) $record->refund_amount_minor : null
        );
    }

    public function exists(string $paymentId): bool
    {
        return PaymentPipelinePayment::query()
            ->where('payment_id', $paymentId)
            ->exists();
    }
}
