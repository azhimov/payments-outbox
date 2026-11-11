<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Stuck payments (lost webhook, timeout, crash) are finished by asking the PSP.
Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping();
