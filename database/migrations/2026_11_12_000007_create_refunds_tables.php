<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            // Derived from the merchant's Idempotency-Key: a retry finds the same row.
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained('payments');
            $table->foreignUuid('merchant_id')->constrained('merchants');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            // processing (amount reserved) -> succeeded | failed
            $table->string('status', 16);
            $table->timestamps();

            $table->index(['payment_id', 'status']);
        });

        // The fake PSP's refunds, one per refund id.
        Schema::create('fake_psp_refunds', function (Blueprint $table): void {
            $table->string('id', 40)->primary();
            $table->string('refund_id')->unique();
            $table->string('payment_id');
            $table->unsignedBigInteger('amount');
            $table->timestamps();

            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_psp_refunds');
        Schema::dropIfExists('refunds');
    }
};
