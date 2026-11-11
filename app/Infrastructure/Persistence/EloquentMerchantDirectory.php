<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Ports\MerchantDirectory;
use App\Infrastructure\Persistence\Models\MerchantModel;

final class EloquentMerchantDirectory implements MerchantDirectory
{
    public function findIdByApiKey(string $apiKey): ?string
    {
        $id = MerchantModel::query()->where('api_key_hash', hash('sha256', $apiKey))->value('id');

        return is_string($id) ? $id : null;
    }
}
