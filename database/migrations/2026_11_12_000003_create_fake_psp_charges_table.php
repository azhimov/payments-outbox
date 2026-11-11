<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| State of the fake PSP. In real life this lives at the provider; here it shares the
| database so demo:verify can count actual charges per payment.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fake_psp_charges', function (Blueprint $table): void {
            $table->string('id', 40)->primary();
            // The merchant's idempotency key: our payment id. One charge per key.
            $table->string('payment_id')->unique();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 16);
            $table->string('decline_reason')->nullable();
            // For pending charges: the outcome the webhook will report.
            $table->string('final_status', 16)->nullable();
            $table->string('webhook_url')->nullable();
            $table->timestamp('webhook_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_psp_charges');
    }
};
