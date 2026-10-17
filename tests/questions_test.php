<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/questions.php';

use function ChimSwornAndScorned\{buildState, pass1Questions, pass2Questions};
use const ChimSwornAndScorned\{PROMISE_STATUS_CHOICES, WANTS};

$ctx = [
    'owner' => 'Lydia',
    'counterpart' => 'Farkas',
    'lines' => [sampleLine(4, 'Lydia', 'You promised.'), sampleLine(5, 'Farkas', 'I will stand with you.')],
    'open_issues' => [sampleIssue()],
];

$state = buildState($ctx);
check($state['owner'] === 'Lydia' && $state['counterpart'] === 'Farkas', 'state names');
check($state['lines'] === ['L1' => 'Lydia: You promised.', 'L2' => 'Farkas: I will stand with you.'], 'labelled lines');
check($state['substance_candidates'] === [
    'L1' => ['ref' => 'e:4', 'speaker' => 'Lydia', 'text' => 'You promised.'],
    'L2' => ['ref' => 'e:5', 'speaker' => 'Farkas', 'text' => 'I will stand with you.'],
], 'both backends receive exact whole-utterance source candidates');
check(
    str_contains($state['open_issues']['abc123abc123abc1'], 'structured terms not recorded')
        && str_contains($state['open_issues']['abc123abc123abc1'], 'use original evidence for any known matter or remedy')
        && str_contains($state['open_issues']['abc123abc123abc1'], 'I\'ll stand with you before Aela.'),
    'legacy issue summary discloses unrecorded structure and points to original evidence'
);

$pass1 = pass1Questions($ctx);
check(array_column($pass1, 'id') === ['addresses:abc123abc123abc1', 'commitment', 'wronged'], 'pass 1 ids');
check(array_unique(array_column($pass1, 'type')) === ['noul'], 'pass 1 is all noul');
check(
    str_contains($pass1[0]['text'], 'Target issue abc123abc123abc1; original quote: "')
        && str_contains($pass1[0]['text'], 'I\'ll stand with you before Aela.'),
    'legacy issue target uses its originating quote when structured matter is absent'
);
check(
    str_contains($pass1[2]['text'], 'A neutral statement that a debt or balance remains owed is not by itself a grievance')
        && str_contains($pass1[2]['text'], 'do not infer breach from the mere existence of an obligation'),
    'neutral unpaid balance alone does not imply personal grievance or breach'
);

$pass2 = pass2Questions($ctx, ['commitment', 'addresses:abc123abc123abc1']);
check(array_column($pass2, 'id') === [
    'commitment.who', 'commitment.strength', 'commitment.acceptance', 'commitment.line',
    'commitment.matter', 'commitment.settlement',
    'issue:abc123abc123abc1.status', 'issue:abc123abc123abc1.trajectory',
    'issue:abc123abc123abc1.line', 'issue:abc123abc123abc1.wants',
], 'pass 2 ids for a commitment and an addressed grievance');
$byId = array_column($pass2, null, 'id');
check($byId['commitment.who']['options'] === ['Lydia', 'Farkas'], 'who options are the pair');
check($byId['commitment.line']['options'] === ['L1', 'L2'], 'line options are labels');
check(
    $byId['commitment.matter']['type'] === 'choice'
        && $byId['commitment.matter']['options'] === ['unknown', 'L1', 'L2']
        && $byId['commitment.settlement']['options'] === ['unknown', 'L1', 'L2']
        && str_contains($byId['commitment.matter']['text'], 'Prefer the line naming the concrete action, object and stated quantity')
        && str_contains($byId['commitment.matter']['text'], 'A pronoun-only or timing line cannot identify the matter by itself'),
    'commitment source questions accept only unknown or witnessed labels'
);
check($byId['issue:abc123abc123abc1.status']['options'] === ['resolved', 'let_go', 'no_change'], 'grievance status options');
check($byId['issue:abc123abc123abc1.wants']['options'] === WANTS, 'grievance wants options');
check(
    str_contains($byId['issue:abc123abc123abc1.status']['text'], "Lydia's explicit acknowledgement that this specific wrong was repaired or settled")
        && str_contains($byId['issue:abc123abc123abc1.status']['text'], 'clear direct evidence of actual repair also qualifies')
        && str_contains($byId['issue:abc123abc123abc1.status']['text'], 'clearly forgives or releases this grievance without repair')
        && str_contains($byId['issue:abc123abc123abc1.status']['text'], 'generic apology alone is neither resolved nor let_go'),
    'grievance status distinguishes specific repair from forgiveness and generic apologies'
);

