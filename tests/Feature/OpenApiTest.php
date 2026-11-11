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

it('documents the gateway errors nginx answers when the api is unreachable', function (): void {
    $responses = fn (string $path): array => openApi()['paths'][$path]['post']['responses'];
    $gatewayError = ['$ref' => '#/components/responses/GatewayError'];

    preg_match_all('/return (\d{3}) \'\{"error":\{"code":"(\w+)"/', (string) file_get_contents(base_path('docker/nginx/default.conf')), $nginx);
    $codes = openApi()['components']['schemas']['Error']['properties']['error']['properties']['code']['examples'];

    expect($nginx[1])->toBe(['502', '504'])
        ->and($codes)->toContain(...$nginx[2])
        ->and($responses('/api/orders/{order}/payments'))->toMatchArray(['502' => $gatewayError, '504' => $gatewayError])
        ->and($responses('/api/payments/{payment}/refunds'))->toMatchArray(['502' => $gatewayError, '504' => $gatewayError]);
});
