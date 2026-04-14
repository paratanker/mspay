<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_pipeline_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_id')->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('merchant_id');
            $table->string('state', 64);
            $table->string('void_reason_code')->nullable();
            $table->string('failed_reason')->nullable();
            $table->unsignedBigInteger('refund_amount_minor')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_pipeline_payments');
    }
};
