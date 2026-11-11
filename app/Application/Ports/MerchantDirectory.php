<?php

declare(strict_types=1);

namespace App\Application\Ports;

interface MerchantDirectory
{
    /** The merchant id for a plain API key, or null. */
    public function findIdByApiKey(string $apiKey): ?string;
}
