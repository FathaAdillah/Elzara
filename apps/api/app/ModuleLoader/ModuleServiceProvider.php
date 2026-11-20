<?php

declare(strict_types=1);

namespace App\ModuleLoader;

use Illuminate\Support\ServiceProvider;

/** M-2 (ADR-010 K-5): glob, never a hand-edited list file — two module PRs must not conflict on one file. */
final class ModuleServiceProvider extends ServiceProvider
{
    private const MIGRATIONS = 'Infrastructure/Database/Migrations';

    public function boot(): void
    {
        $paths = self::allMigrationPaths(base_path('app'));

        if ($paths !== []) {
            $this->loadMigrationsFrom($paths);
        }
    }

    /** @return list<array{name: string, path: string}> */
    public static function discover(string $modulesRoot): array
    {
        $modules = array_map(
            static fn (string $path): array => ['name' => basename($path), 'path' => $path],
            glob($modulesRoot.'/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [],
        );

        usort($modules, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $modules;
    }

    /** @return list<string> modul bertabel; Search dan Reporting tidak punya migrasi (Doc 01 §3.1) */
    public static function migrationPaths(string $modulesRoot): array
    {
        return array_values(array_filter(array_map(
            static fn (array $module): string => $module['path'].'/'.self::MIGRATIONS,
            self::discover($modulesRoot),
        ), static fn (string $path): bool => is_dir($path)));
    }

    /** @return list<string> satu jalur global (M-1): migrasi modul + trio reliability milik Platform */
    public static function allMigrationPaths(string $appRoot): array
    {
        $platform = $appRoot.'/Platform/'.self::MIGRATIONS;

        return array_values(array_filter([
            ...self::migrationPaths($appRoot.'/Modules'),
            is_dir($platform) ? $platform : null,
        ]));
    }
}
