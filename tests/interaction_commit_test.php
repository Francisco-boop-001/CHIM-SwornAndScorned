<?php
declare(strict_types=1);
require __DIR__.'/support/check.php';

// Copy real storage/queue code; only SQL I/O and core API file path are scripted.
$root=sys_get_temp_dir().'/sas-interaction-commit-'.bin2hex(random_bytes(6));mkdir($root,0700,true);
foreach(glob(__DIR__.'/../server/*.php') as $source)copy($source,$root.'/'.basename($source));
$GLOBALS['interaction_fixture_path']=$root.'/state.json';
function chimLoadGeneralSettingsIntoGlobals():void{$GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA']=false;}
function chimInteractionFile():mixed{return fopen($GLOBALS['interaction_fixture_path'],'c+e');}
function chimInteractionRead(mixed $h):array{rewind($h);$s=json_decode(stream_get_contents($h),true,16,JSON_THROW_ON_ERROR);if(!is_bool($s['enabled']??null)||!is_int($s['generation']??null))throw new RuntimeException('bad fixture state');return $s;}
file_put_contents($root.'/pg.php', <<<'PHP'
<?php
namespace ChimSwornAndScorned;
const JSON_FLAGS=JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;
final class Pg {
    public function all(string $sql,array $params=[]):array {
        if(str_contains($sql,'pg_try_advisory_lock'))return [['acquired'=>'t']];
        if(str_contains($sql,'FROM core_player'))return [['value'=>'Player']];
        if(str_contains($sql,'to_regclass'))return [['t'=>null]];
        if(str_starts_with($sql,'UPDATE core_npc_master')){
            ++$GLOBALS['guard_writes'];$this->assertCommitLock();
            if(!empty($GLOBALS['guard_throw_write']))throw new \RuntimeException('scripted write failure');
            return [['id'=>1]];
        }
        if(str_starts_with($sql,'INSERT INTO core_npc_master_history')){
            ++$GLOBALS['guard_history'];$this->assertCommitLock();return [['history_id'=>1]];
        }
        if(str_contains($sql,'FROM core_npc_master'))return [['id'=>1,'npc_name'=>'Lydia','lock_profile'=>0,'extended_data'=>'{}','plugin_extended_data'=>'{}','gamets_last_updated'=>0]];
        return [];
    }
    public function one(string $sql,array $params=[]):?array{return $this->all($sql,$params)[0]??null;}
    public function exec(string $sql,array $params=[]):void {
        if($sql==='COMMIT'){$this->assertCommitLock();++$GLOBALS['guard_commits'];}
    }
    private function assertCommitLock():void {
        if(!$GLOBALS['guard_expect_lock'])return;
        $probe=\chimInteractionFile();$exclusive=flock($probe,LOCK_EX|LOCK_NB);fclose($probe);
        \check(!$exclusive,'interaction shared lock remains held across actual SQL write and COMMIT');
    }
}
PHP);
try {
    require $root.'/queue.php';
    check(ChimSwornAndScorned\sasLogRecord('job_finished','info',['reason'=>'interaction_changed'])['reason']==='interaction_changed','interaction rejection retains its fixed diagnostic reason');
    $pg=new ChimSwornAndScorned\Pg();$store=new ChimSwornAndScorned\PerspectiveStore($pg);
    $request=['owner_npc_id'=>1,'owner_name'=>'Lydia','playthrough_id'=>'unprofiled','event_ids'=>[],'counterpart_name'=>null];
    $state=static function(bool $enabled,int $generation):void {file_put_contents($GLOBALS['interaction_fixture_path'],json_encode(['enabled'=>$enabled,'generation'=>$generation]));};
    $reset=static function(bool $expect=true):void{$GLOBALS['guard_writes']=0;$GLOBALS['guard_commits']=0;$GLOBALS['guard_history']=0;$GLOBALS['guard_expect_lock']=$expect;};
    $mutate=static function(array $ledger):array{$ledger['floor_event_id']=1;return ['status'=>'applied','ledger'=>$ledger,'affinity_delta'=>0];};
    $state(true,9);$reset();
    check($store->commitLedger($request+['interaction_generation'=>7],$mutate)==='interaction_changed','actual store rejects a stale Off/On generation before any write');
    check($GLOBALS['guard_writes']===0&&$GLOBALS['guard_history']===0&&$GLOBALS['guard_commits']===0,'stale generation writes no ledger/history/affinity');
    $state(false,7);$reset();check($store->commitLedger($request+['interaction_generation'=>7],$mutate)==='interaction_changed','interaction Off blocks same generation');
    $state(true,7);$reset();check($store->commitLedger($request+['interaction_generation'=>7],$mutate)==='committed','matching enabled generation commits under shared interaction lock');
    check($GLOBALS['guard_writes']===1&&$GLOBALS['guard_history']===1&&$GLOBALS['guard_commits']===1,'one healthy write/history/commit');
    $probe=chimInteractionFile();check(flock($probe,LOCK_EX|LOCK_NB),'interaction lock released after commit');fclose($probe);
    $state(true,7);$reset();
    $toggle=static function(array $ledger)use($state,$mutate):array{$state(false,8);$state(true,9);return $mutate($ledger);};
    check($store->commitLedger($request+['interaction_generation'=>7],$toggle)==='interaction_changed','state is checked at commit, not only before evaluation');
    $state(true,9);$reset();
    $deps=new ChimSwornAndScorned\PgJobDeps($pg,$store,['id'=>'unprofiled','player_name'=>'Player'],['_interaction_generation'=>7],static fn()=>[],static fn()=>'');
    check($deps->commitLedger($request,$mutate)==='interaction_changed','normal PgJobDeps propagates the originally captured witness to store');
    $state(true,7);$reset();$exclusive=chimInteractionFile();check(flock($exclusive,LOCK_EX|LOCK_NB),'fixture writer holds state lock');
    check($store->commitLedger($request+['interaction_generation'=>7],$mutate)==='interaction_changed','contended state writer fails closed without waiting');fclose($exclusive);
    file_put_contents($GLOBALS['interaction_fixture_path'],'bad JSON');$reset();
    check($store->commitLedger($request+['interaction_generation'=>7],$mutate)==='interaction_changed','unreadable interaction state fails closed');
    check($GLOBALS['guard_writes']===0&&$GLOBALS['guard_history']===0,'invalid state writes nothing');
    $state(true,7);$reset();$GLOBALS['guard_throw_write']=true;
    try{$store->commitLedger($request+['interaction_generation'=>7],$mutate);check(false,'scripted SQL write must throw');}
    catch(RuntimeException $e){check($e->getMessage()==='scripted write failure','original write error is retained');}
    $GLOBALS['guard_throw_write']=false;
    $probe=chimInteractionFile();check(flock($probe,LOCK_EX|LOCK_NB),'interaction lock released after failed SQL write');fclose($probe);
    $reset(false);check($store->commitLedger($request,$mutate)==='committed','existing callers without new-mode witness retain legacy behavior');
    echo "interaction commit witness checks passed (real storage control flow/OS file locks; scripted SQL/core file)\n";
} finally {foreach(glob($root.'/*') as $p)unlink($p);rmdir($root);}
