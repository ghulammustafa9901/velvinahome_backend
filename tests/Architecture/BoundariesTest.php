<?php

declare(strict_types=1);

use Tests\Support\ModuleInspector;

/*
|--------------------------------------------------------------------------
| Module boundaries (spec §3.4.3, §3.4.4, ADR-0007)
|--------------------------------------------------------------------------
|
| A module's public surface is exactly Contracts/, Data/, Events/ and Enums/. Everything
| else is internal. This is the test that makes "just join to their table, it's one query"
| fail the build instead of shipping — which is the failure mode that turns a modular
| monolith back into the tangle this platform replaces.
|
*/

/**
 * Directories of a module that other modules may never import from.
 *
 * @var list<string>
 */
const INTERNAL_NAMESPACES = ['Models', 'Actions', 'Support', 'Queries', 'Http', 'Filament', 'Jobs', 'Policies'];

it('never imports another module\'s internals', function (): void {
    $violations = [];
    $modules = ModuleInspector::modules();

    foreach ($modules as $module) {
        foreach (ModuleInspector::sourceFiles($module) as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            foreach ($modules as $other) {
                if ($other === $module) {
                    continue;
                }

                foreach (INTERNAL_NAMESPACES as $internal) {
                    $needle = 'Modules\\'.$other.'\\'.$internal.'\\';

                    if (str_contains($contents, $needle)) {
                        $violations[] = sprintf(
                            '%s references %s — use %s\'s Contracts or react to its Events instead.',
                            ModuleInspector::relativePath($file),
                            $needle,
                            $other,
                        );
                    }
                }
            }
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
});

it('keeps the Support kernel free of domain dependencies', function (): void {
    $violations = [];

    foreach (ModuleInspector::sourceFiles('Support') as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        foreach (ModuleInspector::modules() as $other) {
            if ($other === 'Support') {
                continue;
            }

            if (str_contains($contents, 'Modules\\'.$other.'\\')) {
                $violations[] = sprintf(
                    '%s depends on %s.',
                    ModuleInspector::relativePath($file),
                    $other,
                );
            }
        }
    }

    expect($violations)->toBe(
        [],
        'Support is the shared kernel: every module depends on it, so it may depend on none of them.',
    );
});

it('declares a service provider for every module', function (): void {
    $missing = [];

    foreach (ModuleInspector::modules() as $module) {
        $provider = 'Modules\\'.$module.'\\Providers\\'.$module.'ServiceProvider';

        if (! class_exists($provider)) {
            $missing[] = $provider;
        }
    }

    expect($missing)->toBe([], 'Each module is wired in through its own service provider.');
});
