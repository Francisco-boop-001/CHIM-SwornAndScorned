<?php
declare(strict_types=1);

// Private CLI subprocess: the parent owns the wall-clock timeout and receives only a reply.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $wire = stream_get_contents(STDIN, 2097153);
    if (!is_string($wire) || strlen($wire) > 2097152) throw new RuntimeException();
    $request = json_decode($wire, true, 64, JSON_THROW_ON_ERROR);
    $root = realpath($argv[1] ?? '');
    if ($root === false || !is_file($root . '/main.php') || !is_array($request)
        || !is_int($request['connector_id'] ?? null) || $request['connector_id'] < 1
        || !is_int($request['max_tokens'] ?? null) || $request['max_tokens'] < 1
        || !is_string($request['actor'] ?? null) || !is_string($request['player'] ?? null)
        || !is_array($request['messages'] ?? null)) throw new RuntimeException();
    $GLOBALS['ENGINE_PATH'] = $root . '/';
    require_once $root . '/conf/conf.php';
    require_once $root . '/lib/postgresql.class.php';
    $GLOBALS['db'] = new sql();
    require_once $root . '/lib/logger.php';
    require_once $root . '/lib/settings.php';
    if (function_exists('chimLoadGeneralSettingsIntoGlobals')) chimLoadGeneralSettingsIntoGlobals();
    $GLOBALS['RELLLM_CONNECTOR'] = $request['connector_id'];
    require_once __DIR__ . '/llm_connector.php';
    $complete = ChimSwornAndScorned\relationshipConnectorDirectCompletion($request['max_tokens'], $request['actor'], $request['player']);
    $response = $complete($request['messages']);
    echo json_encode(['response'=>$response], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    // Do not serialize exception traces, connector configuration or provider payloads.
    exit(1);
}
