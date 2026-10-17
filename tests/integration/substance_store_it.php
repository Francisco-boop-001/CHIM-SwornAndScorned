<?php
declare(strict_types=1);
require __DIR__.'/../support/check.php';
require __DIR__.'/../support/fixtures.php';
require __DIR__.'/../../server/store.php';
require_once __DIR__.'/../../server/rows.php';
use ChimSwornAndScorned\PerspectiveStore;
use function ChimSwornAndScorned\{openEngineConnection,ensureSchema,saveSetting,currentPlaythrough};
if (getenv('SAS_IT')!=='1') { echo "substance store integration skipped\n"; exit(0); }
check(getenv('WSL_DISTRO_NAME')==='DwemerAI4Skyrim3-test','only disposable clone');
putenv('SAS_TEST_SCHEMA=sas_it');
$pg=openEngineConnection('/var/www/html/HerikaServer');
check($pg->one("SELECT current_setting('listen_addresses') AS value")['value']==='','socket-only database');
check($pg->one("SELECT to_regnamespace('sas_it') AS s")['s']===null,'fixture schema absent');
$pg->exec('CREATE SCHEMA sas_it');
try {
    foreach (['CREATE TABLE sas_it.core_npc_master (LIKE public.core_npc_master INCLUDING ALL)',
        'CREATE TABLE sas_it.core_npc_master_history (LIKE public.core_npc_master_history INCLUDING ALL)',
        'CREATE SEQUENCE sas_it.history_id_seq',"ALTER TABLE sas_it.core_npc_master_history ALTER history_id SET DEFAULT nextval('sas_it.history_id_seq')",
        'CREATE TABLE sas_it.eventlog (LIKE public.eventlog INCLUDING ALL)','CREATE SEQUENCE sas_it.eventlog_id_seq',
        "ALTER TABLE sas_it.eventlog ALTER rowid SET DEFAULT nextval('sas_it.eventlog_id_seq')",'SET search_path=sas_it,public'] as $sql) $pg->exec($sql);
    ensureSchema($pg);$pt=currentPlaythrough($pg);check($pt!==null,'clone scope');
    $template=$pg->one("SELECT id FROM public.core_npc_master WHERE npc_name<>'The Narrator' ORDER BY id LIMIT 1");
    $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id=$1',[$template['id']]);
    $pg->exec("UPDATE sas_it.core_npc_master SET id=900001,npc_name='Lydia',lock_profile=0,extended_data='{}',plugin_extended_data='{}'");
    $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id=$1',[$template['id']]);
    $pg->exec("UPDATE sas_it.core_npc_master SET id=900002,npc_name='Farkas',lock_profile=0,extended_data='{}',plugin_extended_data='{}' WHERE id<>900001");
    saveSetting($pg,'enabled',true);saveSetting($pg,'cast',[900001]);
    $event=(int)$pg->one("INSERT INTO sas_it.eventlog(type,data,people,gamets,localts,delivery_state) VALUES('chat','Farkas: I promise to repay 50 septims tomorrow. (talking to Lydia)','|Lydia|Farkas|',100,100,'spoken') RETURNING rowid")['rowid'];
    $request=['owner_npc_id'=>900001,'owner_name'=>'Lydia','playthrough_id'=>$pt['id'],'event_ids'=>[$event],'counterpart_name'=>'Farkas','counterpart_key'=>'npc:900002'];
    $store=new PerspectiveStore($pg);
    $substance=['version'=>1,'nature'=>'obligation','matter'=>['ref'=>'e:'.$event,'speaker'=>'Farkas','text'=>'I promise to repay 50 septims tomorrow.'],
        'settlement'=>['mode'=>'complete_obligation','source'=>null]];
    $mutate=static function (array $ledger) use ($event,$substance):array {
        $ledger['issues'][]=sampleIssue(['id'=>'aabbccddeeff0011','kind'=>'promise','direction'=>'owed_to_me','strength'=>'will','status'=>'pending','counterpart'=>'npc:900002','substance'=>$substance]);
        $ledger['processed']=[$event];return ['status'=>'applied','ledger'=>$ledger,'affinity_delta'=>0];
    };
    $pg->exec("UPDATE sas_it.eventlog SET data='Farkas: I never agreed to that. (talking to Lydia)' WHERE rowid=$1",[$event]);
    check($store->commitLedger($request,$mutate)==='events_missing','fresh changed source rejects richer record');
    check($pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history')['n']==='0','rejected source has no history');
    check($pg->one("SELECT plugin_extended_data::text AS p FROM sas_it.core_npc_master WHERE id=900001")['p']==='{}','rejected source leaves ledger untouched');
    $pg->exec("UPDATE sas_it.eventlog SET data='Farkas: I promise to repay 50 septims tomorrow. (talking to Lydia)',delivery_state='pending' WHERE rowid=$1",[$event]);
    check($store->commitLedger($request,$mutate)==='events_missing','undelivered source rejects richer record');
    $pg->exec("UPDATE sas_it.eventlog SET delivery_state='spoken' WHERE rowid=$1",[$event]);
    check($store->commitLedger($request,$mutate)==='committed','unchanged acknowledged source commits');
    $saved=json_decode($pg->one("SELECT (plugin_extended_data->'sworn_and_scorned')::text AS l FROM sas_it.core_npc_master WHERE id=900001")['l'],true);
    $saved=ChimSwornAndScorned\normalizeLedger($saved,$pt['id']);
    check($saved['issues'][0]['substance']===$substance,'stored exact substance');
    $ackText='I received all 50 septims. The debt is paid.';
    $ack=(int)$pg->one("INSERT INTO sas_it.eventlog(type,data,people,gamets,localts,delivery_state) VALUES('chat',$1,'|Lydia|Farkas|',101,101,'spoken') RETURNING rowid",['Lydia: '.$ackText.' (talking to Farkas)'])['rowid'];
    $ackRow=$pg->one('SELECT rowid,type,data,people,gamets,localts,delivery_state FROM sas_it.eventlog WHERE rowid=$1',[$ack]);
    $ackLine=ChimSwornAndScorned\lineFromEventRow($ackRow,$pt['player_name']);
    $updateRequest=['event_ids'=>[$ack],'source_lines'=>[$ackLine]]+$request;
    $settle=static function(array $ledger):array{$ledger['issues'][0]['status']='fulfilled';return ['status'=>'applied','ledger'=>$ledger,'affinity_delta'=>2];};
    $pg->exec("UPDATE sas_it.eventlog SET data='Lydia: That payment never happened. (talking to Farkas)' WHERE rowid=$1",[$ack]);
    check($store->commitLedger($updateRequest,$settle)==='events_missing','fresh source edit rejects old rich issue settlement');
    $pg->exec("UPDATE sas_it.eventlog SET data=$1,delivery_state='pending' WHERE rowid=$2",['Lydia: '.$ackText.' (talking to Farkas)',$ack]);
    check($store->commitLedger($updateRequest,$settle)==='events_missing','undelivered acknowledgement cannot settle old rich issue');
    $originalRow=$pg->one('SELECT rowid,type,data,people,gamets,localts,delivery_state FROM sas_it.eventlog WHERE rowid=$1',[$event]);
    $originalLine=ChimSwornAndScorned\lineFromEventRow($originalRow,$pt['player_name']);
    $duplicateRequest=['event_ids'=>[$event,$ack],'source_lines'=>[$originalLine,$originalLine]]+$request;
    check($store->commitLedger($duplicateRequest,$settle)==='events_missing','duplicate source IDs cannot hide an unacknowledged settlement event');
    check($pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history')['n']==='1','failed old-issue settlements add no history');
    check($pg->one("SELECT extended_data::text AS e FROM sas_it.core_npc_master WHERE id=900001")['e']==='{}','failed old-issue settlements add no affinity');
    echo "substance store provenance checks passed\n";
} finally { $pg->exec('DROP SCHEMA sas_it CASCADE');putenv('SAS_TEST_SCHEMA'); }
