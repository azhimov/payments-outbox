<?php

declare(strict_types=1);

use App\Infrastructure\Psp\PspSignature;

it('signs timestamp and body with HMAC-SHA256', function (): void {
    $signature = new PspSignature('secret');

    expect($signature->sign(1_700_000_000, '{"a":1}'))
        ->toBe('sha256='.hash_hmac('sha256', '1700000000.{"a":1}', 'secret'));
});

it('verifies within the time window only', function (int $now, bool $valid): void {
    $signer = new PspSignature('secret');
    $sig = $signer->sign(1_700_000_000, '{}');

    expect($signer->verify('1700000000', '{}', $sig, $now, 300))->toBe($valid);
})->with([
    'same second' => [1_700_000_000, true],
    '5 minutes later' => [1_700_000_300, true],
    'too late' => [1_700_000_301, false],
    'from the future' => [1_699_999_699, false],
]);

it('rejects a changed body, a wrong secret or a junk timestamp', function (): void {
    $sig = (new PspSignature('secret'))->sign(1_700_000_000, '{}');

    expect((new PspSignature('secret'))->verify('1700000000', '{"x":1}', $sig, 1_700_000_000, 300))->toBeFalse()
        ->and((new PspSignature('other'))->verify('1700000000', '{}', $sig, 1_700_000_000, 300))->toBeFalse()
        ->and((new PspSignature('secret'))->verify('abc', '{}', $sig, 1_700_000_000, 300))->toBeFalse();
});
