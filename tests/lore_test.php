<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/lore.php';

use function ChimSwornAndScorned\{applyLoreSeeds, emptyLedger, issueId, mergeSeeds, normalizeLedger, validateSeedFile};

$seed = [
    'id' => 'feud-test-a', 'owner' => 'Olfrid Battle-Born', 'counterpart' => 'Eorlund Gray-Mane', 'wants' => 'apology',
    'paraphrase' => 'Test paraphrase about an old family feud.', 'source' => 'https://en.uesp.net/wiki/Skyrim:Olfrid_Battle-Born',
];
$daedra = ['id' => 'vigil-test-b', 'owner' => 'Tolan', 'counterpart' => 'daedra:molag_bal', 'wants' => 'unknown',
    'paraphrase' => 'Test paraphrase about hunting Daedra worshippers.', 'source' => 'https://en.uesp.net/wiki/Skyrim:Tolan'];
$file = ['version' => 1, 'seeds' => [$seed, $daedra]];

check(validateSeedFile($file) === [$seed, $daedra], 'valid file accepted');
rejected(fn () => validateSeedFile(['version' => 2, 'seeds' => []]), 'version must be 1');
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => [$seed, $seed]]), 'duplicate ids rejected');
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => array_fill(0, 257, $seed)]), 'oversized seed file rejected');
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => [['source' => 'https://example.com/x'] + $seed]]), 'non-UESP source rejected');
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => [['source' => 'https://en.uesp.net/wiki/Oblivion:Example'] + $seed]]), 'non-Skyrim UESP source rejected');
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => [['source' => 'https://en.uesp.net.evil/wiki/Skyrim:Example'] + $seed]]), 'spoofed UESP host rejected');
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => [['wants' => 'revenge'] + $seed]]), 'wants vocabulary enforced');
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => [['counterpart' => 'daedra:zeus'] + $seed]]), 'unknown daedra rejected');
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => [['counterpart' => 'DAEDRA:molag_bal'] + $seed]]), 'typed daedra key must use its canonical form');
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => [['counterpart' => 'olfrid battle-born'] + $seed]]), 'self grudge rejected case-insensitively');
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => [['paraphrase' => str_repeat('x', 161)] + $seed]]), 'paraphrase capped at 160');
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => [['unexpected' => true] + $seed]]), 'unknown entry fields rejected');

check(mergeSeeds([$seed], [['wants' => 'explanation'] + $seed])[0]['wants'] === 'explanation', 'override replaces by id');

$resolve = static fn (string $counterpart): ?string => match ($counterpart) {
    'Eorlund Gray-Mane' => 'npc:77',
    'daedra:molag_bal' => 'daedra:molag_bal',
    default => null,
};
$result = applyLoreSeeds(emptyLedger('pt-1'), [$seed, $daedra], $resolve);
check($result['status'] === 'applied' && $result['affinity_delta'] === 0, 'seeding applies without affinity');
check($result['ledger']['seeded'] === ['feud-test-a', 'vigil-test-b'], 'resolved seeds marked');
$issue = $result['ledger']['issues'][0];
check($issue['id'] === issueId('lore:feud-test-a', 'npc:77', 'grievance') && $issue['origin'] === 'lore' && $issue['status'] === 'open', 'lore issue shape');
check($issue['evidence'][0]['ref'] === $seed['source'] && $issue['evidence'][0]['quote'] === $seed['paraphrase'], 'evidence is the cited paraphrase');
check($result['ledger']['issues'][0]['affinity_applied'] === [], 'lore issue does not apply affinity');
normalizeLedger(json_decode(json_encode($result['ledger'], JSON_THROW_ON_ERROR), true), 'pt-1');
check(applyLoreSeeds($result['ledger'], [$seed, $daedra], $resolve)['status'] === 'unchanged', 'seeds apply once');
rejected(fn () => applyLoreSeeds(emptyLedger('pt-1'), [$seed], static fn (string $counterpart): string => 'player'), 'resolver cannot turn an NPC grudge into a player issue');
rejected(fn () => applyLoreSeeds(emptyLedger('pt-1'), [$seed], static fn (string $counterpart): string => 'daedra:molag_bal'), 'named NPC must resolve to a unique NPC key');
rejected(fn () => applyLoreSeeds(emptyLedger('pt-1'), [$daedra], static fn (string $counterpart): string => 'npc:88'), 'explicit Daedra must remain a Daedra key');

$deferred = applyLoreSeeds(emptyLedger('pt-1'), [['counterpart' => 'Unmet Person'] + $seed], $resolve);
check($deferred['status'] === 'unchanged' && $deferred['ledger']['seeded'] === [], 'unknown counterpart is deferred, not marked');

$full = emptyLedger('pt-1');
for ($n = 0; $n < \ChimSwornAndScorned\MAX_OPEN_ISSUES; $n++) {
    $full['issues'][] = sampleIssue(['id' => sprintf('open%012d', $n)]);
}
$capped = applyLoreSeeds($full, [$seed], $resolve);
check($capped['status'] === 'unchanged' && $capped['ledger']['seeded'] === [], 'full issue cap defers a seed');
normalizeLedger($capped['ledger'], 'pt-1');

$seededFull = emptyLedger('pt-1');
$seededFull['seeded'] = array_map(static fn (int $id): string => 'seed-' . $id, range(1, \ChimSwornAndScorned\MAX_SEEDED));
$atSeedCap = applyLoreSeeds($seededFull, [$seed], $resolve);
check($atSeedCap['status'] === 'unchanged' && count($atSeedCap['ledger']['seeded']) === \ChimSwornAndScorned\MAX_SEEDED, 'seeded cap preserves a valid ledger');
normalizeLedger($atSeedCap['ledger'], 'pt-1');

echo "lore checks passed\n";
