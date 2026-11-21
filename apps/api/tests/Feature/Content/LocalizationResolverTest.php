<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Modules\Content\Application\I18n\LocalizationResolver;
use App\Modules\Content\Application\I18n\LocalizedStringRepository;
use App\Modules\Content\Infrastructure\Models\LocalizedString;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class LocalizationResolverTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_database_resource_locale_fallback_and_missing_keys(): void
    {
        $resolver = app(LocalizationResolver::class);
        LocalizedString::create(['key' => 'checkout.disabled', 'group_name' => 'shop', 'locale' => 'id', 'value' => 'DB ID']);
        LocalizedString::create(['key' => 'only.db', 'group_name' => 'shop', 'locale' => 'id', 'value' => 'Fallback ID']);
        LocalizedString::create(['key' => 'empty.db', 'group_name' => 'shop', 'locale' => 'en', 'value' => '']);
        $this->assertSame('DB ID', $resolver->value('checkout.disabled', 'shop'));
        $this->assertSame('DB ID', $resolver->value('checkout.disabled', 'shop', 'fr'));
        $this->assertSame(__('checkout.disabled', [], 'en'), $resolver->value('checkout.disabled', 'shop', 'en'));
        $this->assertSame('Fallback ID', $resolver->value('only.db', 'shop', 'en'));
        $this->assertSame('missing.key', $resolver->value('missing.key', 'shop', 'en'));
        $this->assertSame('empty.db', $resolver->value('empty.db', 'shop', 'en'));
        LocalizedString::create(['key' => 'checkout.disabled', 'group_name' => 'admin', 'locale' => 'en', 'value' => 'DB EN']);
        $this->assertSame('DB EN', $resolver->value('checkout.disabled', 'admin', 'en'));
    }

    public function test_plural_cache_expiry_and_explicit_invalidation(): void
    {
        $resolver = app(LocalizationResolver::class);
        $row = LocalizedString::create(['key' => 'items', 'group_name' => 'shop', 'locale' => 'id', 'value' => 'Other']);
        LocalizedString::create(['key' => 'items', 'group_name' => 'shop', 'locale' => 'id', 'plural_variant' => 1, 'value' => 'One']);
        $this->assertSame('Other', $resolver->value('items', 'shop', 'id', 0));
        $this->assertSame('One', $resolver->value('items', 'shop', 'id', 1));
        $row->update(['value' => 'Changed']);
        $this->assertSame('Other', $resolver->value('items', 'shop'));
        $this->travel(301)->seconds();
        $this->assertSame('Changed', $resolver->value('items', 'shop'));
        $row->update(['value' => 'Invalidated']);
        app(LocalizedStringRepository::class)->invalidate('shop', 'id');
        $this->assertSame('Invalidated', $resolver->value('items', 'shop'));
        $this->travelBack();
    }
}
