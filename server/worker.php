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
$settleOnce = in_array('--settle-once', $arguments, true);
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

$interactionGeneration = null;
$settleDeadline = null;
if ($settleOnce) {
    try {
        require_once $engineRoot . '/lib/chim_interaction.php';
        if (!\chimInteractionAllowed()) exit(0);
        $interactionGeneration = $GLOBALS['chim_interaction_generation'];
        if (!is_int($interactionGeneration) || $interactionGeneration < 0) exit(1);
        $settleDeadline = hrtime(true) + (SETTLE_SECONDS + 1) * 1000000000;
    } catch (\Throwable) {
        exit(1);
    }
}

$pg = openEngineConnection($engineRoot);
ensureSchema($pg);
try {
    $seeds = $settleOnce ? [] : loadSeeds(__DIR__, $engineRoot);
} catch (\Throwable) {
    $seeds = [];
    sasLog('lore_seeds_invalid', 'error');
}
$lastLore = 0;

sasLog('worker_started', 'info', ['pid' => getmypid()]);
$errors = 0;
$paused = null;

while (true) {
    if (function_exists('ptr_runtime_paused') && \ptr_runtime_paused()) {
        sasLog('worker_stopped', 'info', ['reason' => 'playthrough_switch']);
        exit(0); // Release core's process-lifetime lease before the switch deadline.
    }
    if (!is_file(__FILE__)) {
        sasLog('worker_stopped', 'info', ['reason' => 'uninstalled']);
        exit(0);
    }
    $worked = false;
    try {
        if ($settleOnce) {
            if (!\chimInteractionAllowed() || $GLOBALS['chim_interaction_generation'] !== $interactionGeneration) {
                sasLog('worker_stopped', 'info', ['reason' => 'interaction_changed']);
                exit(0);
            }
            if (hrtime(true) > $settleDeadline) exit(0);
        }
        if (function_exists('chimLoadGeneralSettingsIntoGlobals')) {
            \chimLoadGeneralSettingsIntoGlobals();
        }
        $settings = loadSettings($pg);
        if (!$settings['enabled']) {
            sasLog('worker_stopped', 'info', ['reason' => 'disabled']);
            exit(0);
        }
        $reason = workerPauseReason($settings);
        if ($reason !== null) {
            if ($paused !== $reason) sasLog('worker_paused', 'info', ['reason' => $reason]);
            $paused = $reason;
            $errors = 0;
            if ($once || $settleOnce) exit(0);
            sleep(2);
            continue;
        }
        if ($paused !== null) sasLog('worker_resumed', 'info');
        $paused = null;
        if ($settleOnce) $settings['_interaction_generation'] = $interactionGeneration;
        $worked = workerTick($pg, $settings, time());
        if (function_exists('ptr_runtime_paused') && \ptr_runtime_paused()) {
            sasLog('worker_stopped', 'info', ['reason' => 'playthrough_switch']);
            exit(0); // Release core's process-lifetime lease before the switch deadline.
        }
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
        if ($once || $settleOnce) exit(1);
        if (++$errors >= 5) {
            sasLog('worker_stopped', 'error', ['reason' => 'repeated_errors']);
            exit(1);
        }
        try {
            $pg = openEngineConnection($engineRoot);
        } catch (\Throwable) {
        }
    }
    if ($once || ($settleOnce && $worked)) {
        exit(0);
    }
    if (!$worked) {
        sleep($settleOnce ? 1 : 2);
    }
}
