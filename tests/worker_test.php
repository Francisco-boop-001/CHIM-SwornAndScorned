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
        function workerTick($pg, $settings, $now) { throw new \\RuntimeException("synthetic_failure"); }');
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/ext/sworn_and_scorned/worker.php') . ' --once', $output, $status);
    check($status === 1, 'a failed one-shot tick returns failure');
    check(!file_exists($root . '/log/sworn_scorned_worker.pid'), 'one-shot removes its own pid');
} finally {
    $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($paths as $path) $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    rmdir($root);
}
echo "worker checks passed\n";
