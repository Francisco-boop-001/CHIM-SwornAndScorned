<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

function sendSecurityHeaders(): void
{
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'none'");
}

/** Only loopback without forwarding headers, or a server-authenticated user, can open this key-bearing page. */
function pageAccessAllowed(array $server): bool
{
    $forwarded = false;
    foreach (array_keys($server) as $name) {
        $name = strtoupper((string) $name);
        if ($name === 'HTTP_FORWARDED' || $name === 'HTTP_X_FORWARDED'
            || str_starts_with($name, 'HTTP_X_FORWARDED_')
            || in_array($name, ['HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_CF_CONNECTING_IP'], true)) {
            $forwarded = true;
            break;
        }
    }
    $remote = $server['REMOTE_ADDR'] ?? null;
    $loopback = !$forwarded && is_string($remote) && in_array($remote, ['127.0.0.1', '::1'], true);
    $user = $server['REMOTE_USER'] ?? null;
    return $loopback || (is_string($user) && trim($user) !== '');
}

/** Redirect WSL-hosted browser requests to the same plugin path on localhost, without trusting Host. */
function localHandoffUrl(array $server, array $query): ?string
{
    if (($server['REQUEST_METHOD'] ?? '') !== 'GET' || ($query['local'] ?? null) !== '1') {
        return null;
    }
    $script = $server['SCRIPT_NAME'] ?? null;
    if (!is_string($script) || preg_match('~\A/[A-Za-z0-9_./-]{1,200}\.php\z~D', $script) !== 1 || str_contains($script, '..')) {
        return null;
    }
    $port = filter_var($server['SERVER_PORT'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port === false) {
        return null;
    }
    $https = is_string($server['HTTPS'] ?? null) && $server['HTTPS'] !== '' && strtolower($server['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $defaultPort = $https ? 443 : 80;
    unset($query['local']);
    $queryString = http_build_query($query);
    return $scheme . '://localhost' . ($port === $defaultPort ? '' : ':' . $port) . $script . ($queryString === '' ? '' : '?' . $queryString);
}

/** Trust server TLS state, never client forwarding headers. */
function keySubmissionAllowed(array $server): bool
{
    if (!pageAccessAllowed($server)) {
        return false;
    }
    $local = $server;
    unset($local['REMOTE_USER']);
    return pageAccessAllowed($local)
        || (is_string($server['HTTPS'] ?? null) && $server['HTTPS'] !== '' && strtolower($server['HTTPS']) !== 'off');
}

function csrfTokenValid(string $sessionToken, mixed $submitted): bool
{
    return preg_match('/\A[a-f0-9]{64}\z/D', $sessionToken) === 1
        && is_string($submitted)
        && hash_equals($sessionToken, $submitted);
}

/** Cookie options for the page's session; HTTPS gets Secure and all sessions stay HttpOnly/SameSite. */
function sessionCookieOptions(array $server): array
{
    return [
        'httponly' => true,
        'secure' => is_string($server['HTTPS'] ?? null) && $server['HTTPS'] !== '' && strtolower($server['HTTPS']) !== 'off',
        'samesite' => 'Strict',
    ];
}
