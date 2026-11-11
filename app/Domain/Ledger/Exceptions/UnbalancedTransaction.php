<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exceptions;

use DomainException;

final class UnbalancedTransaction extends DomainException {}
