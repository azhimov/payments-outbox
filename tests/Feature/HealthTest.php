<?php

declare(strict_types=1);

it('reports healthy on /up', function (): void {
    $this->get('/up')->assertOk();
});

it('ships the outbox and idempotency packages', function (): void {
    expect(config('outbox.transport'))->not->toBeNull()
        ->and(config('idempotency.header'))->toBe('Idempotency-Key');
});
