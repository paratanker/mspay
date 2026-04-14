<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PaymentSettlementBatch extends Model
{
    protected $table = 'payment_settlement_batches';

    protected $fillable = [
        'batch_id',
    ];
}
