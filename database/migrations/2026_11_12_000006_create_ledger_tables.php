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
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->string('code', 32)->primary();
            $table->string('name');
        });

        DB::table('ledger_accounts')->insert([
            ['code' => 'psp_clearing', 'name' => 'Owed by the PSP for captured payments'],
            ['code' => 'merchant_payable', 'name' => 'Owed to merchants for paid orders'],
        ]);

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            // = the id of the event that caused it: an event can only be booked once.
            $table->uuid('transaction_id');
            $table->string('account', 32);
            $table->foreign('account')->references('code')->on('ledger_accounts');
            $table->string('direction', 6);
            // Minor units, always positive; the direction gives the sign.
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('event_type', 64);
            $table->uuid('payment_id');
            $table->timestamp('created_at');

            $table->unique(['transaction_id', 'account', 'direction']);
            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};
