# Sworn & Scorned — Plan 3: Surfacing, Director Page, Lore Grudges — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make issues matter in play and keep them under the user's control:
- inject one quiet, paced note on the speaker's turn;
- provide a loopback-only director page (settings, cast, key, worker status, spoiler-conscious journal, out-of-character dismiss and mute);
- seed lore grudges from a sourced data file;
- prove compatibility across all 8 combinations of Mind Poisoning, Private Conversation and SHARMAT.

**Architecture:**
- Pure helpers are unit-tested: audience and mention parsing, lore validation and seeding, page security, action parsing and HTML rendering.
- Database glue (the surfacing query, lore seeding, the page model) is tested in the clone's throwaway `sas_it` schema.
- The prompt note goes through `chimRegisterPromptInjection('character_bottom', …)` from `context_pre.php`, beside other plugins' context and never replacing it.

Spec: §2a, §7, §8, §10 (4–5), Amendment A.

**Tech Stack:** PHP 8.2, PostgreSQL (CHIM's), plain HTML and CSS (no page JavaScript), PCV's live-test helpers.

**Prerequisites:** Plans 1, 0 and 2 complete. The ground rules of Plan 2 apply unchanged: the test-clone-only PHP and the integration test command with `SAS_IT=1`.

---

## File structure

| File | Responsibility |
| --- | --- |
| `server/ledger.php` (modify) | Add `seeded` (lore seed ids already applied) to the ledger. |
| `server/lore.php` | Daedra vocabulary; seed-file validation, merge, `applyLoreSeeds()`. |
| `server/lore_grudges.json` | Shipped, UESP-sourced seed list (research task). |
| `server/hook.php` | Surfacing helpers plus `surfaceForSpeaker()`; `pgTextArray()`. |
| `server/context_pre.php` (modify) | Also register the surfacing note. |
| `server/queue.php` (modify) | `loadSeeds()`, `seedLoreOnce()`. |
| `server/worker.php` (modify) | Seed lore once a minute. |
| `server/page_security.php` | Headers, loopback gate, localhost handoff, CSRF check. |
| `server/page_actions.php` | POST validation, issue flag mutation, `performPageAction()`. |
| `server/page_model.php` | Build the page model from the database. |
| `server/page_view.php` | Render HTML (escaped; spoiler-conscious). |
| `server/index.php` | Page entry point. |
| `server/assets/style.css` | Page styles (light and dark). |
| `scripts/build-package.py` (modify) | Allowlist additions. |
| `docs/verification/2026-10-coexistence-matrix.md` | 8-combination results. |

---

### Task 1: Ledger `seeded` field

**Files:**
- Modify: `server/ledger.php` (`emptyLedger`, `normalizeLedger`)
- Modify: `tests/ledger_test.php`

- [ ] **Step 1: Update the tests first**

In `tests/ledger_test.php`, replace the first assertion line

```php
check($empty === ['playthrough_id' => 'pt-1', 'floor_event_id' => 0, 'processed' => [], 'issues' => []], 'empty ledger shape');
```

with

```php
check($empty === ['playthrough_id' => 'pt-1', 'floor_event_id' => 0, 'processed' => [], 'issues' => [], 'seeded' => []], 'empty ledger shape');
```

Replace

```php
$raw = ['playthrough_id' => 'pt-1', 'floor_event_id' => 0, 'processed' => [10], 'issues' => [sampleIssue()]];
```

with

```php
$raw = ['playthrough_id' => 'pt-1', 'floor_event_id' => 0, 'processed' => [10], 'issues' => [sampleIssue()], 'seeded' => ['gray-mane-feud']];
$legacy = $raw;
unset($legacy['seeded']);
check(normalizeLedger($legacy, 'pt-1')['seeded'] === [], 'older ledgers get an empty seeded list');
rejected(fn () => normalizeLedger(['seeded' => [3]] + $raw, 'pt-1'), 'seeded must be strings');
```

- [ ] **Step 2: Run to verify it fails**

Run: `… run-php-tests.sh tests/ledger_test.php`
Expected: `RuntimeException: empty ledger shape`, `exit=255`.

- [ ] **Step 3: Implement**

In `server/ledger.php`, change `emptyLedger` to return:

```php
    return ['playthrough_id' => $playthroughId, 'floor_event_id' => 0, 'processed' => [], 'issues' => [], 'seeded' => []];
```

In `normalizeLedger`, change the final `return [...]` to:

```php
    return [
        'playthrough_id' => $playthroughId,
        'floor_event_id' => $floor,
        'processed' => $processed,
        'issues' => array_map(validateIssue(...), $issues),
        'seeded' => array_key_exists('seeded', $raw) ? requireStringList($raw, 'seeded') : [],
    ];
```

- [ ] **Step 4: Run all unit tests**

Run: `… run-php-tests.sh tests/*_test.php`
Expected: every test passes (`exit=0`). The other suites build ledgers with `emptyLedger()`, so they pick up the new key.

- [ ] **Step 5: Commit**

```bash
git -c safe.directory='*' add server/ledger.php tests/ledger_test.php
git -c safe.directory='*' commit -m "Track applied lore seeds in the ledger"
```

---

### Task 2: Lore seed validation and application

**Files:**
- Create: `server/lore.php`
- Test: `tests/lore_test.php`

- [ ] **Step 1: Write the failing test `tests/lore_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
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
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => [['source' => 'https://example.com/x'] + $seed]]), 'non-UESP source rejected');
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => [['wants' => 'revenge'] + $seed]]), 'wants vocabulary enforced');
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => [['counterpart' => 'daedra:zeus'] + $seed]]), 'unknown daedra rejected');
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => [['counterpart' => 'Olfrid Battle-Born'] + $seed]]), 'self grudge rejected');
rejected(fn () => validateSeedFile(['version' => 1, 'seeds' => [['paraphrase' => str_repeat('x', 161)] + $seed]]), 'paraphrase capped at 160');

check(mergeSeeds([$seed], [['wants' => 'explanation'] + $seed])[0]['wants'] === 'explanation', 'override replaces by id');

$resolve = static fn (string $counterpart): ?string => match ($counterpart) {
    'Eorlund Gray-Mane' => 'npc:77',
    'daedra:molag_bal' => 'daedra:molag_bal',
    default => null,
};
$result = applyLoreSeeds(emptyLedger('pt-1'), [$seed, $daedra], $resolve);
check($result['status'] === 'applied' && $result['affinity_delta'] === 0, 'seeding applies without affinity');
check($result['ledger']['seeded'] === ['feud-test-a', 'vigil-test-b'], 'seeds marked');
$issue = $result['ledger']['issues'][0];
check($issue['id'] === issueId('lore:feud-test-a', 'npc:77', 'grievance') && $issue['origin'] === 'lore' && $issue['status'] === 'open', 'lore issue shape');
check($issue['evidence'][0]['ref'] === $seed['source'] && $issue['evidence'][0]['quote'] === $seed['paraphrase'], 'evidence is the cited paraphrase');
normalizeLedger(json_decode(json_encode($result['ledger'], JSON_THROW_ON_ERROR), true), 'pt-1');

check(applyLoreSeeds($result['ledger'], [$seed, $daedra], $resolve)['status'] === 'unchanged', 'seeds apply once');

$deferred = applyLoreSeeds(emptyLedger('pt-1'), [['counterpart' => 'Unmet Person'] + $seed], $resolve);
check($deferred['status'] === 'unchanged' && $deferred['ledger']['seeded'] === [], 'unknown counterpart is deferred, not marked');

echo "lore checks passed\n";
```

- [ ] **Step 2: Run to verify it fails**

Expected: fatal `Failed opening required '.../server/lore.php'`, `exit=255`.

- [ ] **Step 3: Create `server/lore.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

use UnexpectedValueException;

require_once __DIR__ . '/ledger.php';

const DAEDRA_NAMES = [
    'azura' => 'Azura', 'boethiah' => 'Boethiah', 'clavicus_vile' => 'Clavicus Vile',
    'hermaeus_mora' => 'Hermaeus Mora', 'hircine' => 'Hircine', 'jyggalag' => 'Jyggalag',
    'malacath' => 'Malacath', 'mehrunes_dagon' => 'Mehrunes Dagon', 'mephala' => 'Mephala',
    'meridia' => 'Meridia', 'molag_bal' => 'Molag Bal', 'namira' => 'Namira',
    'nocturnal' => 'Nocturnal', 'peryite' => 'Peryite', 'sanguine' => 'Sanguine',
    'sheogorath' => 'Sheogorath', 'vaermina' => 'Vaermina',
];
const LORE_SOURCE_PREFIX = 'https://en.uesp.net/wiki/';

/** @return list<array{id: string, owner: string, counterpart: string, wants: string, paraphrase: string, source: string}> */
function validateSeedFile(mixed $data): array
{
    if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['seeds'] ?? null) || !array_is_list($data['seeds'])) {
        throw new UnexpectedValueException('seed_file_invalid');
    }
    $seen = [];
    $seeds = [];
    foreach ($data['seeds'] as $seed) {
        if (!is_array($seed)) {
            throw new UnexpectedValueException('seed_invalid');
        }
        $id = $seed['id'] ?? null;
        if (!is_string($id) || preg_match('/\A[a-z0-9-]{3,80}\z/D', $id) !== 1 || isset($seen[$id])) {
            throw new UnexpectedValueException('seed_id_invalid');
        }
        $seen[$id] = true;
        foreach (['owner', 'counterpart', 'paraphrase', 'source'] as $key) {
            if (!is_string($seed[$key] ?? null) || trim($seed[$key]) === '') {
                throw new UnexpectedValueException("seed_{$key}_invalid");
            }
        }
        $owner = trim($seed['owner']);
        $counterpart = trim($seed['counterpart']);
        if (mb_strlen($owner) > 128) {
            throw new UnexpectedValueException('seed_owner_invalid');
        }
        $validCounterpart = str_starts_with($counterpart, 'daedra:')
            ? array_key_exists(substr($counterpart, 7), DAEDRA_NAMES)
            : mb_strlen($counterpart) <= 128 && strcasecmp($counterpart, $owner) !== 0;
        if (!$validCounterpart) {
            throw new UnexpectedValueException('seed_counterpart_invalid');
        }
        if (!in_array($seed['wants'] ?? null, WANTS, true)) {
            throw new UnexpectedValueException('seed_wants_invalid');
        }
        $paraphrase = trim($seed['paraphrase']);
        if (mb_strlen($paraphrase) > MAX_QUOTE_CHARS) {
            throw new UnexpectedValueException('seed_paraphrase_invalid');
        }
        if (!str_starts_with($seed['source'], LORE_SOURCE_PREFIX)) {
            throw new UnexpectedValueException('seed_source_invalid');
        }
        $seeds[] = ['id' => $id, 'owner' => $owner, 'counterpart' => $counterpart, 'wants' => $seed['wants'], 'paraphrase' => $paraphrase, 'source' => $seed['source']];
    }
    return $seeds;
}

/** User overrides replace built-in seeds with the same id. */
function mergeSeeds(array $builtIn, array $override): array
{
    $byId = [];
    foreach ([...$builtIn, ...$override] as $seed) {
        $byId[$seed['id']] = $seed;
    }
    return array_values($byId);
}

/**
 * Add this owner's not-yet-applied seeds as open lore grievances. Never changes affinity.
 * @param callable(string $counterpart): ?string $resolve counterpart text -> key, or null while unknown
 */
function applyLoreSeeds(array $ledger, array $seeds, callable $resolve): array
{
    $changed = false;
    foreach ($seeds as $seed) {
        if (in_array($seed['id'], $ledger['seeded'], true)) {
            continue;
        }
        $key = $resolve($seed['counterpart']);
        if ($key === null) {
            continue;
        }
        if (count(array_filter($ledger['issues'], isOpen(...))) >= MAX_OPEN_ISSUES) {
            break;
        }
        $ledger['issues'][] = [
            'id' => issueId('lore:' . $seed['id'], $key, 'grievance'),
            'origin' => 'lore',
            'kind' => 'grievance',
            'counterpart' => $key,
            'direction' => null,
            'strength' => null,
            'status' => 'open',
            'trajectory' => 'unchanged',
            'wants' => $seed['wants'],
            'evidence' => [[
                'ref' => $seed['source'], 'gamets' => null, 'speaker' => 'lore', 'quote' => $seed['paraphrase'],
                'label' => 'lore', 'p' => null, 'backend' => 'lore',
            ]],
            'links' => [],
            'affinity_applied' => [],
            'muted' => false,
            'dismissed' => false,
            'changed_event_id' => 0,
        ];
        $ledger['seeded'][] = $seed['id'];
        $changed = true;
    }
    return ['status' => $changed ? 'applied' : 'unchanged', 'ledger' => $ledger, 'affinity_delta' => 0];
}
```

- [ ] **Step 4: Run to verify it passes**

Expected: `lore checks passed`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
git -c safe.directory='*' add server/lore.php tests/lore_test.php
git -c safe.directory='*' commit -m "Add lore seed validation and application"
```

---

### Task 3: The lore grudge list (research task)

**Files:**
- Create: `server/lore_grudges.json`, `tests/lore_data_test.php`, `docs/lore-sources.md`

- [ ] **Step 1: Write the failing data test `tests/lore_data_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/lore.php';

use function ChimSwornAndScorned\validateSeedFile;

$seeds = validateSeedFile(json_decode((string) file_get_contents(__DIR__ . '/../server/lore_grudges.json'), true, 16, JSON_THROW_ON_ERROR));
check(count($seeds) >= 10, 'at least ten sourced seeds ship');
$owners = array_unique(array_column($seeds, 'owner'));
check(count($owners) >= 6, 'seeds cover several characters');
check(count(array_filter($seeds, static fn (array $s): bool => str_starts_with($s['counterpart'], 'daedra:'))) >= 2, 'Daedric grudges included');
$doc = (string) file_get_contents(__DIR__ . '/../docs/lore-sources.md');
foreach ($seeds as $seed) {
    check(str_contains($doc, $seed['id']), "seed {$seed['id']} is documented in docs/lore-sources.md");
}

echo "lore data checks passed\n";
```

- [ ] **Step 2: Run to verify it fails**

Expected: a failure opening `server/lore_grudges.json`, `exit=255`.

- [ ] **Step 3: Research the seeds (web research; UESP only)**

Dispatch a research agent (`search-research`), or do it yourself, with these rules:
- **Sources:** every seed needs a UESP page (`https://en.uesp.net/wiki/Skyrim:…`) that states the hostility or grudge. Verify the statement on the page itself; do not rely on memory.
- **Named pairs only** (spec §8): two named vanilla Skyrim characters, or a named character and a Daedric prince key from `DAEDRA_NAMES`. No faction or "all members" entries. A mutual feud becomes two entries, one per owner, only where the page supports each direction.
- **Paraphrase** in your own words, at most 160 characters, written from the owner's point of view, with no verbatim copying from UESP. `wants` comes from `explanation | apology | restitution | keep_promise | thanks | unknown`; use `unknown` unless the page supports something specific.
- **Starting points, each to verify:** Gray-Mane and Battle-Born family members (Whiterun); Elisif the Fair toward Ulfric Stormcloak; members of the Vigilants of Stendarr toward Daedric princes; characters tied to Meridia, Boethiah or Molag Bal quests whose UESP pages describe hostility. Drop any candidate the page does not support.
- **Owner names** must match in-game display names exactly as UESP gives them.

Write `server/lore_grudges.json`:

```json
{
  "version": 1,
  "seeds": [
    {
      "id": "<owner-counterpart-slug>",
      "owner": "<exact NPC name>",
      "counterpart": "<exact NPC name or daedra:key>",
      "wants": "unknown",
      "paraphrase": "<own words, ≤160 chars, owner's point of view>",
      "source": "https://en.uesp.net/wiki/Skyrim:<Page>"
    }
  ]
}
```

Write `docs/lore-sources.md` with one bullet per seed: id, the source URL, and one sentence saying what on the page supports it.

- [ ] **Step 4: Run to verify it passes**

Expected: `lore data checks passed`, `exit=0`.

- [ ] **Step 5: Ask the user to skim the list**

Show the user the owner, counterpart and paraphrase table, and remove anything they veto. Lore is their call.

- [ ] **Step 6: Commit**

```bash
git -c safe.directory='*' add server/lore_grudges.json docs/lore-sources.md tests/lore_data_test.php
git -c safe.directory='*' commit -m "Add UESP-sourced lore grudge seeds"
```

---

### Task 4: Lore seeding in the worker

**Files:**
- Create: `server/hook.php` (only `pgTextArray()` in this task; Task 5 adds the rest)
- Modify: `server/queue.php`, `server/worker.php`
- Test: `tests/integration/lore_it.php`

- [ ] **Step 1: Create `server/hook.php` with the array helper**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/pg.php';

function pgTextArray(array $values): string
{
    return '{' . implode(',', array_map(static fn (string $value): string => '"' . addcslashes($value, "\"\\") . '"', $values)) . '}';
}
```

- [ ] **Step 2: Write the failing integration test `tests/integration/lore_it.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../support/check.php';
require __DIR__ . '/../../server/queue.php';

use ChimSwornAndScorned\PerspectiveStore;
use function ChimSwornAndScorned\{openEngineConnection, seedLoreOnce};

if (getenv('SAS_IT') !== '1') {
    echo "lore integration skipped (set SAS_IT=1 inside the test clone)\n";
    exit(0);
}
check(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'integration tests run only in the test clone');

$pg = openEngineConnection('/var/www/html/HerikaServer');
foreach ([
    'DROP SCHEMA IF EXISTS sas_it CASCADE',
    'CREATE SCHEMA sas_it',
    'CREATE TABLE sas_it.core_npc_master (LIKE public.core_npc_master INCLUDING ALL)',
    'CREATE TABLE sas_it.core_npc_master_history (LIKE public.core_npc_master_history INCLUDING ALL)',
    'SET search_path = sas_it, public',
] as $sql) {
    $pg->exec($sql);
}
try {
    $template = $pg->one("SELECT id FROM public.core_npc_master WHERE npc_name <> 'The Narrator' ORDER BY id LIMIT 1");
    $addNpc = static function (int $id, string $name) use ($pg, $template): void {
        $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id = $1', [$template['id']]);
        $pg->exec("UPDATE sas_it.core_npc_master SET id = $1, npc_name = $2, plugin_extended_data = '{}'::jsonb, lock_profile = 0 WHERE id = $3", [$id, $name, $template['id']]);
    };
    $addNpc(900011, 'Olfrid IT');
    $seeds = [
        ['id' => 'it-feud', 'owner' => 'Olfrid IT', 'counterpart' => 'Eorlund IT', 'wants' => 'unknown', 'paraphrase' => 'Old feud.', 'source' => 'https://en.uesp.net/wiki/Skyrim:X'],
        ['id' => 'it-daedra', 'owner' => 'Olfrid IT', 'counterpart' => 'daedra:molag_bal', 'wants' => 'unknown', 'paraphrase' => 'Hates Molag Bal.', 'source' => 'https://en.uesp.net/wiki/Skyrim:Y'],
    ];
    $pt = ['id' => 'pt-it', 'player_name' => 'Hawke IT'];
    $store = new PerspectiveStore($pg);
    $ledger = static fn (): array => json_decode($pg->one("SELECT (plugin_extended_data->'sworn_and_scorned')::text AS l FROM sas_it.core_npc_master WHERE id = 900011")['l'] ?? 'null', true) ?? [];

    check(seedLoreOnce($pg, $store, $pt, $seeds) === 1, 'owner seeded once');
    check($ledger()['seeded'] === ['it-daedra'], 'daedra seed applied; unmet NPC counterpart deferred');
    check(seedLoreOnce($pg, $store, $pt, $seeds) === 0, 'nothing new to seed');
    $addNpc(900012, 'Eorlund IT');
    check(seedLoreOnce($pg, $store, $pt, $seeds) === 1, 'deferred seed applies once the counterpart exists');
    check($ledger()['seeded'] === ['it-daedra', 'it-feud'], 'both seeds marked');
    check((int) $pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history WHERE npc_id = 900011')['n'] === 2, 'one snapshot per applied seeding');
} finally {
    $pg->exec('DROP SCHEMA IF EXISTS sas_it CASCADE');
}

echo "lore integration checks passed\n";
```

- [ ] **Step 3: Run to verify it fails**

Run: `… env SAS_IT=1 bash scripts/run-php-tests.sh tests/integration/lore_it.php`
Expected: fatal `Call to undefined function ChimSwornAndScorned\seedLoreOnce()`, `exit=255`.

- [ ] **Step 4: Add seeding to `server/queue.php`**

Add these requires next to the existing ones:

```php
require_once __DIR__ . '/lore.php';
require_once __DIR__ . '/hook.php';
```

Append at the end of the file:

```php
const LORE_INTERVAL_SECONDS = 60;
const LORE_OVERRIDE_MAX_BYTES = 262144;

/** Built-in seeds plus the optional user override at <engine>/data/sworn_and_scorned_lore.json. */
function loadSeeds(string $pluginDir, string $engineRoot): array
{
    $builtIn = validateSeedFile(json_decode((string) file_get_contents($pluginDir . '/lore_grudges.json'), true));
    $overridePath = $engineRoot . '/data/sworn_and_scorned_lore.json';
    $override = [];
    if (is_file($overridePath) && !is_link($overridePath) && filesize($overridePath) <= LORE_OVERRIDE_MAX_BYTES) {
        try {
            $override = validateSeedFile(json_decode((string) file_get_contents($overridePath), true));
        } catch (\UnexpectedValueException) {
            sasLog('lore_override_invalid', 'warning');
        }
    }
    return mergeSeeds($builtIn, $override);
}

/** Apply pending seeds to every existing, unambiguous owner row. Returns owners committed. */
function seedLoreOnce(Pg $pg, PerspectiveStore $store, array $playthrough, array $seeds): int
{
    $byOwner = [];
    foreach ($seeds as $seed) {
        $byOwner[mb_strtolower($seed['owner'])][] = $seed;
    }
    if ($byOwner === []) {
        return 0;
    }
    $rows = $pg->all(
        "SELECT id, npc_name, (plugin_extended_data->'sworn_and_scorned')::text AS ledger FROM core_npc_master WHERE lower(npc_name) = ANY($1::text[])",
        [pgTextArray(array_keys($byOwner))]
    );
    $rowsByName = [];
    foreach ($rows as $row) {
        $rowsByName[mb_strtolower($row['npc_name'])][] = $row;
    }
    $resolve = static function (string $counterpart) use ($pg): ?string {
        if (str_starts_with($counterpart, 'daedra:')) {
            return $counterpart;
        }
        $matches = $pg->all('SELECT id FROM core_npc_master WHERE lower(npc_name) = lower($1) LIMIT 2', [$counterpart]);
        return count($matches) === 1 ? 'npc:' . (int) $matches[0]['id'] : null;
    };
    $committed = 0;
    foreach ($rowsByName as $name => $matches) {
        if (count($matches) !== 1) {
            continue; // ambiguous owner name
        }
        $row = $matches[0];
        $current = isset($row['ledger']) ? json_decode($row['ledger'], true) : null;
        $seeded = is_array($current) && ($current['playthrough_id'] ?? null) === $playthrough['id'] && is_array($current['seeded'] ?? null) ? $current['seeded'] : [];
        $pending = array_values(array_filter($byOwner[$name], static fn (array $seed): bool => !in_array($seed['id'], $seeded, true)));
        if ($pending === []) {
            continue;
        }
        $status = $store->commitLedger(
            ['owner_npc_id' => (int) $row['id'], 'owner_name' => $row['npc_name'], 'playthrough_id' => $playthrough['id'], 'event_ids' => [], 'counterpart_name' => null],
            static fn (array $ledger): array => applyLoreSeeds($ledger, $pending, $resolve)
        );
        if ($status === 'committed') {
            $committed++;
        }
    }
    return $committed;
}
```

- [ ] **Step 5: Seed from the worker loop**

In `server/worker.php`, after `ensureSchema($pg);`, add:

```php
try {
    $seeds = loadSeeds(__DIR__, $engineRoot);
} catch (\Throwable) {
    $seeds = [];
    sasLog('lore_seeds_invalid', 'error');
}
$lastLore = 0;
```

Inside the loop, directly after `$worked = workerTick($pg, $settings, time());`, add:

```php
        if ($seeds !== [] && time() - $lastLore >= LORE_INTERVAL_SECONDS) {
            $lastLore = time();
            $playthrough = currentPlaythrough($pg);
            if ($playthrough !== null) {
                seedLoreOnce($pg, new PerspectiveStore($pg), $playthrough, $seeds);
            }
        }
```

- [ ] **Step 6: Run to verify it passes**

Run the lore integration test, then `php -l server/worker.php`.
Expected: `lore integration checks passed`, `exit=0`, and the worker lints clean.

- [ ] **Step 7: Commit**

```bash
git -c safe.directory='*' add server/hook.php server/queue.php server/worker.php tests/integration/lore_it.php
git -c safe.directory='*' commit -m "Seed lore grudges lazily from the worker"
```

---

### Task 5: Surfacing the note on the speaker's turn

**Files:**
- Modify: `server/hook.php`, `server/context_pre.php`
- Test: `tests/hook_test.php` (unit), `tests/integration/hook_it.php`

- [ ] **Step 1: Write the failing unit test `tests/hook_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/hook.php';

use function ChimSwornAndScorned\{audienceNames, displayName, mentionedKeys, pgTextArray, playerInputText, presentKeys};

check(audienceNames('|Lidia Sobieska|Farkas|Hawke|', 'Lidia Sobieska') === ['Farkas', 'Hawke'], 'audience minus the speaker');
check(audienceNames(null, 'Lidia') === [], 'no audience');
check(presentKeys(['Farkas', 'Hawke', 'Stranger'], 'Hawke', ['farkas' => 3]) === ['npc:3', 'player'], 'present keys; unknown names ignored');
check(
    mentionedKeys("Hawke: Did Molag Bal's priest find you, Farkas?", ['daedra:molag_bal' => 'Molag Bal', 'npc:3' => 'Farkas', 'npc:4' => 'Aela'])
        === ['daedra:molag_bal', 'npc:3'],
    'whole-name, case-insensitive mentions'
);
check(mentionedKeys('Farkasson is here', ['npc:3' => 'Farkas']) === [], 'no partial-word matches');
check(displayName('player', 'Hawke', []) === 'Hawke' && displayName('daedra:molag_bal', 'Hawke', []) === 'Molag Bal'
    && displayName('npc:3', 'Hawke', [3 => 'Farkas']) === 'Farkas' && displayName('npc:9', 'Hawke', []) === null, 'display names');
check(playerInputText(['inputtext', '1', '2', 'Hawke: Lidia, are we good?'], 'Hawke') === 'Lidia, are we good?', 'player input text');
check(playerInputText(['rechat', '1', '2', '{}'], 'Hawke') === '', 'no player text on rechat');
check(pgTextArray(['a', 'b"c', 'd\\e']) === '{"a","b\\"c","d\\\\e"}', 'text array escaping');

echo "hook checks passed\n";
```

- [ ] **Step 2: Run to verify it fails**

Expected: fatal `Call to undefined function ChimSwornAndScorned\audienceNames()`, `exit=255`.

- [ ] **Step 3: Add the helpers and `surfaceForSpeaker()` to `server/hook.php`**

Replace the file with:

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/pg.php';
require_once __DIR__ . '/tables.php';
require_once __DIR__ . '/rows.php';
require_once __DIR__ . '/lore.php';
require_once __DIR__ . '/surfacing.php';
require_once __DIR__ . '/logging.php';

const GENERATING_REQUEST_TYPES = ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s', 'rechat', 'continue', 'continue_group'];
const NOTE_PRIORITY = 900;

function pgTextArray(array $values): string
{
    return '{' . implode(',', array_map(static fn (string $value): string => '"' . addcslashes($value, "\"\\") . '"', $values)) . '}';
}

/** Names in CHIM's pipe audience (PCV narrows it to scene members), minus the speaker. */
function audienceNames(?string $cachePeople, string $speaker): array
{
    return array_values(array_filter(parsePeople($cachePeople), static fn (string $name): bool => strcasecmp($name, $speaker) !== 0));
}

/** @param array<string, int> $npcIdsByLowerName */
function presentKeys(array $names, string $playerName, array $npcIdsByLowerName): array
{
    $keys = [];
    foreach ($names as $name) {
        if (strcasecmp($name, $playerName) === 0) {
            $keys[] = 'player';
            continue;
        }
        $id = $npcIdsByLowerName[mb_strtolower($name)] ?? null;
        if ($id !== null) {
            $keys[] = 'npc:' . $id;
        }
    }
    return array_values(array_unique($keys));
}

/** @param array<string, string> $candidates counterpart key => display name */
function mentionedKeys(string $text, array $candidates): array
{
    $found = [];
    foreach ($candidates as $key => $name) {
        if ($name !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $text) === 1) {
            $found[] = $key;
        }
    }
    return $found;
}

function displayName(string $key, string $playerName, array $npcNamesById): ?string
{
    if ($key === 'player') {
        return $playerName;
    }
    if (str_starts_with($key, 'daedra:')) {
        return DAEDRA_NAMES[substr($key, 7)] ?? null;
    }
    if (str_starts_with($key, 'npc:')) {
        return $npcNamesById[(int) substr($key, 4)] ?? null;
    }
    return null;
}

function playerInputText(array $gameRequest, string $playerName): string
{
    if (!in_array($gameRequest[0] ?? '', PLAYER_INPUT_TYPES, true)) {
        return '';
    }
    $data = (string) ($gameRequest[3] ?? '');
    $prefix = $playerName . ': ';
    return str_starts_with($data, $prefix) ? substr($data, strlen($prefix)) : $data;
}

function recentSpeechText(Pg $pg, int $now): string
{
    $rows = $pg->all(
        "SELECT data FROM eventlog WHERE type IN ('chat', 'inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s')
         AND localts >= $1 ORDER BY rowid DESC LIMIT 4",
        [$now - 120]
    );
    return implode("\n", array_column($rows, 'data'));
}

/** The note for this speaker's turn, or null. Records pacing when a note is returned. */
function surfaceForSpeaker(Pg $pg, array $gameRequest, string $speaker, mixed $cachePeople, int $now): ?string
{
    if ($speaker === '') {
        return null;
    }
    if (!loadSettings($pg)['enabled']) {
        return null;
    }
    $playthrough = currentPlaythrough($pg);
    if ($playthrough === null) {
        return null;
    }
    $rows = $pg->all("SELECT id, (plugin_extended_data->'sworn_and_scorned')::text AS ledger FROM core_npc_master WHERE lower(npc_name) = lower($1) LIMIT 2", [$speaker]);
    if (count($rows) !== 1 || $rows[0]['ledger'] === null) {
        return null;
    }
    $speakerId = (int) $rows[0]['id'];
    $ledger = normalizeLedger(json_decode($rows[0]['ledger'], true), $playthrough['id']);
    if ($ledger['issues'] === []) {
        return null;
    }

    $names = audienceNames(is_string($cachePeople) ? $cachePeople : null, $speaker);
    $issueNpcIds = [];
    foreach ($ledger['issues'] as $issue) {
        if (str_starts_with($issue['counterpart'], 'npc:')) {
            $issueNpcIds[] = (int) substr($issue['counterpart'], 4);
        }
    }
    $npcNamesById = [];
    $npcIdsByLowerName = [];
    $lookup = $pg->all(
        'SELECT id, npc_name FROM core_npc_master WHERE lower(npc_name) = ANY($1::text[]) OR id = ANY($2::bigint[])',
        [pgTextArray(array_map('mb_strtolower', $names)), pgIntArray($issueNpcIds)]
    );
    foreach ($lookup as $row) {
        $npcNamesById[(int) $row['id']] = $row['npc_name'];
        $lower = mb_strtolower($row['npc_name']);
        $npcIdsByLowerName[$lower] = isset($npcIdsByLowerName[$lower]) ? -1 : (int) $row['id'];
    }
    $npcIdsByLowerName = array_filter($npcIdsByLowerName, static fn (int $id): bool => $id > 0);

    $candidates = [];
    foreach ($ledger['issues'] as $issue) {
        $name = displayName($issue['counterpart'], $playthrough['player_name'], $npcNamesById);
        if ($name !== null) {
            $candidates[$issue['counterpart']] = $name;
        }
    }
    $text = playerInputText($gameRequest, $playthrough['player_name']) . "\n" . recentSpeechText($pg, $now);
    $pacing = [];
    foreach ($pg->all('SELECT issue_id, surfaced_at, evidence_count FROM ' . sasTable('pacing') . ' WHERE npc_id = $1', [$speakerId]) as $row) {
        $pacing[$row['issue_id']] = ['at' => (int) $row['surfaced_at'], 'evidence_count' => (int) $row['evidence_count']];
    }
    $issue = selectIssueToSurface(
        $ledger,
        presentKeys($names, $playthrough['player_name'], $npcIdsByLowerName),
        mentionedKeys($text, $candidates),
        $pacing,
        $now
    );
    if ($issue === null || !isset($candidates[$issue['counterpart']])) {
        return null;
    }
    $pg->exec(
        'INSERT INTO ' . sasTable('pacing') . ' AS p (npc_id, issue_id, surfaced_at, evidence_count) VALUES ($1, $2, $3, $4)
         ON CONFLICT (npc_id, issue_id) DO UPDATE SET surfaced_at = EXCLUDED.surfaced_at, evidence_count = EXCLUDED.evidence_count',
        [$speakerId, $issue['id'], $now, count($issue['evidence'])]
    );
    sasLog('issue_surfaced', 'info', ['npc_id' => $speakerId, 'issue_id' => $issue['id']]);
    return renderNote($issue, $speaker, $candidates[$issue['counterpart']]);
}
```

In `server/queue.php`, delete the now-duplicated `pgTextArray` if it was added there. It lives only in `hook.php`, which `queue.php` requires.

- [ ] **Step 4: Run the unit test to verify it passes**

Expected: `hook checks passed`, `exit=0`.

- [ ] **Step 5: Write the integration test `tests/integration/hook_it.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../support/check.php';
require __DIR__ . '/../support/fixtures.php';
require __DIR__ . '/../../server/hook.php';

use function ChimSwornAndScorned\{currentPlaythrough, emptyLedger, ensureSchema, openEngineConnection, saveSetting, surfaceForSpeaker};

if (getenv('SAS_IT') !== '1') {
    echo "hook integration skipped (set SAS_IT=1 inside the test clone)\n";
    exit(0);
}
check(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'integration tests run only in the test clone');
putenv('SAS_TEST_SCHEMA=sas_it');

$pg = openEngineConnection('/var/www/html/HerikaServer');
foreach ([
    'DROP SCHEMA IF EXISTS sas_it CASCADE',
    'CREATE SCHEMA sas_it',
    'CREATE TABLE sas_it.core_npc_master (LIKE public.core_npc_master INCLUDING ALL)',
    'CREATE TABLE sas_it.eventlog (LIKE public.eventlog INCLUDING ALL)',
    'SET search_path = sas_it, public',
] as $sql) {
    $pg->exec($sql);
}
try {
    ensureSchema($pg);
    saveSetting($pg, 'enabled', true);
    $pt = currentPlaythrough($pg);
    check($pt !== null, 'clone has a resolvable playthrough');
    $template = $pg->one("SELECT id FROM public.core_npc_master WHERE npc_name <> 'The Narrator' ORDER BY id LIMIT 1");
    $ledger = emptyLedger($pt['id']);
    $ledger['issues'][] = sampleIssue(['id' => 'g000000000000001', 'counterpart' => 'npc:900002']);
    foreach ([[900001, 'Lydia IT', $ledger], [900002, 'Farkas IT', null]] as [$id, $name, $data]) {
        $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id = $1', [$template['id']]);
        $pg->exec(
            "UPDATE sas_it.core_npc_master SET id = $1, npc_name = $2,
                plugin_extended_data = CASE WHEN $3::text IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('sworn_and_scorned', $3::jsonb) END
             WHERE id = $4",
            [$id, $name, $data === null ? null : json_encode($data), $template['id']]
        );
    }
    $request = ['inputtext', '1', '2', $pt['player_name'] . ': Hello there.'];
    $now = time();

    check(surfaceForSpeaker($pg, $request, 'Lydia IT', '|Lydia IT|' . $pt['player_name'] . '|', $now) === null, 'no note when the counterpart is absent');
    $note = surfaceForSpeaker($pg, $request, 'Lydia IT', '|Lydia IT|Farkas IT|', $now);
    check(is_string($note) && str_starts_with($note, 'On your mind: unresolved with Farkas IT.'), 'note when the counterpart is present');
    check(surfaceForSpeaker($pg, $request, 'Lydia IT', '|Lydia IT|Farkas IT|', $now + 10) === null, 'cooldown suppresses a repeat');
    $named = ['inputtext', '1', '2', $pt['player_name'] . ': What is wrong between you and Farkas IT?'];
    check(is_string(surfaceForSpeaker($pg, $named, 'Lydia IT', '|Lydia IT|Farkas IT|', $now + 20)), 'naming the counterpart overrides the cooldown');
    check(surfaceForSpeaker($pg, $request, 'Farkas IT', '|Lydia IT|Farkas IT|', $now) === null, 'other speakers do not get Lydia\'s note');
    saveSetting($pg, 'enabled', false);
    check(surfaceForSpeaker($pg, $named, 'Lydia IT', '|Lydia IT|Farkas IT|', $now + 999) === null, 'disabled plugin injects nothing');
} finally {
    $pg->exec('DROP SCHEMA IF EXISTS sas_it CASCADE');
    putenv('SAS_TEST_SCHEMA');
}

echo "hook integration checks passed\n";
```

- [ ] **Step 6: Run it to verify it passes**

Expected: `hook integration checks passed`, `exit=0`.

- [ ] **Step 7: Register the note in `server/context_pre.php`**

Append to the file, after the existing `try { … } catch` block:

```php
// Surface at most one issue as private context on this speaker's turn (spec §7.1).
try {
    require_once __DIR__ . '/hook.php';
    $sasRequest = $GLOBALS['gameRequest'] ?? null;
    if (is_array($sasRequest)
        && in_array($sasRequest[0] ?? null, GENERATING_REQUEST_TYPES, true)
        && function_exists('chimRegisterPromptInjection')) {
        $sasNote = surfaceForSpeaker(sharedConnection(), $sasRequest, (string) ($GLOBALS['HERIKA_NAME'] ?? ''), $GLOBALS['CACHE_PEOPLE'] ?? null, time());
        if ($sasNote !== null) {
            \chimRegisterPromptInjection('character_bottom', 'sworn_and_scorned', $sasNote, NOTE_PRIORITY);
        }
    }
} catch (\Throwable) {
    // Silent by design: never disturb the request.
}
```

Lint it: `php -l server/context_pre.php` gives `No syntax errors detected`.

- [ ] **Step 8: Commit**

```bash
git -c safe.directory='*' add server/hook.php server/queue.php server/context_pre.php tests/hook_test.php tests/integration/hook_it.php
git -c safe.directory='*' commit -m "Surface one paced note on the speaker's turn"
```

---

### Task 6: Page security

**Files:**
- Create: `server/page_security.php`
- Test: `tests/page_security_test.php`

- [ ] **Step 1: Write the failing test `tests/page_security_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/page_security.php';

use function ChimSwornAndScorned\{csrfTokenValid, localHandoffUrl, pageAccessAllowed};

check(pageAccessAllowed(['REMOTE_ADDR' => '127.0.0.1']), 'loopback allowed');
check(pageAccessAllowed(['REMOTE_ADDR' => '::1']), 'IPv6 loopback allowed');
check(!pageAccessAllowed(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '10.0.0.9']), 'forwarded requests refused');
check(!pageAccessAllowed(['REMOTE_ADDR' => '172.20.0.1']), 'non-loopback refused');
check(pageAccessAllowed(['REMOTE_ADDR' => '10.0.0.5', 'REMOTE_USER' => 'admin']), 'authenticated user allowed');

$server = ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/HerikaServer/ext/sworn_and_scorned/index.php', 'SERVER_PORT' => '8081'];
check(localHandoffUrl($server, ['local' => '1', 'view' => 'director'])
    === 'http://localhost:8081/HerikaServer/ext/sworn_and_scorned/index.php?view=director', 'handoff to localhost');
check(localHandoffUrl($server, []) === null, 'handoff only when asked');
check(localHandoffUrl(['REQUEST_METHOD' => 'POST'] + $server, ['local' => '1']) === null, 'no handoff for POST');
check(localHandoffUrl(['SCRIPT_NAME' => '/x/../evil.php'] + $server, ['local' => '1']) === null, 'traversal refused');

$token = str_repeat('a', 64);
check(csrfTokenValid($token, $token), 'matching token');
check(!csrfTokenValid($token, str_repeat('b', 64)) && !csrfTokenValid($token, null) && !csrfTokenValid('', ''), 'bad tokens refused');

echo "page security checks passed\n";
```

- [ ] **Step 2: Run to verify it fails**

Expected: fatal `Failed opening required '.../server/page_security.php'`, `exit=255`.

- [ ] **Step 3: Create `server/page_security.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

function sendSecurityHeaders(): void
{
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'none'");
}

/** The page holds an API key: loopback without forwarding headers, or an authenticated user (MP's rule). */
function pageAccessAllowed(array $server): bool
{
    $forwarded = array_key_exists('HTTP_FORWARDED', $server)
        || array_key_exists('HTTP_X_FORWARDED_FOR', $server)
        || array_key_exists('HTTP_X_REAL_IP', $server);
    $remote = $server['REMOTE_ADDR'] ?? null;
    $loopback = !$forwarded && is_string($remote) && in_array($remote, ['127.0.0.1', '::1'], true);
    $user = $server['REMOTE_USER'] ?? null;
    return $loopback || (is_string($user) && trim($user) !== '');
}

/** Same page on localhost, for GET ?local=1 opened through the WSL address (manifest config_url). */
function localHandoffUrl(array $server, array $query): ?string
{
    if (($server['REQUEST_METHOD'] ?? '') !== 'GET' || ($query['local'] ?? null) !== '1') {
        return null;
    }
    $script = (string) ($server['SCRIPT_NAME'] ?? '');
    if (preg_match('~\A/[A-Za-z0-9_./-]{1,200}\.php\z~D', $script) !== 1 || str_contains($script, '..')) {
        return null;
    }
    $port = filter_var($server['SERVER_PORT'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port === false) {
        return null;
    }
    $https = ($server['HTTPS'] ?? '') !== '' && ($server['HTTPS'] ?? '') !== 'off';
    $scheme = $https ? 'https' : 'http';
    $defaultPort = $https ? 443 : 80;
    unset($query['local']);
    $queryString = http_build_query($query);
    return $scheme . '://localhost' . ($port === $defaultPort ? '' : ':' . $port) . $script . ($queryString === '' ? '' : '?' . $queryString);
}

function csrfTokenValid(string $sessionToken, mixed $submitted): bool
{
    return preg_match('/\A[a-f0-9]{64}\z/D', $sessionToken) === 1 && is_string($submitted) && hash_equals($sessionToken, $submitted);
}
```

- [ ] **Step 4: Run to verify it passes**

Expected: `page security checks passed`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
git -c safe.directory='*' add server/page_security.php tests/page_security_test.php
git -c safe.directory='*' commit -m "Add page access gate, localhost handoff and CSRF check"
```

---

### Task 7: Page actions

**Files:**
- Create: `server/page_actions.php`
- Test: `tests/page_actions_test.php`

- [ ] **Step 1: Write the failing test `tests/page_actions_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/page_actions.php';

use function ChimSwornAndScorned\{emptyLedger, issueFlagMutation, parsePageAction};

$known = [5124, 2892, 3];
check(parsePageAction(['action' => 'save_settings', 'enabled' => '1', 'cast' => ['5124', '2892'], 'model' => 'typesafe/jev-1.13'], $known)
    === ['action' => 'save_settings', 'enabled' => true, 'cast' => [5124, 2892], 'model' => 'typesafe/jev-1.13'], 'save settings');
check(parsePageAction(['action' => 'save_settings', 'model' => 'typesafe/jev-1.13'], $known)['enabled'] === false, 'unchecked box disables');
rejected(fn () => parsePageAction(['action' => 'save_settings', 'cast' => ['999'], 'model' => 'x/y'], $known), 'unknown cast id refused');
rejected(fn () => parsePageAction(['action' => 'save_settings', 'cast' => array_map('strval', range(1, 9)), 'model' => 'x/y'], range(1, 9)), 'cast over 8 refused');
check(parsePageAction(['action' => 'set_key', 'api_key' => ' sk-or-abc '], $known) === ['action' => 'set_key', 'api_key' => 'sk-or-abc'], 'set key');
rejected(fn () => parsePageAction(['action' => 'set_key', 'api_key' => 'has space'], $known), 'key with whitespace refused');
check(parsePageAction(['action' => 'clear_key'], $known) === ['action' => 'clear_key'], 'clear key');
check(parsePageAction(['action' => 'issue_flag', 'npc_id' => '3', 'issue_id' => 'abc123abc123abc1', 'flag' => 'mute'], $known)
    === ['action' => 'issue_flag', 'npc_id' => 3, 'issue_id' => 'abc123abc123abc1', 'flag' => 'mute'], 'issue flag');
rejected(fn () => parsePageAction(['action' => 'issue_flag', 'npc_id' => '3', 'issue_id' => 'ZZZ', 'flag' => 'mute'], $known), 'bad issue id refused');
rejected(fn () => parsePageAction(['action' => 'drop_tables'], $known), 'unknown action refused');

$ledger = emptyLedger('pt-1');
$ledger['issues'][] = sampleIssue();
$muted = issueFlagMutation('abc123abc123abc1', 'mute')($ledger);
check($muted['status'] === 'applied' && $muted['ledger']['issues'][0]['muted'] === true && $muted['affinity_delta'] === 0, 'mute applies without affinity');
check(issueFlagMutation('abc123abc123abc1', 'mute')($muted['ledger'])['status'] === 'unchanged', 'mute is idempotent');
check(issueFlagMutation('abc123abc123abc1', 'unmute')($muted['ledger'])['ledger']['issues'][0]['muted'] === false, 'unmute');
$dismissed = issueFlagMutation('abc123abc123abc1', 'dismiss')($ledger);
check($dismissed['ledger']['issues'][0]['dismissed'] === true && $dismissed['affinity_delta'] === 0, 'dismiss is an OOC override with no affinity');
check(issueFlagMutation('ffffffffffffffff', 'mute')($ledger)['status'] === 'unchanged', 'unknown issue unchanged');

echo "page actions checks passed\n";
```

- [ ] **Step 2: Run to verify it fails**

Expected: fatal `Failed opening required '.../server/page_actions.php'`, `exit=255`.

- [ ] **Step 3: Create `server/page_actions.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

use UnexpectedValueException;

require_once __DIR__ . '/tables.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/launcher.php';

/** @param list<int> $knownNpcIds */
function parsePageAction(array $post, array $knownNpcIds): array
{
    $action = $post['action'] ?? null;
    if ($action === 'save_settings') {
        $cast = [];
        foreach (is_array($post['cast'] ?? null) ? $post['cast'] : [] as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if (!is_int($id) || !in_array($id, $knownNpcIds, true)) {
                throw new UnexpectedValueException('cast_invalid');
            }
            if (!in_array($id, $cast, true)) {
                $cast[] = $id;
            }
        }
        if (count($cast) > MAX_CAST) {
            throw new UnexpectedValueException('cast_too_large');
        }
        $model = is_string($post['model'] ?? null) ? trim($post['model']) : '';
        if (preg_match('~\A[A-Za-z0-9~._/-]{3,100}\z~D', $model) !== 1) {
            throw new UnexpectedValueException('model_invalid');
        }
        return ['action' => 'save_settings', 'enabled' => ($post['enabled'] ?? null) === '1', 'cast' => $cast, 'model' => $model];
    }
    if ($action === 'set_key') {
        $key = is_string($post['api_key'] ?? null) ? trim($post['api_key']) : '';
        if ($key === '' || strlen($key) > 400 || preg_match('/\s/', $key) === 1) {
            throw new UnexpectedValueException('key_invalid');
        }
        return ['action' => 'set_key', 'api_key' => $key];
    }
    if ($action === 'clear_key') {
        return ['action' => 'clear_key'];
    }
    if ($action === 'issue_flag') {
        $npcId = filter_var($post['npc_id'] ?? null, FILTER_VALIDATE_INT);
        $issueId = $post['issue_id'] ?? null;
        $flag = $post['flag'] ?? null;
        if (!is_int($npcId) || !in_array($npcId, $knownNpcIds, true)
            || !is_string($issueId) || preg_match('/\A[0-9a-f]{16}\z/D', $issueId) !== 1
            || !in_array($flag, ['dismiss', 'mute', 'unmute'], true)) {
            throw new UnexpectedValueException('issue_flag_invalid');
        }
        return ['action' => 'issue_flag', 'npc_id' => $npcId, 'issue_id' => $issueId, 'flag' => $flag];
    }
    throw new UnexpectedValueException('action_unknown');
}

/** Out-of-character override: never an in-world event, never affinity (spec §7.2). */
function issueFlagMutation(string $issueId, string $flag): callable
{
    return static function (array $ledger) use ($issueId, $flag): array {
        foreach ($ledger['issues'] as $index => $issue) {
            if ($issue['id'] !== $issueId) {
                continue;
            }
            [$field, $value] = match ($flag) {
                'dismiss' => ['dismissed', true],
                'mute' => ['muted', true],
                default => ['muted', false],
            };
            if ($issue[$field] === $value) {
                break;
            }
            $ledger['issues'][$index][$field] = $value;
            return ['status' => 'applied', 'ledger' => $ledger, 'affinity_delta' => 0];
        }
        return ['status' => 'unchanged', 'ledger' => $ledger, 'affinity_delta' => 0];
    };
}

/** Perform a validated action; returns a short notice for the page. */
function performPageAction(Pg $pg, array $action, string $engineRoot, string $pluginDir): string
{
    switch ($action['action']) {
        case 'save_settings':
            saveSetting($pg, 'enabled', $action['enabled']);
            saveSetting($pg, 'cast', $action['cast']);
            saveSetting($pg, 'model', $action['model']);
            if ($action['enabled']) {
                ensureWorkerRunning($engineRoot, $pluginDir);
            }
            return 'Settings saved.';
        case 'set_key':
            saveSetting($pg, 'api_key', $action['api_key']);
            return 'Decision model key saved.';
        case 'clear_key':
            saveSetting($pg, 'api_key', '');
            return 'Decision model key removed; the LLM fallback will be used.';
        case 'issue_flag':
            $playthrough = currentPlaythrough($pg);
            $owner = $pg->one('SELECT npc_name FROM core_npc_master WHERE id = $1', [$action['npc_id']]);
            if ($playthrough === null || $owner === null) {
                return 'That character is not available right now.';
            }
            $status = (new PerspectiveStore($pg))->commitLedger(
                ['owner_npc_id' => $action['npc_id'], 'owner_name' => $owner['npc_name'], 'playthrough_id' => $playthrough['id'], 'event_ids' => [], 'counterpart_name' => null],
                issueFlagMutation($action['issue_id'], $action['flag'])
            );
            return match ($status) {
                'committed' => 'Updated.',
                'unchanged' => 'Nothing to change.',
                'busy' => 'Busy right now; try again in a moment.',
                default => 'Could not update that issue.',
            };
    }
    throw new UnexpectedValueException('action_unknown');
}
```

- [ ] **Step 4: Run to verify it passes**

Expected: `page actions checks passed`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
git -c safe.directory='*' add server/page_actions.php tests/page_actions_test.php
git -c safe.directory='*' commit -m "Add page action validation and OOC issue flags"
```

---

### Task 8: Page model, view, entry point and styles

**Files:**
- Create: `server/page_model.php`, `server/page_view.php`, `server/index.php`, `server/assets/style.css`
- Modify: `scripts/build-package.py` (allowlist)
- Test: `tests/page_view_test.php`

- [ ] **Step 1: Write the failing test `tests/page_view_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/page_view.php';

use function ChimSwornAndScorned\renderPage;

$model = [
    'settings' => ['enabled' => true, 'cast' => [3], 'model' => 'typesafe/jev-1.13', 'has_key' => true],
    'npcs' => [['id' => 3, 'name' => 'Farkas'], ['id' => 4, 'name' => '<script>x</script>']],
    'worker' => ['alive' => false, 'jobs' => ['pending' => 2, 'running' => 0, 'failed' => 1], 'errors' => [['reason' => 'http_429', 'count' => 1]]],
    'journal' => [[
        'npc_id' => 3, 'name' => 'Farkas',
        'issues' => [sampleIssue(['counterpart_name' => 'Lydia <b>'])],
    ]],
    'director' => false,
    'art' => false,
];
$html = renderPage($model, str_repeat('a', 64), 'Settings saved.');
check(str_contains($html, '&lt;script&gt;x&lt;/script&gt;') && !str_contains($html, '<script>x</script>'), 'names are escaped');
check(str_contains($html, 'Lydia &lt;b&gt;'), 'counterpart names are escaped');
check(str_contains($html, 'name="csrf" value="' . str_repeat('a', 64) . '"'), 'forms carry the CSRF token');
check(str_contains($html, 'Key saved') && !str_contains($html, 'sk-'), 'key status shown, never the key');
check(str_contains($html, 'Worker stopped') && str_contains($html, '2 waiting') && str_contains($html, '1 failed'), 'worker panel');
check(preg_match('~<details>.*I&#039;ll stand with you before Aela\..*</details>~s', $html) === 1, 'evidence hidden behind a reveal in spoiler-safe view');
check(str_contains($html, 'Out-of-character'), 'dismiss is labelled out-of-character');

$director = renderPage(['director' => true] + $model, str_repeat('a', 64), null);
check(!str_contains($director, '<details>') && str_contains($director, 'I&#039;ll stand with you before Aela.'), 'director view shows evidence directly');

echo "page view checks passed\n";
```

- [ ] **Step 2: Run to verify it fails**

Expected: fatal `Failed opening required '.../server/page_view.php'`, `exit=255`.

- [ ] **Step 3: Create `server/page_view.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfField(string $csrf): string
{
    return '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
}

function renderIssue(array $issue, int $npcId, string $csrf, bool $director): string
{
    $what = $issue['kind'] === 'grievance'
        ? 'Grievance with ' . $issue['counterpart_name']
        : ($issue['direction'] === 'owed_by_me' ? 'Promised ' : 'Promise from ') . $issue['counterpart_name'];
    $state = $issue['status'] . ', ' . $issue['trajectory'] . ($issue['muted'] ? ', muted' : '') . ($issue['dismissed'] ? ', dismissed' : '');
    $evidence = '<ul class="evidence">';
    foreach ($issue['evidence'] as $entry) {
        $evidence .= '<li><q>' . h($entry['quote']) . '</q> <span class="meta">' . h($entry['speaker'] . ' · ' . $entry['label'] . ' · ' . $entry['backend']) . '</span></li>';
    }
    $evidence .= '</ul><p class="meta">Wants: ' . h($issue['wants']) . '</p>';
    $body = $director ? $evidence : '<details><summary>Reveal</summary>' . $evidence . '</details>';
    $buttons = '';
    if (!$issue['dismissed']) {
        $forms = [['mute', 'Mute'], ['dismiss', 'Dismiss (Out-of-character)']];
        if ($issue['muted']) {
            $forms[0] = ['unmute', 'Unmute'];
        }
        foreach ($forms as [$flag, $label]) {
            $buttons .= '<form method="post">' . csrfField($csrf)
                . '<input type="hidden" name="action" value="issue_flag">'
                . '<input type="hidden" name="npc_id" value="' . $npcId . '">'
                . '<input type="hidden" name="issue_id" value="' . h($issue['id']) . '">'
                . '<input type="hidden" name="flag" value="' . $flag . '">'
                . '<button type="submit">' . h($label) . '</button></form>';
        }
    }
    return '<article class="issue"><h4>' . h($what) . '</h4><p class="meta">' . h($state) . '</p>' . $body . '<div class="actions">' . $buttons . '</div></article>';
}

/**
 * @param array $model {settings{enabled, cast, model, has_key}, npcs[{id,name}], worker{alive, jobs{pending,running,failed}, errors[{reason,count}]},
 *                      journal[{npc_id, name, issues[issue + counterpart_name]}], director: bool, art: bool}
 */
function renderPage(array $model, string $csrf, ?string $notice): string
{
    $settings = $model['settings'];
    $out = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Sworn &amp; Scorned</title><link rel="stylesheet" href="assets/style.css"></head><body><main>';
    $out .= '<header><h1>Sworn &amp; Scorned</h1><p class="subtitle">Part of the World of Drama-Llama</p></header>';
    if ($model['art']) {
        $out .= '<img class="art" src="assets/sworn-and-scorned.webp" alt="A Nord offers an open hand on snowy mead-hall steps; a woman above him turns away; a snapped oath ring lies between them.">';
    }
    if ($notice !== null) {
        $out .= '<p class="notice" role="status">' . h($notice) . '</p>';
    }

    $out .= '<section><h2>Settings</h2><form method="post">' . csrfField($csrf) . '<input type="hidden" name="action" value="save_settings">'
        . '<label><input type="checkbox" name="enabled" value="1"' . ($settings['enabled'] ? ' checked' : '') . '> Enabled</label>'
        . '<label>Decision model <input name="model" value="' . h($settings['model']) . '"></label>'
        . '<fieldset><legend>Cast (up to 8)</legend><div class="cast">';
    foreach ($model['npcs'] as $npc) {
        $checked = in_array($npc['id'], $settings['cast'], true) ? ' checked' : '';
        $out .= '<label><input type="checkbox" name="cast[]" value="' . (int) $npc['id'] . '"' . $checked . '> ' . h($npc['name']) . '</label>';
    }
    $out .= '</div></fieldset><button type="submit">Save settings</button></form>';
    $out .= '<form method="post" class="key">' . csrfField($csrf) . '<input type="hidden" name="action" value="set_key">'
        . '<p>' . ($settings['has_key'] ? 'Key saved (hidden).' : 'No key: the CHIM LLM fallback is used.') . '</p>'
        . '<label>OpenRouter key <input type="password" name="api_key" autocomplete="off"></label><button type="submit">Save key</button></form>';
    if ($settings['has_key']) {
        $out .= '<form method="post">' . csrfField($csrf) . '<input type="hidden" name="action" value="clear_key"><button type="submit">Remove key</button></form>';
    }
    $out .= '</section>';

    $jobs = $model['worker']['jobs'];
    $out .= '<section><h2>Worker</h2><p>' . ($model['worker']['alive'] ? 'Worker running' : 'Worker stopped (it starts with the next conversation while enabled)')
        . ' · ' . (int) $jobs['pending'] . ' waiting · ' . (int) $jobs['running'] . ' running · ' . (int) $jobs['failed'] . ' failed</p>';
    if ($model['worker']['errors'] !== []) {
        $out .= '<ul>';
        foreach ($model['worker']['errors'] as $error) {
            $out .= '<li>' . h($error['reason']) . ' × ' . (int) $error['count'] . '</li>';
        }
        $out .= '</ul>';
    }
    $out .= '</section>';

    $out .= '<section><h2>Journal</h2><p class="meta">' . ($model['director']
        ? 'Director view: everything is shown. <a href="?">Spoiler-safe view</a>'
        : 'Spoiler-safe view: open an issue to read it. <a href="?view=director">Director view</a>') . '</p>';
    if ($model['journal'] === []) {
        $out .= '<p>No issues yet.</p>';
    }
    foreach ($model['journal'] as $entry) {
        $out .= '<h3>' . h($entry['name']) . '</h3>';
        foreach ($entry['issues'] as $issue) {
            $out .= renderIssue($issue, (int) $entry['npc_id'], $csrf, $model['director']);
        }
    }
    return $out . '</section></main></body></html>';
}
```

- [ ] **Step 4: Run to verify it passes**

Expected: `page view checks passed`, `exit=0`.

- [ ] **Step 5: Create `server/page_model.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/tables.php';
require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/hook.php';
require_once __DIR__ . '/launcher.php';

function knownNpcIds(Pg $pg): array
{
    return array_map('intval', array_column($pg->all("SELECT id FROM core_npc_master WHERE npc_name <> 'The Narrator'"), 'id'));
}

function buildPageModel(Pg $pg, string $engineRoot, bool $director): array
{
    $settings = loadSettings($pg);
    $npcs = array_map(
        static fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['npc_name']],
        $pg->all("SELECT id, npc_name FROM core_npc_master WHERE npc_name <> 'The Narrator' ORDER BY lower(npc_name) LIMIT 2000")
    );
    $namesById = array_column($npcs, 'name', 'id');
    $jobs = ['pending' => 0, 'running' => 0, 'failed' => 0];
    foreach ($pg->all('SELECT status, count(*) AS n FROM ' . sasTable('jobs') . ' GROUP BY status') as $row) {
        $jobs[$row['status']] = (int) $row['n'];
    }
    $errors = array_map(
        static fn (array $row): array => ['reason' => (string) $row['last_error'], 'count' => (int) $row['n']],
        $pg->all('SELECT last_error, count(*) AS n FROM ' . sasTable('jobs') . " WHERE last_error IS NOT NULL GROUP BY last_error ORDER BY n DESC LIMIT 5")
    );
    $playthrough = currentPlaythrough($pg);
    $journal = [];
    if ($playthrough !== null) {
        $rows = $pg->all(
            "SELECT id, npc_name, (plugin_extended_data->'sworn_and_scorned')::text AS ledger FROM core_npc_master
             WHERE plugin_extended_data ? 'sworn_and_scorned' OR id = ANY($1::bigint[]) ORDER BY lower(npc_name) LIMIT 60",
            [pgIntArray($settings['cast'])]
        );
        foreach ($rows as $row) {
            try {
                $ledger = normalizeLedger($row['ledger'] === null ? null : json_decode($row['ledger'], true), $playthrough['id']);
            } catch (\UnexpectedValueException) {
                continue;
            }
            $issues = [];
            foreach ($ledger['issues'] as $issue) {
                $issue['counterpart_name'] = displayName($issue['counterpart'], $playthrough['player_name'], $namesById) ?? $issue['counterpart'];
                $issues[] = $issue;
            }
            if ($issues !== [] || in_array((int) $row['id'], $settings['cast'], true)) {
                $journal[] = ['npc_id' => (int) $row['id'], 'name' => $row['npc_name'], 'issues' => $issues];
            }
        }
    }
    return [
        'settings' => ['enabled' => $settings['enabled'], 'cast' => $settings['cast'], 'model' => $settings['model'], 'has_key' => $settings['api_key'] !== ''],
        'npcs' => $npcs,
        'worker' => ['alive' => workerPidAlive($engineRoot . '/log/sworn_scorned_worker.pid'), 'jobs' => $jobs, 'errors' => $errors],
        'journal' => $journal,
        'director' => $director,
        'art' => is_file(__DIR__ . '/assets/sworn-and-scorned.webp'),
    ];
}
```

- [ ] **Step 6: Create `server/index.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/page_security.php';
require_once __DIR__ . '/page_actions.php';
require_once __DIR__ . '/page_model.php';
require_once __DIR__ . '/page_view.php';

sendSecurityHeaders();
if (!pageAccessAllowed($_SERVER)) {
    $handoff = localHandoffUrl($_SERVER, $_GET);
    if ($handoff !== null) {
        header('Location: ' . $handoff, true, 302);
        exit;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden. Open this page through localhost (replace the host in your CHIM URL with localhost).\n";
    exit;
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

session_start();
if (!is_string($_SESSION['sas_csrf'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $_SESSION['sas_csrf']) !== 1) {
    $_SESSION['sas_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['sas_csrf'];
session_write_close();

$engineRoot = dirname(__DIR__, 2);
try {
    $pg = openEngineConnection($engineRoot);
    ensureSchema($pg);
} catch (\Throwable) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "The CHIM database is not available.\n";
    exit;
}

$notice = null;
if ($method === 'POST') {
    if (!csrfTokenValid($csrf, $_POST['csrf'] ?? null)) {
        http_response_code(400);
        $notice = 'The form expired; please try again.';
    } else {
        try {
            $notice = performPageAction($pg, parsePageAction($_POST, knownNpcIds($pg)), $engineRoot, __DIR__);
        } catch (\UnexpectedValueException) {
            http_response_code(400);
            $notice = 'That request was not valid.';
        }
    }
}
header('Content-Type: text/html; charset=UTF-8');
echo renderPage(buildPageModel($pg, $engineRoot, ($_GET['view'] ?? '') === 'director'), $csrf, $notice);
```

- [ ] **Step 7: Create `server/assets/style.css`**

```css
:root { --bg: #f4ecd8; --ink: #1d1a17; --rust: #9a3b22; --slate: #4d6475; --line: #cbbf9f; --card: #fbf6ea; }
@media (prefers-color-scheme: dark) {
  :root { --bg: #16130f; --ink: #ece2cc; --rust: #d0714f; --slate: #8fa8ba; --line: #3b342a; --card: #1f1b16; }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.5 Georgia, "Times New Roman", serif; }
main { max-width: 60rem; margin: 0 auto; padding: 1.5rem 1rem 4rem; }
h1 { margin: 0; color: var(--rust); letter-spacing: .02em; }
.subtitle, .meta { color: var(--slate); font-size: .9rem; margin: .25rem 0; }
.art { width: 100%; height: auto; border: 1px solid var(--line); margin: 1rem 0; }
section { border-top: 2px solid var(--line); margin-top: 1.5rem; padding-top: 1rem; }
.notice { background: var(--card); border-left: 4px solid var(--rust); padding: .5rem .75rem; }
label { display: block; margin: .35rem 0; }
input[name="model"], input[name="api_key"] { width: 100%; max-width: 28rem; padding: .35rem; background: var(--card); color: var(--ink); border: 1px solid var(--line); }
fieldset { border: 1px solid var(--line); margin: .75rem 0; }
.cast { display: grid; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); gap: .1rem .75rem; max-height: 18rem; overflow-y: auto; }
button { background: var(--rust); color: #fff; border: 0; padding: .4rem .9rem; cursor: pointer; font: inherit; }
.issue { background: var(--card); border: 1px solid var(--line); padding: .6rem .8rem; margin: .5rem 0; }
.issue h4 { margin: 0; }
.actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .5rem; }
.actions button { background: var(--slate); }
.evidence { padding-left: 1.2rem; }
q { font-style: italic; }
```

- [ ] **Step 8: Extend the package allowlist**

In `scripts/build-package.py`, add these entries to `SERVER_FILES`:

```python
    "assets/style.css",
    "hook.php",
    "index.php",
    "lore.php",
    "lore_grudges.json",
    "page_actions.php",
    "page_model.php",
    "page_security.php",
    "page_view.php",
```

When the user supplies the artwork (`docs/art/hero-image-prompt.md`), convert it to `server/assets/sworn-and-scorned.webp` and add `"assets/sworn-and-scorned.webp",` too. The page shows it only if the file exists.

- [ ] **Step 9: Lint, run the full unit suite and the package check**

```bash
wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash -c 'for f in server/*.php; do php -l "$f" || exit 1; done && bash scripts/run-php-tests.sh tests/*_test.php'
cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev && python tests/package_check.py
```

Expected: all pass.

- [ ] **Step 10: Commit**

```bash
git -c safe.directory='*' add server/page_model.php server/page_view.php server/index.php server/assets/style.css scripts/build-package.py tests/page_view_test.php
git -c safe.directory='*' commit -m "Add the director page: settings, key, worker panel, spoiler-safe journal"
```

---

### Task 9: Page and surfacing on the clone

- [ ] **Step 1: Build, install and enable**

Bump `server/manifest.json` version to `0.1.1` (never reuse a version for a different build). Build with `python scripts/build-package.py`, bring the clone up and install it as in Plan 2 Task 13 Steps 1–2.

- [ ] **Step 2: Open the page through localhost** (from Windows)

Open `http://localhost:8081/HerikaServer/ext/sworn_and_scorned/index.php`, or use the built-in browser pane. Check:
- the page loads;
- **Enabled** plus a cast of Lidia (5124) and Aela (2892) saves and survives a reload;
- the worker panel turns to "running" after the next conversation input;
- "Key saved (hidden)" appears only after the **user** saves a key.

- [ ] **Step 3: Exercise surfacing**

Put a test grievance toward the player on Lidia directly (clone only). First find the playthrough id the plugin uses:

```bash
wsl.exe -d DwemerAI4Skyrim3-test -- psql -h localhost -U dwemer -d dwemer -At -c "select coalesce((select id::text from chim_meta.playthrough_profiles where is_active is true limit 1), 'unprofiled')"
```

Then write the ledger, replacing `PT` with that value:

```bash
wsl.exe -d DwemerAI4Skyrim3-test -- psql -h localhost -U dwemer -d dwemer -c "update core_npc_master set plugin_extended_data = jsonb_set(coalesce(plugin_extended_data,'{}'::jsonb), '{sworn_and_scorned}', '{\"playthrough_id\":\"PT\",\"floor_event_id\":0,\"processed\":[],\"seeded\":[],\"issues\":[{\"id\":\"e2e0000000000001\",\"origin\":\"event\",\"kind\":\"grievance\",\"counterpart\":\"player\",\"direction\":null,\"strength\":null,\"status\":\"open\",\"trajectory\":\"unchanged\",\"wants\":\"explanation\",\"evidence\":[{\"ref\":\"e:0\",\"gamets\":null,\"speaker\":\"Hawke\",\"quote\":\"I will be back before nightfall.\",\"label\":\"wronged\",\"p\":0.9,\"backend\":\"e2e\"}],\"links\":[],\"affinity_applied\":[],\"muted\":false,\"dismissed\":false,\"changed_event_id\":1}]}'::jsonb, true) where id = 5124"
```

Then send an input to Lidia with the player present (`& $S input "Lidia Sobieska" "Lidia, are we good?" "Lidia Sobieska"`), and check:

```bash
wsl.exe -d DwemerAI4Skyrim3-test -- bash -c "grep -c '\"event\":\"issue_surfaced\"' /var/www/html/HerikaServer/log/chim.log"
```

Expected: the count increases once. A second input within 10 minutes does not increase it, unless the input names the counterpart.

- [ ] **Step 4: Out-of-character controls**

In the journal, mute the issue, check that the next input does not surface it, unmute it, then dismiss it. Confirm that each produced a history row (`select count(*) from core_npc_master_history where npc_id = 5124`) and no affinity change.

- [ ] **Step 5: Record the evidence**

Write `docs/verification/2026-10-plan-3-page-surfacing.md` (commands, observations, "live-server, simulated client evidence"), clean up as in Plan 2 Task 13 Step 7, and commit:

```bash
git -c safe.directory='*' add server/manifest.json docs/verification/2026-10-plan-3-page-surfacing.md
git -c safe.directory='*' commit -m "Page and surfacing evidence on the test clone"
```

---

### Task 10: Coexistence matrix (all 8 combinations)

Spec §2a and §10.4: Sworn & Scorned works with any combination of Mind Poisoning (MP), Private Conversation (PCV) and SHARMAT (`aiagent_nsfw`) present or absent, and changes nothing that belongs to them.

**Files:**
- Create: `docs/verification/2026-10-coexistence-matrix.md`

- [ ] **Step 1: Record the starting state**

```bash
wsl.exe -d DwemerAI4Skyrim3-test -- bash -c "ls /var/www/html/HerikaServer/ext"
```

Record the list: the matrix must end with the same set. Locate existing release packages; **do not build in their repos**:

```bash
ls /k/ActorwrightExchange/projects/CHIM-MindPoisoning/dist /k/ActorwrightExchange/projects/CHIM-PrivateConversation-dev/dist
```

Use the newest released `.dwpkg` of each (MP 0.1.17 and PCV 0.1.14) and their SHA-256 values from the `SHA256SUMS.txt` files.

- [ ] **Step 2: Toggling rules (clone only)**

- **MP and PCV:** install with `install_pkg.py` (Plan 2 Task 13 Step 2) and remove through CHIM's plugin manager (`delete_plugin=<name>`).
- **SHARMAT:** to remove it, `mv /var/www/html/HerikaServer/ext/aiagent_nsfw /root/aiagent_nsfw.off`; to restore it, move it back.
- Sworn & Scorned stays installed and enabled, with cast Lidia (5124) and Aela (2892).

- [ ] **Step 3: Run the same scenario for each combination**

Combinations, as MP / PCV / SHARMAT present (1) or absent (0):

`000, 100, 010, 001, 110, 101, 011, 111`

For each combination:
1. Set the plugin presence.
2. Record `grep -c 'PHP Fatal' /var/log/apache2/error.log`.
3. Drive the scenario:
   - two heartbeats for Lidia and Aela;
   - one player input to Lidia, then ack her reply;
   - if PCV is present: `arm pair 5124 2892`, one input `-`, ack the replies, then `end`.
4. Snapshot the other plugins' data, **after the scenario and before our worker runs**:
   ```bash
   psql … -At -c "select id, (plugin_extended_data - 'sworn_and_scorned')::text, (extended_data - 'relationships')::text from core_npc_master where id in (5124, 2892)" > /tmp/before.txt
   ```
5. Wait 25 s, then run `worker.php --once` three times as www-data.
6. Take the same snapshot to `/tmp/after.txt`, and run `diff /tmp/before.txt /tmp/after.txt`. **It must be empty:** our worker changed only its own key, plus relationship edges only on lifecycle transitions, which this scenario does not produce.
7. The PHP fatal count is unchanged.
8. If MP is present: `chim.log` has MP `request_finished` records for the ack (MP still works).
9. If SHARMAT is present and a note surfaced: the CHIM prompt log for that request contains both "On your mind:" and SHARMAT's condition text. Locate the log with `grep -rl 'On your mind:' /var/www/html/HerikaServer/log | head -3`.
10. If PCV excluded the player: no player issue surfaced during the scene.

Record a row per combination: combination, our jobs done, diff empty (yes/no), fatals (before/after), MP records, SHARMAT text present, notes.

- [ ] **Step 4: Restore the starting state**

Restore the plugin set recorded in Step 1, SHARMAT folder included. Remove our test data (Plan 2 Task 13 Step 7). Stop the clone, terminate it, and confirm the gaming distro is `Stopped`.

- [ ] **Step 5: Write the results and commit**

`docs/verification/2026-10-coexistence-matrix.md` holds the table, the commands, and the statement "live-server, simulated-client evidence; not gameplay evidence; model behaviour not judged".

```bash
git -c safe.directory='*' add docs/verification/2026-10-coexistence-matrix.md
git -c safe.directory='*' commit -m "Coexistence matrix: MP, PCV, SHARMAT in all 8 combinations"
```

If any row fails, stop and report it with the failing check. Do not adjust the other plugins.

---

### Task 11: Documentation and handoff

**Files:**
- Modify: `README.md`
- Create: `AGENTS.md`

- [ ] **Step 1: Write the user guide in `README.md`**

Replace the README with sections covering:
- **What it does:** grievances and promises; perspective; lifecycle affinity values (spec §6.4).
- **Install:** CHIM plugin manager.
- **Setup:** open the page; enable; choose up to 8 cast; optional OpenRouter key. Without a key, the CHIM relationship LLM connector is used.
- **How it shows up in play:** one private note on the speaker's turn, paced; it adds to and never overrides condition prompts.
- **Journal:** spoiler-safe and director views; Mute; Dismiss (out-of-character, no affinity).
- **Lore grudges:** the shipped list, and the override file `<HerikaServer>/data/sworn_and_scorned_lore.json` (same format).
- **Compatibility:** MP, PCV, SHARMAT, each optional; link the matrix evidence.
- **Limits:**
  - no secrecy guarantees;
  - silence is not evidence;
  - prompt notes are weakly followed by some models;
  - fixture and clone tests are not gameplay proof;
  - removing the plugin stops future evaluation but does not undo stored affinity.
- **Privacy:** the key is stored in CHIM's database, never logged. Dialogue text is never logged.

- [ ] **Step 2: Write `AGENTS.md`** (contributor rules)

```markdown
# Sworn & Scorned contributor rules

- Never edit Mind Poisoning or Private Conversation; observe them only through CHIM data (spec §2a).
- PHP and database tests run only in the WSL clone `DwemerAI4Skyrim3-test`; never touch `DwemerAI4Skyrim3`.
- Never log dialogue text or credentials; logs use fixed reason codes (`server/logging.php`).
- Ledger writes go through `PerspectiveStore::commitLedger()` only (lock, revalidate, snapshot).
- Affinity changes only on lifecycle transitions (spec §6.4); never per line, never on seeding or OOC actions.
- The prompt note is additive (`character_bottom`), capped at 240 characters, and never writes `HERIKA_PERS`.
- Never reuse a version number for a different build.
```

- [ ] **Step 3: Final checks and commit**

Run the full unit suite, all integration tests (`SAS_IT=1`) and the package check. Then:

```bash
git -c safe.directory='*' add README.md AGENTS.md
git -c safe.directory='*' commit -m "Sworn & Scorned user guide and contributor rules"
```

Report to the user, with evidence links: what was verified (unit, clone database, clone end-to-end, coexistence matrix) and what was not (in-game play, dialogue quality, long-session behaviour).
