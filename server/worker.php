<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

// Background worker (spec §5). `--sas-daemon` forks and detaches; `--once` runs one tick (tests).
// Exits by itself when the plugin is disabled or uninstalled, or after repeated errors.

$engineRoot = realpath(__DIR__ . '/../..');
$arguments = $argv ?? [];
// Core's launcher adopts any process matching worker.php.*--daemon; use our own flag.
$daemon = in_array('--sas-daemon', $arguments, true);
$once = in_array('--once', $arguments, true);
if ($engineRoot === false || !is_file($engineRoot . '/main.php')) {
    fwrite(STDERR, "engine root not found\n");
    exit(1);
}

if ($daemon) {
    if (!function_exists('pcntl_fork') || !function_exists('posix_setsid')) {
        exit(1);
    }
    $child = pcntl_fork();
    if ($child < 0) {
        exit(1);
    }
    if ($child > 0) {
        exit(0);
    }
    posix_setsid();
}

$lockHandle = @fopen($engineRoot . '/log/sworn_scorned_worker.lock', 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit(0); // another worker owns the lock
}
$pidFile = $engineRoot . '/log/sworn_scorned_worker.pid';
file_put_contents($pidFile, (string) getmypid());
register_shutdown_function(static function () use ($pidFile): void {
    if ((int) @file_get_contents($pidFile) === getmypid()) {
        @unlink($pidFile);
    }
});

$GLOBALS['ENGINE_PATH'] = $engineRoot . '/';
require_once $engineRoot . '/conf/conf.php';
require_once $engineRoot . '/lib/postgresql.class.php';
$GLOBALS['db'] = new \sql();
require_once $engineRoot . '/lib/logger.php';
require_once $engineRoot . '/lib/settings.php';
require_once __DIR__ . '/queue.php';

$pg = openEngineConnection($engineRoot);
ensureSchema($pg);
try {
    $seeds = loadSeeds(__DIR__, $engineRoot);
} catch (\Throwable) {
    $seeds = [];
    sasLog('lore_seeds_invalid', 'error');
}
$lastLore = 0;

sasLog('worker_started', 'info', ['pid' => getmypid()]);
$errors = 0;

while (true) {
    if (!is_file(__FILE__)) {
        sasLog('worker_stopped', 'info', ['reason' => 'uninstalled']);
        exit(0);
    }
    $worked = false;
    try {
        if (function_exists('chimLoadGeneralSettingsIntoGlobals')) {
            \chimLoadGeneralSettingsIntoGlobals();
        }
        $settings = loadSettings($pg);
        if (!$settings['enabled']) {
            sasLog('worker_stopped', 'info', ['reason' => 'disabled']);
            exit(0);
        }
        $worked = workerTick($pg, $settings, time());
        if ($seeds !== [] && time() - $lastLore >= LORE_INTERVAL_SECONDS) {
            $lastLore = time();
            $playthrough = currentPlaythrough($pg);
            if ($playthrough !== null) {
                seedLoreOnce($pg, new PerspectiveStore($pg), $playthrough, $seeds);
            }
        }

        $errors = 0;
    } catch (\Throwable $error) {
        sasLog('worker_error', 'error', ['reason' => substr(get_class($error), 0, 60)]);
        if ($once) exit(1);
        if (++$errors >= 5) {
            sasLog('worker_stopped', 'error', ['reason' => 'repeated_errors']);
            exit(1);
        }
        try {
            $pg = openEngineConnection($engineRoot);
        } catch (\Throwable) {
        }
    }
    if ($once) {
        exit(0);
    }
    if (!$worked) {
        sleep(2);
    }
}
