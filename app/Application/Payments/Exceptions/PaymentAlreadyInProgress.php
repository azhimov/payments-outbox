<?php

declare(strict_types=1);

namespace App\Application\Payments\Exceptions;

use RuntimeException;

/** The order already has a payment that is processing or succeeded. */
final class PaymentAlreadyInProgress extends RuntimeException {}
