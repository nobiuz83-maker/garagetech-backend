<?php

namespace App\Services;

use App\Auth\SecurityFailure;
use Illuminate\Http\Request;

final class CompanyRequestSignature
{
    /** @param array<string, string> $headers */
    public function canonical(string $method, string $target, array $headers, string $body): string
    {
        foreach ($headers as $value) {
            if (str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new SecurityFailure('MACHINE_AUTHENTICATION_FAILED', 401);
            }
        }

        return implode("\n", [
            $headers['X-Internal-Service'], $method, $target,
            $headers['X-Company-ID'] ?? '-', $headers['X-Request-ID'],
            $headers['X-Internal-Timestamp'], $headers['X-Internal-Nonce'],
            $headers['Content-Type'] ?? '-', $headers['Idempotency-Key'] ?? '-',
            $headers['If-Match'] ?? '-', hash('sha256', $headers['Cookie'] ?? ''),
            hash('sha256', $headers['X-XSRF-TOKEN'] ?? ''), hash('sha256', $body),
        ]);
    }

    public function fromRequest(Request $request): string
    {
        $headers = [];
        foreach (['X-Internal-Service', 'X-Company-ID', 'X-Request-ID', 'X-Internal-Timestamp',
            'X-Internal-Nonce', 'Content-Type', 'Idempotency-Key', 'If-Match', 'Cookie', 'X-XSRF-TOKEN'] as $name) {
            $values = $request->headers->all($name);
            if (count($values) > 1) {
                throw new SecurityFailure('MACHINE_AUTHENTICATION_FAILED', 401);
            }
            if ($values !== []) {
                $headers[$name] = $values[0];
            }
        }

        return $this->canonical($request->method(), $request->getRequestUri(), $headers, $request->getContent());
    }
}
