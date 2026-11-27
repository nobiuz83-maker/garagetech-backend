<?php

// Standalone synthetic peer: intentionally independent of the application's signature implementation.
$directory = $argv[1];
$serviceName = $argv[2];
function opensslCommand(array $arguments): void
{
    $process = proc_open(['openssl', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException($error);
    }
}
opensslCommand(['req', '-x509', '-newkey', 'ec', '-pkeyopt', 'ec_paramgen_curve:P-256', '-nodes', '-keyout', $directory.'/ca.key', '-out', $directory.'/ca.pem', '-days', '2', '-subj', '/CN=Synthetic LOT1 CA', '-addext', 'basicConstraints=critical,CA:TRUE']);
foreach (['server', 'client', 'renewed-client', 'wrong-client', 'expired-client'] as $name) {
    $commonName = $name === 'server' ? 'localhost' : ($name === 'wrong-client' ? 'untrusted-caller' : $serviceName);
    opensslCommand(['req', '-new', '-newkey', 'ec', '-pkeyopt', 'ec_paramgen_curve:P-256', '-nodes', '-keyout', $directory.'/'.$name.'.key', '-out', $directory.'/'.$name.'.csr', '-subj', '/CN='.$commonName]);
    $extensions = $name === 'server' ? "subjectAltName=IP:127.0.0.1\nextendedKeyUsage=serverAuth\n" : "extendedKeyUsage=clientAuth\n";
    file_put_contents($directory.'/'.$name.'.ext', "basicConstraints=critical,CA:FALSE\n".$extensions);
    opensslCommand(['x509', '-req', '-in', $directory.'/'.$name.'.csr', '-CA', $directory.'/ca.pem', '-CAkey', $directory.'/ca.key', '-CAcreateserial', '-out', $directory.'/'.$name.'.pem', '-days', $name === 'expired-client' ? '0' : '1', '-extfile', $directory.'/'.$name.'.ext']);
}
$context = stream_context_create(['ssl' => [
    'local_cert' => $directory.'/server.pem', 'local_pk' => $directory.'/server.key', 'cafile' => $directory.'/ca.pem',
    'verify_peer' => true, 'verify_peer_name' => false, 'allow_self_signed' => false, 'capture_peer_cert' => true,
]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    throw new RuntimeException($errorMessage);
}
file_put_contents($directory.'/ready.json', json_encode(['port' => (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1)], JSON_THROW_ON_ERROR));
$nonces = [];
while (true) {
    $connection = @stream_socket_accept($server, 2);
    if ($connection === false) {
        continue;
    }
    stream_set_timeout($connection, 2);
    if (! @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        fclose($connection);

        continue;
    }
    $requestLine = trim((string) fgets($connection));
    $parts = explode(' ', $requestLine);
    $headers = [];
    while (($line = fgets($connection)) !== false && trim($line) !== '') {
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower($name)] = trim($value);
    }
    $length = (int) ($headers['content-length'] ?? 0);
    $body = $length > 0 ? stream_get_contents($connection, $length) : '';
    $canonical = implode("\n", [
        $headers['x-internal-service'] ?? '', $parts[0], $parts[1], $headers['x-company-id'] ?? '-',
        $headers['x-request-id'] ?? '', $headers['x-internal-timestamp'] ?? '', $headers['x-internal-nonce'] ?? '',
        $headers['content-type'] ?? '-', $headers['idempotency-key'] ?? '-', $headers['if-match'] ?? '-',
        hash('sha256', $headers['cookie'] ?? ''), hash('sha256', $headers['x-xsrf-token'] ?? ''), hash('sha256', $body),
    ]);
    $peer = stream_context_get_params($connection)['options']['ssl']['peer_certificate'];
    $commonName = openssl_x509_parse($peer)['subject']['CN'] ?? '';
    $nonce = $headers['x-internal-nonce'] ?? '';
    $valid = $commonName === $serviceName && ($headers['x-internal-service'] ?? '') === $commonName
        && hash_equals(hash_hmac('sha256', $canonical, str_repeat('k', 48)), $headers['x-internal-signature'] ?? '')
        && ! isset($nonces[$nonce]);
    $nonces[$nonce] = true;
    if ($valid) {
        if ($parts[0] === 'GET') {
            parse_str(parse_url($parts[1], PHP_URL_QUERY), $input);
            $required = explode(',', $input['requiredPermissions']);
        } else {
            $input = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $required = $input['requiredPermissions'];
        }
        $payload = ['schemaVersion' => 2, 'subject' => '17', 'companyId' => $headers['x-company-id'],
            'membershipId' => 'd96fc044-eeb5-4a26-876c-c20635cb5387', 'authorizationVersion' => 1,
            'permissions' => $required, 'purpose' => $input['purpose'], 'requestId' => $headers['x-request-id'], 'allowed' => true];
    } else {
        $payload = ['error' => ['code' => 'MACHINE_AUTHENTICATION_FAILED', 'requestId' => $headers['x-request-id'] ?? '']];
    }
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
    fwrite($connection, 'HTTP/1.1 '.($valid ? '200 OK' : '401 Unauthorized')."\r\nContent-Type: application/json\r\nContent-Length: ".strlen($encoded)."\r\nConnection: close\r\n\r\n".$encoded);
    fclose($connection);
}
