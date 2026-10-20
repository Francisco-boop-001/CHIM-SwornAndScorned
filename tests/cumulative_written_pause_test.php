<?php
declare(strict_types=1);
function ptr_runtime_paused(): bool { return $GLOBALS['sas_cumulative_paused'] ?? false; }
require __DIR__.'/jobs_test.php';
require_once __DIR__.'/support/written_fixture.php';
require_once __DIR__.'/../server/written_evidence.php';

use ChimSwornAndScorned\DecisionBackend;
use function ChimSwornAndScorned\{emptyLedger, processJob, writtenLineFromEventRow};
use const ChimSwornAndScorned\POLICY_DECISION_MODEL;

$body = 'You are a worthless coward. I meant every word. ' . str_repeat('x', 950);
$long = writtenLineFromEventRow(writtenFixture(401, $body), ['id'=>'unprofiled','player_name'=>'Hawke']);
check($long !== null && mb_strlen($body)>640 && mb_strlen($body)<=2000, 'merge fixture is a valid long written source');
foreach ([0,1,2] as $pauseAt) {
    $GLOBALS['sas_cumulative_paused'] = false;
    $deps = new FakeDeps();
    $deps->playthrough = 'unprofiled';
    $deps->ledger = emptyLedger('unprofiled');
    $deps->lines = [$long];
    $deps->keys = ['Farkas'=>'npc:900002'];
    $backend = new class($pauseAt) implements DecisionBackend {
        public int $calls = 0;
        public function __construct(private int $pauseAt) {}
        public function name(): string { return 'decisions:cumulative-fixture'; }
        public function ask(array $state,array $questions): mixed {
            ++$this->calls;
            $value = static fn(mixed $v): array => ['value'=>$v,'p'=>0.99];
            $answers = ['written.attribution'=>$value(true),'commitment'=>$value(false),'wronged'=>$value(true),
                'grievance.line'=>$value('L1'),'grievance.wants'=>$value('unknown'),
                'grievance.nature'=>$value('insult'),'grievance.matter'=>$value('L1'),
                'grievance.settlement'=>$value('unknown'),'grievance.related'=>$value('none')];
            if ($this->calls===$this->pauseAt) $GLOBALS['sas_cumulative_paused']=true;
            return array_intersect_key($answers,array_flip(array_column($questions,'id')));
        }
    };
    $deps->backends['decisions'] = [$backend,POLICY_DECISION_MODEL];
    $job = ['id'=>401,'playthrough_id'=>'unprofiled','owner_npc_id'=>900001,'owner_name'=>'Lydia',
        'counterpart_name'=>'Farkas','event_ids'=>[401],'backend'=>'decisions','failures'=>0];
    $log = tempnam(sys_get_temp_dir(),'sas-cumulative-log-');
    check(is_string($log),'owned temporary log created');
    $oldLog = ini_get('error_log');ini_set('error_log',$log);
    try {
        $result = processJob($job,$deps);
        $records = (string)file_get_contents($log);
        if ($pauseAt===0) {
            check($result===['outcome'=>'done','reason'=>'committed'] && $backend->calls===2
                && count($deps->ledger['issues'])===1,'long written source is fully evaluated through both passes');
            check($deps->ledger['issues'][0]['substance']['matter']['text']===$body
                && $deps->ledger['processed']===[401],'long source preserves exact matter and processed identity');
            check(str_contains($records,'"event":"written_source_finished"')
                && str_contains($records,'"source_result":"evaluated"'),'durable long-source success is reported');
        } else {
            check($result===['outcome'=>'paused','reason'=>'playthrough_switch']
                && $backend->calls===$pauseAt,'switch after each long-source pass stops later work');
            check($deps->commits===[] && $deps->ledger===emptyLedger('unprofiled'),'paused long source makes no ledger/processed/issue write');
            check(!str_contains($records,'written_source_finished'),'paused long source cannot log durable success');
        }
    } finally {
        $GLOBALS['sas_cumulative_paused']=false;
        if (is_string($oldLog)) ini_set('error_log',$oldLog);
        unlink($log);
    }
}
echo "cumulative written pause checks passed\n";
