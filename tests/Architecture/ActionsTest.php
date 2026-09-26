<?php

declare(strict_types=1);

use Modules\Support\Contracts\Action;
use Tests\Support\ModuleInspector;

/*
|--------------------------------------------------------------------------
| Actions are the only place business logic lives (spec §3.4.1, ADR-0007)
|--------------------------------------------------------------------------
|
| These tests are what stop an Action from quietly turning into a service class that a
| controller reaches into, or from accepting a Request and becoming untestable outside HTTP.
|
*/

/**
 * @return list<array{string, class-string}> [module, class]
 */
function actionClasses(): array
{
    $classes = [];

    foreach (ModuleInspector::modules() as $module) {
        foreach (ModuleInspector::filesInModuleDirectory($module, 'Actions') as $file) {
            $class = ModuleInspector::classNameFor($module, $file);

            if (class_exists($class)) {
                $classes[] = [$module, $class];
            }
        }
    }

    return $classes;
}

it('has no Action outside an Actions directory', function (): void {
    $misplaced = [];

    foreach (ModuleInspector::modules() as $module) {
        foreach (ModuleInspector::sourceFiles($module) as $file) {
            $class = ModuleInspector::classNameFor($module, $file);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->implementsInterface(Action::class)) {
                continue;
            }

            if (! str_contains($reflection->getName(), '\\Actions\\')) {
                $misplaced[] = $reflection->getName();
            }
        }
    }

    expect($misplaced)->toBe([], 'Classes implementing Action must live in the module\'s Actions directory.');
});

it('declares every Action final', function (): void {
    $notFinal = [];

    foreach (actionClasses() as [$module, $class]) {
        if (! (new ReflectionClass($class))->isFinal()) {
            $notFinal[] = $class;
        }
    }

    expect($notFinal)->toBe([], 'An Action must be final: extending one hides business rules in a subclass.');
});

it('makes every Action implement the Action contract', function (): void {
    $missing = [];

    foreach (actionClasses() as [$module, $class]) {
        if (! (new ReflectionClass($class))->implementsInterface(Action::class)) {
            $missing[] = $class;
        }
    }

    expect($missing)->toBe([], 'An Action must implement '.Action::class.' so it is discoverable by type.');
});

it('gives every Action exactly one public entry point', function (): void {
    $violations = [];

    foreach (actionClasses() as [$module, $class]) {
        $reflection = new ReflectionClass($class);

        $entryPoints = array_values(array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $method): bool => ! $method->isConstructor()
                && ! $method->isStatic()
                && $method->getDeclaringClass()->getName() === $reflection->getName(),
        ));

        $names = array_map(fn (ReflectionMethod $m): string => $m->getName(), $entryPoints);

        if (count($names) !== 1) {
            $violations[] = $class.' has ['.implode(', ', $names).']';

            continue;
        }

        if (! in_array($names[0], ['handle', '__invoke'], true)) {
            $violations[] = $class.' entry point is '.$names[0].', expected handle or __invoke';
        }
    }

    expect($violations)->toBe([], 'An Action performs one operation through one public method (handle or __invoke).');
});

it('keeps HTTP and admin-panel types out of Action signatures', function (): void {
    $forbidden = [
        'Illuminate\Http\Request',
        'Illuminate\Http\Response',
        'Illuminate\Http\JsonResponse',
        'Illuminate\Foundation\Http\FormRequest',
    ];

    $violations = [];

    foreach (actionClasses() as [$module, $class]) {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $typeName = $type->getName();

                $isForbidden = in_array($typeName, $forbidden, true)
                    || str_starts_with($typeName, 'Filament\\');

                if ($isForbidden) {
                    $violations[] = sprintf(
                        '%s::%s() takes %s',
                        $class,
                        $method->getName(),
                        $typeName,
                    );
                }
            }
        }
    }

    expect($violations)->toBe(
        [],
        'Input reaches an Action as a validated DTO, never as a Request or a Filament object (spec §3.4.5).',
    );
});
