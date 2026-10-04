<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/page_security.php';
require_once __DIR__ . '/page_actions.php';
require_once __DIR__ . '/page_model.php';
require_once __DIR__ . '/page_view.php';

function pageUnavailable(): never
{
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "The CHIM page is temporarily unavailable.\n";
    exit;
}

sendSecurityHeaders();
if (!pageAccessAllowed($_SERVER)) {
    $handoff = localHandoffUrl($_SERVER, $_GET);
    if ($handoff !== null) {
        header('Location: ' . $handoff, true, 302);
        exit;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden. Open this page through localhost (replace the host in your CHIM URL with localhost).\n";
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

try {
    session_set_cookie_params(sessionCookieOptions($_SERVER));
    if (!session_start()) {
        pageUnavailable();
    }
    if (!is_string($_SESSION['sas_csrf'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $_SESSION['sas_csrf']) !== 1) {
        $_SESSION['sas_csrf'] = bin2hex(random_bytes(32));
    }
    $csrf = $_SESSION['sas_csrf'];
    session_write_close();
} catch (\Throwable) {
    pageUnavailable();
}

$engineRoot = dirname(__DIR__, 2);
set_error_handler(static function (int $severity): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new \ErrorException('page_bootstrap_failed', 0, $severity);
});
try {
    $GLOBALS['ENGINE_PATH'] = $engineRoot . '/';
    require_once $engineRoot . '/conf/conf.php';
    require_once $engineRoot . '/lib/postgresql.class.php';
    $GLOBALS['db'] = new \sql();
    require_once $engineRoot . '/lib/settings.php';
    if (function_exists('chimLoadGeneralSettingsIntoGlobals')) {
        \chimLoadGeneralSettingsIntoGlobals();
    }
    $pg = openEngineConnection($engineRoot);
    ensureSchema($pg);
} catch (\Throwable) {
    restore_error_handler();
    pageUnavailable();
}
restore_error_handler();

$notice = null;
if ($method === 'POST') {
    try {
        $postedScope = currentPlaythrough($pg)['id'] ?? null;
    } catch (\Throwable) {
        pageUnavailable();
    }
    if (!csrfTokenValid(pageActionToken($csrf, $postedScope, $_POST['action'] ?? null), $_POST['csrf'] ?? null)) {
        http_response_code(400);
        $notice = 'The form expired; please try again.';
    } else {
        try {
            $action = parsePageAction($_POST, knownNpcIds($pg));
            $action['playthrough_id'] = $postedScope;
            if ($action['action'] === 'set_key' && !keySubmissionAllowed($_SERVER)) {
                http_response_code(403);
                $notice = 'Use HTTPS or direct localhost to save a key.';
            } else {
                $notice = performPageAction($pg, $action, $engineRoot, __DIR__);
            }
        } catch (KeyStorageError) {
            http_response_code(503);
            $notice = 'Key storage unavailable. Provision or restore the private master key before saving a key.';
        } catch (\UnexpectedValueException) {
            http_response_code(400);
            $notice = 'That request was not valid.';
        } catch (\Throwable) {
            pageUnavailable();
        }
    }
}

try {
    $model = buildPageModel($pg, $engineRoot, ($_GET['view'] ?? '') === 'director');
    $model['key_submission_allowed'] = keySubmissionAllowed($_SERVER);
    header('Content-Type: text/html; charset=UTF-8');
    echo renderPage($model, pageScopeToken($csrf, $model['playthrough_id']), $notice, $csrf);
} catch (\Throwable) {
    pageUnavailable();
}
