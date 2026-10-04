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
check(
    $state['open_issues'] === ['abc123abc123abc1' => 'Lydia feels wronged by Farkas (status: open); originally: "I\'ll stand with you before Aela."'],
    'issue summary includes current status and original evidence'
);

$pass1 = pass1Questions($ctx);
check(array_column($pass1, 'id') === ['addresses:abc123abc123abc1', 'commitment', 'wronged'], 'pass 1 ids');
check(array_unique(array_column($pass1, 'type')) === ['noul'], 'pass 1 is all noul');

$pass2 = pass2Questions($ctx, ['commitment', 'addresses:abc123abc123abc1']);
check(array_column($pass2, 'id') === [
    'commitment.who', 'commitment.strength', 'commitment.acceptance', 'commitment.line',
    'issue:abc123abc123abc1.status', 'issue:abc123abc123abc1.trajectory',
    'issue:abc123abc123abc1.line', 'issue:abc123abc123abc1.wants',
], 'pass 2 ids for a commitment and an addressed grievance');
$byId = array_column($pass2, null, 'id');
check($byId['commitment.who']['options'] === ['Lydia', 'Farkas'], 'who options are the pair');
check($byId['commitment.line']['options'] === ['L1', 'L2'], 'line options are labels');
check($byId['issue:abc123abc123abc1.status']['options'] === ['resolved', 'let_go', 'no_change'], 'grievance status options');
check($byId['issue:abc123abc123abc1.wants']['options'] === WANTS, 'grievance wants options');

$promise = sampleIssue(['id' => 'p000000000000001', 'kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'status' => 'pending', 'wants' => 'keep_promise']);
$promisePass2 = pass2Questions(['open_issues' => [$promise]] + $ctx, ['addresses:p000000000000001']);
check(array_column($promisePass2, 'id') === ['issue:p000000000000001.status', 'issue:p000000000000001.trajectory', 'issue:p000000000000001.line'], 'promise has no wants question');
check($promisePass2[0]['options'] === PROMISE_STATUS_CHOICES, 'promise status options');

$wrongedPass2 = pass2Questions($ctx, ['wronged']);
check(array_column($wrongedPass2, 'id') === ['grievance.line', 'grievance.wants'], 'wronged pass 2 ids');

echo "questions checks passed\n";

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
