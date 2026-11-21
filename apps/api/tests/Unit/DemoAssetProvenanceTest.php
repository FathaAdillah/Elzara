<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DemoAssetProvenanceTest extends TestCase
{
    public function test_ten_original_svgs_match_the_manifest(): void
    {
        $dir = dirname(__DIR__, 2).'/database/seeders/Demo';
        $files = glob($dir.'/assets/*.svg') ?: [];
        $this->assertCount(10, $files);
        foreach ($files as $file) {
            $svg = (string) file_get_contents($file);
            $this->assertStringStartsWith('<svg', $svg);
            $this->assertLessThan(2000, strlen($svg));
            $this->assertStringNotContainsString('<image', $svg);
            $this->assertStringNotContainsString('<script', $svg);
        }

        /** @var list<array{slug: string, name: string, amount_minor: int, file: string}> $manifest */
        $manifest = require $dir.'/catalog-manifest.php';
        $this->assertSame(array_map('basename', $files), collect($manifest)->pluck('file')->sort()->values()->all());
        foreach ($manifest as $item) {
            $this->assertNotSame('', $item['name']);
            $this->assertGreaterThan(0, $item['amount_minor']);
            $this->assertSame($item['slug'].'.svg', $item['file']);
        }
    }
}
