<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Infrastructure\Persistence\Models\MerchantModel;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * The fictional "Demo Store" merchant (key: config demo.api_key, env DEMO_API_KEY).
     */
    public function run(): void
    {
        $key = config('demo.api_key');

        MerchantModel::query()->updateOrCreate(
            ['id' => '01970000-0000-7000-8000-000000000001'],
            ['name' => 'Demo Store', 'api_key_hash' => hash('sha256', is_string($key) ? $key : 'demo-store-key')],
        );
    }
}
