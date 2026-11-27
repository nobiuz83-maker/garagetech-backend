<?php

namespace App\Http\Middleware;

use App\Auth\SecurityFailure;
use App\Services\CompanyProjection;
use App\Services\RemoteCompanyIdentity;
use App\Services\SecurityAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireCompanyPermission
{
    public function __construct(private RemoteCompanyIdentity $identity, private CompanyProjection $companies, private SecurityAudit $audit) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if ($permissions === [] || count($permissions) > 8 || count(array_unique($permissions)) !== count($permissions)) {
            throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
        }
        $read = $request->isMethodSafe();
        foreach ($permissions as $permission) {
            $definition = (config('company_permissions.permissions')[$permission] ?? null);
            if (! is_array($definition) || ! str_starts_with($permission, config('company_security.permission_prefix').'.')
                || ($read && $definition['mutating'])) {
                throw new SecurityFailure('AUTHORIZATION_UNAVAILABLE');
            }
        }
        $actor = $this->identity->verify($request, $permissions, $read ? 'resource.read' : 'resource.write');
        $this->companies->requireActive($actor);
        $request->attributes->set('company_actor', $actor);
        $request->setUserResolver(fn () => $actor);
        if (! $read) {
            if (! preg_match('/^[A-Za-z0-9._:-]{16,128}$/D', $request->header('Idempotency-Key', ''))) {
                throw new SecurityFailure('IDEMPOTENCY_KEY_REQUIRED', 400);
            }
            $this->audit->record('command.authorized', $actor->companyId, $actor->subject, $actor->requestId, ['permissions' => $permissions]);
        }
        $response = $next($request);
        foreach ($request->attributes->get('company_auth_cookies', []) as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
