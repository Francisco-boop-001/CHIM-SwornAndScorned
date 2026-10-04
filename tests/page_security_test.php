<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/page_security.php';

use function ChimSwornAndScorned\{csrfTokenValid, keySubmissionAllowed, localHandoffUrl, pageAccessAllowed, sessionCookieOptions};

check(pageAccessAllowed(['REMOTE_ADDR' => '127.0.0.1']), 'IPv4 loopback allowed');
check(pageAccessAllowed(['REMOTE_ADDR' => '::1']), 'IPv6 loopback allowed');
check(pageAccessAllowed(['REMOTE_ADDR' => '10.0.0.5', 'REMOTE_USER' => 'admin']), 'authenticated user allowed');
check(!pageAccessAllowed(['REMOTE_ADDR' => '172.20.0.1']), 'non-loopback unauthenticated user refused');
check(!pageAccessAllowed(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '10.0.0.9']), 'forwarded address cannot authorize loopback');
check(!pageAccessAllowed(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_HOST' => 'evil.example']), 'forwarded host cannot authorize loopback');
check(!pageAccessAllowed(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_FORWARDED' => 'for=10.0.0.9']), 'Forwarded header cannot authorize loopback');

$server = ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/HerikaServer/ext/sworn_and_scorned/index.php', 'SERVER_PORT' => '8081'];
check(localHandoffUrl($server, ['local' => '1', 'view' => 'director'])
    === 'http://localhost:8081/HerikaServer/ext/sworn_and_scorned/index.php?view=director', 'handoff uses localhost and preserves safe query');
check(localHandoffUrl($server, []) === null, 'handoff only when asked');
check(localHandoffUrl(['REQUEST_METHOD' => 'POST'] + $server, ['local' => '1']) === null, 'no handoff for POST');
check(localHandoffUrl(['SCRIPT_NAME' => '/x/../evil.php'] + $server, ['local' => '1']) === null, 'traversal refused');
check(localHandoffUrl(['HTTPS' => 'on', 'SERVER_PORT' => '443'] + $server, ['local' => '1'])
    === 'https://localhost/HerikaServer/ext/sworn_and_scorned/index.php', 'HTTPS handoff omits default port');

$token = str_repeat('a', 64);
$scopeA = ChimSwornAndScorned\pageScopeToken($token, 'profile-a');
$scopeB = ChimSwornAndScorned\pageScopeToken($token, 'profile-b');
check($scopeA !== $scopeB && !csrfTokenValid($scopeB, $scopeA), 'stale browser form cannot mutate a different playthrough');
check(csrfTokenValid($scopeA, ChimSwornAndScorned\pageScopeToken($token, 'profile-a')), 'current playthrough form remains valid');
check(csrfTokenValid($token, $token), 'matching token');
check(!csrfTokenValid($token, str_repeat('b', 64)) && !csrfTokenValid($token, null) && !csrfTokenValid('', ''), 'bad tokens refused');
check(!csrfTokenValid(str_repeat('A', 64), str_repeat('A', 64)), 'token must use canonical lowercase hex');

$cookie = sessionCookieOptions(['HTTPS' => 'on']);
check($cookie['httponly'] === true && $cookie['secure'] === true && $cookie['samesite'] === 'Strict', 'HTTPS session cookie is protected');
$cookie = sessionCookieOptions([]);
check($cookie['httponly'] === true && $cookie['secure'] === false && $cookie['samesite'] === 'Strict', 'HTTP session cookie remains HttpOnly and SameSite');

check(keySubmissionAllowed(['REMOTE_ADDR' => '127.0.0.1']), 'direct localhost key entry allowed');
check(keySubmissionAllowed(['REMOTE_ADDR' => '10.0.0.5', 'REMOTE_USER' => 'admin', 'HTTPS' => 'on']), 'authenticated HTTPS key entry allowed');
check(!keySubmissionAllowed(['REMOTE_ADDR' => '10.0.0.5', 'REMOTE_USER' => 'admin']), 'remote HTTP key entry refused');
check(!keySubmissionAllowed(['REMOTE_ADDR' => '127.0.0.1', 'REMOTE_USER' => 'admin', 'HTTP_X_FORWARDED_FOR' => '10.0.0.9']), 'proxied localhost HTTP key entry refused');
check(!keySubmissionAllowed(['REMOTE_ADDR' => '10.0.0.5', 'REMOTE_USER' => 'admin', 'HTTP_X_FORWARDED_PROTO' => 'https']), 'client HTTPS header cannot authorize key entry');
check(!keySubmissionAllowed(['REMOTE_ADDR' => '10.0.0.5', 'HTTPS' => 'on']), 'HTTPS alone does not authorize page access');

check(ChimSwornAndScorned\pageActionToken(str_repeat('a',64), null, 'clear_key') === str_repeat('a',64), 'key removal uses session CSRF without a playthrough');
check(ChimSwornAndScorned\pageActionToken(str_repeat('a',64), 'profile-a', 'restart_worker') === ChimSwornAndScorned\pageActionToken(str_repeat('a',64), 'profile-b', 'restart_worker'), 'global restart form survives scope changes');
check(ChimSwornAndScorned\pageActionToken(str_repeat('a',64), 'profile-a', 'save_settings') !== ChimSwornAndScorned\pageActionToken(str_repeat('a',64), 'profile-b', 'save_settings'), 'cast settings remain profile bound');
echo "page security checks passed\n";
