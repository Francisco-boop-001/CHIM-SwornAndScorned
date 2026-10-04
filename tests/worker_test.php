<?php
declare(strict_types=1);
require __DIR__ . '/support/check.php';

// Exercise the real entrypoint with an isolated engine whose tick fails.
$root = sys_get_temp_dir() . '/sas-worker-' . bin2hex(random_bytes(8));
foreach (['ext/sworn_and_scorned', 'conf', 'lib', 'log'] as $directory) mkdir($root . '/' . $directory, 0700, true);
try {
    copy(__DIR__ . '/../server/worker.php', $root . '/ext/sworn_and_scorned/worker.php');
    foreach (['main.php', 'conf/conf.php', 'lib/logger.php', 'lib/settings.php'] as $file) file_put_contents($root . '/' . $file, '<?php');
    file_put_contents($root . '/lib/postgresql.class.php', '<?php class sql {}');
    file_put_contents($root . '/ext/sworn_and_scorned/queue.php', '<?php namespace ChimSwornAndScorned;
        function openEngineConnection($root) { return new \\stdClass(); }
        function ensureSchema($pg) {}
        function loadSeeds($plugin, $root) { return []; }
        function sasLog($event, $level, $meta = []) {}
        function loadSettings($pg) { return ["enabled" => true]; }
        function workerPauseReason($settings) { return null; }
        function workerTick($pg, $settings, $now) { throw new \\RuntimeException("synthetic_failure"); }');
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/ext/sworn_and_scorned/worker.php') . ' --once', $output, $status);
    check($status === 1, 'a failed one-shot tick returns failure');
    check(!file_exists($root . '/log/sworn_scorned_worker.pid'), 'one-shot removes its own pid');
    $stub = '<?php namespace ChimSwornAndScorned;
        function openEngineConnection($root) { return new \\stdClass(); }
        function ensureSchema($pg) {}
        function loadSeeds($plugin, $root) { return []; }
        function sasLog($event, $level, $meta = []) { file_put_contents(__DIR__."/events", $event."\\n", FILE_APPEND); }
        function loadSettings($pg) { return ["enabled" => true]; }
        function workerPauseReason($settings) { return "key_storage_unavailable"; }
        function workerTick($pg, $settings, $now) { file_put_contents(__DIR__."/tick", "called"); throw new \\RuntimeException("must_not_run"); }';
    file_put_contents($root . '/ext/sworn_and_scorned/queue.php', $stub);
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/ext/sworn_and_scorned/worker.php') . ' --once', $output, $status);
    check($status === 0 && !is_file($root . '/ext/sworn_and_scorned/tick'), 'intentional pause is successful and never evaluates');
    check(str_contains(file_get_contents($root . '/ext/sworn_and_scorned/events'), 'worker_paused'), 'intentional pause has a fixed diagnostic');
    check(!file_exists($root . '/log/sworn_scorned_worker.pid'), 'paused one-shot removes its pid');
    $recovery = str_replace(
        'function workerPauseReason($settings) { return "key_storage_unavailable"; }',
        'function workerPauseReason($settings) { $file=__DIR__."/loads"; $n=(int)@file_get_contents($file); file_put_contents($file,(string)($n+1)); return $n===0 ? "key_storage_unavailable" : null; }',
        $stub
    );
    $recovery = str_replace('throw new \\RuntimeException("must_not_run");', 'exit(0);', $recovery);
    file_put_contents($root . '/ext/sworn_and_scorned/queue.php', $recovery);
    unlink($root . '/ext/sworn_and_scorned/events');
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/ext/sworn_and_scorned/worker.php'), $output, $status);
    $events = file_get_contents($root . '/ext/sworn_and_scorned/events');
    check($status === 0 && is_file($root . '/ext/sworn_and_scorned/tick'), 'running worker resumes automatically after key recovery');
    check(substr_count($events, 'worker_paused') === 1 && substr_count($events, 'worker_resumed') === 1 && !str_contains($events, 'worker_error'), 'pause recovery neither churns errors nor requires a respawn');
} finally {
    $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($paths as $path) $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    rmdir($root);
}
echo "worker checks passed\n";
