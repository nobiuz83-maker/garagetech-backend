<?php

namespace App\Http\Controllers\Internal;

use App\Http\Requests\InternalSessionValidationRequest;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;

class SessionValidationController
{
    public function __invoke(InternalSessionValidationRequest $request, PermissionService $permissions): JsonResponse
    {
        $user = $request->user('web');
        $serviceName = $request->attributes->get('internal_service_name');
        $permission = $request->validated('permission');

        if (! $permissions->allows($user, $serviceName, $permission)) {
            return response()->json(['error' => 'FORBIDDEN'], 403);
        }

        return response()->json([
            'subject' => (string) $user->getAuthIdentifier(),
            'permission' => $permission,
            'allowed' => true,
        ])->header('Cache-Control', 'no-store');
    }
}
