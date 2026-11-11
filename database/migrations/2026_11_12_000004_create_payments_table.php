<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants');
            $table->foreignUuid('order_id')->constrained('orders');
            $table->string('status', 24);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->string('psp_payment_id')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });

        // One live payment per order, whatever idempotency key the client sends:
        // a second attempt is possible only after a decline or failure.
        DB::statement("create unique index payments_one_live_per_order on payments (order_id) where status not in ('declined', 'failed')");
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
