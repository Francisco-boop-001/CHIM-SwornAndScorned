<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/launcher.php';

/** Server-only wake request; source processing is reported by writtenEvidenceStatus(). */
function wakeWrittenEvidenceWorker(Pg $pg): array
{
    $reply = static fn(string $status, string $reason): array => ['status'=>$status, 'reason'=>$reason];
    $engineRoot = realpath(__DIR__ . '/../..');
    if ($engineRoot === false || realpath($engineRoot . '/ext/sworn_and_scorned') !== __DIR__
        || !is_file($engineRoot . '/main.php') || !is_file(__DIR__ . '/worker.php')) {
        return $reply('unavailable', 'engine_unavailable');
    }
    if (!function_exists('chimLoadGeneralSettingsIntoGlobals')) {
        return $reply('unavailable', 'settings_bootstrap_unavailable');
    }
    try {
        if (pg_transaction_status($pg->connection) !== PGSQL_TRANSACTION_IDLE) {
            return $reply('unavailable', 'transaction_active');
        }
        \chimLoadGeneralSettingsIntoGlobals();
        if (currentPlaythrough($pg) === null) {
            return $reply('paused', 'playthrough_unavailable');
        }
        $settings = loadSettings($pg);
        $pause = workerPauseReason($settings);
        if ($pause !== null) {
            return $reply('paused', $pause);
        }
        if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || !is_executable('/usr/bin/timeout')
            || !is_executable('/usr/bin/php') || !function_exists('proc_open')) {
            return $reply('unavailable', 'tick_unavailable');
        }
        if (workerPidAlive($engineRoot . '/log/sworn_scorned_worker.pid')) {
            return $reply('running', 'existing_worker');
        }
        // Core's runtime lease lasts for the process lifetime; never start a daemon here.
        $process = @proc_open(
            ['/usr/bin/timeout', '--signal=KILL', '60s', '/usr/bin/php', __DIR__ . '/worker.php', '--settle-once'],
            [0=>['file','/dev/null','r'], 1=>['file','/dev/null','a'], 2=>['file','/dev/null','a']], $pipes
        );
        if (!is_resource($process)) {
            return $reply('unavailable', 'spawn_failed');
        }
        $exit = proc_close($process);
        if (in_array($exit, [9, 124, 137], true)) {
            return $reply('unavailable', 'tick_timeout');
        }
        return $exit === 0 ? $reply('tick_finished', 'child_exited') : $reply('unavailable', 'tick_failed');
    } catch (\Throwable) {
        return $reply('unavailable', 'wake_failed');
    }
}
