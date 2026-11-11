<?php

declare(strict_types=1);

namespace App\Application\Refunds\Exceptions;

use RuntimeException;

/** The PSP gave no clear answer; retry with the same Idempotency-Key. */
final class PspUnavailable extends RuntimeException {}
