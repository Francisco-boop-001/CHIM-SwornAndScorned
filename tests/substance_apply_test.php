<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/apply.php';
require __DIR__ . '/../server/backend.php';

use function ChimSwornAndScorned\{applyProposal, emptyLedger, issueId};
use const ChimSwornAndScorned\POLICY_DECISION_MODEL;

$meta = [
    'event_ids' => [50, 51],
    'counterpart' => 'npc:3',
    'backend' => 'scripted',
    'policy' => POLICY_DECISION_MODEL,
];
$request = sampleLine(50, 'Lydia', 'Please repay the 50 septims tomorrow.');
$promiseLine = sampleLine(51, 'Farkas', 'I will repay the 50 septims tomorrow.');
$substance = [
    'version' => 1,
    'nature' => 'obligation',
    'matter' => ['ref' => 'e:50', 'speaker' => $request['speaker'], 'text' => $request['text']],
    'settlement' => ['mode' => 'complete_obligation', 'source' => null],
];
$richPromise = [
    'kind' => 'promise',
    'direction' => 'owed_to_me',
    'strength' => 'will',
    'wants' => 'keep_promise',
    'line' => $promiseLine,
    'label' => 'commitment',
    'p' => 0.95,
    'substance' => $substance,
    'substance_lines' => [$request, $promiseLine],
];
$first = applyProposal(emptyLedger('pt-rich'), ['new_issues' => [$richPromise], 'updates' => []], $meta);
$promiseId = issueId('e:51', 'npc:3', 'promise');
$promise = issueById($first['ledger'], $promiseId);
check($first['status'] === 'applied' && $first['affinity_delta'] === 0, 'adding a rich record does not change affinity');
check($promise['substance'] === $substance && $promise['evidence'][0]['ref'] === 'e:51', 'rich matter persists separately from originating evidence');

$updateLine = sampleLine(52, 'Lydia', 'We still have not agreed on the new date.');
$changedSubstance = $substance;
$changedSubstance['matter']['text'] = 'Forged replacement matter.';
$updated = applyProposal($first['ledger'], [
    'new_issues' => [],
    'updates' => [[
        'issue_id' => $promiseId,
        'status' => 'renegotiated',
        'status_p' => 0.95,
        'trajectory' => null,
        'wants' => null,
        'line' => $updateLine,
        'substance' => $changedSubstance,
    ]],
], ['event_ids' => [52]] + $meta);
check(issueById($updated['ledger'], $promiseId)['substance'] === $substance, 'later updates cannot replace an existing rich subject');
check($updated['affinity_delta'] === 0, 'renegotiation and evidence updates do not apply affinity');

$newTermsLine = sampleLine(54, 'Farkas', 'I will repay the 50 septims after harvest.');
$newTermsSubstance = [
    'version' => 1,
    'nature' => 'obligation',
    'matter' => ['ref' => 'e:54', 'speaker' => $newTermsLine['speaker'], 'text' => $newTermsLine['text']],
    'settlement' => ['mode' => 'complete_obligation', 'source' => null],
];
$newPromise = $richPromise;
$newPromise['line'] = $newTermsLine;
$newPromise['substance'] = $newTermsSubstance;
$newPromise['substance_lines'] = [$updateLine, $newTermsLine];
unset($newPromise['related_issue_id']);
$renegotiation = [[
    'issue_id' => $promiseId,
    'status' => 'renegotiated',
    'status_p' => 0.95,
    'trajectory' => null,
    'wants' => null,
    'line' => $updateLine,
]];
$renegMeta = ['event_ids' => [52, 54]] + array_diff_key($meta, ['event_ids' => true]);
$withoutSelection = applyProposal($first['ledger'], ['new_issues' => [$newPromise], 'updates' => $renegotiation], $renegMeta);
$newPromiseId = issueId('e:54', 'npc:3', 'promise');
check(issueById($withoutSelection['ledger'], $newPromiseId)['links'] === [], 'rich new promise without explicit predecessor stays unlinked');
check(issueById($withoutSelection['ledger'], $promiseId)['links'] === [], 'missing rich predecessor does not add a reverse link');
check($withoutSelection['affinity_delta'] === 0, 'adding separately stated terms has no affinity effect');

