<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

// Launch the worker when the plugin is enabled. Never blocks or changes the request.
try {
    require_once __DIR__ . '/tables.php';
    require_once __DIR__ . '/launcher.php';
    require_once __DIR__ . '/logging.php';
    $sasEngineRoot = realpath(__DIR__ . '/../..');
    if ($sasEngineRoot !== false && !workerPidAlive($sasEngineRoot . '/log/sworn_scorned_worker.pid')) {
        $sasSettings = loadSettings(sharedConnection());
        if (workerPauseReason($sasSettings) === null) {
            sasLog('worker_launch', 'info', ['status' => ensureWorkerRunning($sasEngineRoot, __DIR__, $sasSettings)]);
        }
    }
} catch (\Throwable) {
    // Silent by design: plugin problems must never affect CHIM's request.
}

// Surface at most one issue as private context on this speaker's turn (spec §7.1).
try {
    require_once __DIR__ . '/hook.php';
    $sasRequest = $GLOBALS['gameRequest'] ?? null;
    if (is_array($sasRequest)
        && in_array($sasRequest[0] ?? null, GENERATING_REQUEST_TYPES, true)
        && function_exists('chimRegisterPromptInjection')) {
        $sasNote = surfaceForSpeaker(sharedConnection(), $sasRequest, (string) ($GLOBALS['HERIKA_NAME'] ?? ''), $GLOBALS['CACHE_PEOPLE'] ?? null, time());
        if ($sasNote !== null) {
            \chimRegisterPromptInjection('character_bottom', 'sworn_and_scorned', $sasNote, NOTE_PRIORITY);
        }
    }
} catch (\Throwable) {
    // Silent by design: never disturb the request.
}
