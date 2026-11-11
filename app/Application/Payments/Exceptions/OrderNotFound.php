<?php

declare(strict_types=1);

namespace App\Application\Payments\Exceptions;

use RuntimeException;

/** The order does not exist (for this merchant). */
final class OrderNotFound extends RuntimeException {}