check(issueById($withoutSelection['ledger'], $promiseId)['status'] === 'pending', 'unlinked replacement does not release the original');
$full = $first['ledger'];
for ($i = 1; $i < ChimSwornAndScorned\MAX_OPEN_ISSUES; ++$i) {
    $full['issues'][] = sampleIssue(['id' => str_pad('cap' . $i, 16, '0')]);
}
$capped = applyProposal($full, ['new_issues' => [$newPromise + ['related_issue_id' => $promiseId]], 'updates' => $renegotiation], $renegMeta);
check(in_array('open_cap', $capped['skipped'], true) && count($capped['ledger']['issues']) === ChimSwornAndScorned\MAX_OPEN_ISSUES, 'replacement at open cap is not added');
check(issueById($capped['ledger'], $promiseId)['status'] === 'pending' && issueById($capped['ledger'], $promiseId)['links'] === [], 'skipped replacement never releases or links original obligation');

$linkedPromise = $newPromise + ['related_issue_id' => $promiseId];
$withSelection = applyProposal($first['ledger'], ['new_issues' => [$linkedPromise], 'updates' => $renegotiation], $renegMeta);
check(issueById($withSelection['ledger'], $newPromiseId)['links'] === [$promiseId], 'explicit same-counterpart renegotiated promise link is added');
check(issueById($withSelection['ledger'], $promiseId)['links'] === [$newPromiseId], 'explicit rich link is reciprocal');
$oppositeDirectionPromise = $newPromise + ['related_issue_id' => $promiseId];
$oppositeDirectionPromise['direction'] = 'owed_by_me';
rejected(
    fn () => applyProposal($first['ledger'], ['new_issues' => [$oppositeDirectionPromise], 'updates' => $renegotiation], $renegMeta),
    'rich promise cannot link to renegotiated promise with the opposite direction'
);

$otherPromise = sampleIssue([
    'id' => 'otherpromise0001',
    'kind' => 'promise',
    'direction' => 'owed_to_me',
    'strength' => 'will',
    'status' => 'pending',
    'counterpart' => 'npc:3',
    'changed_event_id' => 49,
]);
$withOtherPromise = $first['ledger'];
$withOtherPromise['issues'][] = $otherPromise;
$wrongSelection = $newPromise + ['related_issue_id' => $otherPromise['id']];
rejected(
    fn () => applyProposal($withOtherPromise, ['new_issues' => [$wrongSelection], 'updates' => $renegotiation], $renegMeta),
    'rich relation to a same-counterpart promise not changed in this batch is rejected'
);
$unknownSelection = $newPromise + ['related_issue_id' => 'unknown-issue'];
rejected(
    fn () => applyProposal($first['ledger'], ['new_issues' => [$unknownSelection], 'updates' => $renegotiation], $renegMeta),
    'unknown rich related issue is rejected'
);

$grievanceLine = sampleLine(56, 'Lydia', 'You called my sister a thief.');
$grievanceSubstance = [
    'version' => 1,
    'nature' => 'insult',
    'matter' => ['ref' => 'e:56', 'speaker' => $grievanceLine['speaker'], 'text' => $grievanceLine['text']],
    'settlement' => ['mode' => 'unknown', 'source' => null],
];
$grievance = [
    'kind' => 'grievance',
    'direction' => null,
    'strength' => null,
    'wants' => 'explanation',
    'line' => $grievanceLine,
    'label' => 'wronged',
    'p' => 0.95,
    'substance' => $grievanceSubstance,
    'substance_lines' => [$grievanceLine],
    'related_issue_id' => $promiseId,
];
$disputeLine = sampleLine(55, 'Farkas', 'I do not agree that I promised that.');
$disputed = applyProposal($first['ledger'], [
    'new_issues' => [$grievance],
    'updates' => [[
        'issue_id' => $promiseId,
        'status' => 'disputed',
        'status_p' => 0.95,
        'trajectory' => null,
        'wants' => null,
        'line' => $disputeLine,
    ]],
], ['event_ids' => [55, 56]] + array_diff_key($meta, ['event_ids' => true]));
$grievanceId = issueId('e:56', 'npc:3', 'grievance');
check(issueById($disputed['ledger'], $grievanceId)['links'] === [$promiseId], 'explicit disputed promise can link to a rich grievance');
check(issueById($disputed['ledger'], $promiseId)['links'] === [$grievanceId], 'explicit grievance link is reciprocal');
check($disputed['affinity_delta'] === 0, 'disputing and recording a related grievance do not apply affinity');

