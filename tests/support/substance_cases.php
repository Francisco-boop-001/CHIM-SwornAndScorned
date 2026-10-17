<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures.php';
function substancePromise(string $id,string $text):array {
    $ref='e:'.(int)substr($id,-1);
    return sampleIssue(['evidence'=>[['ref'=>$ref,'gamets'=>1.0,'speaker'=>'Farkas','quote'=>$text,'label'=>'commitment','p'=>0.99,'backend'=>'synthetic']],'id'=>$id,'kind'=>'promise','direction'=>'owed_to_me','strength'=>'will','status'=>'pending','wants'=>'keep_promise',
        'substance'=>['version'=>1,'nature'=>'obligation','matter'=>['ref'=>$ref,'speaker'=>'Farkas','text'=>$text],'settlement'=>['mode'=>'complete_obligation','source'=>null]]]);
}
function substanceGrievance():array {
    return sampleIssue(['evidence'=>[['ref'=>'e:1','gamets'=>1.0,'speaker'=>'Lydia','quote'=>'You stole my purse and all 50 coins.','label'=>'wronged','p'=>0.99,'backend'=>'synthetic']],'wants'=>'restitution','substance'=>['version'=>1,'nature'=>'theft',
        'matter'=>['ref'=>'e:1','speaker'=>'Lydia','text'=>'You stole my purse and all 50 coins.'],
        'settlement'=>['mode'=>'explicit','source'=>['ref'=>'e:2','speaker'=>'Lydia','text'=>'Return my purse with all 50 coins.']]]]);
}
/** Expectations are declared before any model run; these are fictional utterances. */
function substanceCases(string $set):array {
    $money=substancePromise('abc123abc123abc1','I promise to repay your 50 septims tomorrow.');
    $shield=substancePromise('abc123abc123abc2','I promise to bring you the steel shield tomorrow.');
    $new=static fn(string $kind,string $nature,int $matter,string $mode,?int $settlement=null,?string $direction=null):array =>
        ['kind'=>$kind,'nature'=>$nature,'matter_index'=>$matter,'settlement_mode'=>$mode,'settlement_index'=>$settlement,'direction'=>$direction];
    $dev=[
        'agreed_loan'=>['speech'=>[['Farkas','I promise I will repay your 50 septims tomorrow.'],['Lydia','I accept your promise.']], 'new'=>[$new('promise','obligation',0,'complete_obligation',null,'owed_to_me')]],
        'unaccepted_offer'=>['speech'=>[['Farkas','I will give you 50 septims tomorrow.'],['Lydia','No, I decline your offer.']], 'new'=>[]],
        'affectionate_insult'=>['speech'=>[['Lydia','You charming bastard, that was brilliant! I love you.']], 'new'=>[]],
        'theft_with_remedy'=>['speech'=>[['Lydia','You stole my purse and all 50 coins. I feel wronged. Return them to me.']], 'new'=>[$new('grievance','theft',0,'explicit',0)]],
        'hurt_unknown_remedy'=>['speech'=>[['Lydia','You humiliated me before Aela. That really hurt me. I do not know what I want you to do about it.']], 'new'=>[$new('grievance','insult',0,'unknown')]],
        'partial_is_not_full'=>['open'=>[$money],'speech'=>[['Lydia','I received 20 of the 50 septims you owe me. The other 30 are still owed.']], 'new'=>[], 'updates'=>['abc123abc123abc1'=>'no_change']],
        'bread_not_payment'=>['open'=>[$money],'speech'=>[['Farkas','Here is some bread.'],['Lydia','Thank you for the bread.']], 'new'=>[], 'updates'=>[]],
        'complete_one_of_two'=>['open'=>[$money,$shield],'speech'=>[['Lydia','I received all 50 septims you owed. The money debt is fully paid; thank you.']], 'new'=>[], 'updates'=>['abc123abc123abc1'=>'fulfilled']],
        'vague_apology'=>['open'=>[substanceGrievance()],'speech'=>[['Farkas','Sorry I was late today.']], 'new'=>[], 'updates'=>[]],
        'specific_restitution'=>['open'=>[substanceGrievance()],'speech'=>[['Lydia','You returned my purse with all 50 coins. I accept that restitution; the theft is settled.']], 'new'=>[], 'updates'=>['abc123abc123abc1'=>'resolved']],
        'changed_terms'=>['open'=>[$money,$shield],'speech'=>[['Farkas','Instead of the original 50-septim debt, I promise to repay 30 septims on Sunday.'],['Lydia','I accept that replacement of the original money agreement.']],
            'new'=>[$new('promise','obligation',0,'complete_obligation',null,'owed_to_me')], 'updates'=>['abc123abc123abc1'=>'renegotiated'],'related'=>'abc123abc123abc1'],
    ];
    $cloak=substancePromise('abc123abc123abc3','I promise to return your blue cloak on Friday.');
    $holdout=[
        'held_own_obligation'=>['speech'=>[['Lydia','I promise to return your silver ring on Saturday.'],['Farkas','I accept that promise.']], 'new'=>[$new('promise','obligation',0,'complete_obligation',null,'owed_by_me')]],
        'held_request_then_pronoun'=>['speech'=>[['Lydia','Will you return my 37 septims on Friday?'],['Farkas','I promise I will do exactly that.'],['Lydia','I accept.']], 'new'=>[$new('promise','obligation',0,'complete_obligation',null,'owed_to_me')+['line_index'=>1]]],
        'held_favour_not_debt'=>['speech'=>[['Farkas','I helped carry your bags yesterday.'],['Lydia','Thank you. I appreciate your help.']], 'new'=>[]],
        'held_combat_rivalry'=>['speech'=>[['Lydia','Ha, you wicked scoundrel! Six bandits before I could draw my sword. Race you to the next one.']], 'new'=>[]],
        'held_genuine_hurt_love'=>['speech'=>[['Lydia','I love you, but calling me worthless before everyone really hurt me. I need you to apologise for that insult.']], 'new'=>[$new('grievance','insult',0,'explicit',0)]],
        'held_unknown_deadline'=>['speech'=>[['Farkas','I promise to return your silver ring.'],['Lydia','I accept your promise.']], 'new'=>[$new('promise','obligation',0,'complete_obligation',null,'owed_to_me')]],
        'held_distinct_obligations'=>['open'=>[$money,$cloak],'speech'=>[['Lydia','You returned my blue cloak as promised. That cloak promise is fulfilled.']], 'new'=>[], 'updates'=>['abc123abc123abc3'=>'fulfilled']],
        'held_partial_restitution'=>['open'=>[substanceGrievance()],'speech'=>[['Lydia','I received the purse, but only 20 of its 50 coins. Returning the other 30 is still required.']], 'new'=>[], 'updates'=>['abc123abc123abc1'=>'no_change']],
        'held_missing_concrete_subject'=>['speech'=>[['Farkas','I promise I will do that.'],['Lydia','I accept.']], 'new'=>[]],
    ];
    $splitLines=[['Lydia','Will you return my 37 septims?'],['Lydia','Can you do it on Friday?'],['Farkas','I promise I will do exactly that.'],['Lydia','I accept those terms.']];
    $split=substancePromise('abc123abc123abc1',$splitLines[0][1]);
    $split['substance']['matter']['speaker']='Lydia';$split['evidence'][0]['speaker']='Lydia';
    $split['substance']['context']=array_map(static fn(array $l,int $i):array=>['ref'=>'e:'.(1+$i),'speaker'=>$l[0],'text'=>$l[1]],$splitLines,array_keys($splitLines));
    $interleaved=$split['substance']['context'];
    $interleaved[]=['ref'=>'e:5','speaker'=>'Farkas','text'=>'I also promise to return your blue cloak on Sunday.'];
    $interleaved[]=['ref'=>'e:6','speaker'=>'Lydia','text'=>'I accept that separate cloak promise too.'];
    $split['substance']['context']=$interleaved;
    $splitCloak=substancePromise('abc123abc123abc3',$interleaved[4]['text']);
    $splitCloak['substance']['matter']['ref']='e:5';$splitCloak['evidence'][0]['ref']='e:5';
    $splitCloak['substance']['context']=$interleaved;
    $boundary=[
        'split_origin'=>['speech'=>$splitLines,'new'=>[$new('promise','obligation',0,'complete_obligation',null,'owed_to_me')+['line_index'=>2]]],
        'split_matching_payment'=>['open'=>[$split,$splitCloak],'speech'=>[['Lydia','You returned my full 37 septims on Friday as agreed. That money promise is fulfilled.']],'new'=>[],'updates'=>['abc123abc123abc1'=>'fulfilled']],
        'split_partial_payment'=>['open'=>[$split,$splitCloak],'speech'=>[['Lydia','I received 12 of the 37 septims. The remaining 25 are still owed.']],'new'=>[],'updates'=>['abc123abc123abc1'=>'no_change']],
        'interleaved_cloak_only'=>['open'=>[$split,$splitCloak],'speech'=>[['Lydia','You returned my blue cloak on Sunday, fulfilling that cloak promise. The money is still owed.']],'new'=>[],'updates'=>['abc123abc123abc1'=>'no_change','abc123abc123abc3'=>'fulfilled']],
    ];
    if ($set==='boundary') return $boundary;
    if ($set==='development') return $dev;
    if ($set==='holdout') return $holdout;
    throw new RuntimeException('unknown_corpus');
}
function substanceContext(array $case):array {
    $lines=[];
    foreach($case['speech'] as $i=>[$speaker,$text]) $lines[]=sampleLine(100+$i,$speaker,$text);
    return ['owner'=>'Lydia','counterpart'=>'Farkas','lines'=>$lines,'open_issues'=>$case['open']??[]];
}
function substanceFailures(array $case,array $proposal):array {
    $fail=[];$expected=$case['new'];$actual=$proposal['new_issues'];
    if(count($actual)>count($expected))$fail[]='false_creation';
    if(count($actual)<count($expected))$fail[]='missed_genuine_issue';
    foreach($expected as $i=>$e) {
        if(!isset($actual[$i]))continue;$a=$actual[$i];$s=$a['substance']??null;
        $line=$case['speech'][$e['matter_index']];
        $matter=['ref'=>'e:'.(100+$e['matter_index']),'speaker'=>$line[0],'text'=>$line[1]];
        $source=$e['settlement_index']===null?null:['ref'=>'e:'.(100+$e['settlement_index']),'speaker'=>$case['speech'][$e['settlement_index']][0],'text'=>$case['speech'][$e['settlement_index']][1]];
        $exact=['version'=>1,'nature'=>$e['nature'],'matter'=>$matter,'settlement'=>['mode'=>$e['settlement_mode'],'source'=>$source]];
        $exact['context']=array_map(static fn(array $l,int $i):array=>['ref'=>'e:'.(100+$i),'speaker'=>$l[0],'text'=>$l[1]],$case['speech'],array_keys($case['speech']));
        $originIndex=$e['line_index']??$e['matter_index'];
        if (($a['line']['event_id']??null)!==100+$originIndex || ($a['line']['speaker']??null)!==$case['speech'][$originIndex][0] || ($a['line']['text']??null)!==$case['speech'][$originIndex][1]) $fail[]='wrong_terms';
        if(($a['kind']??null)!==$e['kind']||($a['direction']??null)!==$e['direction']||$s!==$exact)$fail[]='wrong_terms';
        if(($a['related_issue_id']??null)!==($case['related']??null))$fail[]='wrong_issue_update';
    }
    $updates=[];
    foreach($proposal['updates'] as $u)$updates[$u['issue_id']]=$u['status'];
    if($updates!==($case['updates']??[])) {
        foreach($updates as $id=>$status) {
            if(!array_key_exists($id,$case['updates']??[]))$fail[]='wrong_issue_update';
            elseif(in_array($status,['fulfilled','resolved','let_go','released'],true)&&($case['updates'][$id]??null)!==$status)$fail[]='unsupported_settlement';
        }
        $fail[]='wrong_issue_update';
    }
    return array_values(array_unique($fail));
}
