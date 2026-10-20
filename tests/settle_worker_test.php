<?php
declare(strict_types=1);
require __DIR__.'/support/check.php';

// Actual worker control flow; DB/backend/clock are scripted and never touch CHIM.
$root = sys_get_temp_dir().'/sas-settle-worker-'.bin2hex(random_bytes(6));
$plugin = $root.'/ext/sworn_and_scorned';
mkdir($plugin,0700,true); mkdir($root.'/lib'); mkdir($root.'/conf'); mkdir($root.'/log');
copy(__DIR__.'/../server/worker.php',$plugin.'/worker.php');
file_put_contents($root.'/main.php',"<?php\n");
file_put_contents($root.'/conf/conf.php',"<?php\n");
file_put_contents($root.'/lib/postgresql.class.php',"<?php\nclass sql {}\n");
file_put_contents($root.'/lib/logger.php',"<?php\n");
file_put_contents($root.'/lib/settings.php',"<?php\nfunction chimLoadGeneralSettingsIntoGlobals(): void {}\n");
file_put_contents($root.'/lib/chim_interaction.php', <<<'PHP'
<?php
function chimInteractionBegin(): void { $GLOBALS['chim_interaction_generation'] ??= 42; }
function chimInteractionState(): array { return ['enabled'=>true,'generation'=>getenv('SAS_SETTLE_CASE')==='offon' && ($GLOBALS['fake_clock']??0)>0 ? 44 : 42]; }
function chimInteractionAllowed(): bool { chimInteractionBegin(); return chimInteractionState()['generation']===$GLOBALS['chim_interaction_generation']; }
chimInteractionBegin();
PHP);
file_put_contents($plugin.'/queue.php', <<<'PHP'
<?php
namespace ChimSwornAndScorned;
const SETTLE_SECONDS = 20;
const LORE_INTERVAL_SECONDS = 60;
class Pg {}
class PerspectiveStore { public function __construct(Pg $pg) {} }
$GLOBALS['fake_clock']=0;
$GLOBALS['observed']=['ticks'=>0,'attempts'=>0,'seeds'=>0,'generations'=>[]];
register_shutdown_function(static function():void { file_put_contents(dirname(__DIR__,2).'/observed.json',json_encode($GLOBALS['observed'])); });
function openEngineConnection(string $path): Pg { return new Pg(); }
function ensureSchema(Pg $pg): void {}
function loadSeeds(string $plugin,string $engine): array { return [['synthetic'=>true]]; }
function seedLoreOnce(Pg $pg,PerspectiveStore $store,array $pt,array $seeds): int { ++$GLOBALS['observed']['seeds']; return 0; }
function currentPlaythrough(Pg $pg): array { return ['id'=>'fixture','player_name'=>'Player']; }
function loadSettings(Pg $pg): array { return ['enabled'=>true]; }
function workerPauseReason(array $settings): ?string { return null; }
function sasLog(string $code,string $level,array $meta=[]): void {}
function hrtime(bool $number=false): int { return $GLOBALS['fake_clock']*1000000000; }
function time(): int { return 100+$GLOBALS['fake_clock']; }
function sleep(int $seconds): int { $GLOBALS['fake_clock']+=$seconds; return 0; }
function workerTick(Pg $pg,array $settings,int $now): bool {
    $o=&$GLOBALS['observed']; ++$o['ticks'];
    $o['generations'][]=$settings['_interaction_generation']??null;
    $case=getenv('SAS_SETTLE_CASE');
    if ($case==='error') throw new \RuntimeException('scripted job failure');
    if ($o['ticks']>30 || ($case!=='idle' && $o['ticks']>2)) throw new \RuntimeException('unexpected repeated attempt');
    if ($case==='ready' && $o['ticks']===2) { ++$o['attempts']; return true; }
    return false;
}
PHP);
try {
    foreach (['ready','idle','offon','error'] as $case) {
        putenv('SAS_SETTLE_CASE='.$case);
        $proc=proc_open(['/usr/bin/timeout','--signal=KILL','5s',PHP_BINARY,$plugin.'/worker.php','--settle-once'],
            [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        check(is_resource($proc),'scripted worker starts');
        $output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        $exit=proc_close($proc);$o=json_decode(file_get_contents($root.'/observed.json'),true,32,JSON_THROW_ON_ERROR);
        check($exit===($case==='error'?1:0),'bounded settle wake exits after first attempt, idle deadline, interaction change or error: '.$case.' '.$output);
        check($o['seeds']===0,'settle-only wake never seeds lore');
        if ($case==='ready') check($o['ticks']===2 && $o['attempts']===1,'quiet interval can become one attempted job, then exits');
        if ($case==='idle') check($o['ticks']>=20 && $o['ticks']<=23 && $o['attempts']===0,'idle quiet poll has a finite twenty-second clock budget');
        if ($case==='offon') check($o['ticks']===1 && $o['attempts']===0,'Off/On cannot revive captured generation during quiet wait');
        if ($case==='error') check($o['ticks']===1,'failed attempt is not retried by settle-only wake');
        check(array_unique($o['generations'])===[42],'one captured core generation follows every poll');
    }
    echo "settle worker orchestration passed (scripted clock/DB/backend; no model)\n";
} finally {
    putenv('SAS_SETTLE_CASE');
    foreach (glob($plugin.'/*') as $p) unlink($p);
    foreach (glob($root.'/lib/*') as $p) unlink($p);
    foreach (glob($root.'/conf/*') as $p) unlink($p);
    foreach (glob($root.'/log/*') as $p) unlink($p);
    if(is_file($root.'/observed.json'))unlink($root.'/observed.json');
    unlink($root.'/main.php');rmdir($plugin);rmdir($root.'/ext');rmdir($root.'/lib');rmdir($root.'/conf');rmdir($root.'/log');rmdir($root);
}
