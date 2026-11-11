<?php

declare(strict_types=1);

namespace App\Domain\Sample;

use App\Infrastructure\Persistence\OrderModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class LeakyAggregate extends Model
{
    public function save(array $options = []): bool
    {
        DB::table('orders')->insert([]);
        $when = now();

        return (new OrderModel) !== null && $when !== null;
    }

    public function fine(\DateTimeImmutable $at): string
    {
        return $at->format('c').strtoupper('ok');
    }
}
