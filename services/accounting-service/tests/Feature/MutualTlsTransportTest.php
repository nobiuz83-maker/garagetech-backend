<?php

namespace Tests\Feature;

use App\Auth\SecurityFailure;
use App\Services\RemoteCompanyIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MutualTlsTransportTest extends TestCase
{
    public function test_real_mutual_tls_signature_identity_and_client_certificate_rotation(): void
    {
        $directory = sys_get_temp_dir().'/lot1-mtls-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $server = new Process([PHP_BINARY, base_path('tests/Support/mtls-identity-server.php'), $directory, config('company_security.service_name')]);
        $server->start();
        try {
            $deadline = microtime(true) + 10;
            while (! is_file($directory.'/ready.json') && $server->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($directory.'/ready.json', $server->getErrorOutput());
            $port = json_decode(file_get_contents($directory.'/ready.json'), true)['port'];
            config()->set('company_security.auth_url', 'https://127.0.0.1:'.$port);
            config()->set('company_security.ca_certificate_file', $directory.'/ca.pem');
            config()->set('company_security.client_certificate_file', $directory.'/client.pem');
            config()->set('company_security.client_key_file', $directory.'/client.key');
            file_put_contents($directory.'/signing.key', str_repeat('k', 48));
            config()->set('company_security.signing_key_file', $directory.'/signing.key');
            $request = Request::create('/synthetic-read', 'GET', [], ['vicartech-auth-session' => 'synthetic-session']);
            $request->headers->set('X-Company-ID', (string) Str::uuid());
            $request->headers->set('X-Request-ID', (string) Str::uuid());
            $permission = config('company_security.permission_prefix').'.read';
            $identity = app(RemoteCompanyIdentity::class);
            $this->assertSame('17', $identity->verify($request, [$permission], 'resource.read')->subject);
            config()->set('company_security.client_certificate_file', $directory.'/renewed-client.pem');
            config()->set('company_security.client_key_file', $directory.'/renewed-client.key');
            $this->assertSame('17', $identity->verify($request, [$permission], 'resource.read')->subject);
            // A CA-trusted certificate with a different caller name still grants no capability.
            $expiry = openssl_x509_parse(file_get_contents($directory.'/expired-client.pem'))['validTo_time_t'];
            while (time() <= $expiry) {
                usleep(10000);
            }
            config()->set('company_security.client_certificate_file', $directory.'/expired-client.pem');
            config()->set('company_security.client_key_file', $directory.'/expired-client.key');
            $this->assertUnavailable(fn () => $identity->verify($request, [$permission], 'resource.read'));
            config()->set('company_security.client_certificate_file', $directory.'/wrong-client.pem');
            config()->set('company_security.client_key_file', $directory.'/wrong-client.key');
            $this->assertUnavailable(fn () => $identity->verify($request, [$permission], 'resource.read'));
            config()->set('company_security.client_certificate_file', $directory.'/client.pem');
            config()->set('company_security.client_key_file', $directory.'/client.key');
            config()->set('company_security.auth_url', 'https://localhost:'.$port);
            $this->assertUnavailable(fn () => $identity->verify($request, [$permission], 'resource.read'));
            config()->set('company_security.auth_url', 'https://127.0.0.1:'.$port);
            config()->set('company_security.ca_certificate_file', $directory.'/client.pem');
            $this->assertUnavailable(fn () => $identity->verify($request, [$permission], 'resource.read'));
            config()->set('company_security.ca_certificate_file', $directory.'/ca.pem');
            config()->set('company_security.client_certificate_file', $directory.'/ca.pem');
            config()->set('company_security.client_key_file', $directory.'/ca.key');
            $this->assertUnavailable(fn () => $identity->verify($request, [$permission], 'resource.read'));
        } finally {
            $server->stop(1);
            // Only disposable synthetic fixture files are removed; no database or Docker volume is involved.
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function assertUnavailable(callable $operation): void
    {
        try {
            $operation();
            $this->fail('An invalid TLS or machine identity must not authenticate.');
        } catch (SecurityFailure $failure) {
            $this->assertSame(503, $failure->status);
        }
    }
}
