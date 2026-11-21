<?php

declare(strict_types=1);

namespace App\Modules\Content\Application\I18n;

use App\Modules\Content\Infrastructure\Models\LocalizedString;
use Illuminate\Support\Facades\Cache;

final class LocalizedStringRepository
{
    public function find(string $key, string $group, string $locale, ?int $plural = null): ?string
    {
        /** @var list<array{key: string, value: string, plural_variant: int|null}> $rows */
        $rows = Cache::remember("i18n.{$group}.{$locale}", 300, fn (): array => LocalizedString::query()
            ->where('group_name', $group)->where('locale', $locale)
            ->get(['key', 'value', 'plural_variant'])->toArray());
        foreach ($rows as $row) {
            if ($row['key'] === $key && ($row['plural_variant'] ?? 0) === ($plural ?? 0) && $row['value'] !== '') {
                return $row['value'];
            }
        }

        return null;
    }

    public function invalidate(string $group, string $locale): void
    {
        Cache::forget("i18n.{$group}.{$locale}");
    }
}
