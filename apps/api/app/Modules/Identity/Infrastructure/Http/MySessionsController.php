<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use App\Platform\Http\StrictValidation;
use App\Platform\Http\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use stdClass;

// Layar perangkat di profil. Kepemilikan ditegakkan di query: baris milik akun lain
// diperlakukan sama dengan baris yang tidak ada (404), tanpa membocorkan keberadaannya.
final class MySessionsController
{
    public function __construct(private readonly WebSession $sessions) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->sessions->activeUser($request);
        StrictValidation::validate($request, ['limit' => 'sometimes', 'cursor' => 'sometimes|string']);
        $page = $this->sessions->activePage($request, $user);
        $currentHash = $this->sessions->hash($request);

        return response()->json([
            'data' => array_map(fn (stdClass $row): array => $this->read($row, $currentHash), $page['data']),
            'meta' => $page['meta'],
        ]);
    }

    /** @return array{id: string, device_label: ?string, user_agent: ?string, ip_address: ?string, last_seen_at: string, is_current: bool} */
    private function read(stdClass $row, string $currentHash): array
    {
        $text = fn (string $column): ?string => is_string($row->{$column} ?? null) ? $row->{$column} : null;
        $hash = (string) $text('session_hash');

        return [
            'id' => $hash,
            'device_label' => $text('device_label'),
            'user_agent' => $text('user_agent'),
            'ip_address' => $text('ip'),
            'last_seen_at' => WireTime::format(CarbonImmutable::parse($text('last_seen_at') ?? (string) $text('created_at'))),
            'is_current' => hash_equals($currentHash, $hash),
        ];
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
}
