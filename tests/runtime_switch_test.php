<?php
declare(strict_types=1);
require __DIR__ . '/support/check.php';

// Real entrypoint and frozen core flock contract; scripted DB/model only.
// SAS_RUNTIME_SOURCE must name the correspondence core lib/playthrough_runtime.php.
$runtime = getenv('SAS_RUNTIME_SOURCE');
check(is_string($runtime) && is_file($runtime), 'SAS_RUNTIME_SOURCE must identify the frozen core runtime');
$case = $argv[1] ?? 'idle';
check(in_array($case, ['idle', 'before_work', 'pass1', 'pass2', 'pass1_error', 'pass2_error'], true), 'known runtime-switch case');
$root = sys_get_temp_dir() . '/sas-runtime-switch-' . bin2hex(random_bytes(8));
$plugin = $root . '/ext/sworn_and_scorned';
foreach ([$plugin, $root . '/lib', $root . '/conf', $root . '/log'] as $directory) mkdir($directory, 0700, true);
$processes = [];
$pipesByProcess = [];
$start = static function (array $command) use (&$processes, &$pipesByProcess): int {
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'bounded fixture child starts');
    $processes[] = $process;
    $pipesByProcess[] = $pipes;
    return array_key_last($processes);
};
$waitFor = static function (string $path, float $seconds = 5.0): void {
    $deadline = microtime(true) + $seconds;
    do {
        clearstatcache(true, $path);
        if (is_file($path)) return;
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('fixture barrier missing: ' . basename($path));
};
$waitExit = static function (int $index, float $seconds = 5.0) use (&$processes): int {
    $deadline = microtime(true) + $seconds;
    do {
        $status = proc_get_status($processes[$index]);
        if (!$status['running']) return $status['exitcode'];
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('worker did not release its process-lifetime lease');
};
try {
    copy(__DIR__ . '/../server/worker.php', $plugin . '/worker.php');
    copy(__DIR__ . '/support/runtime_switch_queue.php', $plugin . '/queue.php');
    copy($runtime, $root . '/lib/playthrough_runtime.php');
    foreach (['main.php', 'conf/conf.php', 'lib/logger.php', 'lib/settings.php'] as $file) file_put_contents($root . '/' . $file, "<?php\n");
    file_put_contents($root . '/lib/postgresql.class.php', <<<'PHP'
<?php
require_once __DIR__ . '/playthrough_runtime.php';
class sql { public function __construct() { ptr_runtime_enter(); } }
PHP);
    file_put_contents($root . '/switch.php', <<<'PHP'
<?php
require __DIR__ . '/lib/playthrough_runtime.php';
try {
    $state = ptr_runtime_begin_switch(3.0);
    file_put_contents(__DIR__ . '/switched', $state['generation']);
    $deadline = microtime(true) + 8;
    while (!is_file(__DIR__ . '/release-switch') && microtime(true) < $deadline) usleep(10000);
    ptr_runtime_release_switch($state);
} catch (Throwable $error) {
    file_put_contents(__DIR__ . '/switch-error', get_class($error));
    exit(1);
}
PHP);
    putenv('SAS_RUNTIME_FIXTURE=' . $root);
    putenv('SAS_RUNTIME_PLUGIN=' . realpath(__DIR__ . '/../server'));
    putenv('SAS_RUNTIME_FIXTURES=' . realpath(__DIR__ . '/support/fixtures.php'));
    putenv('SAS_RUNTIME_CASE=' . $case);
    if ($case === 'before_work') {
        $switch = $start([PHP_BINARY, $root . '/switch.php']);
        $waitFor($root . '/switched');
        $worker = $start([PHP_BINARY, $plugin . '/worker.php', '--once']);
        check($waitExit($worker) === 75, 'core pause before bootstrap rejects worker without work');
        check(!is_file($root . '/tick'), 'paused bootstrap never scans or evaluates');
    } else {
        $worker = $start([PHP_BINARY, $plugin . '/worker.php']);
        $waitFor($root . ($case === 'idle' ? '/idle-ready' : '/ask-' . (str_starts_with($case, 'pass1') ? '1' : '2')));
        $switch = $start([PHP_BINARY, $root . '/switch.php']);
        $waitFor($root . '/log/playthrough_runtime/paused');
        if ($case !== 'idle') touch($root . '/release-model');
        $exit = $waitExit($worker, 4.0);
        check($exit === 0, 'core switch makes the worker exit cleanly');
        $waitFor($root . '/switched', 1.0);
        check(!is_file($root . '/switch-error'), 'actual core exclusive switch acquired within its bounded timeout');
        check(!is_file($root . '/log/sworn_scorned_worker.pid'), 'switch exit removes the plugin pid');
        if ($case !== 'idle') {
            $result = json_decode(file_get_contents($root . '/result.json'), true, 32, JSON_THROW_ON_ERROR);
            check($result === ['outcome' => 'paused', 'reason' => 'playthrough_switch'], 'pause preserves the job instead of neutral/retry/internal_error');
            $calls = (int) file_get_contents($root . '/calls');
            check($calls === (str_starts_with($case, 'pass1') ? 1 : 2), 'pause between passes never starts the next model call');
            check(!is_file($root . '/commit'), 'pause during either pass never reaches ledger or affinity commit');
        }
    }
    touch($root . '/release-switch');
    check($waitExit($switch) === 0, 'controller releases the switch cleanly');
    $generation = file_get_contents($root . '/switched');
    check(is_string($generation) && $generation !== '', 'switch creates a fresh generation');
    putenv('SAS_RUNTIME_CASE=restart');
    @unlink($root . '/result.json');
    $restart = $start([PHP_BINARY, $plugin . '/worker.php', '--once']);
    check($waitExit($restart) === 0, 'fresh worker starts after switch');
    check(file_get_contents($root . '/seen-generation') === $generation, 'fresh bootstrap captures the switched generation');
    check(is_file($root . '/commit'), 'fresh worker can evaluate and commit the retained synthetic job');
    echo 'runtime switch ' . $case . " checks passed (real core lock, scripted DB/model)\n";
} finally {
    foreach ($processes as $index => $process) {
        if (proc_get_status($process)['running']) proc_terminate($process, 9);
        foreach ($pipesByProcess[$index] as $pipe) fclose($pipe);
        proc_close($process);
    }
    foreach (['SAS_RUNTIME_FIXTURE', 'SAS_RUNTIME_PLUGIN', 'SAS_RUNTIME_FIXTURES', 'SAS_RUNTIME_CASE'] as $name) putenv($name);
    $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($paths as $path) $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    rmdir($root);
}
