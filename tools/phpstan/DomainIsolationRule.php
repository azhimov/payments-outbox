<?php

declare(strict_types=1);

namespace Tools\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * App\Domain must stay plain PHP: no Laravel (Eloquent, facades, helpers), no Carbon,
 * and no classes from the outer layers (Application, Infrastructure, Presentation).
 * A draft of the future laravel-architecture-rules package.
 *
 * @implements Rule<Node>
 */
final readonly class DomainIsolationRule implements Rule
{
    private const DOMAIN = 'App\\Domain';

    private const FORBIDDEN = [
        'Illuminate',
        'Laravel',
        'Carbon',
        'App\\Application',
        'App\\Infrastructure',
        'App\\Presentation',
    ];

    public function __construct(private ReflectionProvider $reflection) {}

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->inDomain($scope)) {
            return [];
        }

        return match (true) {
            // use Illuminate\...; (imports keep their written name)
            $node instanceof Use_ => array_values(array_filter(array_map(
                fn ($use): ?IdentifierRuleError => $this->checkClass($use->name->toString()),
                $node->uses,
            ))),
            // class X extends Y implements Z
            $node instanceof Class_ => $this->checkNames(array_filter([$node->extends, ...$node->implements]), $scope),
            // new X, X::call(), X::$prop, X::CONST, $a instanceof X
            ($node instanceof New_ || $node instanceof StaticCall || $node instanceof StaticPropertyFetch
                || $node instanceof ClassConstFetch || $node instanceof Instanceof_) && $node->class instanceof Name => $this->checkNames([$node->class], $scope),
            // now(), config(), app(): global helpers defined by the framework
            $node instanceof FuncCall && $node->name instanceof Name => $this->checkFunction($node->name, $scope),
            default => [],
        };
    }

    /**
     * @param  array<Name>  $names
     * @return list<IdentifierRuleError>
     */
    private function checkNames(array $names, Scope $scope): array
    {
        return array_values(array_filter(array_map(
            fn (Name $name): ?IdentifierRuleError => $this->checkClass($scope->resolveName($name)),
            $names,
        )));
    }

    private function checkClass(string $name): ?IdentifierRuleError
    {
        foreach (self::FORBIDDEN as $prefix) {
            if ($name === $prefix || str_starts_with($name, $prefix.'\\')) {
                return RuleErrorBuilder::message("Domain code must not depend on {$name} ({$prefix}).")
                    ->identifier('architecture.domainIsolation')
                    ->build();
            }
        }

        return null;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkFunction(Name $name, Scope $scope): array
    {
        if (! $this->reflection->hasFunction($name, $scope)) {
            return [];
        }

        $file = $this->reflection->getFunction($name, $scope)->getFileName();

        if ($file === null || ! str_contains(str_replace('\\', '/', $file), '/laravel/framework/')) {
            return [];
        }

        return [
            RuleErrorBuilder::message("Domain code must not call the framework helper {$name->toString()}().")
                ->identifier('architecture.domainIsolation')
                ->build(),
        ];
    }

    private function inDomain(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        return $namespace !== null && ($namespace === self::DOMAIN || str_starts_with($namespace, self::DOMAIN.'\\'));
    }
}
