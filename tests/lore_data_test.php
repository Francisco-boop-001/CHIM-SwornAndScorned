<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/lore.php';

use function ChimSwornAndScorned\validateSeedFile;

$seeds = validateSeedFile(json_decode((string) file_get_contents(__DIR__ . '/../server/lore_grudges.json'), true, 16, JSON_THROW_ON_ERROR));
check(count($seeds) === 10, 'exactly ten sourced seeds ship');
$owners = array_unique(array_column($seeds, 'owner'));
check(count($owners) === 8, 'seeds cover eight named characters');
check(count(array_filter($seeds, static fn (array $s): bool => str_starts_with($s['counterpart'], 'daedra:'))) === 2, 'two Daedric grudges ship');
$doc = (string) file_get_contents(__DIR__ . '/../docs/lore-sources.md');
foreach ($seeds as $seed) {
    check(str_contains($doc, '- `' . $seed['id'] . '` — ' . $seed['source']), "seed {$seed['id']} has its cited source documented");
}

echo "lore data checks passed\n";
