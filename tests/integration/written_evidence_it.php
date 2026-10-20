<?php
declare(strict_types=1);
require __DIR__.'/../support/check.php';
require __DIR__.'/../support/fixtures.php';
require __DIR__.'/../support/written_fixture.php';
require __DIR__.'/../../server/written_source.php';
require __DIR__.'/../../server/queue.php';
require __DIR__.'/../support/scripted_backend.php';
require __DIR__.'/../../server/page_view.php';
use ChimSwornAndScorned\{Pg,PerspectiveStore,JobDeps,DecisionBackend};
use function ChimSwornAndScorned\{openEngineConnection,ensureSchema,saveSetting,currentPlaythrough,normalizeLedger,enqueueWrittenEvidence,writtenEvidenceStatus,readEvidenceLine,claimJob,processJob,issueId,renderIssue,renderNote,pgIntArray};
use const ChimSwornAndScorned\POLICY_DECISION_MODEL;
if(getenv('SAS_IT')!=='1'){echo "written integration skipped\n";exit(0);}
check(getenv('WSL_DISTRO_NAME')==='DwemerAI4Skyrim3-test','registered disposable clone only');
putenv('SAS_TEST_SCHEMA=sas_it');
$connection=pg_connect('host=/var/run/postgresql dbname=dwemer user=postgres',PGSQL_CONNECT_FORCE_NEW);
check($connection instanceof \PgSql\Connection,'explicit peer socket connection');$pg=new Pg($connection);
check($pg->one("SELECT current_setting('listen_addresses') AS v")['v']==='','socket-only clone');
check($pg->one("SELECT to_regnamespace('sas_it') AS s")['s']===null,'schema must be absent');
$pg->exec('CREATE SCHEMA sas_it');
try {
    foreach(['CREATE TABLE sas_it.core_npc_master (LIKE public.core_npc_master INCLUDING ALL)',
        'CREATE TABLE sas_it.core_npc_master_history (LIKE public.core_npc_master_history INCLUDING ALL)',
        'CREATE SEQUENCE sas_it.history_id_seq',"ALTER TABLE sas_it.core_npc_master_history ALTER history_id SET DEFAULT nextval('sas_it.history_id_seq')",
        'CREATE TABLE sas_it.eventlog (LIKE public.eventlog INCLUDING ALL)','CREATE SEQUENCE sas_it.eventlog_id_seq',
        "ALTER TABLE sas_it.eventlog ALTER rowid SET DEFAULT nextval('sas_it.eventlog_id_seq')",'SET search_path=sas_it,public'] as $sql)$pg->exec($sql);
    ensureSchema($pg);$pt=currentPlaythrough($pg);check($pt!==null,'current scope resolved');
    $template=$pg->one("SELECT id FROM public.core_npc_master WHERE npc_name<>'The Narrator' ORDER BY id LIMIT 1");
    foreach([[900001,'Lydia'],[900002,'Farkas']] as [$id,$name]){
        $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id=$1',[$template['id']]);
        $pg->exec("UPDATE sas_it.core_npc_master SET id=$1,npc_name=$2,lock_profile=0,extended_data='{}',plugin_extended_data='{}' WHERE id=$3",[$id,$name,$template['id']]);
    }
    saveSetting($pg,'enabled',true);saveSetting($pg,'cast',[900001,900002]);
    $publish=static function(array $row)use($pg,$pt):int {
        $body=json_decode($row['data'],true);$body['playthrough_id']=$pt['id'];
        return (int)$pg->one('INSERT INTO sas_it.eventlog(type,sess,data,people,gamets,localts,delivery_state) VALUES($1,$2,$3,$4,100,100,NULL) RETURNING rowid',[$row['type'],$row['sess'],json_encode($body,JSON_THROW_ON_ERROR),$row['people']])['rowid'];
    };
    $uniqueSource=static function(array $row,int $n):array {
        $body=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);$prefix=sprintf('%08x',$n);
        $body['document_id']=$prefix.'-1111-4111-8111-111111111111';
        $body['receipt']['receipt_id']=$prefix.'-2222-4222-8222-222222222222';
        $body['receipt']['operation_id']=$prefix.'-3333-4333-8333-333333333333';
        $row['data']=json_encode($body,JSON_THROW_ON_ERROR);return $row;
    };
    $a=static fn(mixed $v):array=>['value'=>$v,'p'=>0.99];
    $answers=['commitment'=>$a(false),'wronged'=>$a(true),'written.attribution'=>$a(true),'grievance.line'=>$a('L1'),'grievance.wants'=>$a('unknown'),'grievance.matter'=>$a('L1'),'grievance.nature'=>$a('insult'),'grievance.settlement'=>$a('unknown')];
    $deps=new class($pg,$pt,$answers) implements JobDeps {
        public ?Closure $beforeCommit=null;
        public int $backendSelections=0;
        public function __construct(private Pg $pg,private array $pt,private array $answers){}
        public function setAnswers(array $answers):void{$this->answers=$answers;}
        public function currentPlaythroughId():?string{return currentPlaythrough($this->pg)['id']??null;}
        public function linesFor(array $ids):array{$out=[];foreach($ids as $id){$l=readEvidenceLine($this->pg,$id,$this->pt);if($l!==null)$out[]=$l;}return $out;}
        public function ledgerFor(int $id,string $pt):array{$r=$this->pg->one("SELECT (plugin_extended_data->'sworn_and_scorned')::text AS l FROM sas_it.core_npc_master WHERE id=$1",[$id]);return normalizeLedger($r['l']===null?null:json_decode($r['l'],true),$pt);}
        public function counterpartKey(string $name):?string{return $name==='Farkas'?'npc:900002':null;}
        public function backend(string $kind):?array{++$this->backendSelections;return [new ScriptedBackend($this->answers),POLICY_DECISION_MODEL];}
        public function commitLedger(array $request,callable $mutate):string{if($this->beforeCommit!==null)($this->beforeCommit)();return (new PerspectiveStore($this->pg))->commitLedger($request,$mutate);}
    };
    // Status/enqueue must not decrypt/migrate even an inherited legacy dedicated key.
    $legacy='synthetic-legacy-placeholder';$oldKeyPath=getenv('CHIM_SWORN_SCORNED_KEY_FILE');
    putenv('CHIM_SWORN_SCORNED_KEY_FILE=invalid-key-path-must-not-be-used');
    $pg->exec("INSERT INTO sas_it.sworn_scorned_settings(key,value) VALUES('api_key',$1::jsonb) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value",[json_encode($legacy)]);
    $projection=ChimSwornAndScorned\writtenQueueSettings($pg,$pt);
    check($projection['api_key']==='', 'read-only projection excludes saved key contents');
    // An existing cursor must cross the unrelated rows; bootstrap must not skip over the test.
    $pg->exec('INSERT INTO sas_it.sworn_scorned_cursor(playthrough_id,last_rowid) VALUES($1,0)',[$pt['id']]);
    // Carrier rows must stay private to exact-marker readers, not starve the speech scan.
    $plain=(int)$pg->one("INSERT INTO sas_it.eventlog(type,sess,data,people,gamets,localts) VALUES('info','ordinary_info','plain text: not JSON','|Lydia|',100,100) RETURNING rowid")['rowid'];
    $pg->exec("INSERT INTO sas_it.eventlog(type,sess,data,people,gamets,localts) SELECT 'info','ordinary_info','more plain text','|Lydia|',100,100 FROM generate_series(1,501)");
    $private=(int)$pg->one("INSERT INTO sas_it.eventlog(type,sess,data,people,gamets,localts) VALUES('info','correspondence_v1','private anchor: not JSON','|Lydia|',100,100) RETURNING rowid")['rowid'];
    check(readEvidenceLine($pg,$plain,$pt)===null && readEvidenceLine($pg,$private,$pt)===null,'ordinary and private-anchor info are not written evidence');
    $legacyRow=writtenFixture();$legacyRow['type']='written_evidence';$legacyEventId=$publish($legacyRow);
    check(enqueueWrittenEvidence($pg,$legacyEventId)['status']==='invalid','unsafe legacy raw-type source has no compatibility fallback');
    $legacyRequest=['owner_npc_id'=>900001,'owner_name'=>'Lydia','playthrough_id'=>$pt['id'],'event_ids'=>[$legacyEventId],'counterpart_name'=>'Farkas','counterpart_key'=>'npc:900002'];
    check((new PerspectiveStore($pg))->commitLedger($legacyRequest,static function(array $l):array{throw new RuntimeException('legacy raw carrier reached mutator');})==='events_missing','locked store rejects unsafe carrier even without caller source_lines');
    $pg->exec('DELETE FROM sas_it.eventlog WHERE rowid=$1',[$legacyEventId]);
    $id=$publish(writtenFixture());$queued=enqueueWrittenEvidence($pg,$id);
    check($queued['status']==='queued','more than scan-limit ordinary info rows do not starve eligible written source');

    check(($queued['credential_readiness']??null)==='unchecked','public queue reports conservative credential readiness without opening key storage');
    check(json_decode($pg->one("SELECT value::text AS v FROM sas_it.sworn_scorned_settings WHERE key='api_key'")['v'],true)===$legacy,'status/enqueue leave legacy key byte-value unchanged');
    $pg->exec("DELETE FROM sas_it.sworn_scorned_settings WHERE key='api_key'");
    if($oldKeyPath===false)putenv('CHIM_SWORN_SCORNED_KEY_FILE');else putenv('CHIM_SWORN_SCORNED_KEY_FILE='.$oldKeyPath);
    check($queued['status']==='queued','public source API queues persisted source');
    check(writtenEvidenceStatus($pg,$id)['status']==='queued','queued is not processed');
    check((int)$pg->one('SELECT count(*) AS n FROM sas_it.sworn_scorned_jobs')['n']===1,'one recipient job only');
    $job=claimJob($pg,$pt['id']);check($job['owner_npc_id']===900001 && $job['counterpart_name']==='Farkas','absent claimed sender is counterpart only');
    check(processJob($job,$deps)===['outcome'=>'done','reason'=>'committed'],'full queued evaluation committed');
    $ledger=$deps->ledgerFor(900001,$pt['id']);$issue=$ledger['issues'][0];$line=readEvidenceLine($pg,$id,$pt);
    check($issue['id']===issueId('e:'.$id,'npc:900002','grievance') && $issue['counterpart']==='npc:900002' && $issue['status']==='open','exact issue identity parties lifecycle');
    check($issue['substance']['matter']['written']===$line['written'] && $issue['substance']['matter']['text']===$line['text'],'persisted exact body and provenance');
    check($issue['evidence'][0]['written']===$line['written'],'written evidence survives normalization');
    $history=normalizeLedger(json_decode($pg->one("SELECT (plugin_extended_data->'sworn_and_scorned')::text AS l FROM sas_it.core_npc_master_history WHERE npc_id=900001 ORDER BY history_id DESC LIMIT 1")['l'],true),$pt['id']);
    check($history===$ledger,'history retains exact written source and replay keys');
    check($deps->ledgerFor(900002,$pt['id'])['issues']===[],'claimed author ledger untouched');
    check(str_contains(renderIssue($issue,900001,'csrf',true),'Claimed sender') && str_contains(renderNote($issue,'Lydia','Farkas'),'letter'),'journal and reminder label attribution');
    check(writtenEvidenceStatus($pg,$id)['status']==='processed','public completion requires persisted marker');
    $budgetId=$publish($uniqueSource(writtenFixture(),80));$budgetLine=readEvidenceLine($pg,$budgetId,$pt);
    $budgetEntry=ChimSwornAndScorned\writtenSourceEntry($budgetLine);$budgetEntry['result']='substance_budget';
    $budgetLedger=$deps->ledgerFor(900001,$pt['id']);$budgetLedger['written_sources'][]=$budgetEntry;
    $pg->exec("UPDATE sas_it.core_npc_master SET plugin_extended_data=jsonb_set(plugin_extended_data,'{sworn_and_scorned}',$1::jsonb) WHERE id=900001",[json_encode($budgetLedger,JSON_THROW_ON_ERROR)]);
    $backendSelections=$deps->backendSelections;$jobCount=(int)$pg->one('SELECT count(*) AS n FROM sas_it.sworn_scorned_jobs')['n'];
    check(writtenEvidenceStatus($pg,$budgetId)===['status'=>'abstained','reason'=>'substance_budget'],'legacy terminal budget marker is publicly abstained');
    check(enqueueWrittenEvidence($pg,$budgetId)===['status'=>'abstained','reason'=>'substance_budget'],'legacy budget marker cannot be re-enqueued');
    check($deps->backendSelections===$backendSelections && (int)$pg->one('SELECT count(*) AS n FROM sas_it.sworn_scorned_jobs')['n']===$jobCount,'legacy budget replay performs no backend selection or job creation');
    check($deps->ledgerFor(900001,$pt['id'])===$budgetLedger,'legacy terminal budget marker is not migrated or reopened');
    $count=(int)$pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history')['n'];
    check(processJob($job,$deps)===['outcome'=>'done','reason'=>'replay'],'exact replay no model effect');
    check((int)$pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history')['n']===$count,'replay no history');
    $deps->beforeCommit=static function()use($pg,$id):void{$pg->exec('UPDATE sas_it.eventlog SET localts=localts+1 WHERE rowid=$1',[$id]);};
    check(processJob($job,$deps)['outcome']==='stale','replay-only local timestamp change cannot report success');
    $deps->beforeCommit=null;$pg->exec('UPDATE sas_it.eventlog SET localts=100 WHERE rowid=$1',[$id]);
    $duplicate=$publish(writtenFixture());check(enqueueWrittenEvidence($pg,$duplicate)['status']==='invalid','alternate duplicate document rejected');
    check(writtenEvidenceStatus($pg,$id)['status']==='invalid','duplicate invalidates fresh evidence rather than successful no-op');
    $pg->exec('DELETE FROM sas_it.eventlog WHERE rowid=$1',[$duplicate]);
    $pg->exec('DELETE FROM sas_it.eventlog WHERE rowid=$1',[$id]);
    check(writtenEvidenceStatus($pg,$id)['status']==='invalid','missing source is not success');
    $replacement=$publish(writtenFixture());check(enqueueWrittenEvidence($pg,$replacement)['status']==='invalid','stable source identity survives original pruning');
    $pg->exec('DELETE FROM sas_it.eventlog WHERE rowid=$1',[$replacement]);
    $row=writtenFixture();$e=json_decode($row['data'],true);$e['document_id']='11111111-1111-4111-8111-111111111111';$e['receipt']['receipt_id']='22222222-2222-4222-8222-222222222222';$e['receipt']['operation_id']='33333333-3333-4333-8333-333333333333';$row['data']=json_encode($e);
    $new=$publish($row);check(enqueueWrittenEvidence($pg,$new)['status']==='queued','new distinct letter queued');
    $newJob=$job;$newJob['event_ids']=[$new];
    $deps->beforeCommit=static function()use($pg,$new):void{$pg->exec("UPDATE sas_it.eventlog SET data=jsonb_set(data::jsonb,'{body}',to_jsonb('Replaced body'::text))::text WHERE rowid=$1",[$new]);};
    check(processJob($newJob,$deps)['outcome']==='stale','source edited during model call rejected under commit lock');
    check((int)$pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history')['n']===$count,'tamper no history or affinity write');
    check($pg->one("SELECT extended_data->'relationships'->'Farkas'->>'aff' AS a FROM sas_it.core_npc_master WHERE id=900001")['a']===null,'no affinity on creation/replay/rejected writes');
    $deps->beforeCommit=null;
    $freshRow=static function(int $n):array{
        $r=writtenFixture();$e=json_decode($r['data'],true);
        $e['document_id']=sprintf('%08x-1111-4111-8111-111111111111',$n);
        $e['receipt']['receipt_id']=sprintf('%08x-2222-4222-8222-222222222222',$n);
        $e['receipt']['operation_id']=sprintf('%08x-3333-4333-8333-333333333333',$n);
        $r['data']=json_encode($e);return $r;
    };
    $ordinal=10;
    // A receipt/operation cannot be replayed by giving the document another UUID.
    foreach(['receipt_id','operation_id'] as $key){
        $first=$freshRow(++$ordinal);$one=$publish($first);
        $second=$freshRow(++$ordinal);$a1=json_decode($first['data'],true);$a2=json_decode($second['data'],true);
        $a2['receipt'][$key]=$a1['receipt'][$key];$second['data']=json_encode($a2);$two=$publish($second);
        check(readEvidenceLine($pg,$one,$pt)===null && readEvidenceLine($pg,$two,$pt)===null,'alternate document reusing '.$key.' invalidates both');
        $pg->exec('DELETE FROM sas_it.eventlog WHERE rowid=ANY($1::bigint[])',[pgIntArray([$one,$two])]);
    }
    // DB identity authentication, not merely shape validation.
    foreach([999999,900002] as $badRecipient){
        $r=$freshRow(++$ordinal);$e=json_decode($r['data'],true);$e['recipient']['id']=$badRecipient;$r['data']=json_encode($e);$bad=$publish($r);
        check(readEvidenceLine($pg,$bad,$pt)===null,'missing or mismatched canonical recipient id rejected');
        $pg->exec('DELETE FROM sas_it.eventlog WHERE rowid=$1',[$bad]);
    }
    $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id=$1',[$template['id']]);
    $pg->exec("UPDATE sas_it.core_npc_master SET id=900003,npc_name='FARKAS' WHERE id=$1",[$template['id']]);
    $ambiguous=$publish($freshRow(++$ordinal));check(readEvidenceLine($pg,$ambiguous,$pt)===null,'duplicate canonical sender name abstains');
    $pg->exec('DELETE FROM sas_it.core_npc_master WHERE id=900003');$pg->exec('DELETE FROM sas_it.eventlog WHERE rowid=$1',[$ambiguous]);
    // Changes invisible in the displayed body must also invalidate the locked source.
    foreach(['exposure','attachment','receipt','sender_identity','duplicate','missing','carrier','marker'] as $race){
        $raceId=$publish($freshRow(++$ordinal));$raceJob=$job;$raceJob['event_ids']=[$raceId];$duplicateId=null;
        $deps->beforeCommit=static function()use($pg,$raceId,$race,&$duplicateId):void{
            if($race==='sender_identity'){$pg->exec("UPDATE sas_it.core_npc_master SET npc_name='Changed Farkas' WHERE id=900002");return;}
            if($race==='missing'){$pg->exec('DELETE FROM sas_it.eventlog WHERE rowid=$1',[$raceId]);return;}
            if($race==='carrier'){$pg->exec("UPDATE sas_it.eventlog SET type='written_evidence' WHERE rowid=$1",[$raceId]);return;}
            if($race==='marker'){$pg->exec("UPDATE sas_it.eventlog SET sess='correspondence_v1' WHERE rowid=$1",[$raceId]);return;}
            $e=json_decode($pg->one('SELECT data FROM sas_it.eventlog WHERE rowid=$1',[$raceId])['data'],true);
            if($race==='duplicate'){$duplicateId=(int)$pg->one('INSERT INTO sas_it.eventlog(type,sess,data,people,gamets,localts) SELECT type,sess,data,people,gamets,localts FROM sas_it.eventlog WHERE rowid=$1 RETURNING rowid',[$raceId])['rowid'];return;}
            if($race==='exposure')$e['receipt']['exposure']='read';
            if($race==='attachment')$e['attachments']=[['kind'=>'gold','form_id'=>'Skyrim.esm|0000000F','quantity'=>50]];
            if($race==='receipt')$e['receipt']['receipt_id']='44444444-4444-4444-8444-444444444444';
            $pg->exec('UPDATE sas_it.eventlog SET data=$1 WHERE rowid=$2',[json_encode($e),$raceId]);
        };
        check(processJob($raceJob,$deps)['outcome']==='stale',$race.' changed during evaluation fails closed');
        check((int)$pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history')['n']===$count,$race.' makes no snapshot');
        $pg->exec("UPDATE sas_it.core_npc_master SET npc_name='Farkas' WHERE id=900002");
        $pg->exec('DELETE FROM sas_it.eventlog WHERE rowid=ANY($1::bigint[])',[pgIntArray(array_filter([$raceId,$duplicateId]))]);
    }
    $deps->beforeCommit=null;
    // Restore actual fixture state/history, including its eventlog source anchors.
    $beforeFuture=$deps->ledgerFor(900001,$pt['id']);
    $future=$publish($freshRow(++$ordinal));$futureJob=$job;$futureJob['event_ids']=[$future];
    check(processJob($futureJob,$deps)['reason']==='committed','distinct future source committed');
    check(count($deps->ledgerFor(900001,$pt['id'])['written_sources'])===count($beforeFuture['written_sources'])+1,'future source acquired its own restore marker');
    $pg->exec("UPDATE sas_it.core_npc_master SET plugin_extended_data=jsonb_set(plugin_extended_data,'{sworn_and_scorned}',$1::jsonb) WHERE id=900001",[json_encode($beforeFuture)]);
    $pg->exec('DELETE FROM sas_it.eventlog WHERE rowid=$1',[$future]);
    check($deps->ledgerFor(900001,$pt['id'])===$beforeFuture && writtenEvidenceStatus($pg,$future)['status']==='invalid','restored ledger drops future knowledge and missing future anchor cannot succeed');
    // Queue chronology: an immediate later letter waits behind unsettled speech.
    $pg->exec('TRUNCATE sas_it.eventlog,sas_it.sworn_scorned_jobs,sas_it.sworn_scorned_marks,sas_it.sworn_scorned_cursor');
    $pg->exec('INSERT INTO sas_it.sworn_scorned_cursor(playthrough_id,last_rowid) VALUES($1,0)',[$pt['id']]);
    $speech=(int)$pg->one("INSERT INTO sas_it.eventlog(type,data,people,gamets,localts,delivery_state) VALUES('chat','Lydia: I need a moment. (talking to Farkas)','|Lydia|Farkas|',100,$1,'spoken') RETURNING rowid",[time()])['rowid'];
    $later=$publish($freshRow(++$ordinal));$pg->exec('UPDATE sas_it.eventlog SET localts=$1 WHERE rowid=$2',[time(),$later]);$pending=enqueueWrittenEvidence($pg,$later);
    check($pending['status']==='pending','later letter does not jump unsettled speech');
    $pg->exec('UPDATE sas_it.eventlog SET localts=100 WHERE rowid=ANY($1::bigint[])',[pgIntArray([$speech,$later])]);
    check(enqueueWrittenEvidence($pg,$later)['status']==='queued','settled speech and letter enqueue together');
    $chronological=claimJob($pg,$pt['id']);check($chronological['event_ids']===[$speech,$later],'recipient job keeps exact chronological event order');
    $senderJob=$pg->one('SELECT event_ids::text AS ids FROM sas_it.sworn_scorned_jobs WHERE owner_npc_id=900002');
    check($senderJob!==null && ChimSwornAndScorned\parsePgIntArray($senderJob['ids'])===[$speech],'claimed sender sees actual speech but never the letter');
    $pg->exec('TRUNCATE sas_it.eventlog,sas_it.sworn_scorned_jobs,sas_it.sworn_scorned_marks,sas_it.sworn_scorned_cursor');
    $earlier=$publish($freshRow(++$ordinal));$later=$publish($freshRow(++$ordinal));
    check(enqueueWrittenEvidence($pg,$later)['status']==='queued' && enqueueWrittenEvidence($pg,$earlier)['status']==='queued','out-of-order first API calls preserve earlier letter');
    check(claimJob($pg,$pt['id'])['event_ids']===[$earlier,$later],'initial scanner starts at earliest eligible written source');
    // Both public API and worker scanners hold this same cursor lock for the transaction.
    $connection2=pg_connect('host=/var/run/postgresql dbname=dwemer user=postgres',PGSQL_CONNECT_FORCE_NEW);check($connection2 instanceof \PgSql\Connection,'second peer connection');$pg2=new Pg($connection2);
    $pg2->exec('SET search_path=sas_it,public');$pg->exec('BEGIN');$pg->one('SELECT last_rowid FROM sas_it.sworn_scorned_cursor WHERE playthrough_id=$1 FOR UPDATE',[$pt['id']]);
    $pg2->exec('BEGIN');$pg2->exec("SET LOCAL lock_timeout='100ms'");$blocked=false;
    try{ChimSwornAndScorned\scanOnce($pg2,$pt,ChimSwornAndScorned\loadSettings($pg2),time());}catch(RuntimeException){$blocked=str_contains(pg_last_error($connection2),'lock timeout');}finally{$pg2->exec('ROLLBACK');$pg->exec('ROLLBACK');pg_close($connection2);}
    check($blocked,'second real scanner is serialized by cursor lock');
    // Even at full written retention, a real spoken acknowledgement must still settle its debt.
    $cap=ChimSwornAndScorned\emptyLedger($pt['id']);$base=readEvidenceLine($pg,$earlier,$pt);
    for($i=1;$i<=1024;++$i){$x=$base;$x['event_id']=$i;$x['written']['document_id']=sprintf('%08x-aaaa-4aaa-8aaa-aaaaaaaaaaaa',$i);$x['written']['receipt']['receipt_id']=sprintf('%08x-bbbb-4bbb-8bbb-bbbbbbbbbbbb',$i);$x['written']['receipt']['operation_id']=sprintf('%08x-cccc-4ccc-8ccc-cccccccccccc',$i);$cap['written_sources'][]=ChimSwornAndScorned\writtenSourceEntry($x);}
    $cap['floor_event_id']=1024;$cap['issues']=[sampleIssue(['id'=>'cap-debt','kind'=>'promise','counterpart'=>'npc:900002','direction'=>'owed_to_me','strength'=>'will','status'=>'pending'])];
    $pg->exec("UPDATE sas_it.core_npc_master SET plugin_extended_data=jsonb_set(plugin_extended_data,'{sworn_and_scorned}',$1::jsonb) WHERE id=900001",[json_encode($cap)]);
    $pg->exec('TRUNCATE sas_it.eventlog,sas_it.sworn_scorned_jobs,sas_it.sworn_scorned_marks,sas_it.sworn_scorned_cursor');$pg->one("SELECT setval('sas_it.eventlog_id_seq',2000)");
    $pg->exec('INSERT INTO sas_it.sworn_scorned_cursor(playthrough_id,last_rowid) VALUES($1,2000)',[$pt['id']]);
    $ackId=(int)$pg->one("INSERT INTO sas_it.eventlog(type,data,people,gamets,localts,delivery_state) VALUES('chat','Lydia: You returned all 50 septims. Your money promise is fulfilled. (talking to Farkas)','|Lydia|Farkas|',100,100,'spoken') RETURNING rowid")['rowid'];
    $over=$publish($freshRow(++$ordinal));$pg->exec('BEGIN');ChimSwornAndScorned\scanOnce($pg,$pt,ChimSwornAndScorned\loadSettings($pg),time());$pg->exec('COMMIT');
    $capJob=claimJob($pg,$pt['id']);check($capJob['event_ids']===[$ackId,$over],'capacity fixture exercises actual mixed queue job');
    $deps->setAnswers(['commitment'=>$a(false),'wronged'=>$a(false),'addresses:cap-debt'=>$a(true),'issue:cap-debt.status'=>$a('fulfilled'),'issue:cap-debt.line'=>$a('L1'),'issue:cap-debt.trajectory'=>$a('improving')]);
    check(processJob($capJob,$deps)['reason']==='committed','mixed cap preserves ordinary speech processing');
    $capSaved=$deps->ledgerFor(900001,$pt['id']);check($capSaved['issues'][0]['status']==='fulfilled' && count($capSaved['written_sources'])===1024,'speech settles without adding or evicting written keys');
    check((int)$pg->one("SELECT extended_data->'relationships'->'Farkas'->>'aff' AS a FROM sas_it.core_npc_master WHERE id=900001")['a']===2,'ordinary acknowledgement retains exact qualifying affinity at written capacity');
    $beforeCapCount=(int)$pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history')['n'];
    check(writtenEvidenceStatus($pg,$over)===['status'=>'abstained','reason'=>'written_capacity'] && enqueueWrittenEvidence($pg,$over)===['status'=>'abstained','reason'=>'written_capacity'],'source1025 explicitly abstains');
    check((int)$pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history')['n']===$beforeCapCount && $deps->ledgerFor(900001,$pt['id'])===$capSaved,'overflow has no extra history or ledger mutation');
    echo "written evidence DB pipeline checks passed\n";
}finally{$pg->exec('DROP SCHEMA sas_it CASCADE');putenv('SAS_TEST_SCHEMA');if(isset($oldKeyPath)){if($oldKeyPath===false)putenv('CHIM_SWORN_SCORNED_KEY_FILE');else putenv('CHIM_SWORN_SCORNED_KEY_FILE='.$oldKeyPath);}}
