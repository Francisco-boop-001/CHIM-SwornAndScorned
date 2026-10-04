<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/ledger.php';

use function ChimSwornAndScorned\{clipText, emptyLedger, enforceClosedCap, isOpen, isReplay, issueId, markProcessed, normalizeLedger, validateIssue};

$empty = emptyLedger('pt-1');
check($empty === ['playthrough_id' => 'pt-1', 'floor_event_id' => 0, 'processed' => [], 'issues' => [], 'seeded' => []], 'empty ledger shape');
check(normalizeLedger(null, 'pt-1') === $empty, 'null becomes an empty ledger');
check(
    normalizeLedger(['playthrough_id' => 'pt-0', 'floor_event_id' => 0, 'processed' => [], 'issues' => [sampleIssue()]], 'pt-1') === $empty,
    'another playthrough becomes an empty ledger'
);

$raw = ['playthrough_id' => 'pt-1', 'floor_event_id' => 0, 'processed' => [10], 'issues' => [sampleIssue()], 'seeded' => ['gray-mane-feud']];
$legacy = $raw;
unset($legacy['seeded']);
check(normalizeLedger($legacy, 'pt-1')['seeded'] === [], 'older ledgers get an empty seeded list');
rejected(fn () => normalizeLedger(['seeded' => [3]] + $raw, 'pt-1'), 'seeded entries must be strings');
rejected(fn () => normalizeLedger(['seeded' => ['bad seed id']] + $raw, 'pt-1'), 'seeded entries must be seed ids');
rejected(fn () => normalizeLedger(['seeded' => ['feud-one', 'feud-one']] + $raw, 'pt-1'), 'seeded ids must be unique');
rejected(fn () => normalizeLedger(['seeded' => array_map(static fn (int $id): string => 'seed-' . $id, range(1, 513))] + $raw, 'pt-1'), 'seeded list must be bounded');
check(normalizeLedger($raw, 'pt-1') === $raw, 'valid ledger round-trips unchanged');
$viaJson = json_decode(json_encode($raw, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
check(normalizeLedger($viaJson, 'pt-1') === $raw, 'JSON round-trip restores float gamets');

rejected(fn () => validateIssue(sampleIssue(['counterpart' => 'Farkas'])), 'counterpart must be typed');
rejected(fn () => validateIssue(sampleIssue(['kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'status' => 'open'])), 'promise cannot be open');
rejected(fn () => validateIssue(sampleIssue(['evidence' => [['ref' => 'e:1']]])), 'evidence fields are required');
rejected(fn () => validateIssue(sampleIssue(['wants' => 'revenge'])), 'wants vocabulary enforced');
rejected(fn () => normalizeLedger(['playthrough_id' => 'pt-1', 'floor_event_id' => 5, 'processed' => [3], 'issues' => []], 'pt-1'), 'processed ids must be above the floor');
check(validateIssue(sampleIssue(['direction' => 'owed_to_me']))['direction'] === null, 'grievance ignores direction');
check(validateIssue(sampleIssue(['counterpart' => 'daedra:molag_bal']))['counterpart'] === 'daedra:molag_bal', 'daedra counterpart accepted');
check(validateIssue(sampleIssue(['counterpart' => 'player']))['counterpart'] === 'player', 'player counterpart accepted');

$id = issueId('e:10', 'npc:3', 'promise');
check(preg_match('/^[0-9a-f]{16}$/D', $id) === 1, 'issue id is 16 hex characters');
check($id === issueId('e:10', 'npc:3', 'promise'), 'issue id is deterministic');
check($id !== issueId('e:10', 'npc:3', 'grievance'), 'kind changes the issue id');

$ledger = markProcessed(emptyLedger('pt-1'), range(1, 130));
check(count($ledger['processed']) === 128 && $ledger['floor_event_id'] === 2, 'processed cap 128 raises the floor');
check(isReplay($ledger, [2]) && isReplay($ledger, [50]) && !isReplay($ledger, [131]), 'replay detection by floor and list');
check(isReplay($ledger, [131, 50]), 'any replayed id marks the batch as replay');

check(!isOpen(sampleIssue(['dismissed' => true])), 'dismissed issue is not open');
check(isOpen(sampleIssue(['kind' => 'promise', 'status' => 'disputed'])), 'disputed promise is open');
check(!isOpen(sampleIssue(['status' => 'resolved'])), 'resolved grievance is closed');

$issues = [sampleIssue(['id' => 'open000000000001']), sampleIssue(['id' => 'open000000000002'])];
for ($n = 1; $n <= 20; $n++) {
    $issues[] = sampleIssue(['id' => sprintf('closed%010d', $n), 'status' => 'resolved', 'changed_event_id' => $n]);
}
$capped = enforceClosedCap($issues);
check(count($capped) === 18, 'two open plus sixteen closed kept');
check($capped[2]['changed_event_id'] === 20 && $capped[17]['changed_event_id'] === 5, 'newest closed issues kept');

$clip = clipText(str_repeat('é', 200), 160);
check(mb_strlen($clip) === 160 && str_ends_with($clip, '…'), 'clipped to 160 with an ellipsis');
check(clipText("  a \n b  ", 160) === 'a b', 'whitespace collapsed');

echo "ledger checks passed\n";
