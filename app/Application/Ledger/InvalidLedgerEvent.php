<?php

declare(strict_types=1);

namespace App\Application\Ledger;

use RuntimeException;

/** The event cannot be booked as it is: it goes to the dead-letter queue for a human. */
final class InvalidLedgerEvent extends RuntimeException {}
