<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Http\Controllers;

use App\Modules\Settings\Application\Commands\UpdateFeatureFlags;
use App\Modules\Settings\Application\Queries\ListFeatureFlags;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AdminFeatureFlagController
{
    use DispatchesJobs;

    public function index(ListFeatureFlags $flags): JsonResponse
    {
        return response()->json(['data' => $flags->adminFlags()])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, ListFeatureFlags $flags): JsonResponse
    {
        /** @var array{flags: list<array{code: string, default_enabled: bool, env?: string, enabled?: bool, rollout_percent?: int}>, reason: string} $data */
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:320', 'regex:/\\S/'],
            'flags' => ['required', 'array', 'min:1', 'max:100'],
            'flags.*' => ['required', 'array:code,default_enabled,env,enabled,rollout_percent'],
            'flags.*.code' => ['required', 'string', 'max:64', 'distinct'],
            'flags.*.default_enabled' => ['required', 'boolean'],
            'flags.*.env' => ['required_with:flags.*.enabled,flags.*.rollout_percent', 'string', 'in:local,staging,prod'],
            'flags.*.enabled' => ['required_with:flags.*.env,flags.*.rollout_percent', 'boolean'],
            'flags.*.rollout_percent' => ['sometimes', 'integer', 'between:0,100'],
        ]);
        $requestId = $request->attributes->get('request_id');
        $this->dispatchSync(new UpdateFeatureFlags(
            $request->attributes->getInt('admin_actor_id'), $data['flags'], $data['reason'],
            is_string($requestId) && strlen($requestId) === 26 ? $requestId : (string) Str::ulid(),
            $request->ip(), (string) $request->userAgent(),
        ));

        return $this->index($flags);
    }
}
