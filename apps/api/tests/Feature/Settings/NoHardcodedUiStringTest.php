<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Content\Contracts\LocalizedText;
use Tests\TestCase;

final class NoHardcodedUiStringTest extends TestCase
{
    public function test_new_api_messages_are_resource_backed(): void
    {
        $paths = [
            'Modules/Settings/Infrastructure/Http/Controllers/FeatureFlagController.php',
            'Modules/Settings/Infrastructure/Http/Controllers/AdminFeatureFlagController.php',
            'Modules/Orders/Infrastructure/Http/Controllers/PlaceOrderController.php',
            'Modules/Settings/Infrastructure/Http/Middleware/EnsureCheckoutEnabled.php',
            'Modules/Settings/Infrastructure/Http/RequireUuidIdempotencyKey.php',
        ];
        foreach ($paths as $path) {
            $source = file_get_contents(app_path($path));
            $this->assertDoesNotMatchRegularExpression('/[\x27\x22](?:message|detail|title)[\x27\x22]\\s*=>\\s*[\x27\x22][A-Za-z]/', $source, $path);
        }
        foreach (['id', 'en'] as $locale) {
            foreach (['checkout.title', 'checkout.disabled', 'messages.idempotency.invalid'] as $key) {
                $value = __($key, [], $locale);
                $this->assertIsString($value);
                $this->assertNotSame($key, $value);
                $this->assertNotSame('', $value);
            }
        }
        $this->assertInstanceOf(LocalizedText::class, app(LocalizedText::class));
    }
}
