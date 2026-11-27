<?php

namespace Tests;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait CompanyIdentityFixtures
{
    private string $companySecurityDirectory;

    protected string $companyTestId;

    protected string $companyTestSubject = '17';

    protected function configureCompanyIdentity(): void
    {
        $this->companyTestId = (string) Str::uuid();
        $this->companySecurityDirectory = sys_get_temp_dir().'/company-identity-'.Str::random(16);
        mkdir($this->companySecurityDirectory);
        foreach (['ca_certificate_file', 'client_certificate_file', 'client_key_file', 'signing_key_file'] as $setting) {
            $filename = $this->companySecurityDirectory.'/'.$setting;
            file_put_contents($filename, str_repeat('k', 48));
            config()->set('company_security.'.$setting, $filename);
        }
        config()->set('company_security.enabled', true);
        config()->set('company_security.auth_url', 'https://auth-service:8443');
        DB::table('company_subjects')->insert([
            'company_id' => $this->companyTestId,
            'status' => 'active',
            'directory_version' => 1,
        ]);
        DB::table('company_security_policies')->insert([
            'company_id' => $this->companyTestId,
            'version' => 1,
            'data' => json_encode(['schemaVersion' => 1, 'distinctAuthorPermissions' => []], JSON_THROW_ON_ERROR),
        ]);
        $this->withUnencryptedCookie('vicartech-auth-session', 'opaque-session')
            ->withHeaders([
                'X-Company-ID' => $this->companyTestId,
                'Idempotency-Key' => (string) Str::uuid(),
                'X-XSRF-TOKEN' => 'browser-csrf',
            ]);
    }

    protected function companyValidationResponse(OutgoingRequest $request): PromiseInterface
    {
        $requestData = $request->data();
        $companyHeader = $request->header('X-Company-ID')[0] ?? null;
        $requestIdHeader = $request->header('X-Request-ID')[0] ?? null;
        if ($request->method() === 'GET') {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $requiredPermissions = explode(',', (string) ($query['requiredPermissions'] ?? ''));
            $purpose = $query['purpose'] ?? '';
        } else {
            $requiredPermissions = $requestData['requiredPermissions'] ?? [];
            $purpose = $requestData['purpose'] ?? '';
        }

        return Http::response([
            'schemaVersion' => 2,
            'subject' => $this->companyTestSubject,
            'companyId' => $companyHeader,
            'membershipId' => '11111111-1111-4111-8111-111111111111',
            'authorizationVersion' => 1,
            'permissions' => $requiredPermissions,
            'purpose' => $purpose,
            'requestId' => $requestIdHeader,
            'allowed' => true,
        ]);
    }

    protected function fakeCompanyIdentity(?callable $otherRequest = null): void
    {
        Http::swap(new Factory);
        Http::fake(function (OutgoingRequest $request) use ($otherRequest) {
            if (str_contains($request->url(), '/api/internal/v2/session/validate')) {
                return $this->companyValidationResponse($request);
            }

            return $otherRequest ? $otherRequest($request) : Http::response([], 404);
        });
    }

    protected function cleanupCompanyIdentity(): void
    {
        foreach (glob($this->companySecurityDirectory.'/*') ?: [] as $filename) {
            unlink($filename);
        }
        rmdir($this->companySecurityDirectory);
    }
}
