<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $api_key_hash
 */
final class MerchantModel extends Model
{
    use HasUuids;

    protected $table = 'merchants';

    protected $fillable = ['id', 'name', 'api_key_hash'];
}
