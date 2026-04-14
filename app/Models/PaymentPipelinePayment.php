<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PaymentPipelinePayment extends Model
{
    protected $table = 'payment_pipeline_payments';

    protected $fillable = [
        'payment_id',
        'amount_minor',
        'currency',
        'merchant_id',
        'state',
        'void_reason_code',
        'failed_reason',
        'refund_amount_minor',
    ];
}
