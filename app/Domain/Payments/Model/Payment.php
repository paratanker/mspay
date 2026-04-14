<?php

namespace App\Domain\Payments\Model;

final class Payment
{
    private string $id;

    private int $amountMinor;

    private string $currency;

    private string $merchantId;

    private string $state;

    private ?string $voidReasonCode = null;

    private ?string $failedReason = null;

    private ?int $refundAmountMinor = null;

    public function __construct(string $id, int $amountMinor, string $currency, string $merchantId) {
        $this->id = $id;
        $this->amountMinor = $amountMinor;
        $this->currency = $currency;
        $this->merchantId = $merchantId;
        $this->state = PaymentState::INITIATED;
    }

    public static function reconstitute(
        string $id,
        int $amountMinor,
        string $currency,
        string $merchantId,
        string $state,
        ?string $voidReasonCode = null,
        ?string $failedReason = null,
        ?int $refundAmountMinor = null
    ): self {
        $payment = new self($id, $amountMinor, $currency, $merchantId);
        $payment->state = $state;
        $payment->voidReasonCode = $voidReasonCode;
        $payment->failedReason = $failedReason;
        $payment->refundAmountMinor = $refundAmountMinor;

        return $payment;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function merchantId(): string
    {
        return $this->merchantId;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function voidReasonCode(): ?string
    {
        return $this->voidReasonCode;
    }

    public function failedReason(): ?string
    {
        return $this->failedReason;
    }

    public function refundAmountMinor(): ?int
    {
        return $this->refundAmountMinor;
    }

    public function transitionTo(string $state): void
    {
        $this->state = $state;
    }

    public function markVoided(?string $reasonCode): void
    {
        $this->voidReasonCode = $reasonCode;
        $this->state = PaymentState::VOIDED;
    }

    public function markRefunded(?int $amountMinor = null): void
    {
        $this->refundAmountMinor = $amountMinor;
        $this->state = PaymentState::REFUNDED;
    }

    public function markFailed(string $reason): void
    {
        $this->failedReason = $reason;
        $this->state = PaymentState::FAILED;
    }
}