$brokenLine = sampleLine(57, 'Lydia', 'You did not repay the 50 septims.');
$broken = applyProposal($first['ledger'], [
    'new_issues' => [$grievance],
    'updates' => [[
        'issue_id' => $promiseId,
        'status' => 'broken',
        'status_p' => 0.95,
        'trajectory' => null,
        'wants' => null,
        'line' => $brokenLine,
    ]],
], ['event_ids' => [57, 56]] + array_diff_key($meta, ['event_ids' => true]));
check(issueById($broken['ledger'], $grievanceId)['links'] === [$promiseId], 'explicit broken promise can link to a rich grievance');

$forgedText = $richPromise;
$forgedText['substance']['matter']['text'] = 'A short fabricated summary.';
rejected(
    fn () => applyProposal(emptyLedger('pt-rich'), ['new_issues' => [$forgedText], 'updates' => []], $meta),
    'rich source text must equal the exact witnessed utterance'
);
$forgedSpeaker = $richPromise;
$forgedSpeaker['substance']['matter']['speaker'] = 'Unknown Actor';
rejected(
    fn () => applyProposal(emptyLedger('pt-rich'), ['new_issues' => [$forgedSpeaker], 'updates' => []], $meta),
    'rich source speaker must equal the witnessed speaker'
);
$forgedRef = $richPromise;
$forgedRef['substance']['matter']['ref'] = 'e:999';
rejected(
    fn () => applyProposal(emptyLedger('pt-rich'), ['new_issues' => [$forgedRef], 'updates' => []], $meta),
    'rich source reference must identify a witnessed line'
);
$offBatchLine = sampleLine(99, 'Lydia', 'Please repay me after harvest.');
$offBatch = $richPromise;
$offBatch['substance']['matter'] = ['ref' => 'e:99', 'speaker' => $offBatchLine['speaker'], 'text' => $offBatchLine['text']];
$offBatch['substance_lines'] = [$offBatchLine, $promiseLine];
rejected(
    fn () => applyProposal(emptyLedger('pt-rich'), ['new_issues' => [$offBatch], 'updates' => []], $meta),
    'rich source line must belong to the acknowledged batch'
);

$unknownMatter = $richPromise;
$unknownMatter['substance'] = ['version' => 1, 'nature' => 'unknown', 'matter' => null, 'settlement' => ['mode' => 'unknown', 'source' => null]];
rejected(
    fn () => applyProposal(emptyLedger('pt-rich'), ['new_issues' => [$unknownMatter], 'updates' => []], $meta),
    'new rich proposal with unknown matter is rejected'
);

$legacyLine = sampleLine(61, 'Farkas', 'I will stand beside you.');
$legacyMeta = ['event_ids' => [61], 'counterpart' => 'npc:3', 'backend' => 'scripted', 'policy' => POLICY_DECISION_MODEL];
$legacy = applyProposal(emptyLedger('pt-rich'), ['new_issues' => [[
    'kind' => 'promise',
    'direction' => 'owed_to_me',
    'strength' => 'will',
    'wants' => 'keep_promise',
    'line' => $legacyLine,
    'label' => 'commitment',
    'p' => 0.95,
]], 'updates' => []], $legacyMeta);
check(!array_key_exists('substance', issueById($legacy['ledger'], issueId('e:61', 'npc:3', 'promise'))), 'legacy proposal without substance remains compatible');

echo "substance apply checks passed\n";
