<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/Support.php';

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Integration');
