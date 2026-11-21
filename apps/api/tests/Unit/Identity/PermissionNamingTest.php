<?php

declare(strict_types=1);

namespace Tests\Unit\Identity;

use PHPUnit\Framework\TestCase;

final class PermissionNamingTest extends TestCase
{
    public function test_config_uses_the_canonical_documented_prefixes_and_special_actions(): void
    {
        $root = dirname(__DIR__, 5);
        $document = file_get_contents($root.'/docs/11-blueprint-reconciliation.md');
        $this->assertIsString($document);
        $start = strpos($document, '| Modul (Doc 09 §19) | Prefix permission |');
        $this->assertNotFalse($start);
        $table = explode("\n\n", substr($document, $start), 2)[0];
        preg_match_all('/`([a-z]+)`/', $table, $matches);
        $prefixes = $matches[1];
        $this->assertCount(21, $prefixes);
        preg_match('/aksi khusus \(([^\n]+)\)/u', $document, $specialLine);
        $this->assertArrayHasKey(1, $specialLine);
        preg_match_all('/`([a-z]+\.[a-z_]+)`/', $specialLine[1], $specialMatches);
        $special = $specialMatches[1];
        $this->assertCount(4, $special);
        $config = require $root.'/apps/api/config/identity.roles.php';
        $this->assertEqualsCanonicalizing($prefixes, array_keys($config['actions']));
        $actualSpecial = [];
        foreach ($config['actions'] as $prefix => $actions) {
            $this->assertNotEmpty($actions);
            foreach ($actions as $action) {
                $permission = $prefix.'.'.$action;
                $this->assertMatchesRegularExpression('/^[a-z]+\.[a-z_]+$/', $permission);
                if (! in_array($action, ['view', 'create', 'update', 'delete'], true)) {
                    $actualSpecial[] = $permission;
                }
            }
        }
        $this->assertEqualsCanonicalizing($special, $actualSpecial);
    }
}
