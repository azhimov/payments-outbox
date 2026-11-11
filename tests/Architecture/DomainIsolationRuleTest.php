<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tools\PhpStan\DomainIsolationRule;

/**
 * @extends RuleTestCase<DomainIsolationRule>
 */
final class DomainIsolationRuleTest extends RuleTestCase
{
    /**
     * On a fresh machine (every CI run) PHPStan's test container reads its directory
     * listings cache before it exists. PHPStan silences that read with @ and PHPUnit
     * ignores silenced warnings, but Pest's printer (Collision 8.9) still reports it.
     * An empty file is what PHPStan treats as "no listings yet", and it rewrites it.
     */
    public static function setUpBeforeClass(): void
    {
        $cache = sys_get_temp_dir().'/phpstan-tests/cache';
        if (! is_dir($cache)) {
            mkdir($cache, 0777, true);
        }
        if (! is_file($cache.'/directory-listings.bin')) {
            touch($cache.'/directory-listings.bin');
        }
    }

    protected function getRule(): Rule
    {
        return new DomainIsolationRule($this->createReflectionProvider());
    }

    public function test_it_reports_framework_and_outer_layers_in_the_domain(): void
    {
        $this->analyse([__DIR__.'/data/domain-violations.php'], [
            ['Domain code must not depend on App\Infrastructure\Persistence\OrderModel (App\Infrastructure).', 7],
            ['Domain code must not depend on Illuminate\Database\Eloquent\Model (Illuminate).', 8],
            ['Domain code must not depend on Illuminate\Support\Facades\DB (Illuminate).', 9],
            ['Domain code must not depend on Illuminate\Database\Eloquent\Model (Illuminate).', 11],
            ['Domain code must not depend on Illuminate\Support\Facades\DB (Illuminate).', 15],
            ['Domain code must not call the framework helper now().', 16],
            ['Domain code must not depend on App\Infrastructure\Persistence\OrderModel (App\Infrastructure).', 18],
        ]);
    }

    public function test_it_leaves_other_layers_alone(): void
    {
        $this->analyse([__DIR__.'/data/outside-domain.php'], []);
    }
}
