<?php
declare(strict_types=1);
require __DIR__ . '/support/check.php';
check(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'worker retry checks only in disposable clone');
$root = sys_get_temp_dir() . '/sas-fallback-retry-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
foreach (glob(__DIR__ . '/../server/*.php') as $source) copy($source, $root . '/' . basename($source));
// Real workerTick/processJob/finishJob; only database I/O and model transport are scripted.
file_put_contents($root . '/pg.php', <<<'PHP'
<?php
namespace ChimSwornAndScorned;
const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
final class Pg {
    public array $job = ['id'=>7,'playthrough_id'=>'unprofiled','owner_npc_id'=>2,'owner_name'=>'Lydia',
        'counterpart_name'=>'Farkas','event_ids'=>'{4,5}','backend'=>'decisions','failures'=>0];
    public bool $failed = false;
    public array $delays = [];
    public function one(string $sql, array $params=[]): ?array { return $this->all($sql, $params)[0] ?? null; }
    public function all(string $sql, array $params=[]): array {
        if (str_contains($sql, 'FROM core_player')) return [['value'=>'Player']];
        if (str_contains($sql, 'to_regclass')) return [['t'=>null]];
        if (str_contains($sql, 'SELECT last_rowid')) return [['last_rowid'=>'99']];
        if (str_contains($sql, 'SELECT id, npc_name')) return [['id'=>'2','npc_name'=>'Lydia']];
        if (str_contains($sql, 'SELECT rowid')) {
            if (!str_contains($sql, 'ANY(')) return [];
            return array_map(static fn(int $id):array=>['rowid'=>(string)$id,'type'=>'chat','sess'=>'',
                'data'=>($id===4?'Lydia: Will you help?':'Farkas: I will help.'),'people'=>'Lydia|Farkas',
                'gamets'=>(string)$id,'localts'=>'1','delivery_state'=>'spoken'], [4,5]);
        }
        if (str_starts_with($sql, 'UPDATE ') && str_contains($sql, 'RETURNING id,')) return $this->failed ? [] : [$this->job];
        if (str_contains($sql, 'AS ledger')) return [['ledger'=>null]];
        if (str_contains($sql, 'pg_try_advisory_lock')) return [['acquired'=>'t']];
        if (str_contains($sql, 'SELECT npc_name, lock_profile')) throw new \RuntimeException('sql_failed');
        if (str_contains($sql, 'SELECT id FROM core_npc_master')) return [['id'=>'3']];
        throw new \RuntimeException('unexpected_fixture_query');
    }
    public function exec(string $sql, array $params=[]): void {
        if (in_array($sql, ['BEGIN','COMMIT','ROLLBACK'], true) || str_starts_with($sql, 'SET LOCAL ') || str_contains($sql, 'pg_advisory_unlock')) return;
        if (str_contains($sql, 'SELECT owner_npc_id')) throw new \RuntimeException('unexpected_fixture_exec');
        if (str_contains($sql, "status = 'failed'")) { $this->failed = true; return; }
        if (str_contains($sql, 'backend = $2')) {
            $this->job['backend']=$params[1]; $this->job['failures']=$params[2]; $this->delays[]=$params[3]; return;
        }
        throw new \RuntimeException('unexpected_fixture_write');
    }
}
PHP);
$pgSource = file_get_contents(__DIR__ . '/../server/pg.php');
$helperStart = strpos($pgSource, 'function pgIntArray(');
$helperEnd = strpos($pgSource, 'function openEngineConnection(');
file_put_contents($root . '/pg.php', substr($pgSource, $helperStart, $helperEnd - $helperStart), FILE_APPEND);
class LLMConnector {
    public function readOne($id): array { return ['id'=>$id,'driver'=>'openrouterjson','model'=>'fixture-model']; }
    public function setOldGlobals($row): void { $GLOBALS['CONNECTOR']['openrouterjson']=['url'=>'https://openrouter.ai/api/v1','API_KEY'=>'']; }
}
$GLOBALS['RELLLM_CONNECTOR']=3;
$GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA']=false;
$priorLog=ini_get('error_log');
ini_set('error_log', $root . '/diagnostics.log');
try {
    require $root . '/queue.php';
    $pg=new ChimSwornAndScorned\Pg();
    // Turning Jev off leaves already-queued Decisions jobs to use the available relationship LLM.
    $settings=['enabled'=>true,'use_jev'=>false,'cast'=>[2],'api_key'=>'','model'=>'fixture-model'];
    $calls=0;
    $complete=static function(array $messages) use (&$calls): string { ++$calls; throw new RuntimeException('synthetic failure'); };
    for ($tick=0; $tick<10 && !$pg->failed; ++$tick) {
        check(ChimSwornAndScorned\workerTick($pg,$settings,1000,static function(){throw new RuntimeException('Jev transport must not run');},$complete),'queued job runs');
    }
    check($pg->failed && $calls===4,'actual worker must exhaust one relationship-LLM budget, not eight requests');
    check($pg->delays===[30,120,600],'fallback retains exactly its three configured retry delays');
    $logs=file($root.'/diagnostics.log',FILE_IGNORE_NEW_LINES);
    check(count($logs)===4,'one bounded diagnostic per actual model attempt');
    foreach ($logs as $log) {
        $start=strpos($log,'{'); $entry=json_decode(substr($log,$start),true,32,JSON_THROW_ON_ERROR);
        check($entry['backend']==='llm','fallback calls are labelled as the actual backend');
    }
    $pg=new ChimSwornAndScorned\Pg();
    $calls=0;
    $complete=static function(array $messages) use (&$calls): string {
        ++$calls;
        return '{"answers":{"commitment":{"value":false,"confidence":"high"},"wronged":{"value":false,"confidence":"high"}}}';
    };
    for ($tick=0; $tick<10 && !$pg->failed; ++$tick) {
        check(ChimSwornAndScorned\workerTick($pg,$settings,1000,static function(){throw new RuntimeException('Jev transport must not run');},$complete),'commit failure is retried');
    }
    check($pg->failed && $calls===4,'storage exception after fallback cannot double its model-call budget');
    check($pg->delays===[30,120,600],'storage exception retains one fallback retry schedule');
    $logs=array_slice(file($root.'/diagnostics.log',FILE_IGNORE_NEW_LINES),4);
    check(count($logs)===4,'one diagnostic per post-evaluation storage failure');
    foreach ($logs as $log) {
        $entry=json_decode(substr($log,strpos($log,'{')),true,32,JSON_THROW_ON_ERROR);
        check($entry['backend']==='llm' && $entry['reason']==='internal_error','exception-path logs retain actual fallback backend');
    }
    echo "fallback retry worker checks passed (request failures and storage exceptions; four requests each)\n";
} finally {
    if (is_string($priorLog)) ini_set('error_log',$priorLog);
    foreach (glob($root.'/*') as $path) unlink($path);
    rmdir($root);
}