$promise = sampleIssue(['id' => 'p000000000000001', 'kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'status' => 'pending', 'wants' => 'keep_promise']);
$promisePass2 = pass2Questions(['open_issues' => [$promise]] + $ctx, ['addresses:p000000000000001']);
check(array_column($promisePass2, 'id') === ['issue:p000000000000001.status', 'issue:p000000000000001.trajectory', 'issue:p000000000000001.line'], 'promise has no wants question');
check($promisePass2[0]['options'] === PROMISE_STATUS_CHOICES, 'promise status options');
check(
    str_contains($promisePass2[0]['text'], 'Target issue p000000000000001; original quote: "'),
    'legacy lifecycle prompt repeats its issue ID and original quote'
);
check(
    str_contains($promisePass2[0]['text'], 'Judge fulfillment only against the selected matter and its directly related terms')
        && str_contains($promisePass2[0]['text'], 'unrelated obligations elsewhere in the shared exchange do not block it')
        && str_contains($promisePass2[0]['text'], 'If the selected obligation is explicitly still outstanding, choose no_change'),
    'promise fulfillment is scoped to its selected matter and related terms'
);
check(
    str_contains($promisePass2[2]['text'], 'For a renegotiated promise, prefer the line stating concrete replacement terms (action, amount or deadline) over a pronoun-only acceptance')
        && str_contains($promisePass2[2]['text'], 'use the whole exchange to establish mutual acceptance'),
    'renegotiation source selection prefers concrete replacement terms over a pronoun-only acceptance'
);

$wrongedPass2 = pass2Questions($ctx, ['wronged']);
check(array_column($wrongedPass2, 'id') === [
    'grievance.line', 'grievance.wants', 'grievance.matter', 'grievance.nature', 'grievance.settlement',
], 'wronged pass 2 ids');
$wrongedById = array_column($wrongedPass2, null, 'id');
check(
    $wrongedById['grievance.matter']['options'] === ['unknown', 'L1', 'L2']
        && $wrongedById['grievance.settlement']['options'] === ['unknown', 'L1', 'L2'],
    'grievance source questions accept only unknown or witnessed labels'
);
check(
    str_contains($wrongedById['grievance.matter']['text'], "Prefer Lydia's concrete complaint when it names the actual wrong")
        && str_contains($wrongedById['grievance.matter']['text'], 'otherwise prefer the counterpart\'s concrete act or specific accepted request'),
    'grievance matter selection prefers a specific complaint, then a concrete source from the counterpart'
);
check($wrongedById['grievance.nature']['options'] === [
    'obligation', 'insult', 'theft', 'betrayal', 'broken_agreement', 'harm', 'unknown',
], 'grievance nature uses the supported interpretation choices');
check(!isset($wrongedById['grievance.related']), 'no relation question appears without a prior promise');


// A vague later turn must not erase the distinct original obligations.
$bread = $promise;
$bread['evidence'][0]['quote'] = 'I will bring bread tomorrow.';
$bread['evidence'][] = ['quote' => 'I am sorry.'] + $bread['evidence'][0];
$mead = $bread;
$mead['id'] = 'd000000000000001';
$mead['strength'] = 'will_try';
$mead['evidence'][0]['quote'] = 'I will try to bring mead tonight.';
$later = ['open_issues' => [$bread, $mead]] + $ctx;
$summaries = buildState($later)['open_issues'];
check(str_contains($summaries[$bread['id']], 'bring bread tomorrow') && str_contains($summaries[$mead['id']], 'bring mead tonight'), 'originating terms remain distinct after apology');
check(str_contains($summaries[$bread['id']], 'I am sorry.') && str_contains($summaries[$bread['id']], 'pending') && str_contains($summaries[$mead['id']], 'will_try'), 'latest evidence, status and strength remain visible');
check(str_contains(pass1Questions($later)[0]['text'], 'bring bread tomorrow'), 'address question retains original obligation');

$relatedContext = ['open_issues' => [$promise]] + $ctx;
$relatedPass2 = pass2Questions($relatedContext, ['commitment', 'wronged']);
$relatedById = array_column($relatedPass2, null, 'id');
check(
    $relatedById['commitment.related']['options'] === ['none', 'p000000000000001']
        && $relatedById['grievance.related']['options'] === ['none', 'p000000000000001'],
    'new issue relations require an explicit eligible prior promise or none'
);

// Known subject and settlement stay ahead of later unrelated evidence.
$subject = $bread;
$subject['substance'] = [
    'version' => 1,
    'nature' => 'theft',
    'matter' => ['ref' => 'e:4', 'speaker' => 'Lydia', 'text' => 'You took my 50 septims.'],
    'settlement' => [
        'mode' => 'explicit',
        'source' => ['ref' => 'e:5', 'speaker' => 'Lydia', 'text' => 'Return exactly 50 septims by sundown.'],
    ],
    'context' => [
        ['ref' => 'e:4', 'speaker' => 'Lydia', 'text' => 'You took my 50 septims.'],
        ['ref' => 'e:5', 'speaker' => 'Lydia', 'text' => 'Return exactly 50 septims by sundown.'],
    ],
];
$subjectSummary = buildState(['open_issues' => [$subject]] + $ctx)['open_issues'][$subject['id']];
$matterAt = strpos($subjectSummary, 'You took my 50 septims.');
$settlementAt = strpos($subjectSummary, 'Return exactly 50 septims by sundown.');
$contextAt = strpos($subjectSummary, 'originating exchange (may contain unrelated topics; selected matter anchors this issue)');
$latestAt = strpos($subjectSummary, 'I am sorry.');
check(
    $matterAt !== false && $settlementAt !== false && $contextAt !== false && $latestAt !== false
        && str_contains($subjectSummary, 'e:4 Lydia: "You took my 50 septims."')
        && str_contains($subjectSummary, 'e:5 Lydia: "Return exactly 50 septims by sundown."')
        && $matterAt < $contextAt && $settlementAt < $contextAt && $contextAt < $latestAt,
    'immutable matter, settlement and source context precede later evidence in issue summary'
);

$targetGrievance = sampleIssue([
    'id' => 'g000000000000001',
    'kind' => 'grievance',
    'direction' => null,
    'strength' => null,
    'status' => 'pending',
]);
$targetGrievance['substance'] = [
    'version' => 1,
    'nature' => 'theft',
    'matter' => ['ref' => 'e:4', 'speaker' => 'Lydia', 'text' => 'You took my 50 septims.'],
    'settlement' => ['mode' => 'unknown', 'source' => null],
    'context' => [
        ['ref' => 'e:4', 'speaker' => 'Lydia', 'text' => 'You took my 50 septims.'],
        ['ref' => 'e:5', 'speaker' => 'Lydia', 'text' => 'Return exactly 50 septims by sundown.'],
    ],
];
$targetCtx = ['open_issues' => [$targetGrievance]] + $ctx;
$targetLabel = 'Target issue g000000000000001; selected matter e:4 Lydia: "You took my 50 septims."';
$targetPass1 = pass1Questions($targetCtx)[0]['text'];
$targetAt = strpos($targetPass1, $targetLabel);
$targetContextAt = strpos($targetPass1, 'originating exchange (may contain unrelated topics; selected matter anchors this issue)');
check(
    $targetAt !== false && $targetContextAt !== false && $targetAt < $targetContextAt,
    'pass one identifies the selected issue matter before showing its full context'
);
$targetPass2 = array_column(pass2Questions($targetCtx, ['addresses:g000000000000001']), null, 'id');
check(
    str_contains($targetPass2['issue:g000000000000001.status']['text'], $targetLabel)
        && str_contains($targetPass2['issue:g000000000000001.trajectory']['text'], $targetLabel)
        && str_contains($targetPass2['issue:g000000000000001.line']['text'], $targetLabel)
        && str_contains($targetPass2['issue:g000000000000001.wants']['text'], $targetLabel),
    'each per-issue pass-two question repeats its exact selected matter anchor'
);
echo "questions checks passed\n";
