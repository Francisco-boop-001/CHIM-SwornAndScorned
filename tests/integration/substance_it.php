<?php
declare(strict_types=1);
require __DIR__.'/../support/check.php';
require __DIR__.'/../support/fixtures.php';
require __DIR__.'/../../server/queue.php';
require __DIR__.'/../support/scripted_backend.php';
require __DIR__.'/../../server/page_view.php';
use ChimSwornAndScorned\PerspectiveStore;
use function ChimSwornAndScorned\{openEngineConnection,ensureSchema,saveSetting,currentPlaythrough,lineFromEventRow,pairLinesWitnessedBy,evaluatePerspective,applyProposal,issueId,normalizeLedger,issueSummary,renderNote,renderIssue};
use const ChimSwornAndScorned\POLICY_DECISION_MODEL;
if(getenv('SAS_IT')!=='1'){echo "substance pipeline integration skipped\n";exit(0);}
check(getenv('WSL_DISTRO_NAME')==='DwemerAI4Skyrim3-test','only disposable clone');
putenv('SAS_TEST_SCHEMA=sas_it');
$pg=openEngineConnection('/var/www/html/HerikaServer');
check($pg->one("SELECT current_setting('listen_addresses') AS v")['v']==='','socket-only clone');
check($pg->one("SELECT to_regnamespace('sas_it') AS s")['s']===null,'schema absent');
$pg->exec('CREATE SCHEMA sas_it');
try {
    foreach(['CREATE TABLE sas_it.core_npc_master (LIKE public.core_npc_master INCLUDING ALL)',
        'CREATE TABLE sas_it.core_npc_master_history (LIKE public.core_npc_master_history INCLUDING ALL)',
        'CREATE SEQUENCE sas_it.history_id_seq',"ALTER TABLE sas_it.core_npc_master_history ALTER history_id SET DEFAULT nextval('sas_it.history_id_seq')",
        'CREATE TABLE sas_it.eventlog (LIKE public.eventlog INCLUDING ALL)','CREATE SEQUENCE sas_it.eventlog_id_seq',
        "ALTER TABLE sas_it.eventlog ALTER rowid SET DEFAULT nextval('sas_it.eventlog_id_seq')",'SET search_path=sas_it,public'] as $sql)$pg->exec($sql);
    ensureSchema($pg);$pt=currentPlaythrough($pg);check($pt!==null,'scope exists');
    $template=$pg->one("SELECT id FROM public.core_npc_master WHERE npc_name<>'The Narrator' ORDER BY id LIMIT 1");
    foreach([[900001,'Lydia'],[900002,'Farkas']] as [$id,$name]){
        $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id=$1',[$template['id']]);
        $pg->exec("UPDATE sas_it.core_npc_master SET id=$1,npc_name=$2,lock_profile=0,extended_data='{}',plugin_extended_data='{\"other_plugin\":{\"keep\":true}}' WHERE id=$3",[$id,$name,$template['id']]);
    }
    saveSetting($pg,'enabled',true);saveSetting($pg,'cast',[900001]);$store=new PerspectiveStore($pg);
    $load=static function()use($pg,$pt):array{$raw=$pg->one("SELECT (plugin_extended_data->'sworn_and_scorned')::text AS l FROM sas_it.core_npc_master WHERE id=900001")['l'];return normalizeLedger($raw===null?null:json_decode($raw,true),$pt['id']);};
    $historyCount=static fn():int=>(int)$pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history')['n'];
    $affinity=static fn():int=>(int)($pg->one("SELECT extended_data->'relationships'->'Farkas'->>'aff' AS a FROM sas_it.core_npc_master WHERE id=900001")['a']??0);
    $events=static function(array $speech)use($pg,$pt):array{
        $ids=[];$lines=[];
        foreach($speech as [$speaker,$text]){
            $listener=$speaker==='Lydia'?'Farkas':'Lydia';
            $id=(int)$pg->one("INSERT INTO sas_it.eventlog(type,data,people,gamets,localts,delivery_state) VALUES('chat',$1,'|Lydia|Farkas|',100,100,'spoken') RETURNING rowid",[$speaker.': '.$text.' (talking to '.$listener.')'])['rowid'];
            $row=$pg->one('SELECT rowid,type,data,people,gamets,localts,delivery_state FROM sas_it.eventlog WHERE rowid=$1',[$id]);
            $line=lineFromEventRow($row,$pt['player_name']);check($line!==null,'acknowledged row parsed');$ids[]=$id;$lines[]=$line;
        }
        return [$ids,pairLinesWitnessedBy($lines,'Lydia','Farkas')];
    };
    $run=static function(array $speech,array $answers)use($events,$load,$pt,$store,$pg):array{
        [$ids,$lines]=$events($speech);$ledger=$load();
        $ctx=['owner'=>'Lydia','counterpart'=>'Farkas','lines'=>$lines,'open_issues'=>array_values(array_filter($ledger['issues'],ChimSwornAndScorned\isOpen(...)))];
        $proposal=evaluatePerspective($ctx,new ScriptedBackend($answers),POLICY_DECISION_MODEL);
        $request=['owner_npc_id'=>900001,'owner_name'=>'Lydia','playthrough_id'=>$pt['id'],'event_ids'=>$ids,'counterpart_name'=>'Farkas','counterpart_key'=>'npc:900002','source_lines'=>$lines];
        $meta=['event_ids'=>$ids,'counterpart'=>'npc:900002','backend'=>'synthetic','policy'=>POLICY_DECISION_MODEL];
        $mutate=static fn(array $fresh):array=>applyProposal($fresh,$proposal,$meta);
        check($store->commitLedger($request,$mutate)==='committed','evaluated fixture committed');
        $saved=$load();
        $history=json_decode($pg->one("SELECT (plugin_extended_data->'sworn_and_scorned')::text AS l FROM sas_it.core_npc_master_history WHERE npc_id=900001 ORDER BY history_id DESC LIMIT 1")['l'],true);
        $history=normalizeLedger($history,$pt['id']);
        check($saved===$history,'ledger and history retain exact normalized representation');
        return [$saved,$ids,$request,$mutate,$proposal];
    };
    $answer=static fn(mixed $value):array=>['value'=>$value,'p'=>0.99];
    $commitment=['commitment'=>$answer(true),'wronged'=>$answer(false),'commitment.who'=>$answer('Farkas'),'commitment.strength'=>$answer('will'),'commitment.acceptance'=>$answer('accepted'),'commitment.line'=>$answer('L1'),'commitment.matter'=>$answer('L1'),'commitment.settlement'=>$answer('unknown')];
    [$ledger,$ids]=$run([['Farkas','I promise I will repay your 50 septims tomorrow.'],['Lydia','I accept your promise.']],$commitment);
    $money=issueId('e:'.$ids[0],'npc:900002','promise');
    $expected=['version'=>1,'nature'=>'obligation','matter'=>['ref'=>'e:'.$ids[0],'speaker'=>'Farkas','text'=>'I promise I will repay your 50 septims tomorrow.'],'settlement'=>['mode'=>'complete_obligation','source'=>null]];
    $expected['context']=[$expected['matter'],['ref'=>'e:'.$ids[1],'speaker'=>'Lydia','text'=>'I accept your promise.']];
    check(count($ledger['issues'])===1 && issueById($ledger,$money)['substance']===$expected,'exact agreed debt amount/deadline/source stored');
    check(issueById($ledger,$money)['direction']==='owed_to_me' && issueById($ledger,$money)['counterpart']==='npc:900002','exact debtor and creditor perspective');check($affinity()===0,'new obligation adds no affinity');
    [$ledger,$ids]=$run([['Farkas','I promise I will bring you the steel shield tomorrow.'],['Lydia','I accept your promise.']],$commitment);
    $shield=issueId('e:'.$ids[0],'npc:900002','promise');$shieldSubstance=issueById($ledger,$shield)['substance'];
    $updates=static function(string $id,string $status)use($answer):array{return ['commitment'=>$answer(false),'wronged'=>$answer(false),'addresses:'.$id=>$answer(true),'issue:'.$id.'.status'=>$answer($status),'issue:'.$id.'.trajectory'=>$answer($status==='no_change'?'unchanged':'improving'),'issue:'.$id.'.line'=>$answer('L1')];};
    [$ledger]=$run([['Lydia','I received 20 of the 50 septims. The other 30 are still owed.']],$updates($money,'no_change'));
    check(issueById($ledger,$money)['status']==='pending' && issueById($ledger,$money)['substance']===$expected,'partial repayment preserves full original debt');check($affinity()===0,'partial repayment grants no fulfilment affinity');
    $note=renderNote(issueById($ledger,$money),'Lydia','Farkas');check(str_contains($note,'50 septims tomorrow')&&!str_contains($note,'other 30'),'reminder uses pinned terms not latest partial quote');
    check(str_contains(issueSummary(issueById($ledger,$money),'Lydia','Farkas'),'50 septims tomorrow'),'evaluator summary preserves debt');
    check(str_contains(renderIssue(issueById($ledger,$money),900001,'csrf',true),'50 septims tomorrow'),'journal preserves debt');
    [$ledger,$ids,$request,$mutate]=$run([['Lydia','You paid all 50 septims you owed. That money promise is fulfilled.']],$updates($money,'fulfilled'));
    check(issueById($ledger,$money)['status']==='fulfilled' && issueById($ledger,$shield)['status']==='pending','only matching obligation settles');
    check(issueById($ledger,$shield)['substance']===$shieldSubstance && $affinity()===2,'unrelated terms unchanged and exact +2 once');
    $count=$historyCount();check($store->commitLedger($request,$mutate)==='replay','same evidence replay rejected');check($historyCount()===$count&&$affinity()===2,'replay adds neither history nor affinity');
    $theftText='You stole my purse with all 50 coins. That wronged me. Return them to me.';
    [$ledger,$ids]=$run([['Lydia',$theftText]],['commitment'=>$answer(false),'wronged'=>$answer(true),'grievance.line'=>$answer('L1'),'grievance.wants'=>$answer('restitution'),'grievance.nature'=>$answer('theft'),'grievance.matter'=>$answer('L1'),'grievance.settlement'=>$answer('L1')]);
    $theft=issueId('e:'.$ids[0],'npc:900002','grievance');$source=['ref'=>'e:'.$ids[0],'speaker'=>'Lydia','text'=>$theftText];
    check(issueById($ledger,$theft)['substance']===['version'=>1,'nature'=>'theft','matter'=>$source,'settlement'=>['mode'=>'explicit','source'=>$source],'context'=>[$source]],'exact wrong and stated restitution stored');
    [$ledger]=$run([['Farkas','Sorry I was late today.']],['commitment'=>$answer(false),'wronged'=>$answer(false)]);
    check(issueById($ledger,$theft)['status']==='open'&&$affinity()===2,'unrelated apology cannot settle theft');
    $resolve=$updates($theft,'resolved');$resolve['issue:'.$theft.'.wants']=$answer('unknown');
    [$ledger]=$run([['Lydia','You returned my purse and all 50 coins. I accept that restitution; the theft is settled.']],$resolve);
    check(issueById($ledger,$theft)['status']==='resolved'&&$affinity()===4,'matching restitution settles with +2 once');
    $oldShield=$shieldSubstance;
    $renewal=$commitment+$updates($shield,'renegotiated');
    $renewal['commitment.related']=$answer($shield);
    $renewal['issue:'.$shield.'.line']=['value'=>'L2','p'=>0.79];
    [$ledger,$ids]=$run([['Farkas','Instead of the steel shield tomorrow, I promise I will bring you a silver ring on Sunday.'],['Lydia','I accept that replacement agreement.']],$renewal);
    $successor=issueId('e:'.$ids[0],'npc:900002','promise');
    $replacement=['version'=>1,'nature'=>'obligation','matter'=>['ref'=>'e:'.$ids[0],'speaker'=>'Farkas','text'=>'Instead of the steel shield tomorrow, I promise I will bring you a silver ring on Sunday.'],'settlement'=>['mode'=>'complete_obligation','source'=>null]];
    $replacement['context']=[$replacement['matter'],['ref'=>'e:'.$ids[1],'speaker'=>'Lydia','text'=>'I accept that replacement agreement.']];
    check($successor!==$shield && issueById($ledger,$successor)['substance']===$replacement,'replacement has distinct identity and exact new terms');
    check(issueById($ledger,$shield)['status']==='released' && issueById($ledger,$shield)['substance']===$oldShield,'superseded obligation is released without rewriting original terms');
    check(issueById($ledger,$shield)['evidence'][array_key_last(issueById($ledger,$shield)['evidence'])]['ref']==='e:'.$ids[0],'renewal uses independently verified concrete commitment source');
    check(issueById($ledger,$shield)['links']===[$successor] && issueById($ledger,$successor)['links']===[$shield],'only the identified predecessor and successor link reciprocally');
    check(issueById($ledger,$successor)['status']==='pending' && $affinity()===4,'replacement pending without affinity change');
    [$ledger]=$run([['Lydia','You brought the silver ring on Sunday as agreed. The replacement promise is fulfilled.']],$updates($successor,'fulfilled'));
    check(issueById($ledger,$successor)['status']==='fulfilled' && issueById($ledger,$shield)['status']==='released' && $affinity()===6,'only replacement fulfils after renegotiation');
    [$legacyIds,$legacyLines]=$events([['Farkas','I will stand beside you.']]);
    $legacyProposal=['new_issues'=>[['kind'=>'promise','direction'=>'owed_to_me','strength'=>'will','wants'=>'keep_promise','line'=>$legacyLines[0],'label'=>'commitment','p'=>0.99]],'updates'=>[]];
    $legacyRequest=['owner_npc_id'=>900001,'owner_name'=>'Lydia','playthrough_id'=>$pt['id'],'event_ids'=>$legacyIds,'counterpart_name'=>'Farkas','counterpart_key'=>'npc:900002','source_lines'=>$legacyLines];
    $legacyMeta=['event_ids'=>$legacyIds,'counterpart'=>'npc:900002','backend'=>'legacy-fixture','policy'=>POLICY_DECISION_MODEL];
    check($store->commitLedger($legacyRequest,static fn(array $fresh):array=>applyProposal($fresh,$legacyProposal,$legacyMeta))==='committed','old-shape record persisted through normal writer');
    $legacyId=issueId('e:'.$legacyIds[0],'npc:900002','promise');$ledger=$load();$legacy=issueById($ledger,$legacyId);
    check(!array_key_exists('substance',$legacy) && str_contains(renderIssue($legacy,900001,'csrf',false),'unknown'),'legacy remains readable with unknown terms and no invented backfill');
    $readHistory=$historyCount();$load();check($historyCount()===$readHistory && $affinity()===6,'reading legacy data makes no history or affinity changes');
    $splitAnswers=$commitment;$splitAnswers['commitment.line']=$answer('L3');$splitAnswers['commitment.matter']=$answer('L1');
    $splitSpeech=[['Lydia','Will you return my 37 septims?'],['Lydia','Can you do it on Friday?'],['Farkas','I promise I will do exactly that.'],['Lydia','I accept those terms.']];
    [$ledger,$ids]=$run($splitSpeech,$splitAnswers);
    $splitId=issueId('e:'.$ids[2],'npc:900002','promise');
    $splitContext=[];foreach($splitSpeech as $i=>[$speaker,$text])$splitContext[]=['ref'=>'e:'.$ids[$i],'speaker'=>$speaker,'text'=>$text];
    $splitSubstance=['version'=>1,'nature'=>'obligation','matter'=>$splitContext[0],'settlement'=>['mode'=>'complete_obligation','source'=>null],'context'=>$splitContext];
    check(issueById($ledger,$splitId)['substance']===$splitSubstance && issueById($ledger,$splitId)['direction']==='owed_to_me','split amount/deadline/acceptance retained exactly with parties and each source');
    check(str_contains(issueSummary(issueById($ledger,$splitId),'Lydia','Farkas'),'Friday') && str_contains(renderIssue(issueById($ledger,$splitId),900001,'csrf',true),'Friday'),'later evaluator and journal retain split deadline');
    $splitNote=renderNote(issueById($ledger,$splitId),'Lydia','Farkas');check(str_contains($splitNote,'37 septims') && str_contains($splitNote,'Friday') && mb_strlen($splitNote)<=240,'bounded reminder retains short split agreement');
    [$ledger]=$run([['Lydia','I received 12 of the 37 septims. The remaining 25 are still owed.']],$updates($splitId,'no_change'));
    check(issueById($ledger,$splitId)['status']==='pending' && issueById($ledger,$splitId)['substance']===$splitSubstance && $affinity()===6,'split debt remains outstanding and immutable after partial payment');
    [$ledger]=$run([['Lydia','You returned all 37 septims on Friday. The money promise is fulfilled.']],$updates($splitId,'fulfilled'));
    check(issueById($ledger,$splitId)['status']==='fulfilled' && $affinity()===8,'split agreement settles only on complete matching evidence');
    $other=json_decode($pg->one("SELECT (plugin_extended_data->'other_plugin')::text AS p FROM sas_it.core_npc_master WHERE id=900001")['p'],true);check($other===['keep'=>true],'unrelated plugin state preserved');
    echo json_encode(['persisted_pipeline'=>true,'exact_substance_history'=>true,'two_obligations'=>true,'partial_not_fulfilled'=>true,'specific_settlement'=>true,'replay_no_duplicate_effect'=>true,'renegotiation_identity'=>true,'legacy_read_only'=>true,'split_terms'=>true,'affinity_total'=>8])."\n";
}finally{$pg->exec('DROP SCHEMA sas_it CASCADE');putenv('SAS_TEST_SCHEMA');}
