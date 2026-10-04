<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/apply.php';
require __DIR__ . '/../server/backend.php';

use function ChimSwornAndScorned\{applyProposal, emptyLedger, issueId, normalizeLedger};
use const ChimSwornAndScorned\POLICY_DECISION_MODEL;

$vow = sampleLine(5, 'Farkas', 'I will stand with you before Aela.');
$meta = ['event_ids' => [4, 5], 'counterpart' => 'npc:3', 'backend' => 'scripted', 'policy' => POLICY_DECISION_MODEL];
$newPromise = ['new_issues' => [[
    'kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'wants' => 'keep_promise',
    'line' => $vow, 'label' => 'commitment', 'p' => 0.95,
]], 'updates' => []];

// New promise.
$first = applyProposal(emptyLedger('pt-1'), $newPromise, $meta);
$promiseId = issueId('e:5', 'npc:3', 'promise');
$promise = issueById($first['ledger'], $promiseId);
check($first['status'] === 'applied' && $first['affinity_delta'] === 0, 'new promise applied without affinity');
check($promise['status'] === 'pending' && $promise['direction'] === 'owed_to_me' && $promise['changed_event_id'] === 5, 'promise fields');
check($promise['evidence'] === [[
    'ref' => 'e:5', 'gamets' => 5.0, 'speaker' => 'Farkas', 'quote' => 'I will stand with you before Aela.',
    'label' => 'commitment', 'p' => 0.95, 'backend' => 'scripted',
]], 'evidence quotes the actual line');
check($first['ledger']['processed'] === [4, 5], 'batch marked processed');
normalizeLedger(json_decode(json_encode($first['ledger'], JSON_THROW_ON_ERROR), true), 'pt-1');

// Replay of the same batch is a no-op.
$replay = applyProposal($first['ledger'], $newPromise, $meta);
check($replay['status'] === 'replay' && $replay['ledger'] === $first['ledger'] && $replay['affinity_delta'] === 0, 'replay is a no-op');

// Broken with high confidence: -3 once.
$breakMeta = ['event_ids' => [7, 8]] + $meta;
$silence = sampleLine(7, 'Lydia', 'You said nothing while Aela mocked me.');
$broken = applyProposal($first['ledger'], ['new_issues' => [], 'updates' => [[
    'issue_id' => $promiseId, 'status' => 'broken', 'status_p' => 0.95, 'trajectory' => 'worsening', 'wants' => null, 'line' => $silence,
]]], $breakMeta);
$brokenIssue = issueById($broken['ledger'], $promiseId);
$key = "{$promiseId}:pending>broken";
check($broken['affinity_delta'] === -3 && $broken['transitions'] === [$key], 'broken promise: -3 and transition recorded');
check($brokenIssue['status'] === 'broken' && $brokenIssue['affinity_applied'] === [$key] && $brokenIssue['trajectory'] === 'worsening', 'issue closed as broken');
check(count($brokenIssue['evidence']) === 2 && $brokenIssue['evidence'][1]['label'] === 'broken', 'status evidence appended');

// Status change below affinity_min: transition without affinity.
$soft = applyProposal($first['ledger'], ['new_issues' => [], 'updates' => [[
    'issue_id' => $promiseId, 'status' => 'fulfilled', 'status_p' => 0.85, 'trajectory' => null, 'wants' => null, 'line' => $silence,
]]], $breakMeta);
check($soft['affinity_delta'] === 0 && issueById($soft['ledger'], $promiseId)['status'] === 'fulfilled', 'transition without affinity below 0.90');
check(issueById($soft['ledger'], $promiseId)['affinity_applied'] === [], 'no affinity key recorded');

// Renegotiated keeps the promise pending.
$reneg = applyProposal($first['ledger'], ['new_issues' => [], 'updates' => [[
    'issue_id' => $promiseId, 'status' => 'renegotiated', 'status_p' => 0.9, 'trajectory' => null, 'wants' => null, 'line' => $silence,
]]], $breakMeta);
$renegIssue = issueById($reneg['ledger'], $promiseId);
check($renegIssue['status'] === 'pending' && $renegIssue['evidence'][1]['label'] === 'renegotiated' && $reneg['skipped'] === [], 'renegotiated stays pending');

// A renegotiation that yields a new promise links the two.
$newTerms = sampleLine(8, 'Farkas', 'Then I will speak to Aela privately.');
$relinked = applyProposal($first['ledger'], [
    'new_issues' => [['kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'wants' => 'keep_promise', 'line' => $newTerms, 'label' => 'commitment', 'p' => 0.9]],
    'updates' => [['issue_id' => $promiseId, 'status' => 'renegotiated', 'status_p' => 0.9, 'trajectory' => null, 'wants' => null, 'line' => $silence]],
], $breakMeta);
check(issueById($relinked['ledger'], issueId('e:8', 'npc:3', 'promise'))['links'] === [$promiseId], 'renegotiation links the new promise');
check(issueById($relinked['ledger'], $promiseId)['links'] === [issueId('e:8', 'npc:3', 'promise')], 'old promise links to the new terms');

// Disallowed transition is skipped.
$disputed = applyProposal($first['ledger'], ['new_issues' => [], 'updates' => [[
    'issue_id' => $promiseId, 'status' => 'disputed', 'status_p' => 0.9, 'trajectory' => null, 'wants' => null, 'line' => $silence,
]]], $breakMeta);
$later = sampleLine(9, 'Lydia', 'We still disagree.');
$again = applyProposal($disputed['ledger'], ['new_issues' => [], 'updates' => [[
    'issue_id' => $promiseId, 'status' => 'disputed', 'status_p' => 0.9, 'trajectory' => null, 'wants' => null, 'line' => $later,
]]], ['event_ids' => [9]] + $meta);
check($again['skipped'] === ['transition_not_allowed'], 'disputed to disputed is not allowed');

// Wrong counterpart cannot touch the issue.
$wrong = applyProposal($first['ledger'], ['new_issues' => [], 'updates' => [[
    'issue_id' => $promiseId, 'status' => 'broken', 'status_p' => 0.95, 'trajectory' => null, 'wants' => null, 'line' => $silence,
]]], ['counterpart' => 'npc:9'] + $breakMeta);
check($wrong['skipped'] === ['update_target_missing'] && $wrong['affinity_delta'] === 0, 'counterpart must match');

// Broken promise plus a new grievance in the same batch are linked both ways.
$linked = applyProposal($first['ledger'], [
    'new_issues' => [['kind' => 'grievance', 'direction' => null, 'strength' => null, 'wants' => 'explanation', 'line' => $silence, 'label' => 'wronged', 'p' => 0.9]],
    'updates' => [['issue_id' => $promiseId, 'status' => 'broken', 'status_p' => 0.95, 'trajectory' => null, 'wants' => null, 'line' => $silence]],
], $breakMeta);
$grievanceId = issueId('e:7', 'npc:3', 'grievance');
check(issueById($linked['ledger'], $grievanceId)['links'] === [$promiseId], 'grievance links to the broken promise');
check(issueById($linked['ledger'], $promiseId)['links'] === [$grievanceId], 'promise links to the grievance');

// Open-issue cap.
$full = emptyLedger('pt-1');
for ($n = 1; $n <= 24; $n++) {
    $full['issues'][] = sampleIssue(['id' => sprintf('full%012d', $n)]);
}
$capped = applyProposal($full, $newPromise, $meta);
check($capped['skipped'] === ['open_cap'] && count($capped['ledger']['issues']) === 24, 'open cap respected');

echo "apply checks passed\n";
