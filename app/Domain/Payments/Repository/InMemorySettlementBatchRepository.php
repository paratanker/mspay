<?php

namespace App\Domain\Payments\Repository;

final class InMemorySettlementBatchRepository implements SettlementBatchRepository
{
    /**
     * @var array<string, int>
     */
    private array $processedBatches = [];

    public function markProcessed(string $batchId): bool
    {
        if (array_key_exists($batchId, $this->processedBatches)) {
            return false;
        }

        $this->processedBatches[$batchId] = time();

        return true;
    }

    public function hasProcessed(string $batchId): bool
    {
        return array_key_exists($batchId, $this->processedBatches);
    }
}
