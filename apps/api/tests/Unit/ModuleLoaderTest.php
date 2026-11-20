<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ModuleLoader\ModuleServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Smoke test loader: berjalan tanpa database (Doc 01 §3.1, ADR-010 K-5). */
final class ModuleLoaderTest extends TestCase
{
    private const MODULES = [
        'Identity', 'Catalog', 'Inventory', 'Cart', 'Orders', 'PreOrder', 'Sourcing', 'Procurement',
        'MarketplacePurchase', 'Billing', 'Payments', 'Fulfillment', 'Logistics', 'Promotion', 'UGC',
        'Notification', 'Content', 'Support', 'Search', 'Reporting', 'Settings', 'Audit',
    ];

    private const WITHOUT_TABLES = ['Search', 'Reporting'];

    #[Test]
    public function glob_menemukan_dua_puluh_dua_modul(): void
    {
        // loader mengurutkan hasil glob dengan strcmp, jadi harapan diurutkan dengan aturan yang sama.
        $expected = self::MODULES;
        sort($expected, SORT_STRING);

        $this->assertSame($expected, array_column($this->modules(), 'name'));
    }

    #[Test]
    public function hanya_modul_bertabel_yang_punya_root_migrasi(): void
    {
        $paths = ModuleServiceProvider::migrationPaths(base_path('app/Modules'));

        $this->assertCount(20, $paths);

        foreach (self::WITHOUT_TABLES as $module) {
            $this->assertNotContains($module, array_map(
                static fn (string $path): string => basename(dirname($path, 3)),
                $paths,
            ));
        }
    }

    #[Test]
    public function jalur_global_mencakup_migrasi_platform(): void
    {
        $paths = ModuleServiceProvider::allMigrationPaths(base_path('app'));

        $this->assertCount(21, $paths);
        // loader menempelkan pemisah '/' ke base path, jadi bandingkan bentuk realpath-nya saja.
        $this->assertContains(
            realpath(base_path('app/Platform/Infrastructure/Database/Migrations')),
            array_map('realpath', $paths),
        );
        $this->assertFileExists(
            base_path('app/Platform/Infrastructure/Database/Migrations/0001_Platform_create_reliability_tables.php'));
    }

    /** @return list<array{name: string, path: string}> */
    private function modules(): array
    {
        return ModuleServiceProvider::discover(base_path('app/Modules'));
    }
}
