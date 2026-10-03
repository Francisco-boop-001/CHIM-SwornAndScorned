# Sworn & Scorned — Plan 1: Domain Core — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the pure-PHP domain core of Sworn & Scorned: the per-NPC perspective ledger, issue lifecycle rules, witness filtering, the decision-backend contract, two-pass question building, the evaluator, idempotent proposal application, and surfacing selection and rendering. None of it touches CHIM.

**Architecture:** Namespaced functions over plain associative arrays, matching Mind Poisoning's idiom (`namespace ChimSwornAndScorned`, `declare(strict_types=1)`, `UnexpectedValueException` for invalid data). The decision model sits behind a `DecisionBackend` interface, so tests use a scripted fake. CHIM wiring (hooks, DB, worker, adapters) comes in Plan 2 (see `2026-10-03-sworn-and-scorned-roadmap.md`).

**Tech Stack:** PHP 8.2 (CHIM's version), `mbstring`, plain PHP check scripts (no framework), Bash runner inside the WSL test clone.

**Spec:** `docs/superpowers/specs/2026-10-03-sworn-and-scorned-v1-design.md`

---

## Ground rules for the implementer (read first)

- **Repo:** `K:\ActorwrightExchange\projects\CHIM-SwornAndScorned-dev`. In Git Bash it is `/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev`, and inside WSL it is `/mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev`.
- **Git needs a per-command override**, because the K: drive does not record ownership. Always use `git -c safe.directory='*' …`. Never change global git config.
- **PHP runs only inside the WSL distro `DwemerAI4Skyrim3-test`.** **Never** run any command against `DwemerAI4Skyrim3`, the user's live gaming server. Even a read boots it and writes its disk. Before the first test run of a session, run `wsl.exe -l -v`. `DwemerAI4Skyrim3` must show `Stopped`. If it shows `Running`, stop and ask the user. Do not stop it yourself. At the end of the session, run `wsl.exe --terminate DwemerAI4Skyrim3-test`.
- **The test command** (from Git Bash, any cwd):

  ```bash
  wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/<name>_test.php
  ```

  The runner prints `===== <file>`, the last 12 output lines and `exit=<code>`. It refuses to run outside the test distro.
- **Do not edit** Mind Poisoning (`CHIM-MindPoisoning`) or Private Conversation (`CHIM-PrivateConversation-dev`). This plugin never depends on their code.
- Data shapes used throughout:
  - **Issue:** keys in this exact order: `id, origin, kind, counterpart, direction, strength, status, trajectory, wants, evidence, links, affinity_applied, muted, dismissed, changed_event_id`.
  - **Evidence:** keys in this order: `ref, gamets, speaker, quote, label, p, backend`.
  - **Line** (an `eventlog` speech row as the worker will supply it): `event_id:int, gamets:float, type:string, speaker:string, listener:string, people:list<string>, text:string`.

## File structure

| File | Responsibility |
| --- | --- |
| `.gitattributes` | Force LF so Bash scripts run inside WSL. |
| `README.md` | One-paragraph project description. |
| `tasks/todo.md` | Progress checklist (house convention). |
| `scripts/run-php-tests.sh` | Test runner, refuses outside the test distro. |
| `tests/support/check.php` | `check()`, `rejected()` assertion helpers. |
| `tests/support/fixtures.php` | `sampleIssue()`, `sampleLine()`, `issueById()` builders. |
| `tests/support/scripted_backend.php` | `ScriptedBackend` fake `DecisionBackend`. |
| `server/vocab.php` | Vocabularies and caps (constants). |
| `server/ledger.php` | Ledger and issue validation, ids, replay/processed floor, closed cap, text clipping. |
| `server/transitions.php` | Allowed status transitions, transition keys, lifecycle affinity table. |
| `server/witness.php` | Select the pair's speech lines a perspective owner witnessed. |
| `server/backend.php` | `DecisionBackend` interface, policies, answer validation, thresholds. |
| `server/questions.php` | State and question builders for pass 1 and pass 2. |
| `server/evaluator.php` | `evaluatePerspective()`: two-pass questions into a proposal. |
| `server/apply.php` | `applyProposal()`: proposal into a new ledger, affinity delta, transitions. |
| `server/surfacing.php` | Choose at most one issue to surface, and render the ≤240-character note. |

---

### Task 0: Scaffold, test runner and environment check

**Files:**
- Create: `.gitattributes`, `README.md`, `tasks/todo.md`, `scripts/run-php-tests.sh`, `tests/support/check.php`, `tests/smoke_test.php`

- [ ] **Step 1: Set the repo-local git identity** (this writes only this repo's `.git/config`)

```bash
cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev
git -c safe.directory='*' config user.name "Francisco Estaba"
git -c safe.directory='*' config user.email "franciscos.estabas.github@gmail.com"
```

- [ ] **Step 2: Create `.gitattributes`**

```gitattributes
* text=auto eol=lf
*.webp binary
*.png binary
```

- [ ] **Step 3: Create `README.md`**

```markdown
# Sworn & Scorned

Part of the World of Drama-Llama. A CHIM server plugin that gives NPCs (and the player)
persistent grievances and promises with each other, so a later encounter has something
concrete to be about. Issues are tracked per perspective, judged asynchronously by a
decision model, surfaced quietly in conversation and rolled back with the save.

Works alone; fully compatible with Mind Poisoning, Private Conversation and SHARMAT.
Status: in development (Plan 1, domain core). Design: `docs/superpowers/specs/`.
```

- [ ] **Step 4: Create `tasks/todo.md`**

```markdown
# Sworn & Scorned — todo

## Plan 1: domain core (docs/superpowers/plans/2026-10-03-sworn-and-scorned-plan-1-domain-core.md)

- [ ] Task 0 scaffold and runner
- [ ] Task 1 vocabulary and ledger
- [ ] Task 2 transitions
- [ ] Task 3 witness filter
- [ ] Task 4 decision backend contract
- [ ] Task 5 questions
- [ ] Task 6 evaluator
- [ ] Task 7 apply
- [ ] Task 8 surfacing
- [ ] Task 9 full suite and handoff
```

- [ ] **Step 5: Create `scripts/run-php-tests.sh`**

```bash
#!/bin/bash
# Run Sworn & Scorned PHP checks with the test distro's PHP 8.2.
# Usage: run-php-tests.sh tests/a_test.php [tests/b_test.php ...]
TEST_DISTRO="${SAS_TEST_DISTRO:-DwemerAI4Skyrim3-test}"
if [ "${WSL_DISTRO_NAME:-}" != "$TEST_DISTRO" ]; then
    echo "REFUSING: not running inside $TEST_DISTRO"; exit 1
fi
cd "$(dirname "$0")/.." || exit 1
rc=0
for t in "$@"; do
    echo "===== $t"
    timeout 300 php "$t" 2>&1 | tail -n 12
    s=${PIPESTATUS[0]}; echo "exit=$s"; [ "$s" = 0 ] || rc=1
done
exit $rc
```

- [ ] **Step 6: Create `tests/support/check.php`**

```php
<?php
declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejected(callable $call, string $message): void
{
    try {
        $call();
    } catch (UnexpectedValueException) {
        return;
    }
    throw new RuntimeException($message);
}
```

- [ ] **Step 7: Create `tests/smoke_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';

check(PHP_VERSION_ID >= 80200, 'PHP 8.2 or newer required');
check(extension_loaded('mbstring'), 'mbstring extension required');
check(extension_loaded('json'), 'json extension required');
rejected(static function (): void {
    throw new UnexpectedValueException('x');
}, 'rejected() must accept UnexpectedValueException');

echo "smoke checks passed\n";
```

- [ ] **Step 8: Check the environment, then run the smoke test**

```bash
wsl.exe -l -v
```

Expected: `DwemerAI4Skyrim3` is `Stopped`. If it is `Running`, STOP and ask the user.

```bash
wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/smoke_test.php
```

Expected: `smoke checks passed` and `exit=0`. If mbstring is missing, stop and report it (it is Plan 0 question 8).

- [ ] **Step 9: Commit**

```bash
cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev
git -c safe.directory='*' add .gitattributes README.md tasks/todo.md scripts/run-php-tests.sh tests/support/check.php tests/smoke_test.php
git -c safe.directory='*' commit -m "Scaffold Sworn & Scorned: runner, check helpers, plans"
```

---

### Task 1: Vocabulary and ledger

**Files:**
- Create: `server/vocab.php`, `server/ledger.php`, `tests/support/fixtures.php`
- Test: `tests/ledger_test.php`

- [ ] **Step 1: Create `tests/support/fixtures.php`**

```php
<?php
declare(strict_types=1);

function sampleIssue(array $overrides = []): array
{
    return array_replace([
        'id' => 'abc123abc123abc1',
        'origin' => 'event',
        'kind' => 'grievance',
        'counterpart' => 'npc:3',
        'direction' => null,
        'strength' => null,
        'status' => 'open',
        'trajectory' => 'unchanged',
        'wants' => 'explanation',
        'evidence' => [[
            'ref' => 'e:10',
            'gamets' => 100.0,
            'speaker' => 'Farkas',
            'quote' => "I'll stand with you before Aela.",
            'label' => 'wronged',
            'p' => 0.91,
            'backend' => 'fake',
        ]],
        'links' => [],
        'affinity_applied' => [],
        'muted' => false,
        'dismissed' => false,
        'changed_event_id' => 10,
    ], $overrides);
}

function sampleLine(
    int $eventId,
    string $speaker,
    string $text,
    array $people = ['Lydia', 'Farkas'],
    string $listener = '',
    string $type = 'chat'
): array {
    return [
        'event_id' => $eventId,
        'gamets' => (float) $eventId,
        'type' => $type,
        'speaker' => $speaker,
        'listener' => $listener,
        'people' => $people,
        'text' => $text,
    ];
}

function issueById(array $ledger, string $id): array
{
    foreach ($ledger['issues'] as $issue) {
        if ($issue['id'] === $id) {
            return $issue;
        }
    }
    throw new RuntimeException("issue {$id} not found");
}
```

- [ ] **Step 2: Write the failing test `tests/ledger_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/ledger.php';

use function ChimSwornAndScorned\{clipText, emptyLedger, enforceClosedCap, isOpen, isReplay, issueId, markProcessed, normalizeLedger, validateIssue};

$empty = emptyLedger('pt-1');
check($empty === ['playthrough_id' => 'pt-1', 'floor_event_id' => 0, 'processed' => [], 'issues' => []], 'empty ledger shape');
check(normalizeLedger(null, 'pt-1') === $empty, 'null becomes an empty ledger');
check(
    normalizeLedger(['playthrough_id' => 'pt-0', 'floor_event_id' => 0, 'processed' => [], 'issues' => [sampleIssue()]], 'pt-1') === $empty,
    'another playthrough becomes an empty ledger'
);

$raw = ['playthrough_id' => 'pt-1', 'floor_event_id' => 0, 'processed' => [10], 'issues' => [sampleIssue()]];
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
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/ledger_test.php`
Expected: a PHP fatal error `Failed opening required '.../server/ledger.php'` and `exit=255`.

- [ ] **Step 4: Create `server/vocab.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

const KINDS = ['grievance', 'promise'];
const ORIGINS = ['event', 'lore'];
const DIRECTIONS = ['owed_by_me', 'owed_to_me'];
const STRENGTHS = ['will', 'will_try'];
const GRIEVANCE_STATUSES = ['open', 'resolved', 'let_go'];
const PROMISE_STATUSES = ['pending', 'fulfilled', 'disputed', 'broken', 'released'];
const OPEN_STATUSES = ['open', 'pending', 'disputed'];
const TRAJECTORIES = ['improving', 'worsening', 'unchanged'];
const WANTS = ['explanation', 'apology', 'restitution', 'keep_promise', 'thanks', 'unknown'];

const MAX_PROCESSED = 128;
const MAX_OPEN_ISSUES = 24;
const MAX_CLOSED_ISSUES = 16;
const MAX_EVIDENCE = 8;
const MAX_QUOTE_CHARS = 160;
const MAX_NOTE_CHARS = 240;
```

- [ ] **Step 5: Create `server/ledger.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

use UnexpectedValueException;

require_once __DIR__ . '/vocab.php';

function emptyLedger(string $playthroughId): array
{
    return ['playthrough_id' => $playthroughId, 'floor_event_id' => 0, 'processed' => [], 'issues' => []];
}

/**
 * Decode stored ledger data. A missing ledger or one from another playthrough starts empty;
 * a corrupt ledger throws so the caller fails closed instead of overwriting it.
 */
function normalizeLedger(mixed $raw, string $playthroughId): array
{
    if (!is_array($raw) || ($raw['playthrough_id'] ?? null) !== $playthroughId) {
        return emptyLedger($playthroughId);
    }
    $floor = $raw['floor_event_id'] ?? null;
    if (!is_int($floor) || $floor < 0) {
        throw new UnexpectedValueException('ledger_floor_invalid');
    }
    $processed = $raw['processed'] ?? null;
    if (!is_array($processed) || !array_is_list($processed)) {
        throw new UnexpectedValueException('ledger_processed_invalid');
    }
    foreach ($processed as $eventId) {
        if (!is_int($eventId) || $eventId <= $floor) {
            throw new UnexpectedValueException('ledger_processed_invalid');
        }
    }
    $issues = $raw['issues'] ?? null;
    if (!is_array($issues) || !array_is_list($issues)) {
        throw new UnexpectedValueException('ledger_issues_invalid');
    }
    return [
        'playthrough_id' => $playthroughId,
        'floor_event_id' => $floor,
        'processed' => $processed,
        'issues' => array_map(validateIssue(...), $issues),
    ];
}

function validateIssue(mixed $issue): array
{
    if (!is_array($issue)) {
        throw new UnexpectedValueException('issue_not_object');
    }
    $kind = requireEnum($issue, 'kind', KINDS);
    $isPromise = $kind === 'promise';
    $evidence = $issue['evidence'] ?? null;
    if (!is_array($evidence) || !array_is_list($evidence)) {
        throw new UnexpectedValueException('issue_evidence_invalid');
    }
    $changed = $issue['changed_event_id'] ?? null;
    if (!is_int($changed) || $changed < 0) {
        throw new UnexpectedValueException('issue_changed_event_id_invalid');
    }
    $id = $issue['id'] ?? null;
    if (!is_string($id) || $id === '') {
        throw new UnexpectedValueException('issue_id_invalid');
    }
    return [
        'id' => $id,
        'origin' => requireEnum($issue, 'origin', ORIGINS),
        'kind' => $kind,
        'counterpart' => requireCounterpart($issue['counterpart'] ?? null),
        'direction' => $isPromise ? requireEnum($issue, 'direction', DIRECTIONS) : null,
        'strength' => $isPromise ? requireEnum($issue, 'strength', STRENGTHS) : null,
        'status' => requireEnum($issue, 'status', $isPromise ? PROMISE_STATUSES : GRIEVANCE_STATUSES),
        'trajectory' => requireEnum($issue, 'trajectory', TRAJECTORIES),
        'wants' => requireEnum($issue, 'wants', WANTS),
        'evidence' => array_map(validateEvidence(...), $evidence),
        'links' => requireStringList($issue, 'links'),
        'affinity_applied' => requireStringList($issue, 'affinity_applied'),
        'muted' => ($issue['muted'] ?? false) === true,
        'dismissed' => ($issue['dismissed'] ?? false) === true,
        'changed_event_id' => $changed,
    ];
}

function validateEvidence(mixed $entry): array
{
    if (!is_array($entry)) {
        throw new UnexpectedValueException('evidence_invalid');
    }
    foreach (['ref', 'speaker', 'quote', 'label', 'backend'] as $key) {
        if (!is_string($entry[$key] ?? null)) {
            throw new UnexpectedValueException('evidence_invalid');
        }
    }
    $p = $entry['p'] ?? null;
    if ($p !== null && !((is_int($p) || is_float($p)) && $p >= 0 && $p <= 1)) {
        throw new UnexpectedValueException('evidence_invalid');
    }
    $gamets = $entry['gamets'] ?? null;
    if ($gamets !== null && !is_int($gamets) && !is_float($gamets)) {
        throw new UnexpectedValueException('evidence_invalid');
    }
    return [
        'ref' => $entry['ref'],
        'gamets' => $gamets === null ? null : (float) $gamets,
        'speaker' => $entry['speaker'],
        'quote' => $entry['quote'],
        'label' => $entry['label'],
        'p' => $p === null ? null : (float) $p,
        'backend' => $entry['backend'],
    ];
}

function requireEnum(array $data, string $key, array $allowed): string
{
    $value = $data[$key] ?? null;
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        throw new UnexpectedValueException("issue_{$key}_invalid");
    }
    return $value;
}

function requireCounterpart(mixed $value): string
{
    if (!is_string($value) || preg_match('/^(player|npc:[1-9][0-9]*|daedra:[a-z][a-z_]*)$/D', $value) !== 1) {
        throw new UnexpectedValueException('issue_counterpart_invalid');
    }
    return $value;
}

function requireStringList(array $data, string $key): array
{
    $value = $data[$key] ?? null;
    if (!is_array($value) || !array_is_list($value)) {
        throw new UnexpectedValueException("issue_{$key}_invalid");
    }
    foreach ($value as $item) {
        if (!is_string($item)) {
            throw new UnexpectedValueException("issue_{$key}_invalid");
        }
    }
    return $value;
}

function issueId(string $originRef, string $counterpart, string $kind): string
{
    return substr(hash('sha256', $originRef . '|' . $counterpart . '|' . $kind), 0, 16);
}

function isOpen(array $issue): bool
{
    return !$issue['dismissed'] && in_array($issue['status'], OPEN_STATUSES, true);
}

function isReplay(array $ledger, array $eventIds): bool
{
    foreach ($eventIds as $eventId) {
        if ($eventId <= $ledger['floor_event_id'] || in_array($eventId, $ledger['processed'], true)) {
            return true;
        }
    }
    return false;
}

function markProcessed(array $ledger, array $eventIds): array
{
    $all = array_values(array_unique(array_merge($ledger['processed'], $eventIds)));
    sort($all);
    $floor = $ledger['floor_event_id'];
    while (count($all) > MAX_PROCESSED) {
        $floor = max($floor, array_shift($all));
    }
    $ledger['processed'] = $all;
    $ledger['floor_event_id'] = $floor;
    return $ledger;
}

function enforceClosedCap(array $issues): array
{
    $open = array_values(array_filter($issues, isOpen(...)));
    $closed = array_values(array_filter($issues, static fn (array $issue): bool => !isOpen($issue)));
    usort($closed, static fn (array $a, array $b): int => $b['changed_event_id'] <=> $a['changed_event_id']);
    return array_merge($open, array_slice($closed, 0, MAX_CLOSED_ISSUES));
}

function clipText(string $text, int $max): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $max - 1)) . '…';
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/ledger_test.php`
Expected: `ledger checks passed` and `exit=0`.

- [ ] **Step 7: Commit**

```bash
cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev
git -c safe.directory='*' add server/vocab.php server/ledger.php tests/support/fixtures.php tests/ledger_test.php
git -c safe.directory='*' commit -m "Add perspective ledger: validation, ids, replay floor, caps"
```

---

### Task 2: Transitions and lifecycle affinity

**Files:**
- Create: `server/transitions.php`
- Test: `tests/transitions_test.php`

- [ ] **Step 1: Write the failing test `tests/transitions_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/transitions.php';

use function ChimSwornAndScorned\{lifecycleAffinityDelta, transitionAllowed, transitionKey};

check(transitionAllowed('promise', 'pending', 'broken'), 'pending promise can break');
check(transitionAllowed('promise', 'pending', 'released'), 'pending promise can be released');
check(transitionAllowed('promise', 'disputed', 'fulfilled'), 'disputed promise can still be fulfilled');
check(!transitionAllowed('promise', 'disputed', 'pending'), 'disputed does not return to pending');
check(!transitionAllowed('promise', 'fulfilled', 'broken'), 'fulfilled is terminal');
check(!transitionAllowed('promise', 'disputed', 'disputed'), 'no self transition');
check(transitionAllowed('grievance', 'open', 'let_go'), 'open grievance can be let go');
check(!transitionAllowed('grievance', 'resolved', 'open'), 'resolved grievance is terminal');
check(!transitionAllowed('grievance', 'open', 'fulfilled'), 'grievance cannot use promise vocabulary');

check(transitionKey('abc', 'pending', 'broken') === 'abc:pending>broken', 'transition key format');

$owedToMe = sampleIssue(['kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'status' => 'pending']);
check(lifecycleAffinityDelta($owedToMe, 'fulfilled') === 2, 'kept promise to me: +2');
check(lifecycleAffinityDelta($owedToMe, 'broken') === -3, 'broken promise to me: -3');
check(lifecycleAffinityDelta($owedToMe, 'disputed') === 0, 'disputed: 0');
check(lifecycleAffinityDelta($owedToMe, 'released') === 0, 'released: 0');

$owedByMe = sampleIssue(['kind' => 'promise', 'direction' => 'owed_by_me', 'strength' => 'will', 'status' => 'pending']);
check(lifecycleAffinityDelta($owedByMe, 'fulfilled') === 0, 'my own kept promise does not change my view');
check(lifecycleAffinityDelta($owedByMe, 'broken') === 0, 'my own broken promise does not change my view');

check(lifecycleAffinityDelta(sampleIssue(), 'resolved') === 2, 'resolved grievance: +2');
check(lifecycleAffinityDelta(sampleIssue(), 'let_go') === 1, 'let go grievance: +1');

echo "transitions checks passed\n";
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/transitions_test.php`
Expected: fatal `Failed opening required '.../server/transitions.php'`, `exit=255`.

- [ ] **Step 3: Create `server/transitions.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

const PROMISE_TRANSITIONS = [
    'pending' => ['fulfilled', 'disputed', 'broken', 'released'],
    'disputed' => ['fulfilled', 'broken', 'released'],
];
const GRIEVANCE_TRANSITIONS = [
    'open' => ['resolved', 'let_go'],
];

// Spec §6.4. Only the perspective owner's view of the counterpart changes.
const GRIEVANCE_AFFINITY = ['resolved' => 2, 'let_go' => 1];
const PROMISE_OWED_TO_ME_AFFINITY = ['fulfilled' => 2, 'broken' => -3];

function transitionAllowed(string $kind, string $from, string $to): bool
{
    $table = $kind === 'promise' ? PROMISE_TRANSITIONS : GRIEVANCE_TRANSITIONS;
    return in_array($to, $table[$from] ?? [], true);
}

function transitionKey(string $issueId, string $from, string $to): string
{
    return "{$issueId}:{$from}>{$to}";
}

function lifecycleAffinityDelta(array $issue, string $to): int
{
    if ($issue['kind'] === 'grievance') {
        return GRIEVANCE_AFFINITY[$to] ?? 0;
    }
    if ($issue['direction'] !== 'owed_to_me') {
        return 0;
    }
    return PROMISE_OWED_TO_ME_AFFINITY[$to] ?? 0;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/transitions_test.php`
Expected: `transitions checks passed`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev
git -c safe.directory='*' add server/transitions.php tests/transitions_test.php
git -c safe.directory='*' commit -m "Add issue transitions and lifecycle affinity table"
```

---

### Task 3: Witness filter

**Files:**
- Create: `server/witness.php`
- Test: `tests/witness_test.php`

- [ ] **Step 1: Write the failing test `tests/witness_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/witness.php';

use function ChimSwornAndScorned\pairLinesWitnessedBy;

$lines = [
    sampleLine(5, 'Farkas', 'I will stand with you before Aela.'),
    sampleLine(6, 'Aela', 'Farkas is a coward.'),                                   // third party: MP's business
    sampleLine(7, 'Farkas', 'Lydia is late again.', ['Farkas', 'Aela']),            // Lydia absent
    sampleLine(8, 'The Narrator', 'The Narrator: Aela claims Farkas stole.', ['Lydia', 'Farkas'], '', 'instruction'),
    sampleLine(9, 'Lydia', 'Why did he stay silent?', ['Lydia'], 'explicit_disable_rechat'),
    sampleLine(4, 'Lydia', 'You promised.'),
    sampleLine(10, 'Lydia', 'I saw the drinks.', ['Lydia', 'Farkas'], '', 'Consume'),
];

$lydia = pairLinesWitnessedBy($lines, 'Lydia', 'Farkas');
check(array_column($lydia, 'event_id') === [4, 5], 'Lydia sees only pair speech she witnessed, in order');

$farkas = pairLinesWitnessedBy($lines, 'Farkas', 'Lydia');
check(array_column($farkas, 'event_id') === [4, 5, 7], 'a speaker always witnesses their own line');

$player = pairLinesWitnessedBy([sampleLine(11, 'Dovah', 'I will find your sword.', ['Lydia'], 'Lydia', 'inputtext')], 'Lydia', 'Dovah');
check(array_column($player, 'event_id') === [11], 'player input counts as speech');

check(pairLinesWitnessedBy([], 'Lydia', 'Farkas') === [], 'no lines, no result');

echo "witness checks passed\n";
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/witness_test.php`
Expected: fatal `Failed opening required '.../server/witness.php'`, `exit=255`.

- [ ] **Step 3: Create `server/witness.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

// Speech rows only. Instruction rows (PCV "The Narrator: <direction>") and action rows
// (e.g. SHARMAT Consume) are never evidence. Exact values are Plan 0 question 6.
const SPEECH_EVENT_TYPES = ['chat', 'inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'];

// PCV addresses solo reflection and wrap-up lines here; they are not exchanges.
const SENTINEL_LISTENER = 'explicit_disable_rechat';

/**
 * Lines spoken by either member of the pair that the perspective owner witnessed
 * (owner spoke them or is in the line's people list), ordered by event id.
 */
function pairLinesWitnessedBy(array $lines, string $owner, string $counterpart): array
{
    $kept = array_values(array_filter($lines, static function (array $line) use ($owner, $counterpart): bool {
        if (!in_array($line['type'], SPEECH_EVENT_TYPES, true) || $line['listener'] === SENTINEL_LISTENER) {
            return false;
        }
        if ($line['speaker'] !== $owner && $line['speaker'] !== $counterpart) {
            return false;
        }
        return $line['speaker'] === $owner || in_array($owner, $line['people'], true);
    }));
    usort($kept, static fn (array $a, array $b): int => $a['event_id'] <=> $b['event_id']);
    return $kept;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/witness_test.php`
Expected: `witness checks passed`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev
git -c safe.directory='*' add server/witness.php tests/witness_test.php
git -c safe.directory='*' commit -m "Add witness filter: pair speech the owner witnessed"
```

---

### Task 4: Decision backend contract and policies

**Files:**
- Create: `server/backend.php`, `tests/support/scripted_backend.php`
- Test: `tests/backend_test.php`

- [ ] **Step 1: Write the failing test `tests/backend_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/backend.php';
require __DIR__ . '/support/scripted_backend.php';

use function ChimSwornAndScorned\{confident, validatedAnswers};
use const ChimSwornAndScorned\{POLICY_DECISION_MODEL, POLICY_LLM_FALLBACK};

$questions = [
    ['id' => 'a', 'type' => 'noul', 'text' => '?', 'options' => []],
    ['id' => 'b', 'type' => 'choice', 'text' => '?', 'options' => ['will', 'will_try']],
];

$valid = validatedAnswers($questions, [
    'a' => ['value' => true, 'p' => 0.93],
    'b' => ['value' => 'maybe', 'p' => 0.99],
    'zzz' => ['value' => true, 'p' => 1],
]);
check($valid === ['a' => ['value' => true, 'p' => 0.93]], 'unknown ids and labels are dropped');
check(validatedAnswers($questions, ['a' => ['value' => 'yes', 'p' => 0.9]]) === [], 'noul needs a bool');
check(validatedAnswers($questions, ['a' => ['value' => true, 'p' => 1.5]]) === [], 'p must be within 0..1');
check(validatedAnswers($questions, ['a' => ['value' => true, 'p' => NAN]]) === [], 'p must be finite');
check(validatedAnswers($questions, ['b' => ['value' => 'will', 'p' => 1]]) === ['b' => ['value' => 'will', 'p' => 1.0]], 'integer p is cast to float');
check(validatedAnswers($questions, 'garbage') === [], 'non-array raw answers are ignored');

check(confident($valid, 'a', 0.9) === true, 'above threshold returns the value');
check(confident($valid, 'a', 0.95) === null, 'below threshold returns null');
check(confident($valid, 'missing', 0.1) === null, 'missing answer returns null');

check(POLICY_DECISION_MODEL === ['detect_min' => 0.80, 'status_min' => 0.80, 'affinity_min' => 0.90], 'decision model policy');
check(POLICY_LLM_FALLBACK === ['detect_min' => 0.90, 'status_min' => 0.90, 'affinity_min' => 0.90], 'LLM fallback policy is stricter');

$backend = new ScriptedBackend(['a' => ['value' => true, 'p' => 0.9], 'c' => ['value' => true, 'p' => 0.9]]);
check($backend->ask([], $questions) === ['a' => ['value' => true, 'p' => 0.9]], 'scripted backend answers only asked ids');
check($backend->calls === [['a', 'b']] && $backend->name() === 'scripted', 'scripted backend records calls');

echo "backend checks passed\n";
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/backend_test.php`
Expected: fatal `Failed opening required '.../server/backend.php'`, `exit=255`.

- [ ] **Step 3: Create `server/backend.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

/**
 * A decision backend answers typed questions about a structured state.
 * Adapters (OpenRouter Decisions API, CHIM LLM fallback) arrive in Plan 2.
 */
interface DecisionBackend
{
    public function name(): string;

    /**
     * @param array $state JSON-serialisable state (owner, counterpart, labelled lines, open issues)
     * @param list<array{id: string, type: string, text: string, options: list<string>}> $questions
     * @return mixed Raw answers keyed by question id: ['id' => ['value' => bool|string, 'p' => float]].
     *               Validated by validatedAnswers(); anything else is ignored.
     */
    public function ask(array $state, array $questions): mixed;
}

// Thresholds per backend (spec §6.3, §6.4). The LLM adapter maps its self-reported
// high/medium/low to numbers so that only "high" clears these minimums.
const POLICY_DECISION_MODEL = ['detect_min' => 0.80, 'status_min' => 0.80, 'affinity_min' => 0.90];
const POLICY_LLM_FALLBACK = ['detect_min' => 0.90, 'status_min' => 0.90, 'affinity_min' => 0.90];

/**
 * Keep only answers to asked questions whose value is allowed for the question type
 * and whose probability is a finite number in [0, 1].
 */
function validatedAnswers(array $questions, mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $valid = [];
    foreach ($questions as $question) {
        $answer = $raw[$question['id']] ?? null;
        if (!is_array($answer)) {
            continue;
        }
        $p = $answer['p'] ?? null;
        if (!(is_int($p) || is_float($p)) || !is_finite((float) $p) || $p < 0 || $p > 1) {
            continue;
        }
        $value = $answer['value'] ?? null;
        $allowed = $question['type'] === 'noul'
            ? is_bool($value)
            : is_string($value) && in_array($value, $question['options'], true);
        if (!$allowed) {
            continue;
        }
        $valid[$question['id']] = ['value' => $value, 'p' => (float) $p];
    }
    return $valid;
}

function confident(array $answers, string $id, float $min): bool|string|null
{
    $answer = $answers[$id] ?? null;
    return ($answer !== null && $answer['p'] >= $min) ? $answer['value'] : null;
}
```

- [ ] **Step 4: Create `tests/support/scripted_backend.php`**

```php
<?php
declare(strict_types=1);

use ChimSwornAndScorned\DecisionBackend;

final class ScriptedBackend implements DecisionBackend
{
    /** @var list<list<string>> question ids asked, one list per call */
    public array $calls = [];

    public function __construct(private array $answers, private string $label = 'scripted')
    {
    }

    public function name(): string
    {
        return $this->label;
    }

    public function ask(array $state, array $questions): mixed
    {
        $ids = array_column($questions, 'id');
        $this->calls[] = $ids;
        return array_intersect_key($this->answers, array_flip($ids));
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/backend_test.php`
Expected: `backend checks passed`, `exit=0`.

- [ ] **Step 6: Commit**

```bash
cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev
git -c safe.directory='*' add server/backend.php tests/support/scripted_backend.php tests/backend_test.php
git -c safe.directory='*' commit -m "Add decision backend contract, policies and answer validation"
```

---

### Task 5: Questions

**Files:**
- Create: `server/questions.php`
- Test: `tests/questions_test.php`

- [ ] **Step 1: Write the failing test `tests/questions_test.php`**

```php
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
    $state['open_issues'] === ['abc123abc123abc1' => 'Lydia feels wronged by Farkas: "I\'ll stand with you before Aela."'],
    'issue summary quotes the latest evidence'
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/questions_test.php`
Expected: fatal `Failed opening required '.../server/questions.php'`, `exit=255`.

- [ ] **Step 3: Create `server/questions.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/vocab.php';

const PROMISE_STATUS_CHOICES = ['fulfilled', 'broken', 'renegotiated', 'released', 'disputed', 'no_change'];
const GRIEVANCE_STATUS_CHOICES = ['resolved', 'let_go', 'no_change'];
const ACCEPTANCE_CHOICES = ['accepted', 'declined', 'unclear'];

function noul(string $id, string $text): array
{
    return ['id' => $id, 'type' => 'noul', 'text' => $text, 'options' => []];
}

function choice(string $id, string $text, array $options): array
{
    return ['id' => $id, 'type' => 'choice', 'text' => $text, 'options' => array_values($options)];
}

/** @return array<string, array> lines keyed L1..Ln in the given order */
function lineLabels(array $lines): array
{
    $labelled = [];
    foreach (array_values($lines) as $index => $line) {
        $labelled['L' . ($index + 1)] = $line;
    }
    return $labelled;
}

function issueSummary(array $issue, string $owner, string $counterpart): string
{
    $what = match (true) {
        $issue['kind'] === 'grievance' => "{$owner} feels wronged by {$counterpart}",
        $issue['direction'] === 'owed_by_me' => "{$owner} promised {$counterpart} something",
        default => "{$counterpart} promised {$owner} something",
    };
    if ($issue['evidence'] === []) {
        return $what;
    }
    $latest = $issue['evidence'][array_key_last($issue['evidence'])];
    return "{$what}: \"{$latest['quote']}\"";
}

/**
 * @param array $ctx {owner: string, counterpart: string, lines: list<line>, open_issues: list<issue>}
 */
function buildState(array $ctx): array
{
    $lines = [];
    foreach (lineLabels($ctx['lines']) as $label => $line) {
        $lines[$label] = $line['speaker'] . ': ' . $line['text'];
    }
    $issues = [];
    foreach ($ctx['open_issues'] as $issue) {
        $issues[$issue['id']] = issueSummary($issue, $ctx['owner'], $ctx['counterpart']);
    }
    return ['owner' => $ctx['owner'], 'counterpart' => $ctx['counterpart'], 'lines' => $lines, 'open_issues' => $issues];
}

function pass1Questions(array $ctx): array
{
    $owner = $ctx['owner'];
    $counterpart = $ctx['counterpart'];
    $questions = [];
    foreach ($ctx['open_issues'] as $issue) {
        $questions[] = noul(
            "addresses:{$issue['id']}",
            "Do these lines address this open matter between {$owner} and {$counterpart}: "
            . issueSummary($issue, $owner, $counterpart) . '?'
        );
    }
    $questions[] = noul('commitment', "In these lines, does {$owner} or {$counterpart} commit to doing something for the other?");
    $questions[] = noul('wronged', "In these lines, does {$owner} express feeling wronged by {$counterpart}?");
    return $questions;
}

/**
 * @param list<string> $positives pass 1 question ids answered true with enough confidence
 */
function pass2Questions(array $ctx, array $positives): array
{
    $owner = $ctx['owner'];
    $counterpart = $ctx['counterpart'];
    $labels = array_keys(lineLabels($ctx['lines']));
    $questions = [];
    if (in_array('commitment', $positives, true)) {
        $questions[] = choice('commitment.who', 'Who made the commitment?', [$owner, $counterpart]);
        $questions[] = choice('commitment.strength', 'Is it a firm commitment ("I will") or only an attempt ("I will try")?', STRENGTHS);
        $questions[] = choice('commitment.acceptance', 'Did the other person accept the commitment?', ACCEPTANCE_CHOICES);
        $questions[] = choice('commitment.line', 'Which line contains the commitment?', $labels);
    }
    if (in_array('wronged', $positives, true)) {
        $questions[] = choice('grievance.line', "Which line shows {$owner} feeling wronged?", $labels);
        $questions[] = choice('grievance.wants', "What does {$owner} want from {$counterpart} about it?", WANTS);
    }
    foreach ($ctx['open_issues'] as $issue) {
        if (!in_array("addresses:{$issue['id']}", $positives, true)) {
            continue;
        }
        $prefix = "issue:{$issue['id']}";
        $isPromise = $issue['kind'] === 'promise';
        $questions[] = choice("{$prefix}.status", 'After these lines, what is the state of this matter?', $isPromise ? PROMISE_STATUS_CHOICES : GRIEVANCE_STATUS_CHOICES);
        $questions[] = choice("{$prefix}.trajectory", 'Did these lines make it better, worse, or neither?', TRAJECTORIES);
        $questions[] = choice("{$prefix}.line", 'Which line matters most for it?', $labels);
        if (!$isPromise) {
            $questions[] = choice("{$prefix}.wants", "What does {$owner} now want from {$counterpart} about it?", WANTS);
        }
    }
    return $questions;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/questions_test.php`
Expected: `questions checks passed`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev
git -c safe.directory='*' add server/questions.php tests/questions_test.php
git -c safe.directory='*' commit -m "Add two-pass question and state builders"
```

---

### Task 6: Evaluator

**Files:**
- Create: `server/evaluator.php`
- Test: `tests/evaluator_test.php`

- [ ] **Step 1: Write the failing test `tests/evaluator_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/evaluator.php';
require __DIR__ . '/support/scripted_backend.php';

use function ChimSwornAndScorned\evaluatePerspective;
use const ChimSwornAndScorned\{POLICY_DECISION_MODEL, POLICY_LLM_FALLBACK};

$ask = sampleLine(4, 'Lydia', 'Will you defend me before Aela?');
$vow = sampleLine(5, 'Farkas', 'I will stand with you before Aela.');
$ctx = ['owner' => 'Lydia', 'counterpart' => 'Farkas', 'lines' => [$ask, $vow], 'open_issues' => []];
$empty = ['new_issues' => [], 'updates' => []];

// No witnessed lines: no provider call.
$silent = new ScriptedBackend([]);
check(evaluatePerspective(['lines' => []] + $ctx, $silent, POLICY_DECISION_MODEL) === $empty, 'no lines, empty proposal');
check($silent->calls === [], 'no lines, no backend call');

// Nothing detected: one call only.
$quiet = new ScriptedBackend(['commitment' => ['value' => false, 'p' => 0.9], 'wronged' => ['value' => false, 'p' => 0.9]]);
check(evaluatePerspective($ctx, $quiet, POLICY_DECISION_MODEL) === $empty, 'nothing detected');
check(count($quiet->calls) === 1, 'pass 2 skipped when pass 1 is negative');

// Accepted firm promise from the counterpart.
$promiseAnswers = [
    'commitment' => ['value' => true, 'p' => 0.95],
    'wronged' => ['value' => false, 'p' => 0.9],
    'commitment.who' => ['value' => 'Farkas', 'p' => 0.9],
    'commitment.strength' => ['value' => 'will', 'p' => 0.88],
    'commitment.acceptance' => ['value' => 'accepted', 'p' => 0.85],
    'commitment.line' => ['value' => 'L2', 'p' => 0.97],
];
$proposal = evaluatePerspective($ctx, new ScriptedBackend($promiseAnswers), POLICY_DECISION_MODEL);
check($proposal['new_issues'] === [[
    'kind' => 'promise',
    'direction' => 'owed_to_me',
    'strength' => 'will',
    'wants' => 'keep_promise',
    'line' => $vow,
    'label' => 'commitment',
    'p' => 0.95,
]] && $proposal['updates'] === [], 'accepted promise from the counterpart becomes owed_to_me');

// Lydia's own promise is owed_by_me with unknown wants.
$own = evaluatePerspective($ctx, new ScriptedBackend(['commitment.who' => ['value' => 'Lydia', 'p' => 0.9]] + $promiseAnswers), POLICY_DECISION_MODEL);
check($own['new_issues'][0]['direction'] === 'owed_by_me' && $own['new_issues'][0]['wants'] === 'unknown', 'own promise is owed_by_me');

// Unclear acceptance creates nothing; a favour is not an agreed debt.
$unclear = evaluatePerspective($ctx, new ScriptedBackend(['commitment.acceptance' => ['value' => 'unclear', 'p' => 0.9]] + $promiseAnswers), POLICY_DECISION_MODEL);
check($unclear === $empty, 'unclear acceptance creates no promise');

// Low pass 1 confidence stops before pass 2.
$weak = new ScriptedBackend(['commitment' => ['value' => true, 'p' => 0.6]] + $promiseAnswers);
check(evaluatePerspective($ctx, $weak, POLICY_DECISION_MODEL) === $empty && count($weak->calls) === 1, 'low confidence detection ignored');

// The LLM fallback policy is stricter: 0.85 detection is not enough.
$llm = evaluatePerspective($ctx, new ScriptedBackend(['commitment' => ['value' => true, 'p' => 0.85]] + $promiseAnswers), POLICY_LLM_FALLBACK);
check($llm === $empty, 'LLM fallback needs high confidence');

// New grievance.
$grievance = evaluatePerspective($ctx, new ScriptedBackend([
    'commitment' => ['value' => false, 'p' => 0.9],
    'wronged' => ['value' => true, 'p' => 0.9],
    'grievance.line' => ['value' => 'L1', 'p' => 0.9],
    'grievance.wants' => ['value' => 'explanation', 'p' => 0.6],
]), POLICY_DECISION_MODEL);
check($grievance['new_issues'] === [[
    'kind' => 'grievance', 'direction' => null, 'strength' => null, 'wants' => 'unknown',
    'line' => $ask, 'label' => 'wronged', 'p' => 0.9,
]], 'grievance with low-confidence wants falls back to unknown');

// Addressed open promise.
$open = sampleIssue(['id' => 'p000000000000001', 'kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'status' => 'pending', 'wants' => 'keep_promise']);
$addressedCtx = ['open_issues' => [$open]] + $ctx;
$addressedAnswers = [
    'addresses:p000000000000001' => ['value' => true, 'p' => 0.9],
    'commitment' => ['value' => false, 'p' => 0.9],
    'wronged' => ['value' => false, 'p' => 0.9],
    'issue:p000000000000001.status' => ['value' => 'broken', 'p' => 0.92],
    'issue:p000000000000001.trajectory' => ['value' => 'worsening', 'p' => 0.85],
    'issue:p000000000000001.line' => ['value' => 'L1', 'p' => 0.9],
];
$broken = evaluatePerspective($addressedCtx, new ScriptedBackend($addressedAnswers), POLICY_DECISION_MODEL);
check($broken['updates'] === [[
    'issue_id' => 'p000000000000001', 'status' => 'broken', 'status_p' => 0.92,
    'trajectory' => 'worsening', 'wants' => null, 'line' => $ask,
]] && $broken['new_issues'] === [], 'addressed promise update');

$doubtful = evaluatePerspective($addressedCtx, new ScriptedBackend(['issue:p000000000000001.status' => ['value' => 'broken', 'p' => 0.7]] + $addressedAnswers), POLICY_DECISION_MODEL);
check($doubtful['updates'][0]['status'] === null && $doubtful['updates'][0]['status_p'] === 0.7, 'doubtful status is not proposed');

echo "evaluator checks passed\n";
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/evaluator_test.php`
Expected: fatal `Failed opening required '.../server/evaluator.php'`, `exit=255`.

- [ ] **Step 3: Create `server/evaluator.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/backend.php';
require_once __DIR__ . '/questions.php';

/**
 * Judge one perspective (owner about counterpart) over the lines the owner witnessed.
 * Returns a proposal; nothing is persisted here. Backend exceptions propagate so the
 * worker can retry the job (Plan 2).
 *
 * @param array $ctx {owner, counterpart, lines: list<line> (already witness-filtered), open_issues: list<issue>}
 * @return array{new_issues: list<array>, updates: list<array>}
 */
function evaluatePerspective(array $ctx, DecisionBackend $backend, array $policy): array
{
    $proposal = ['new_issues' => [], 'updates' => []];
    if ($ctx['lines'] === []) {
        return $proposal;
    }
    $state = buildState($ctx);
    $detect = $policy['detect_min'];

    $pass1 = pass1Questions($ctx);
    $answers1 = validatedAnswers($pass1, $backend->ask($state, $pass1));
    $positives = [];
    foreach ($pass1 as $question) {
        if (confident($answers1, $question['id'], $detect) === true) {
            $positives[] = $question['id'];
        }
    }
    if ($positives === []) {
        return $proposal;
    }

    $pass2 = pass2Questions($ctx, $positives);
    $answers2 = validatedAnswers($pass2, $backend->ask($state, $pass2));
    $lines = lineLabels($ctx['lines']);
    $lineFor = static fn (string $id): ?array => $lines[(string) confident($answers2, $id, $detect)] ?? null;

    if (in_array('commitment', $positives, true)) {
        $who = confident($answers2, 'commitment.who', $detect);
        $strength = confident($answers2, 'commitment.strength', $detect);
        $acceptance = confident($answers2, 'commitment.acceptance', $detect);
        $line = $lineFor('commitment.line');
        if (is_string($who) && is_string($strength) && $acceptance === 'accepted' && $line !== null) {
            $mine = $who === $ctx['owner'];
            $proposal['new_issues'][] = [
                'kind' => 'promise',
                'direction' => $mine ? 'owed_by_me' : 'owed_to_me',
                'strength' => $strength,
                'wants' => $mine ? 'unknown' : 'keep_promise',
                'line' => $line,
                'label' => 'commitment',
                'p' => $answers1['commitment']['p'],
            ];
        }
    }

    if (in_array('wronged', $positives, true)) {
        $line = $lineFor('grievance.line');
        $wants = confident($answers2, 'grievance.wants', $detect);
        if ($line !== null) {
            $proposal['new_issues'][] = [
                'kind' => 'grievance',
                'direction' => null,
                'strength' => null,
                'wants' => is_string($wants) ? $wants : 'unknown',
                'line' => $line,
                'label' => 'wronged',
                'p' => $answers1['wronged']['p'],
            ];
        }
    }

    foreach ($ctx['open_issues'] as $issue) {
        if (!in_array("addresses:{$issue['id']}", $positives, true)) {
            continue;
        }
        $prefix = "issue:{$issue['id']}";
        $status = $answers2["{$prefix}.status"] ?? null;
        $wants = $issue['kind'] === 'grievance' ? confident($answers2, "{$prefix}.wants", $detect) : null;
        $trajectory = confident($answers2, "{$prefix}.trajectory", $detect);
        $proposal['updates'][] = [
            'issue_id' => $issue['id'],
            'status' => ($status !== null && $status['p'] >= $policy['status_min']) ? $status['value'] : null,
            'status_p' => $status['p'] ?? 0.0,
            'trajectory' => is_string($trajectory) ? $trajectory : null,
            'wants' => is_string($wants) ? $wants : null,
            'line' => $lineFor("{$prefix}.line"),
        ];
    }

    return $proposal;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/evaluator_test.php`
Expected: `evaluator checks passed`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev
git -c safe.directory='*' add server/evaluator.php tests/evaluator_test.php
git -c safe.directory='*' commit -m "Add two-pass perspective evaluator"
```

---

### Task 7: Apply proposals

**Files:**
- Create: `server/apply.php`
- Test: `tests/apply_test.php`

- [ ] **Step 1: Write the failing test `tests/apply_test.php`**

```php
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
    'issue_id' => $promiseId, 'status' => 'fulfilled', 'status_p' => 0.85, 'trajectory' => null, 'wants' => null, 'line' => null,
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
    'updates' => [['issue_id' => $promiseId, 'status' => 'renegotiated', 'status_p' => 0.9, 'trajectory' => null, 'wants' => null, 'line' => null]],
], $breakMeta);
check(issueById($relinked['ledger'], issueId('e:8', 'npc:3', 'promise'))['links'] === [$promiseId], 'renegotiation links the new promise');
check(issueById($relinked['ledger'], $promiseId)['links'] === [issueId('e:8', 'npc:3', 'promise')], 'old promise links to the new terms');

// Disallowed transition is skipped.
$disputed = applyProposal($first['ledger'], ['new_issues' => [], 'updates' => [[
    'issue_id' => $promiseId, 'status' => 'disputed', 'status_p' => 0.9, 'trajectory' => null, 'wants' => null, 'line' => null,
]]], $breakMeta);
$again = applyProposal($disputed['ledger'], ['new_issues' => [], 'updates' => [[
    'issue_id' => $promiseId, 'status' => 'disputed', 'status_p' => 0.9, 'trajectory' => null, 'wants' => null, 'line' => null,
]]], ['event_ids' => [9]] + $meta);
check($again['skipped'] === ['transition_not_allowed'], 'disputed to disputed is not allowed');

// Wrong counterpart cannot touch the issue.
$wrong = applyProposal($first['ledger'], ['new_issues' => [], 'updates' => [[
    'issue_id' => $promiseId, 'status' => 'broken', 'status_p' => 0.95, 'trajectory' => null, 'wants' => null, 'line' => null,
]]], ['counterpart' => 'npc:9'] + $breakMeta);
check($wrong['skipped'] === ['update_target_missing'] && $wrong['affinity_delta'] === 0, 'counterpart must match');

// Broken promise plus a new grievance in the same batch are linked both ways.
$linked = applyProposal($first['ledger'], [
    'new_issues' => [['kind' => 'grievance', 'direction' => null, 'strength' => null, 'wants' => 'explanation', 'line' => $silence, 'label' => 'wronged', 'p' => 0.9]],
    'updates' => [['issue_id' => $promiseId, 'status' => 'broken', 'status_p' => 0.95, 'trajectory' => null, 'wants' => null, 'line' => null]],
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/apply_test.php`
Expected: fatal `Failed opening required '.../server/apply.php'`, `exit=255`.

- [ ] **Step 3: Create `server/apply.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

use UnexpectedValueException;

require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/transitions.php';

/**
 * Apply an evaluator proposal to one perspective ledger. Pure: the caller persists the
 * returned ledger and affinity delta together (Plan 2 store).
 *
 * @param array $meta {event_ids: list<int>, counterpart: string, backend: string, policy: array}
 * @return array{status: string, ledger: array, affinity_delta: int, transitions: list<string>, skipped: list<string>}
 */
function applyProposal(array $ledger, array $proposal, array $meta): array
{
    $eventIds = $meta['event_ids'];
    if ($eventIds === []) {
        throw new UnexpectedValueException('batch_empty');
    }
    if (isReplay($ledger, $eventIds)) {
        return ['status' => 'replay', 'ledger' => $ledger, 'affinity_delta' => 0, 'transitions' => [], 'skipped' => []];
    }
    $batchMax = max($eventIds);
    $delta = 0;
    $transitions = [];
    $skipped = [];
    $changedIds = [];
    $newIds = [];
    $issues = $ledger['issues'];
    $index = [];
    foreach ($issues as $position => $issue) {
        $index[$issue['id']] = $position;
    }

    foreach ($proposal['updates'] as $update) {
        $position = $index[$update['issue_id']] ?? null;
        if ($position === null || !isOpen($issues[$position]) || $issues[$position]['counterpart'] !== $meta['counterpart']) {
            $skipped[] = 'update_target_missing';
            continue;
        }
        $issue = $issues[$position];
        $label = 'addressed';
        $to = $update['status'];
        if (is_string($to) && $to !== 'no_change') {
            $label = $to;
            if (transitionAllowed($issue['kind'], $issue['status'], $to)) {
                $key = transitionKey($issue['id'], $issue['status'], $to);
                if ($update['status_p'] >= $meta['policy']['affinity_min'] && !in_array($key, $issue['affinity_applied'], true)) {
                    $delta += lifecycleAffinityDelta($issue, $to);
                    $issue['affinity_applied'][] = $key;
                }
                $issue['status'] = $to;
                $transitions[] = $key;
                $changedIds[] = $issue['id'];
            } elseif ($to === 'renegotiated') {
                // Terms changed; the promise stays pending but any new promise in this batch links to it.
                $changedIds[] = $issue['id'];
            } else {
                $skipped[] = 'transition_not_allowed';
            }
        }
        if (is_string($update['trajectory'])) {
            $issue['trajectory'] = $update['trajectory'];
        }
        if (is_string($update['wants'])) {
            $issue['wants'] = $update['wants'];
        }
        if ($update['line'] !== null) {
            $issue['evidence'] = appendEvidence(
                $issue['evidence'],
                evidenceFromLine($update['line'], $label, $update['status_p'], $meta['backend'])
            );
        }
        $issue['changed_event_id'] = $batchMax;
        $issues[$position] = $issue;
    }

    foreach ($proposal['new_issues'] as $new) {
        $id = issueId('e:' . $new['line']['event_id'], $meta['counterpart'], $new['kind']);
        if (isset($index[$id])) {
            $skipped[] = 'issue_exists';
            continue;
        }
        if (count(array_filter($issues, isOpen(...))) >= MAX_OPEN_ISSUES) {
            $skipped[] = 'open_cap';
            continue;
        }
        $isPromise = $new['kind'] === 'promise';
        $issues[] = [
            'id' => $id,
            'origin' => 'event',
            'kind' => $new['kind'],
            'counterpart' => $meta['counterpart'],
            'direction' => $isPromise ? $new['direction'] : null,
            'strength' => $isPromise ? $new['strength'] : null,
            'status' => $isPromise ? 'pending' : 'open',
            'trajectory' => 'unchanged',
            'wants' => $new['wants'],
            'evidence' => [evidenceFromLine($new['line'], $new['label'], $new['p'], $meta['backend'])],
            'links' => [],
            'affinity_applied' => [],
            'muted' => false,
            'dismissed' => false,
            'changed_event_id' => $batchMax,
        ];
        $index[$id] = array_key_last($issues);
        $newIds[] = $id;
    }

    // A new issue born in the same batch as a status change is part of the same story
    // (e.g. broken promise -> grievance): link both ways.
    if ($newIds !== [] && $changedIds !== []) {
        foreach ($issues as $position => $issue) {
            $others = in_array($issue['id'], $newIds, true)
                ? $changedIds
                : (in_array($issue['id'], $changedIds, true) ? $newIds : []);
            foreach ($others as $other) {
                if (!in_array($other, $issues[$position]['links'], true)) {
                    $issues[$position]['links'][] = $other;
                }
            }
        }
    }

    $ledger['issues'] = enforceClosedCap($issues);
    $ledger = markProcessed($ledger, $eventIds);
    return ['status' => 'applied', 'ledger' => $ledger, 'affinity_delta' => $delta, 'transitions' => $transitions, 'skipped' => $skipped];
}

function evidenceFromLine(array $line, string $label, float $p, string $backend): array
{
    return [
        'ref' => 'e:' . $line['event_id'],
        'gamets' => (float) $line['gamets'],
        'speaker' => $line['speaker'],
        'quote' => clipText($line['text'], MAX_QUOTE_CHARS),
        'label' => $label,
        'p' => $p,
        'backend' => $backend,
    ];
}

/** Keep the originating evidence plus the most recent entries. */
function appendEvidence(array $evidence, array $entry): array
{
    $evidence[] = $entry;
    if (count($evidence) > MAX_EVIDENCE) {
        $evidence = array_merge([$evidence[0]], array_slice($evidence, -(MAX_EVIDENCE - 1)));
    }
    return $evidence;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/apply_test.php`
Expected: `apply checks passed`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev
git -c safe.directory='*' add server/apply.php tests/apply_test.php
git -c safe.directory='*' commit -m "Add idempotent proposal application with lifecycle affinity and links"
```

---

### Task 8: Surfacing selection and note rendering

**Files:**
- Create: `server/surfacing.php`
- Test: `tests/surfacing_test.php`

- [ ] **Step 1: Write the failing test `tests/surfacing_test.php`**

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/surfacing_test.php`
Expected: fatal `Failed opening required '.../server/surfacing.php'`, `exit=255`.

- [ ] **Step 3: Create `server/surfacing.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/ledger.php';

const SURFACE_COOLDOWN_SECONDS = 600;

const WANTS_PHRASES = [
    'explanation' => 'You want an explanation.',
    'apology' => 'You want an apology.',
    'restitution' => 'You want amends.',
    'keep_promise' => 'You want that promise kept.',
    'thanks' => 'You want to give thanks.',
    'unknown' => '',
];

/**
 * Pick at most one issue for the speaker's turn (spec §7.1).
 *
 * @param list<string> $presentCounterparts counterpart keys present this turn (npc:<id>, player)
 * @param list<string> $mentionedCounterparts counterpart keys named in the current exchange or player input
 * @param array<string, array{at: int, evidence_count: int}> $pacing last surfacing per issue id
 */
function selectIssueToSurface(
    array $ledger,
    array $presentCounterparts,
    array $mentionedCounterparts,
    array $pacing,
    int $now,
    int $cooldownSeconds = SURFACE_COOLDOWN_SECONDS
): ?array {
    $eligible = [];
    foreach ($ledger['issues'] as $issue) {
        if (!isOpen($issue) || $issue['muted']) {
            continue;
        }
        $counterpart = $issue['counterpart'];
        $mentioned = in_array($counterpart, $mentionedCounterparts, true);
        $reachable = str_starts_with($counterpart, 'daedra:')
            ? $mentioned
            : in_array($counterpart, $presentCounterparts, true);
        if (!$reachable) {
            continue;
        }
        $last = $pacing[$issue['id']] ?? null;
        $cooling = $last !== null
            && $now - $last['at'] < $cooldownSeconds
            && $last['evidence_count'] === count($issue['evidence']);
        if ($cooling && !$mentioned) {
            continue;
        }
        $eligible[] = $issue;
    }
    if ($eligible === []) {
        return null;
    }
    usort($eligible, static fn (array $a, array $b): int => [$b['changed_event_id'], $a['id']] <=> [$a['changed_event_id'], $b['id']]);
    return $eligible[0];
}

/** Private, speaker-side context line of at most MAX_NOTE_CHARS characters. */
function renderNote(array $issue, string $ownerName, string $counterpartName): string
{
    $head = match (true) {
        $issue['kind'] === 'grievance' => "On your mind: unresolved with {$counterpartName}.",
        $issue['direction'] === 'owed_by_me' => "On your mind: you promised {$counterpartName} something.",
        default => "On your mind: {$counterpartName} promised you something.",
    };
    $tail = trim(WANTS_PHRASES[$issue['wants']] . ' Raise it only if it fits the moment.');
    if ($issue['evidence'] === []) {
        return clipText("{$head} {$tail}", MAX_NOTE_CHARS);
    }
    $evidence = $issue['evidence'][array_key_last($issue['evidence'])];
    $who = match (true) {
        $issue['origin'] === 'lore' => 'Old grudge',
        $evidence['speaker'] === $ownerName => 'You said',
        default => "{$evidence['speaker']} said",
    };
    $fixed = mb_strlen("{$head} {$who}: \"\" {$tail}");
    $quote = clipText($evidence['quote'], max(20, MAX_NOTE_CHARS - $fixed));
    return clipText("{$head} {$who}: \"{$quote}\" {$tail}", MAX_NOTE_CHARS);
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/surfacing_test.php`
Expected: `surfacing checks passed`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev
git -c safe.directory='*' add server/surfacing.php tests/surfacing_test.php
git -c safe.directory='*' commit -m "Add surfacing selection with pacing and note rendering"
```

---

### Task 9: Full suite, lint and handoff

**Files:**
- Modify: `tasks/todo.md`

- [ ] **Step 1: Lint every server file and run the whole suite**

```bash
wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash -c 'for f in server/*.php; do php -l "$f" || exit 1; done && bash scripts/run-php-tests.sh tests/*_test.php'
```

Expected: `No syntax errors detected` for each of the 9 server files, then for each of the 9 test files a `... checks passed` line and `exit=0`. The command's exit status is 0.

- [ ] **Step 2: Shut down the test distro and confirm the gaming distro was untouched**

```bash
wsl.exe --terminate DwemerAI4Skyrim3-test
wsl.exe -l -v
```

Expected: `DwemerAI4Skyrim3` still `Stopped`.

- [ ] **Step 3: Tick all Plan 1 items in `tasks/todo.md` and append a review note**

Append under the checklist:

```markdown
Review (Plan 1): 9 server files lint clean; 9 check scripts pass in DwemerAI4Skyrim3-test
(PHP 8.2). Pure domain only: no CHIM hooks, database, provider, worker or in-game behaviour
is exercised or claimed. Next: Plan 0 clone verification (see roadmap).
```

- [ ] **Step 4: Commit**

```bash
cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev
git -c safe.directory='*' add tasks/todo.md
git -c safe.directory='*' commit -m "Plan 1 domain core complete"
```
