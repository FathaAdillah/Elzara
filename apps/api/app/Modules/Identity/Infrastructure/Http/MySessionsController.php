<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Layar perangkat di profil. Kepemilikan ditegakkan di query: baris milik akun lain
// diperlakukan sama dengan baris yang tidak ada (404), tanpa membocorkan keberadaannya.
final class MySessionsController
{
    public function __construct(private readonly WebSession $sessions) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->sessions->activeUser($request);
        /** @var array{limit?: int|string, cursor?: string} $data */
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:64'],
        ]);
        $limit = (int) ($data['limit'] ?? 20);
        $after = isset($data['cursor']) ? $this->decodeCursor($data['cursor']) : null;
        $currentHash = $this->sessions->hash($request);

        $rows = $this->sessions->activeRows($user, $after, $limit + 1);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return response()->json([
            'data' => array_map(fn (object $row): array => [
                'id' => $row->session_hash,
                'device_label' => $row->device_label,
                'user_agent' => $row->user_agent,
                'ip_address' => $row->ip,
                'last_seen_at' => CarbonImmutable::parse($row->last_seen_at ?? $row->created_at)->toIso8601ZuluString(),
                'is_current' => hash_equals($currentHash, $row->session_hash),
            ], $rows),
            'meta' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $hasMore && $last !== null ? rtrim(strtr(base64_encode((string) $last->id), '+/', '-_'), '=') : null,
                'prev_cursor' => null,
            ],
        ]);
    }

    public function destroy(Request $request, string $sessionId): Response
    {
        $user = $this->sessions->activeUser($request);
        $isCurrent = hash_equals($this->sessions->hash($request), $sessionId);

        abort_unless($this->sessions->revoke($user, $sessionId), 404);

        if ($isCurrent) {
            $this->sessions->close($request, $user);
        }

        return response()->noContent();
    }

    private function decodeCursor(string $cursor): int
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        abort_unless(is_string($decoded) && ctype_digit($decoded) && (int) $decoded > 0, 422, 'Cursor tidak valid.');

        return (int) $decoded;
    }
}
