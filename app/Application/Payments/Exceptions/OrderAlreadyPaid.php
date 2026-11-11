<?php

declare(strict_types=1);

namespace App\Application\Payments\Exceptions;

use RuntimeException;

/** The order is already paid. */
final class OrderAlreadyPaid extends RuntimeException {}
