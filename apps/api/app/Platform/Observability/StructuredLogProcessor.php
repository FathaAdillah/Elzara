<?php

declare(strict_types=1);

namespace App\Platform\Observability;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Log JSON keluar proses tanpa PII/rahasia (Doc 01 §12): kunci sensitif dimasking, user_id di-hash,
 * email/telepon/bearer token di pesan disamarkan. Daftarkan SEBELUM PsrLogMessageProcessor di config:
 * Monolog menjalankan processor terakhir lebih dulu, jadi placeholder diisi dari context yang sudah bersih.
 */
final class StructuredLogProcessor implements ProcessorInterface
{
    public const REDACTED = '[redacted]';

    private const SENSITIVE_KEY = '/(pass(word)?|secret|token|authorization|cookie|api[_-]?key|otp|signature|credential|email|phone|msisdn|nik|npwp|address|card|cvv|iban)/i';

    private const USER_KEYS = ['user_id', 'actor_id', 'customer_id'];

    /** @var array<string, string> */
    private const MESSAGE_PATTERNS = [
        '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => '[email]',
        '/(?<![\w-])(?:\+?62|0)8\d{7,11}\b/' => '[phone]',
        '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i' => 'Bearer [redacted]',
    ];

    public function __construct(
        private readonly ?string $service = null,
        private readonly ?string $environment = null,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $this->scrub($record->extra);
        $extra['service'] = $this->service ?? self::config('observability.service_name', 'elzara-api');
        $extra['environment'] = $this->environment ?? self::config('app.env', 'production');

        return $record->with(
            message: $this->scrubString($record->message),
            context: $this->scrub($record->context),
            extra: $extra,
        );
    }

    private static function config(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    public static function hashUser(int|string $id): string
    {
        $key = config('app.key');

        return substr(hash_hmac('sha256', (string) $id, is_string($key) ? $key : 'elzara'), 0, 16);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function scrub(array $data, int $depth = 0): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::USER_KEYS, true) && (is_int($value) || is_string($value))) {
                $clean[str_replace('_id', '_hash', $key)] = self::hashUser($value);

                continue;
            }

            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) && $depth < 6 => $this->scrub($value, $depth + 1),
                is_array($value) => self::REDACTED,
                is_string($value) => $this->scrubString($value),
                default => $value,
            };
        }

        return $clean;
    }

    private function scrubString(string $value): string
    {
        return (string) preg_replace(array_keys(self::MESSAGE_PATTERNS), array_values(self::MESSAGE_PATTERNS), $value);
    }
}
