<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Symfony\Component\Yaml\Yaml;

function openApi(): array
{
    return Yaml::parseFile(base_path('docs/openapi.yaml'));
}

it('is OpenAPI 3.1', function (): void {
    expect(openApi()['openapi'])->toBe('3.1.0');
});

it('documents every API route and nothing else', function (): void {
    $documented = [];
    foreach (openApi()['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            $documented[] = strtoupper($method).' '.$path;
        }
    }

    $routes = collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $r): bool => str_starts_with($r->uri(), 'api/'))
        ->flatMap(fn (Route $r): array => array_map(fn (string $m): string => "{$m} /{$r->uri()}", array_diff($r->methods(), ['HEAD'])))
        ->values()
        ->all();

    expect($documented)->toEqualCanonicalizing($routes);
});

it('marks the paying and refunding routes as needing an Idempotency-Key', function (): void {
    $refs = fn (string $path): array => array_column(openApi()['paths'][$path]['post']['parameters'], '$ref');

    expect($refs('/api/orders/{order}/payments'))->toContain('#/components/parameters/IdempotencyKey')
        ->and($refs('/api/payments/{payment}/refunds'))->toContain('#/components/parameters/IdempotencyKey');
});
