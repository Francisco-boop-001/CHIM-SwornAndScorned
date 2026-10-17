<?php
declare(strict_types=1);
require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/page_view.php';
require __DIR__ . '/../server/surfacing.php';
use function ChimSwornAndScorned\{renderIssue, renderNote};
$matter = ['ref'=>'e:21','speaker'=>'Farkas','text'=>'I promise to repay the 50 septims tomorrow.'];
$issue = sampleIssue(['kind'=>'promise','direction'=>'owed_to_me','strength'=>'will','status'=>'pending','wants'=>'keep_promise',
    'substance'=>['version'=>1,'nature'=>'obligation','matter'=>$matter,'settlement'=>['mode'=>'complete_obligation','source'=>null]]]);
$issue['evidence'][] = array_replace($issue['evidence'][0], ['quote'=>'Thank you for the bread.']);
$html = renderIssue($issue, 1, 'csrf', true);
check(str_contains($html, '50 septims tomorrow') && str_contains($html, 'Completion of the stated obligation'), 'journal shows specific obligation and supported completion expectation');
check(str_contains($html, 'e:21') && str_contains($html, 'Farkas'), 'journal preserves source and speaker');
$note=renderNote($issue,'Lydia','Farkas');
check(str_contains($note,'50 septims tomorrow') && !str_contains($note,'bread'), 'reminder uses immutable subject rather than unrelated latest evidence');
check(mb_strlen($note)<=240 && str_ends_with($note,'Raise it only if it fits the moment.'), 'reminder cap and closing instruction');
$theft=sampleIssue(['substance'=>['version'=>1,'nature'=>'theft','matter'=>['ref'=>'e:31','speaker'=>'Lydia','text'=>'You stole my <purse>.'],
    'settlement'=>['mode'=>'explicit','source'=>['ref'=>'e:32','speaker'=>'Lydia','text'=>'Return the purse and all 50 coins.']]]]);
$html=renderIssue($theft,1,'csrf',true);
check(str_contains($html,'&lt;purse&gt;') && !str_contains($html,'my <purse>'), 'matter escaped');
check(str_contains($html,'Return the purse and all 50 coins.') && str_contains($html,'e:32'), 'explicit settlement provenance rendered');
$unknown=sampleIssue();
$html=renderIssue($unknown,1,'csrf',true);
check(str_contains($html,'Matter: unknown') && str_contains($html,'Settlement: unknown'), 'legacy terms remain explicitly unknown');
$long=$issue;
$long['substance']['matter']['text']=str_repeat('Specific obligation ',30).'50 septims tomorrow.';
$note=renderNote($long,str_repeat('Lydia',20),str_repeat('Farkas',20));
check(mb_strlen($note)<=240 && str_ends_with($note,'Raise it only if it fits the moment.'), 'long substance retains hard cap and closing instruction');
$split=$issue;
$split['substance']['matter']=['ref'=>'e:41','speaker'=>'Lydia','text'=>'Return my 37 septims.'];
$split['substance']['context']=[$split['substance']['matter'],['ref'=>'e:42','speaker'=>'Lydia','text'=>'By Friday.'],['ref'=>'e:43','speaker'=>'Farkas','text'=>'I promise.'],['ref'=>'e:44','speaker'=>'Lydia','text'=>'I accept.']];
$html=renderIssue($split,1,'csrf',true);$note=renderNote($split,'Lydia','Farkas');
check(str_contains($html,'By Friday.') && str_contains($html,'e:42'),'journal preserves split deadline and its source');
check(str_contains($note,'37 septims') && str_contains($note,'Friday') && mb_strlen($note)<=240,'short split-term reminder carries amount and deadline');
echo "substance view checks passed\n";
