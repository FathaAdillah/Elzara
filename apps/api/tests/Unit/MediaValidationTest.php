<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Content\Application\MagicByteValidator;
use App\Modules\Content\Infrastructure\Storage\BrowserPresigner;
use App\Platform\Domain\Exception\MediaValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MediaValidationTest extends TestCase
{
    public function test_presign_uses_public_origin_and_fixed_short_expiries(): void
    {
        config([
            'filesystems.disks.product-media.key' => 'fixture-key',
            'filesystems.disks.product-media.secret' => 'fixture-secret',
            'filesystems.s3.presign_endpoint' => 'https://storage.localhost',
        ]);
        $signer = new BrowserPresigner;
        $url = $signer->put('product-media', '12345678-1234-4123-8123-123456789012.jpg', 'image/jpeg');
        $this->assertSame('storage.localhost', parse_url($url, PHP_URL_HOST));
        $this->assertSame('/product-media/12345678-1234-4123-8123-123456789012.jpg', parse_url($url, PHP_URL_PATH));
        $this->assertStringNotContainsString('minio:9000', $url);
        $this->assertStringContainsString('X-Amz-Expires=600', $url);
        $download = $signer->get('request-attachments', 'fixture.pdf', 'version-1');
        $this->assertStringContainsString('X-Amz-Expires=120', $download);
        $this->assertStringContainsString('versionId=version-1', $download);
    }

    public function test_path_prefix_is_rejected_before_signing(): void
    {
        config(['filesystems.s3.presign_endpoint' => 'https://localhost/storage']);
        $this->expectException(\InvalidArgumentException::class);
        (new BrowserPresigner)->put('product-media', 'fixture.jpg', 'image/jpeg');
    }

    #[DataProvider('validObjects')]
    public function test_supported_signatures(string $bytes, string $mime): void
    {
        (new MagicByteValidator)->validate($bytes, $mime);
        $this->addToAssertionCount(1);
    }

    public static function validObjects(): array
    {
        return [["\xff\xd8\xfftest", 'image/jpeg'], ["\x89PNG\r\n\x1a\ntest", 'image/png'], ['%PDF-1.7 test', 'application/pdf'], ['RIFF0000WEBPtest', 'image/webp']];
    }

    #[DataProvider('invalidObjects')]
    public function test_invalid_objects_are_rejected(string $bytes, string $mime): void
    {
        $this->expectException(MediaValidationFailed::class);
        (new MagicByteValidator)->validate($bytes, $mime);
    }

    public static function invalidObjects(): array
    {
        return [['', 'image/jpeg'], ['text', 'image/jpeg'], ["\x89PNG\r\n\x1a\n", 'image/jpeg'], ['RIFF0000WAVE', 'image/webp'], ['%PDF-1.7', 'text/plain'], ['%PDF-'.str_repeat('a', 20 * 1024 * 1024), 'application/pdf']];
    }
}
