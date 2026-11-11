<?php

declare(strict_types=1);

namespace App\Application\Refunds\Exceptions;

use RuntimeException;

/** The payment does not exist (for this merchant). */
final class PaymentNotFound extends RuntimeException {}
