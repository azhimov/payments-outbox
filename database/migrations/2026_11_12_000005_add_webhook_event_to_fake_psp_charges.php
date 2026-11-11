<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fake_psp_charges', function (Blueprint $table): void {
            // One event id per final status, reused when the webhook is sent again.
            $table->string('webhook_event_id', 40)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('fake_psp_charges', function (Blueprint $table): void {
            $table->dropUnique(['webhook_event_id']);
            $table->dropColumn('webhook_event_id');
        });
    }
};
