<?php

declare(strict_types=1);

namespace Database\Seeders\Reference;

use App\Modules\Content\Infrastructure\Models\LocalizedString;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Lang;
use RuntimeException;

final class LocalizationSeeder extends Seeder
{
    public function run(): void
    {
        $strings = ['shop' => ['media.alt.default' => ['id' => 'Foto produk', 'en' => 'Product photo']], 'admin' => ['admin.flag.title' => ['id' => 'Feature Flags', 'en' => 'Feature Flags']]];
        foreach (['id', 'en'] as $locale) {
            foreach (['title', 'disabled'] as $line) {
                if (! Lang::hasForLocale("checkout.{$line}", $locale)) {
                    throw new RuntimeException("Terjemahan checkout.{$line} ({$locale}) tidak ditemukan di lang/");
                }
                $strings['shop']["checkout.{$line}"][$locale] = (string) trans("checkout.{$line}", [], $locale);
            }
        }
        foreach ($strings as $group => $keys) {
            foreach ($keys as $key => $byLocale) {
                foreach ($byLocale as $locale => $value) {
                    LocalizedString::query()->firstOrCreate(
                        ['key' => $key, 'locale' => $locale, 'plural_variant' => null],
                        ['group_name' => $group, 'value' => $value],
                    );
                }
            }
        }
    }
}
