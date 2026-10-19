<?php
declare(strict_types=1);
require __DIR__.'/support/check.php';
require __DIR__.'/support/fixtures.php';
require __DIR__.'/support/written_fixture.php';
require __DIR__.'/../server/queue.php';
require __DIR__.'/support/scripted_backend.php';
require __DIR__.'/../server/page_view.php';
use function ChimSwornAndScorned\{writtenLineFromEventRow,pairLinesWitnessedBy,perspectivesOf,buildState,evaluatePerspective,applyProposal,emptyLedger,normalizeLedger,renderNote,renderIssue,issueSummary};
use const ChimSwornAndScorned\POLICY_DECISION_MODEL;
$pt=['id'=>'unprofiled','player_name'=>'Hawke'];
$line=writtenLineFromEventRow(writtenFixture(),$pt);
check($line!==null,'written fixture parsed');
check(pairLinesWitnessedBy([$line],'Lydia','Farkas')===[$line],'recipient can use written evidence without physical sender');
check(pairLinesWitnessedBy([$line],'Farkas','Lydia')===[],'claimed sender acquires no knowledge');
check(perspectivesOf($line,['Lydia'=>900001,'Farkas'=>900002])===[['Lydia','Farkas']],'recipient only even with sender selected');
$ctx=['owner'=>'Lydia','counterpart'=>'Farkas','lines'=>[$line],'open_issues'=>[]];
$state=buildState($ctx);
check(str_contains($state['lines']['L1'],'claimed sender') && str_contains(json_encode($state),'receipt_id'),'model sees modality and source receipt, not fabricated speech');
$a=static fn(mixed $v):array=>['value'=>$v,'p'=>0.99];
$wrong=['commitment'=>$a(false),'wronged'=>$a(true),'written.attribution'=>$a(true),'grievance.line'=>$a('L1'),'grievance.wants'=>$a('unknown'),'grievance.matter'=>$a('L1'),'grievance.nature'=>$a('insult'),'grievance.settlement'=>$a('unknown')];
$proposal=evaluatePerspective($ctx,new ScriptedBackend($wrong),POLICY_DECISION_MODEL);
check(count($proposal['new_issues'])===1,'direct hostile letter can establish recipient grievance when attribution supported');
$sub=$proposal['new_issues'][0]['substance'];
check($sub['matter']['text']===$line['text'] && $sub['matter']['written']===$line['written'],'exact body/claimed provenance attached to matter');
$uncertain=$wrong;$uncertain['written.attribution']=$a(false);
check(evaluatePerspective($ctx,new ScriptedBackend($uncertain),POLICY_DECISION_MODEL)['new_issues']===[],'doubted attribution cannot blame claimed sender');
$promise=['commitment'=>$a(true),'wronged'=>$a(false),'written.attribution'=>$a(true),'commitment.who'=>$a('Farkas'),'commitment.strength'=>$a('will'),'commitment.acceptance'=>$a('accepted'),'commitment.line'=>$a('L1'),'commitment.matter'=>$a('L1'),'commitment.settlement'=>$a('unknown')];
check(evaluatePerspective($ctx,new ScriptedBackend($promise),POLICY_DECISION_MODEL)['new_issues']===[],'body claim alone cannot invent recipient acceptance despite model answer');
$open=sampleIssue(['id'=>'debt','counterpart'=>'npc:900002','kind'=>'promise','direction'=>'owed_to_me','status'=>'pending']);
$ctx['open_issues']=[$open];
$updates=['commitment'=>$a(false),'wronged'=>$a(false),'written.attribution'=>$a(true),'addresses:debt'=>$a(true),'issue:debt.status'=>$a('fulfilled'),'issue:debt.line'=>$a('L1'),'issue:debt.trajectory'=>$a('improving')];
$p=evaluatePerspective($ctx,new ScriptedBackend($updates),POLICY_DECISION_MODEL);
check(($p['updates'][0]['status']??null)!=='fulfilled','letter claim cannot settle debt on behalf of absent/unknown actual payer');
$meta=['event_ids'=>[41],'counterpart'=>'npc:900002','backend'=>'scripted','policy'=>POLICY_DECISION_MODEL,'source_lines'=>[$line]];
$result=applyProposal(emptyLedger('unprofiled'),$proposal,$meta);
$ledger=normalizeLedger($result['ledger'],'unprofiled');$issue=$ledger['issues'][0];
check($result['affinity_delta']===0 && $issue['evidence'][0]['written']===$line['written'],'written origin preserved with zero creation affinity');
check(str_contains(issueSummary($issue,'Lydia','Farkas'),'claimed sender'),'later decisions retain authorship uncertainty');
check(str_contains(renderIssue($issue,900001,'csrf',true),'Claimed sender'),'journal does not report physical speech or confirmed authorship');
$note=renderNote($issue,'Lydia','Farkas');check(str_contains($note,'letter') && mb_strlen($note)<=240,'bounded reminder carries written attribution');
check(applyProposal($ledger,$proposal,$meta)['status']==='replay','same written source is replay');
$alternate=$line;$alternate['event_id']=42;$m=$meta;$m['event_ids']=[42];$m['source_lines']=[$alternate];
check(applyProposal($ledger,['new_issues'=>[],'updates'=>[]],$m)['status']==='written_source_changed','alternate row cannot bypass stable document replay');
// Source-kind replay survives issue pruning, processed-floor rollover and history restoration.
$pruned=$ledger;$pruned['issues']=[];$pruned['floor_event_id']=10000;$pruned['processed']=[];
check(ChimSwornAndScorned\writtenSourceState($pruned,[$line])==='replay','stable document replay survives row floor and closed issue pruning');
$restored=normalizeLedger(json_decode(json_encode($ledger),true),'unprofiled');
check(ChimSwornAndScorned\writtenSourceState($restored,[$line])==='replay','restored history retains source identity');
foreach(['receipt_id','operation_id'] as $key){
    $reuse=$alternate;$reuse['written']['document_id']='11111111-1111-4111-8111-111111111111';
    $reuse['written']['receipt'][$key==='receipt_id'?'operation_id':'receipt_id']='22222222-2222-4222-8222-222222222222';
    check(ChimSwornAndScorned\writtenSourceState($ledger,[$reuse])==='written_source_changed','independent '.$key.' replay is blocked');
}
$full=emptyLedger('unprofiled');
for($i=1;$i<=1024;++$i){
    $x=$line;$x['event_id']=100+$i;
    foreach(['document_id','receipt_id','operation_id'] as $k){$id=sprintf('%08x-1111-4111-8111-111111111111',$i);if($k==='document_id')$x['written'][$k]=$id;else $x['written']['receipt'][$k]=$id;}
    $full['written_sources'][]=ChimSwornAndScorned\writtenSourceEntry($x);
}
$full=normalizeLedger($full,'unprofiled');
check(ChimSwornAndScorned\writtenSourceState($full,[$line])==='written_capacity','1025th source abstains without replay-key eviction');
$r=applyProposal($full,['new_issues'=>[],'updates'=>[]],$meta);
check($r['status']==='written_capacity' && $r['ledger']===$full && $r['affinity_delta']===0,'capacity changes no ledger or affinity');
// Attachment receipt is a fact about arrival, not proof that the signature paid the debt.
$attached=$line;$attached['written']['attachments']=[['kind'=>'gold','form_id'=>'Skyrim.esm|0000000F','quantity'=>50]];
$ctx['lines']=[$attached];
check(evaluatePerspective($ctx,new ScriptedBackend($updates),POLICY_DECISION_MODEL)['updates']===[],'confirmed received gift cannot create an issue update from a written payment claim');
$grievance=sampleIssue(['id'=>'debt','counterpart'=>'npc:900002']);$ctx['open_issues']=[$grievance];
foreach(['resolved','let_go'] as $status){$u=$updates;$u['issue:debt.status']=$a($status);$u['issue:debt.wants']=$a('unknown');$p=evaluatePerspective($ctx,new ScriptedBackend($u),POLICY_DECISION_MODEL);check($p['updates']===[],'counterpart cannot '.$status.' recipient grievance in a letter');}
$credit=sampleIssue(['id'=>'debt','kind'=>'promise','counterpart'=>'npc:900002','direction'=>'owed_by_me','strength'=>'will','status'=>'pending']);$ctx['open_issues']=[$credit];$u=$updates;$u['issue:debt.status']=$a('released');
$creditLine=writtenLineFromEventRow(writtenFixture(41,'I release you from the 50 septims you owe me. You owe me no more on that debt.'),$pt);$ctx['lines']=[$creditLine];
$p=evaluatePerspective($ctx,new ScriptedBackend($u),POLICY_DECISION_MODEL);check($p['updates'][0]['status']==='released','attributed creditor may release recipient debt');
$l=emptyLedger('unprofiled');$l['issues']=[$credit];$creditMeta=$meta;$creditMeta['source_lines']=[$creditLine];$r=applyProposal($l,$p,$creditMeta);
check($r['ledger']['issues'][0]['status']==='released' && $r['affinity_delta']===0,'creditor release changes lifecycle without affinity');
$u['written.attribution']=$a(false);check(evaluatePerspective($ctx,new ScriptedBackend($u),POLICY_DECISION_MODEL)['updates']===[],'doubted creditor cannot release debt');
// Complete origin limit applies to exact body, not truncated typed candidates.
foreach([640,641] as $n){
    $bounded=$line;$bounded['text']=str_repeat('x',$n);$bounded['written']['body']=$bounded['text'];
    $c=['owner'=>'Lydia','counterpart'=>'Farkas','lines'=>[$bounded],'open_issues'=>[]];
    $p=evaluatePerspective($c,new ScriptedBackend($wrong),POLICY_DECISION_MODEL);
    check(count($p['new_issues'])===($n===640?1:0),'exact '.$n.' codepoint substance boundary');
    check(ChimSwornAndScorned\writtenSourceEntry($bounded)['result']===($n===640?'evaluated':'substance_budget'),'creation-budget abstention is distinct');
}
// Spoken recipient acceptance can support an attributed written proposal; the sender stays absent.
$accept=sampleLine(42,'Lydia','I accept your promise to return my 50 septims tomorrow.');
$offered=writtenLineFromEventRow(writtenFixture(41,'I promise I will return your 50 septims tomorrow. Delivering your steel shield instead will also settle this agreement.'),$pt);
$c=['owner'=>'Lydia','counterpart'=>'Farkas','lines'=>[$offered,$accept],'open_issues'=>[]];
$accepted=$promise;$accepted['commitment.settlement']=$a('L1');
$p=evaluatePerspective($c,new ScriptedBackend($accepted),POLICY_DECISION_MODEL);
check(count($p['new_issues'])===1 && $p['new_issues'][0]['substance']['context'][0]['written']===$offered['written'],'actual recipient reply supports accepted written obligation with full provenance');
$m=$meta;$m['event_ids']=[41,42];$m['source_lines']=[$offered,$accept];
$r=applyProposal(emptyLedger('unprofiled'),$p,$m);$l=normalizeLedger(json_decode(json_encode($r['ledger']),true),'unprofiled');$original=$l['issues'][0]['substance'];
check($original['settlement']['mode']==='explicit' && $original['settlement']['source']['written']===$offered['written'] && $original['context'][0]['written']===$offered['written'],'exact written context and explicit alternative survive storage roundtrip');
$target=$l['issues'][0]['id'];$ack=sampleLine(43,'Lydia','You returned all 50 septims as promised. That money promise is fulfilled.');
$u=['commitment'=>$a(false),'wronged'=>$a(false),'addresses:'.$target=>$a(true),'issue:'.$target.'.status'=>$a('fulfilled'),'issue:'.$target.'.line'=>$a('L1'),'issue:'.$target.'.trajectory'=>$a('improving')];
$c=['owner'=>'Lydia','counterpart'=>'Farkas','lines'=>[$ack],'open_issues'=>$l['issues']];$p=evaluatePerspective($c,new ScriptedBackend($u),POLICY_DECISION_MODEL);
$m=['event_ids'=>[43],'counterpart'=>'npc:900002','backend'=>'scripted','policy'=>POLICY_DECISION_MODEL];$r=applyProposal($l,$p,$m);
check($r['ledger']['issues'][0]['status']==='fulfilled' && $r['affinity_delta']===2 && $r['ledger']['issues'][0]['substance']===$original,'later actual recipient acknowledgement settles once and preserves exact written terms');
$allegation=writtenLineFromEventRow(writtenFixture(44,'Faendal stole your purse. I heard it from someone.'),$pt);
$c=['owner'=>'Lydia','counterpart'=>'Farkas','lines'=>[$allegation],'open_issues'=>[]];
$negative=['commitment'=>$a(false),'wronged'=>$a(false),'written.attribution'=>$a(true)];
check(evaluatePerspective($c,new ScriptedBackend($negative),POLICY_DECISION_MODEL)['new_issues']===[],'third-party allegation is usable prose, not an automatic grievance');
check(perspectivesOf($allegation,['Lydia'=>900001,'Farkas'=>900002,'Faendal'=>900003])===[['Lydia','Farkas']],'mentioned third party acquires no perspective or physical witness');
$longOrigin=[$line];for($i=42;$i<=49;++$i)$longOrigin[]=sampleLine($i,'Lydia','I need to consider this.');
$c=['owner'=>'Lydia','counterpart'=>'Farkas','lines'=>$longOrigin,'open_issues'=>[]];$p=evaluatePerspective($c,new ScriptedBackend($wrong),POLICY_DECISION_MODEL);
$m=$meta;$m['event_ids']=array_column($longOrigin,'event_id');$m['source_lines']=$longOrigin;
$r=applyProposal(emptyLedger('unprofiled'),$p,$m);
check($r['ledger']['issues']===[] && $r['ledger']['written_sources'][0]['result']==='substance_budget','nine-line origin reports creation abstention even with a short letter');
check(ChimSwornAndScorned\writtenSourceState(normalizeLedger($r['ledger'],'unprofiled'),[$line])==='replay','informational budget result does not redefine immutable source identity');
echo "written flow checks passed\n";
