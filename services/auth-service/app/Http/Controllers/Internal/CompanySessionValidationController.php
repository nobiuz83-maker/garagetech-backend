<?php

namespace App\Http\Controllers\Internal;

use App\Auth\SecurityFailure;
use App\Http\Requests\ValidateCompanySessionRequest;
use App\Services\CompanyPermissionService;
use App\Services\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class CompanySessionValidationController
{
    public function __invoke(ValidateCompanySessionRequest $request, CompanyPermissionService $permissions, SecurityAudit $audit): JsonResponse
    {
        $companyId = $request->header('X-Company-ID', '');
        if (! Str::isUuid($companyId)) {
            throw new SecurityFailure('COMPANY_CONTEXT_INVALID', 400);
        }
        $read = $request->isMethod('GET');
        $required = $request->validated('requiredPermissions');
        $purpose = $request->validated('purpose');
        // Cross-domain workflows receive their own exact whitelists when their endpoints are implemented.
        if ($purpose !== ($read ? 'resource.read' : 'resource.write')) {
            throw new SecurityFailure('FORBIDDEN', 403);
        }
        $caller = $request->attributes->get('verified_machine');
        $callerConfiguration = config('company_security.callers.'.$caller, []);
        $prefixes = $callerConfiguration['prefixes'] ?? [$callerConfiguration['prefix'] ?? null];
        $catalog = config('company_permissions.permissions');
        foreach ($required as $permission) {
            $prefixAllowed = is_array($prefixes) && array_filter($prefixes, 'is_string') !== []
                && collect($prefixes)->contains(fn (string $prefix): bool => str_starts_with($permission, $prefix.'.'));
            if (! isset($catalog[$permission]) || ! $prefixAllowed
                || ($read && $catalog[$permission]['mutating'])) {
                throw new SecurityFailure('FORBIDDEN', 403);
            }
        }
        $context = $permissions->context($request->user('web'), $companyId);
        if (array_diff($required, $context['permissions']) !== []) {
            throw new SecurityFailure('FORBIDDEN', 403);
        }
        if (array_intersect($required, config('company_permissions.recent_authentication_permissions')) !== []) {
            try {
                $permissions->requireRecentAuthentication($request->session()->get('auth.password_confirmed_at'));
            } catch (SecurityFailure $failure) {
                $audit->record('sensitive.authorization.denied', $companyId, (string) $request->user('web')->getAuthIdentifier(), $request->header('X-Request-ID'), ['permissions' => $required]);
                throw $failure;
            }
            $audit->record('sensitive.authorization.granted', $companyId, (string) $request->user('web')->getAuthIdentifier(), $request->header('X-Request-ID'), ['permissions' => $required]);
        }

        return response()->json([
            'schemaVersion' => 2, 'subject' => (string) $request->user('web')->getAuthIdentifier(),
            'companyId' => $companyId, 'membershipId' => $context['membershipId'],
            'authorizationVersion' => $context['authorizationVersion'], 'permissions' => $required,
            'purpose' => $purpose, 'requestId' => $request->header('X-Request-ID'), 'allowed' => true,
        ])->header('Cache-Control', 'no-store, private');
    }
}
