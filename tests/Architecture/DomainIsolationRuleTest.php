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
