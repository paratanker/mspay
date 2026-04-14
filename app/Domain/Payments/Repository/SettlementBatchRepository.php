<?php

namespace App\Domain\Payments\Repository;

interface SettlementBatchRepository
{
    public function markProcessed(string $batchId): bool;

    public function hasProcessed(string $batchId): bool;
}
