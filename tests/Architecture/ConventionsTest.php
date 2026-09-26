<?php

declare(strict_types=1);

use Tests\Support\ModuleInspector;

/*
|--------------------------------------------------------------------------
| Project-wide conventions (spec §3.4, §6.3, §12.1, ADR-0008)
|--------------------------------------------------------------------------
|
| Each of these encodes a rule that is cheap to keep and expensive to retrofit: strict types,
| no mass assignment, thin callers, no float money, and no internal ids in public output.
|
*/

it('declares strict types in every PHP file', function (): void {
    $missing = [];

    foreach (ModuleInspector::allProjectPhpFiles() as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        if (! str_contains($contents, 'declare(strict_types=1)')) {
            $missing[] = ModuleInspector::relativePath($file);
        }
    }

    expect($missing)->toBe([], 'Every PHP file declares strict_types=1 (spec §12.1).');
});

it('never mass assigns from the request', function (): void {
    $forbiddenPatterns = [
        '$request->all()',
        'request()->all()',
        '$request->input()',
    ];

    $violations = [];

    foreach (ModuleInspector::allProjectPhpFiles() as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        foreach ($forbiddenPatterns as $pattern) {
            if (str_contains($contents, $pattern)) {
                $violations[] = ModuleInspector::relativePath($file).' uses '.$pattern;
            }
        }
    }

    expect($violations)->toBe(
        [],
        'Input enters Actions as a validated DTO; $request->all() never reaches a model (spec §3.4.5).',
    );
});

it('keeps writes out of thin callers', function (): void {
    // Controllers, Filament resources, console commands, jobs and webhook handlers validate
    // input and call an Action. They do not persist anything themselves (spec §3.4.1).
    $thinDirectories = ['Http/Controllers', 'Filament', 'Console', 'Jobs'];

    $writeSignals = ['->save()', '->delete()', '->forceDelete()', 'DB::table(', 'DB::insert(', 'DB::update('];

    $violations = [];

    foreach (ModuleInspector::modules() as $module) {
        foreach ($thinDirectories as $directory) {
            foreach (ModuleInspector::filesInModuleDirectory($module, $directory) as $file) {
                $contents = (string) file_get_contents($file->getPathname());

                foreach ($writeSignals as $signal) {
                    if (str_contains($contents, $signal)) {
                        $violations[] = sprintf(
                            '%s performs a write (%s) — call an Action instead.',
                            ModuleInspector::relativePath($file),
                            $signal,
                        );
                    }
                }
            }
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
});

it('never handles money as a float', function (): void {
    // Money is an integer count of minor units. A float anywhere near it is how rounding
    // drift gets into order totals and refunds (spec §6.3, ADR-0008).
    $moneySignals = ['money', 'amount', 'price', 'total', 'cost', 'discount', 'refund', 'tax'];

    $violations = [];

    foreach (ModuleInspector::allProjectPhpFiles() as $file) {
        $contents = (string) file_get_contents($file->getPathname());
        $lowered = strtolower($contents);

        $touchesMoney = false;

        foreach ($moneySignals as $signal) {
            if (str_contains($lowered, $signal)) {
                $touchesMoney = true;

                break;
            }
        }

        if (! $touchesMoney) {
            continue;
        }

        if (preg_match('/\(float\)|\(double\)|float\s+\$|:\s*float\b|floatval\(/', $contents) === 1) {
            $violations[] = ModuleInspector::relativePath($file);
        }
    }

    expect($violations)->toBe(
        [],
        'A file that deals with money must not use float. Use Modules\Support\ValueObjects\Money.',
    );
});

it('exposes no internal ids from API resources', function (): void {
    // Public identifiers are ULIDs; the auto-increment key never leaves the database
    // (spec §6.1, ADR-0008).
    $violations = [];

    foreach (ModuleInspector::modules() as $module) {
        foreach (ModuleInspector::filesInModuleDirectory($module, 'Http/Resources') as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match("/'id'\s*=>\s*\\\$this->id\b/", $contents) === 1) {
                $violations[] = ModuleInspector::relativePath($file);
            }
        }
    }

    expect($violations)->toBe(
        [],
        "An API resource exposes 'id' => \$this->ulid, never the internal auto-increment id.",
    );
});
