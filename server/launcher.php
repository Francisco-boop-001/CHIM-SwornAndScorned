<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/tables.php';

function workerCommand(string $phpBinary, string $workerPath): string
{
    return escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerPath) . ' --sas-daemon';
}

function workerPidAlive(string $pidFile, string $procRoot = '/proc'): bool
{
    $pidText = is_file($pidFile) ? trim((string) @file_get_contents($pidFile)) : '';
    $pid = filter_var($pidText, FILTER_VALIDATE_INT);
    if (!is_int($pid) || $pid < 1 || !is_dir("{$procRoot}/{$pid}")) {
        return false;
    }
    $cmdline = @file_get_contents("{$procRoot}/{$pid}/cmdline");
    if (!is_string($cmdline)) {
        return false;
    }
    $hasWorkerPath = false;
    $hasOurFlag = false;
    foreach (explode("\0", $cmdline) as $argument) {
        $hasWorkerPath = $hasWorkerPath || str_ends_with($argument, '/ext/sworn_and_scorned/worker.php');
        $hasOurFlag = $hasOurFlag || $argument === '--sas-daemon';
    }
    return $hasWorkerPath && $hasOurFlag;
}

/** Spawn the daemon; the worker forks and detaches itself. */
function ensureWorkerRunning(string $engineRoot, string $pluginDir, #[\SensitiveParameter] array $settings, string $phpBinary = '/usr/bin/php'): string
{
    if (workerPauseReason($settings) !== null) return 'paused';
    if (workerPidAlive($engineRoot . '/log/sworn_scorned_worker.pid')) {
        return 'running';
    }
    $log = $engineRoot . '/log/sworn_scorned_worker.log';
    $process = @proc_open(
        workerCommand($phpBinary, $pluginDir . '/worker.php'),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes
    );
    if (!is_resource($process)) {
        return 'spawn_failed';
    }
    proc_close($process);
    return 'spawned';
}
