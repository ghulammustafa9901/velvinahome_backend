<?php

declare(strict_types=1);

namespace Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Filesystem and reflection helper for the architecture tests.
 *
 * The boundary rules in spec §3.4 are only real if a machine checks them, and the checks have
 * to work on modules that do not exist yet — modules are added phase by phase. So everything
 * here discovers modules from the filesystem rather than from a hardcoded list.
 */
final class ModuleInspector
{
    public static function modulesPath(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'modules';
    }

    /**
     * Module short names, e.g. ['Identity', 'Platform', 'Settings', 'Support'].
     *
     * @return list<string>
     */
    public static function modules(): array
    {
        $entries = scandir(self::modulesPath()) ?: [];

        $modules = array_values(array_filter(
            $entries,
            fn (string $entry): bool => $entry !== '.'
                && $entry !== '..'
                && is_dir(self::modulesPath().DIRECTORY_SEPARATOR.$entry)
                && is_file(self::modulesPath().DIRECTORY_SEPARATOR.$entry.DIRECTORY_SEPARATOR.'composer.json'),
        ));

        sort($modules);

        return $modules;
    }

    /**
     * Every PHP file under a module's src/ directory.
     *
     * @return list<SplFileInfo>
     */
    public static function sourceFiles(string $module): array
    {
        return self::phpFilesIn(self::modulesPath().DIRECTORY_SEPARATOR.$module.DIRECTORY_SEPARATOR.'src');
    }

    /**
     * Every PHP file of every module, plus the application's own app/ directory.
     *
     * @return list<SplFileInfo>
     */
    public static function allProjectPhpFiles(): array
    {
        $files = self::phpFilesIn(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app');

        foreach (self::modules() as $module) {
            $files = array_merge($files, self::sourceFiles($module));
        }

        return $files;
    }

    /**
     * PHP files inside one sub-directory of a module's src/, e.g. 'Actions'.
     *
     * @return list<SplFileInfo>
     */
    public static function filesInModuleDirectory(string $module, string $directory): array
    {
        $path = self::modulesPath()
            .DIRECTORY_SEPARATOR.$module
            .DIRECTORY_SEPARATOR.'src'
            .DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);

        return self::phpFilesIn($path);
    }

    /**
     * Fully-qualified class name for a file inside a module's src/ directory.
     */
    public static function classNameFor(string $module, SplFileInfo $file): string
    {
        $srcRoot = self::modulesPath().DIRECTORY_SEPARATOR.$module.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR;

        $relative = str_replace($srcRoot, '', $file->getPathname());
        $relative = substr($relative, 0, -strlen('.php'));

        return 'Modules\\'.$module.'\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
    }

    /**
     * Path relative to the project root, for readable failure messages.
     */
    public static function relativePath(SplFileInfo $file): string
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR;

        return str_replace([$root, '\\'], ['', '/'], $file->getPathname());
    }

    /**
     * @return list<SplFileInfo>
     */
    private static function phpFilesIn(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        usort($files, fn (SplFileInfo $a, SplFileInfo $b): int => strcmp($a->getPathname(), $b->getPathname()));

        return $files;
    }
}
