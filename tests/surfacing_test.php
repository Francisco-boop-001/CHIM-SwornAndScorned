<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/surfacing.php';

use function ChimSwornAndScorned\{emptyLedger, renderNote, selectIssueToSurface};

$promise = ['kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'status' => 'pending', 'wants' => 'keep_promise'];
$ledger = emptyLedger('pt-1');
$ledger['issues'] = [
    sampleIssue(['id' => 'g000000000000001', 'changed_event_id' => 10]),
    sampleIssue(['id' => 'p000000000000001', 'changed_event_id' => 12] + $promise),
    sampleIssue(['id' => 'd000000000000001', 'counterpart' => 'daedra:molag_bal', 'origin' => 'lore', 'changed_event_id' => 20]),
    sampleIssue(['id' => 'm000000000000001', 'muted' => true, 'changed_event_id' => 30]),
    sampleIssue(['id' => 'x000000000000001', 'dismissed' => true, 'changed_event_id' => 40]),
    sampleIssue(['id' => 'c000000000000001', 'status' => 'resolved', 'changed_event_id' => 50]),
    sampleIssue(['id' => 'o000000000000001', 'counterpart' => 'npc:9', 'changed_event_id' => 60]),
];
$now = 10_000;

check(selectIssueToSurface($ledger, ['npc:3'], [], [], $now)['id'] === 'p000000000000001', 'most recently changed eligible issue wins');
check(selectIssueToSurface($ledger, [], [], [], $now) === null, 'nobody present, nothing surfaced');

$cooling = ['p000000000000001' => ['at' => $now - 100, 'evidence_count' => 1]];
check(selectIssueToSurface($ledger, ['npc:3'], [], $cooling, $now)['id'] === 'g000000000000001', 'cooling issue is skipped');

$newEvidence = ['p000000000000001' => ['at' => $now - 100, 'evidence_count' => 0]];
check(selectIssueToSurface($ledger, ['npc:3'], [], $newEvidence, $now)['id'] === 'p000000000000001', 'new evidence ends the cooldown');

$expired = ['p000000000000001' => ['at' => $now - 601, 'evidence_count' => 1]];
check(selectIssueToSurface($ledger, ['npc:3'], [], $expired, $now)['id'] === 'p000000000000001', 'cooldown expires after 600 seconds');

check(selectIssueToSurface($ledger, ['npc:3'], ['npc:3'], $cooling, $now)['id'] === 'p000000000000001', 'player naming the counterpart overrides the cooldown');
check(selectIssueToSurface($ledger, ['npc:3'], ['daedra:molag_bal'], [], $now)['id'] === 'd000000000000001', 'daedra issue surfaces when the prince is mentioned');
check(selectIssueToSurface($ledger, ['daedra:molag_bal'], [], [], $now) === null, 'daedra are never merely present');
$daedraOnly = emptyLedger('pt-1');
$daedraOnly['issues'] = [$ledger['issues'][2]];
$daedraCooling = ['d000000000000001' => ['at' => $now - 10, 'evidence_count' => 1, 'evidence_ref' => 'e:10']];
check(selectIssueToSurface($daedraOnly, [], ['daedra:molag_bal'], $daedraCooling, $now, 600, []) === null,
    'NPC mentioning a prince gives eligibility but does not bypass cooldown');
check(selectIssueToSurface($daedraOnly, [], ['daedra:molag_bal'], $daedraCooling, $now, 600, ['daedra:molag_bal']) !== null,
    'current player mention can bypass cooldown separately');

$grievance = sampleIssue();
check(
    renderNote($grievance, 'Lydia', 'Farkas')
        === 'On your mind: unresolved with Farkas. Farkas said: "I\'ll stand with you before Aela." You want an explanation. Raise it only if it fits the moment.',
    'grievance note'
);

$mine = sampleIssue(['kind' => 'promise', 'direction' => 'owed_by_me', 'strength' => 'will', 'status' => 'pending', 'wants' => 'unknown',
    'evidence' => [['ref' => 'e:3', 'gamets' => 3.0, 'speaker' => 'Lydia', 'quote' => 'I will bring the mead.', 'label' => 'commitment', 'p' => 0.9, 'backend' => 'fake']]]);
check(
    renderNote($mine, 'Lydia', 'Farkas') === 'On your mind: you promised Farkas something. You said: "I will bring the mead." Raise it only if it fits the moment.',
    'own promise note, unknown wants omitted'
);

$lore = sampleIssue(['origin' => 'lore', 'counterpart' => 'npc:7',
    'evidence' => [['ref' => 'uesp:Gray-Mane', 'gamets' => null, 'speaker' => 'lore', 'quote' => 'The Battle-Borns sided with the Empire against your kin.', 'label' => 'lore', 'p' => null, 'backend' => 'lore']]]);
check(str_contains(renderNote($lore, 'Olfrid', 'Eorlund'), 'Old grudge: "The Battle-Borns'), 'lore note');

$long = sampleIssue(['evidence' => [['ref' => 'e:1', 'gamets' => 1.0, 'speaker' => 'Farkas', 'quote' => str_repeat('word ', 80), 'label' => 'wronged', 'p' => 0.9, 'backend' => 'fake']]]);
$note = renderNote($long, 'Lydia', 'Farkas');
check(mb_strlen($note) <= 240 && str_ends_with($note, 'Raise it only if it fits the moment.'), 'long quotes are clipped to fit 240');

echo "surfacing checks passed\n";
