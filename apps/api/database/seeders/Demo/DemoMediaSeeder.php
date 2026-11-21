<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Content\Infrastructure\Models\Media;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Seeds media rows directly: SVG is intentionally outside the user-upload MIME allowlist. */
final class DemoMediaSeeder extends Seeder
{
    public const MODEL_TYPE = 'demo_catalog_item';

    public const DISK = 'product-media';

    public function run(): void
    {
        DemoEnvironment::assertNotProduction();

        /** @var list<array{slug: string, name: string, amount_minor: int, file: string}> $manifest */
        $manifest = require __DIR__.'/catalog-manifest.php';
        $disk = Storage::disk(self::DISK);
        foreach ($manifest as $index => $item) {
            $path = 'published/demo/'.$item['file'];
            if (Media::query()->where('disk', self::DISK)->where('path', $path)->exists()) {
                continue;
            }
            $svg = file_get_contents(__DIR__.'/assets/'.$item['file']);
            if ($svg === false || preg_match('/<svg[^>]*\swidth="(\d+)"[^>]*\sheight="(\d+)"/', $svg, $size) !== 1) {
                throw new RuntimeException("Aset demo tidak valid: {$item['file']}");
            }
            $disk->put($path, $svg, ['mimetype' => 'image/svg+xml']);
            Media::query()->create([
                'model_type' => self::MODEL_TYPE,
                'model_id' => $index + 1,
                'kind' => 'image',
                'disk' => self::DISK,
                'path' => $path,
                'mime' => 'image/svg+xml',
                'size_bytes' => strlen($svg),
                'width' => (int) $size[1],
                'height' => (int) $size[2],
                'sha256' => hash('sha256', $svg),
                'alt_key' => 'media.alt.default',
                'position' => 0,
                'is_moderated' => true,
                'moderation_status' => 'approved',
            ]);
        }
    }
}
