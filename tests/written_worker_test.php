<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';

$source = __DIR__ . '/../server/written_worker.php';
check(is_file($source), 'public written worker wake entry point exists');
$root = sys_get_temp_dir() . '/sas-written-wake-' . bin2hex(random_bytes(6));
$plugin = $root . '/ext/sworn_and_scorned';
mkdir($plugin, 0700, true);
// The process/DB boundary is scripted; production wrapper bytes are copied unchanged.
$stub = <<<'PHP'
<?php
namespace ChimSwornAndScorned;
final class Pg { public object $connection; public function __construct() { $this->connection = new \stdClass(); } }
function pg_transaction_status(object $connection): int { return $GLOBALS['wake_fixture']['transaction']; }
function currentPlaythrough(Pg $pg): ?array { $GLOBALS['wake_calls'][] = 'scope'; return $GLOBALS['wake_fixture']['scope']; }
function loadSettings(Pg $pg): array {
    $GLOBALS['wake_calls'][] = 'settings';
    if ($GLOBALS['wake_fixture']['load_error']) throw new \RuntimeException('synthetic-private-error-do-not-return');
    return ['enabled'=>true, 'private'=>'synthetic-secret-do-not-return'];
}
function workerPauseReason(array $settings): ?string {
    $GLOBALS['wake_calls'][] = ['gate', $settings];
    return $GLOBALS['wake_fixture']['pause'];
}
function ensureWorkerRunning(string $engine, string $plugin, array $settings): string {
    throw new \RuntimeException('offscreen wake must never start the lasting daemon');
}
function workerPidAlive(string $path): bool {
    $GLOBALS['wake_calls'][] = ['pid', $path];
    return $GLOBALS['wake_fixture']['daemon'];
}
function is_executable(string $path): bool { return $GLOBALS['wake_fixture']['executable']; }
function proc_open(array $command, array $descriptors, mixed &$pipes): mixed {
    $GLOBALS['wake_calls'][] = ['child', $command, $descriptors];
    if ($GLOBALS['wake_fixture']['process_error']) throw new \RuntimeException('synthetic-process-secret');
    $pipes = [];
    return $GLOBALS['wake_fixture']['process'] ? tmpfile() : false;
}
function proc_close(mixed $process): int {
    fclose($process);
    $GLOBALS['wake_calls'][] = 'reaped';
    return $GLOBALS['wake_fixture']['exit'];
}
PHP;
file_put_contents($plugin . '/launcher.php', $stub);
file_put_contents($plugin . '/worker.php', "<?php\nthrow new RuntimeException('fixture must never execute a worker');\n");
file_put_contents($root . '/main.php', "<?php\n");
copy($source, $plugin . '/written_worker.php');
if (!defined('PGSQL_TRANSACTION_IDLE')) define('PGSQL_TRANSACTION_IDLE', 0);
try {
    require $plugin . '/written_worker.php';
    $pg = new ChimSwornAndScorned\Pg();
    $defaults = ['transaction'=>PGSQL_TRANSACTION_IDLE, 'scope'=>['id'=>'fixture', 'player_name'=>'Player'],
        'pause'=>null, 'load_error'=>false, 'daemon'=>false, 'executable'=>true,
        'process_error'=>false, 'process'=>true, 'exit'=>0];
    $run = static function(array $changes = []) use ($pg, $defaults): array {
        $GLOBALS['wake_fixture'] = array_replace($defaults, $changes);
        $GLOBALS['wake_calls'] = [];
        return ChimSwornAndScorned\wakeWrittenEvidenceWorker($pg);
    };
    check($run() === ['status'=>'unavailable', 'reason'=>'settings_bootstrap_unavailable'], 'missing trusted bootstrap fails closed');
    check($GLOBALS['wake_calls'] === [], 'missing bootstrap touches no settings or launcher');
    function chimLoadGeneralSettingsIntoGlobals(): void {
        $GLOBALS['wake_calls'][] = 'general_settings';
        if (!empty($GLOBALS['wake_fixture']['bootstrap_error'])) throw new RuntimeException('synthetic-bootstrap-secret');
    }
    check($run(['transaction'=>99]) === ['status'=>'unavailable', 'reason'=>'transaction_active'], 'caller transaction cannot launch a worker');
    check($GLOBALS['wake_calls'] === [], 'active transaction has no other effects');
    check($run(['scope'=>null]) === ['status'=>'paused', 'reason'=>'playthrough_unavailable'], 'unavailable scope is paused');
    check($GLOBALS['wake_calls'] === ['general_settings','scope'], 'unavailable scope loads no keys or launcher');
    foreach (['disabled','restore_policy','key_storage_unavailable','jev_credentials_unavailable'] as $reason) {
        check($run(['pause'=>$reason]) === ['status'=>'paused', 'reason'=>$reason], 'shared gate preserved: ' . $reason);
        check(array_column(array_filter($GLOBALS['wake_calls'], 'is_array'), 0) === ['gate'], 'paused wake never reaches launcher');
        check(array_slice($GLOBALS['wake_calls'], 0, 3) === ['general_settings','scope','settings'], 'fresh core scope and private settings load precede eligibility');
    }
    check($run(['daemon'=>true]) === ['status'=>'running','reason'=>'existing_worker'], 'existing daemon is only observed, never started or repaired');
    check($GLOBALS['wake_calls'][array_key_last($GLOBALS['wake_calls'])] === ['pid',$root.'/log/sworn_scorned_worker.pid'], 'only fixed existing daemon PID is checked');
    check($run(['executable'=>false]) === ['status'=>'unavailable','reason'=>'tick_unavailable'], 'missing fixed bounded runner fails closed');
    foreach ([0=>['tick_finished','child_exited'], 1=>['unavailable','tick_failed'],
        75=>['unavailable','tick_failed'], 9=>['unavailable','tick_timeout'],
        124=>['unavailable','tick_timeout'], 137=>['unavailable','tick_timeout']] as $exit=>$expected) {
        $result = $run(['exit'=>$exit]);
        check($result === ['status'=>$expected[0], 'reason'=>$expected[1]], 'bounded child result: ' . $exit);
        $calls = $GLOBALS['wake_calls'];
        check($calls[count($calls)-2] === ['child',
            ['/usr/bin/timeout','--signal=KILL','60s','/usr/bin/php',$plugin.'/worker.php','--settle-once'],
            [0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']]], 'fixed argv runs only the settled bounded mode without a shell or outer foreground timeout');
        check($calls[array_key_last($calls)] === 'reaped', 'synchronous caller reaps its bounded child');
        check(!str_contains(json_encode($result), 'private') && !str_contains(json_encode($result), 'secret'), 'private loader values never leave wrapper');
        check(!in_array($result['status'], ['queued','processed','spawn_requested'], true), 'child completion never claims source processing');
    }
    check($run(['process'=>false]) === ['status'=>'unavailable','reason'=>'spawn_failed'], 'refused child process is bounded');
    foreach (['load_error','bootstrap_error','process_error'] as $error) {
        check($run([$error=>true]) === ['status'=>'unavailable', 'reason'=>'wake_failed'], 'exceptions are bounded and private: ' . $error);
    }
    unlink($root . '/main.php');
    check($run() === ['status'=>'unavailable', 'reason'=>'engine_unavailable'], 'not an installed CHIM root cannot launch');
    check($GLOBALS['wake_calls'] === [], 'invalid fixed engine has no effects');
    echo "written worker wake checks passed (scripted process/DB boundary; no worker/model executed)\n";
} finally {
    foreach ([$plugin.'/written_worker.php', $plugin.'/launcher.php', $plugin.'/worker.php', $root.'/main.php'] as $path) {
        if (is_file($path)) unlink($path);
    }
    rmdir($plugin);
    rmdir($root . '/ext');
    rmdir($root);
}
