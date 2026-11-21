<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Content\Infrastructure\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Media> */
final class MediaFactory extends Factory
{
    protected $model = Media::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'model_type' => 'admin-upload',
            'model_id' => 1,
            'kind' => 'image',
            'disk' => 'product-media',
            'path' => 'published/'.Str::uuid()->toString().'.webp',
            'mime' => 'image/webp',
            'size_bytes' => 1024,
            'width' => 800,
            'height' => 800,
            'sha256' => hash('sha256', Str::random(32)),
            'position' => 0,
            'is_moderated' => true,
            'moderation_status' => 'approved',
        ];
    }

    public function pending(): self
    {
        return $this->state(['is_moderated' => false, 'moderation_status' => 'pending']);
    }
}
