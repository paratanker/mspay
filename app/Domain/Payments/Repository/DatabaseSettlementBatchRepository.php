<?php

namespace App\Domain\Payments\Repository;

use App\Models\PaymentSettlementBatch;

final class DatabaseSettlementBatchRepository implements SettlementBatchRepository
{
    public function markProcessed(string $batchId): bool
    {
        if ($this->hasProcessed($batchId)) {
            return false;
        }

        PaymentSettlementBatch::query()->create([
            'batch_id' => $batchId,
        ]);

        return true;
    }

    public function hasProcessed(string $batchId): bool
    {
        return PaymentSettlementBatch::query()
            ->where('batch_id', $batchId)
            ->exists();
    }
}
