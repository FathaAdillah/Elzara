<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use App\Modules\Identity\Application\AdminSession;
use App\Modules\Identity\Application\Commands\AssignRoleWithScope;
use App\Modules\Identity\Application\Commands\GrantPermissionToUser;
use App\Modules\Identity\Application\Commands\RevokePermissionFromUser;
use App\Modules\Identity\Application\Commands\RevokeRoleFromUser;
use App\Modules\Identity\Application\Queries\UserAccess;
use App\Platform\Http\StrictValidation;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminAccessController
{
    use DispatchesJobs;

    public function __construct(private UserAccess $access, private AdminSession $sessions) {}

    public function show(string $user): JsonResponse
    {
        return response()->json($this->access->forUser($this->access->target($user)))->header('Cache-Control', 'no-store');
    }

    public function grant(Request $request, string $user): JsonResponse
    {
        /** @var array{permission: string, reason: string} $data */
        $data = StrictValidation::validate($request, ['permission' => 'required|string|max:64', 'reason' => 'required|string|max:320|regex:/\S/']);
        $target = $this->access->target($user);
        $this->dispatchSync(new GrantPermissionToUser($this->sessions->activeUser($request), $target, $data['permission'], $data['reason'],
            $request->attributes->getString('request_id'), $request->ip(), (string) $request->userAgent()));

        return response()->json($this->access->forUser($target))->header('Cache-Control', 'no-store');
    }

    public function revokePermission(Request $request, string $user, string $permission): JsonResponse
    {
        /** @var array{reason: string} $data */
        $data = StrictValidation::validate($request, ['reason' => 'required|string|max:320|regex:/\S/']);
        $target = $this->access->target($user);
        $this->dispatchSync(new RevokePermissionFromUser($this->sessions->activeUser($request), $target, $permission, $data['reason'],
            $request->attributes->getString('request_id'), $request->ip(), (string) $request->userAgent()));

        return response()->json($this->access->forUser($target))->header('Cache-Control', 'no-store');
    }

    public function assignRole(Request $request, string $user): JsonResponse
    {
        /** @var array{role: string, reason: string, scope_type?: string|null, scope_id?: int|null} $data */
        $data = StrictValidation::validate($request, [
            'role' => 'required|string|max:48', 'reason' => 'required|string|max:320|regex:/\S/',
            'scope_type' => 'nullable|required_with:scope_id|string|in:batch,category,warehouse',
            'scope_id' => 'nullable|required_with:scope_type|integer|min:1',
        ]);
        $target = $this->access->target($user);
        $this->dispatchSync(new AssignRoleWithScope($this->sessions->activeUser($request), $target, $data['role'], $data['reason'],
            $request->attributes->getString('request_id'), $request->ip(), (string) $request->userAgent(), $data['scope_type'] ?? null,
            isset($data['scope_id']) ? (int) $data['scope_id'] : null));

        return response()->json($this->access->forUser($target))->header('Cache-Control', 'no-store');
    }

    public function revokeRole(Request $request, string $user, string $role): JsonResponse
    {
        /** @var array{reason: string} $data */
        $data = StrictValidation::validate($request, ['reason' => 'required|string|max:320|regex:/\S/']);
        $target = $this->access->target($user);
        $this->dispatchSync(new RevokeRoleFromUser($this->sessions->activeUser($request), $target, $role, $data['reason'],
            $request->attributes->getString('request_id'), $request->ip(), (string) $request->userAgent()));

        return response()->json($this->access->forUser($target))->header('Cache-Control', 'no-store');
    }
}
