# Sworn & Scorned — Plan 2: CHIM Integration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Connect the Plan 1 domain core to CHIM. That means: packaging; an eventlog scanner that turns spoken lines into per-perspective jobs; a job queue with chronology, leases and retries; the OpenRouter Decisions backend and the CHIM LLM fallback; a locked, snapshotted ledger store; and a self-managing worker daemon launched from `context_pre.php`.

**Architecture:** Pure functions (row parsing, batching, scan window, backend request and response mapping, job orchestration, retry policy, settings normalisation) are unit-tested with fakes. SQL-bound code (`Pg`, tables, queue, store) is tested against the real PostgreSQL in the **test clone**, inside a throwaway schema `sas_it`. One end-to-end task installs the package on the clone and drives it with PCV's client simulator. Spec: §4.2, §5, §6.1, §9, plus Amendment A.

**Tech Stack:** PHP 8.2 (`pgsql`, `curl`, `pcntl`, `posix`, `mbstring`), PostgreSQL (CHIM's), Python 3 for packaging, and PCV's live-test helpers (used, not modified).

**Prerequisites:** Plan 1 complete. Plan 0 findings show "Plan changes required: None". If they do not, apply the listed changes to this plan first.

---

## Ground rules (same as Plan 1, plus database rules)

- **Repo, git and PHP:** as in Plan 1. Use `git -c safe.directory='*' …`. PHP runs only in `DwemerAI4Skyrim3-test`. Never touch `DwemerAI4Skyrim3`, and check `wsl.exe -l -v` before each session.
- **Unit test command:**
  `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/<name>_test.php`
- **Integration tests** (Tasks 9 and 10) need the clone database running. Start it first with PCV's helper:
  ```powershell
  K:\ActorwrightExchange\projects\CHIM-PrivateConversation-dev\scripts\live-test\sim.ps1 -Script env.sh up
  ```
  Then run:
  `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- env SAS_IT=1 bash scripts/run-php-tests.sh tests/integration/<name>_it.php`
  They create and drop schema `sas_it` and never write `public` tables.
- **Never print, log, or commit API keys.** Logs carry fixed reason codes, never dialogue text.
- **Never edit Mind Poisoning or Private Conversation.**
- End every session with `wsl.exe --terminate DwemerAI4Skyrim3-test`.

## File structure (new unless noted)

| File | Responsibility |
| --- | --- |
| `server/manifest.json` | CHIM plugin manifest. |
| `scripts/build-package.py` | Copied from PCV and adapted: allowlist and no mutable paths. |
| `tests/package_check.py` | Builds the dwpkg and verifies its members and manifest. |
| `server/logging.php` | `sasLog()`: one JSON line to CHIM's Logger; whitelisted fields only. |
| `server/backend_error.php` | `DecisionBackendError` (reason code, retryable flag). |
| `server/rows.php` | `lineFromEventRow()`, `parsePeople()`: eventlog row to Line. |
| `server/batches.php` | `scanWindow()`, `perspectivesOf()`, `settledBatches()`. |
| `server/decisions_backend.php` | `OpenRouterDecisionBackend`, request and answer mapping, `curlTransport()`. |
| `server/llm_backend.php` | `LlmFallbackBackend`, prompt building and answer parsing. |
| `server/llm_connector.php` | `relationshipConnectorCompletion()`, `relationshipConnectorAvailable()`: CHIM's LLM connector call. |
| `server/jobs.php` | `JobDeps` interface, `processJob()`, `nextStep()` retry policy. |
| `server/pg.php` | `Pg` wrapper, array helpers, `openEngineConnection()`, `sharedConnection()`. |
| `server/tables.php` | `sasTable()`, `ensureSchema()`, settings, `currentPlaythrough()`. |
| `server/store.php` | `PerspectiveStore::commitLedger()`: lock, revalidate, write, snapshot. |
| `server/queue.php` | `scanOnce()`, `claimJob()`, `finishJob()`, `PgJobDeps`, `workerTick()`. |
| `server/launcher.php` | `workerCommand()`, `workerPidAlive()`, `ensureWorkerRunning()`. |
| `server/worker.php` | Daemon entry point (fork, single instance, loop, self-exit). |
| `server/context_pre.php` | CHIM hook: launch the worker when enabled. Plan 3 adds surfacing. |
| `tests/support/llm_stubs.php` | Stub `LLMConnector` and driver for the connector test. |
| `tests/integration/store_it.php`, `tests/integration/queue_it.php` | Clone database tests in schema `sas_it`. |

---

### Task 1: Manifest and packaging

**Files:**
- Create: `server/manifest.json`, `scripts/build-package.py` (copy), `tests/package_check.py`

- [ ] **Step 1: Create `server/manifest.json`**

Set `server_compatibility_reference` to the HerikaServer revision recorded in the Plan 0 findings, section 1.

```json
{
  "name": "sworn_and_scorned",
  "version": "0.1.0",
  "schema_version": 2,
  "git_repo": "Francisco-boop-001/CHIM-SwornAndScorned",
  "description": "PRE-ALPHA: Persistent grievances and promises between NPCs and the player, judged asynchronously by a decision model and surfaced quietly in conversation. Opt-in; works alone or with Mind Poisoning, Private Conversation and SHARMAT.",
  "status": "development_candidate",
  "config_url": "../ext/sworn_and_scorned/index.php?local=1",
  "default_channel": "candidate",
  "channels": {
    "candidate": {
      "label": "PRE-ALPHA development candidate",
      "branch": "main",
      "package_source": "release",
      "manifest_url": "https://raw.githubusercontent.com/Francisco-boop-001/CHIM-SwornAndScorned/main/server/manifest.json",
      "package_urls": [
        "https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/releases/download/sworn_and_scorned-v<version>/sworn_and_scorned.tar.gz"
      ],
      "archive_strip_components": 1,
      "allow_force": false
    }
  },
  "server_compatibility_reference": "PASTE_REVISION_FROM_PLAN_0_FINDINGS"
}
```

Replace `PASTE_REVISION_FROM_PLAN_0_FINDINGS` with the recorded revision before committing.

- [ ] **Step 2: Copy PCV's build script**

```bash
cp /k/ActorwrightExchange/projects/CHIM-PrivateConversation-dev/scripts/build-package.py /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev/scripts/build-package.py
```

- [ ] **Step 3: Adapt the copy**

Edit `scripts/build-package.py`:
1. First line docstring: `"""Build and verify the explicit CHIM Sworn & Scorned package payload."""`
2. Replace the whole `SERVER_FILES = ( … )` tuple with:

```python
SERVER_FILES = (
    "apply.php",
    "backend.php",
    "backend_error.php",
    "batches.php",
    "context_pre.php",
    "decisions_backend.php",
    "evaluator.php",
    "jobs.php",
    "launcher.php",
    "ledger.php",
    "llm_backend.php",
    "llm_connector.php",
    "logging.php",
    "manifest.json",
    "pg.php",
    "queue.php",
    "questions.php",
    "rows.php",
    "store.php",
    "surfacing.php",
    "tables.php",
    "transitions.php",
    "vocab.php",
    "witness.php",
    "worker.php",
)
```

3. In the package manifest, change `"server": {"mutable_paths": ["state"]},` to `"server": {"mutable_paths": []},`.
4. In `verify_package`, replace

```python
    if package_manifest.get("server", {}).get("mutable_paths") != ["state"]:
        raise PackageError("Package must preserve the extension-relative state directory")
```

with

```python
    if package_manifest.get("server", {}).get("mutable_paths") != []:
        raise PackageError("Package must not declare mutable paths")
```

Then check that no other `"state"` mentions remain: `grep -n '"state"' scripts/build-package.py` should print nothing.

- [ ] **Step 4: Write `tests/package_check.py`**

```python
from __future__ import annotations

import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[1]


class PackageCheck(unittest.TestCase):
    def test_dwpkg_members_and_manifest(self) -> None:
        manifest = json.loads((ROOT / "server" / "manifest.json").read_text(encoding="utf-8"))
        self.assertEqual(manifest["name"], "sworn_and_scorned")
        self.assertEqual(manifest["config_url"], "../ext/sworn_and_scorned/index.php?local=1")
        self.assertNotIn("PASTE_REVISION", manifest["server_compatibility_reference"])
        with tempfile.TemporaryDirectory(prefix=".sas-package-check-", dir=ROOT) as directory:
            output = Path(directory) / "out.dwpkg"
            subprocess.run([sys.executable, str(ROOT / "scripts" / "build-package.py"), "--output", str(output)], check=True, cwd=ROOT)
            with ZipFile(output) as archive:
                names = set(archive.namelist())
                self.assertIn("server/worker.php", names)
                self.assertIn("server/context_pre.php", names)
                self.assertNotIn("server/AGENTS.md", names)
                outer = json.loads(archive.read("manifest.json"))
                self.assertEqual(outer["schema_version"], 4)
                self.assertEqual(outer["name"], "sworn_and_scorned")
                self.assertEqual(outer["server"]["mutable_paths"], [])
                for line in archive.read("checksums.sha256").decode("ascii").splitlines():
                    digest, path = line.split("  ", 1)
                    self.assertEqual(hashlib.sha256(archive.read(path)).hexdigest(), digest)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 5: Run it (it fails until all server files exist)**

Run: `cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev && python tests/package_check.py`
Expected now: FAIL with `package failed: ...` naming a missing allowlisted file (for example `backend_error.php`). It passes at Task 12.

- [ ] **Step 6: Commit**

```bash
cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev
git -c safe.directory='*' add server/manifest.json scripts/build-package.py tests/package_check.py
git -c safe.directory='*' commit -m "Add manifest and package builder for sworn_and_scorned"
```

---

### Task 2: Logging and backend error

**Files:**
- Create: `server/logging.php`, `server/backend_error.php`
- Test: `tests/logging_test.php`

- [ ] **Step 1: Write the failing test `tests/logging_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/logging.php';
require __DIR__ . '/../server/backend_error.php';

use ChimSwornAndScorned\DecisionBackendError;
use function ChimSwornAndScorned\sasLog;

$file = tempnam(sys_get_temp_dir(), 'saslog');
ini_set('error_log', $file);
sasLog('job_finished', 'info', ['job_id' => 7, 'outcome' => 'done', 'text' => 'secret dialogue', 'api_key' => 'k']);
$logged = (string) file_get_contents($file);
unlink($file);
check(str_contains($logged, '"plugin":"sworn_and_scorned"') && str_contains($logged, '"job_id":7'), 'record written');
check(!str_contains($logged, 'secret dialogue') && !str_contains($logged, 'api_key'), 'non-whitelisted fields dropped');

$error = new DecisionBackendError('http_429', true);
check($error->reasonCode === 'http_429' && $error->retryable && $error->getMessage() === 'http_429', 'error carries code and retryable flag');

echo "logging checks passed\n";
```

- [ ] **Step 2: Run it to verify it fails**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash scripts/run-php-tests.sh tests/logging_test.php`
Expected: fatal `Failed opening required '.../server/logging.php'`, `exit=255`.

- [ ] **Step 3: Create `server/logging.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

const LOG_FIELDS = ['job_id', 'owner_npc_id', 'npc_id', 'issue_id', 'outcome', 'reason', 'backend', 'jobs', 'failures', 'pid', 'status'];

/**
 * One JSON line to CHIM's Logger (log/chim.log) or PHP's error_log.
 * Only whitelisted fields are kept: never pass dialogue text or credentials.
 */
function sasLog(string $event, string $level, array $fields = []): void
{
    try {
        $record = ['plugin' => 'sworn_and_scorned', 'event' => $event, 'level' => $level]
            + array_intersect_key($fields, array_flip(LOG_FIELDS));
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            return;
        }
        $method = ['debug' => 'debug', 'info' => 'info', 'warning' => 'warn', 'error' => 'error'][$level] ?? 'info';
        if (class_exists('Logger', false) && is_callable(['\\Logger', $method])) {
            @\Logger::$method($json);
        } else {
            @error_log($json);
        }
    } catch (\Throwable) {
    }
}
```

- [ ] **Step 4: Create `server/backend_error.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

final class DecisionBackendError extends \RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly bool $retryable,
        ?\Throwable $previous = null
    ) {
        parent::__construct($reasonCode, 0, $previous);
    }
}
```

- [ ] **Step 5: Run it to verify it passes**

Expected: `logging checks passed`, `exit=0`.

- [ ] **Step 6: Commit**

```bash
git -c safe.directory='*' add server/logging.php server/backend_error.php tests/logging_test.php
git -c safe.directory='*' commit -m "Add whitelisted logging and decision backend error"
```

---

### Task 3: eventlog rows to lines

**Files:**
- Create: `server/rows.php`
- Test: `tests/rows_test.php`

- [ ] **Step 1: Write the failing test `tests/rows_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/rows.php';

use function ChimSwornAndScorned\{lineFromEventRow, parsePeople};

$player = 'Hawke';
$spoken = [
    'rowid' => '41', 'type' => 'chat',
    'data' => 'Farkas: I will stand with you before Aela. (talking to Lidia Sobieska)',
    'people' => '|Farkas|Lidia Sobieska|Hawke|', 'gamets' => '292500100', 'localts' => '1790000000',
    'delivery_state' => 'spoken',
];
check(lineFromEventRow($spoken, $player) === [
    'event_id' => 41, 'gamets' => 292500100.0, 'type' => 'chat', 'speaker' => 'Farkas',
    'listener' => 'Lidia Sobieska', 'people' => ['Farkas', 'Lidia Sobieska', 'Hawke'],
    'text' => 'I will stand with you before Aela.', 'localts' => 1790000000,
], 'spoken chat line');
check(lineFromEventRow(['delivery_state' => 'aborted'] + $spoken, $player) === null, 'aborted lines ignored');
check(lineFromEventRow(['delivery_state' => 'emitted'] + $spoken, $player) === null, 'unacknowledged lines ignored');
check(lineFromEventRow(['type' => 'chat_background'] + $spoken, $player) === null, 'background chat ignored');
check(lineFromEventRow(['data' => 'no separator here'] + $spoken, $player) === null, 'malformed data ignored');

$solo = lineFromEventRow(['data' => 'Lidia Sobieska: Why did he stay silent? (talking to explicit_disable_rechat)'] + $spoken, $player);
check($solo['listener'] === 'explicit_disable_rechat', 'sentinel listener kept for the witness filter');

$input = [
    'rowid' => '42', 'type' => 'inputtext', 'data' => 'Hawke: Lidia, did Aela keep her word?',
    'people' => '|Lidia Sobieska|Hawke|', 'gamets' => '292500200', 'localts' => '1790000005', 'delivery_state' => null,
];
$line = lineFromEventRow($input, $player);
check($line['speaker'] === 'Hawke' && $line['listener'] === '' && $line['text'] === 'Lidia, did Aela keep her word?', 'player input line');
check(lineFromEventRow(['data' => 'Bruce: hi there'] + $input, $player) === null, 'input under another name ignored');
check(lineFromEventRow(['type' => 'instruction', 'data' => 'The Narrator: Aela claims Farkas stole.'] + $input, $player) === null, 'instruction rows ignored');

check(parsePeople(null) === [] && parsePeople('||') === [] && parsePeople('|A| B |') === ['A', 'B'], 'people parsing');

echo "rows checks passed\n";
```

- [ ] **Step 2: Run it to verify it fails**

Expected: fatal `Failed opening required '.../server/rows.php'`, `exit=255`.

- [ ] **Step 3: Create `server/rows.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

const PLAYER_INPUT_TYPES = ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'];

function parsePeople(?string $people): array
{
    if ($people === null || $people === '') {
        return [];
    }
    return array_values(array_filter(
        array_map('trim', explode('|', $people)),
        static fn (string $name): bool => $name !== ''
    ));
}

/**
 * eventlog row -> Line, or null when the row is not usable speech.
 * NPC lines count only once acknowledged (delivery_state 'spoken'); instruction, background
 * and action rows never count (spec §2a, Amendment A).
 *
 * @param array $row {rowid, type, data, people, gamets, localts, delivery_state} as strings from PostgreSQL
 */
function lineFromEventRow(array $row, string $playerName): ?array
{
    $type = (string) ($row['type'] ?? '');
    if ($type === 'chat') {
        if (($row['delivery_state'] ?? null) !== 'spoken') {
            return null;
        }
    } elseif (!in_array($type, PLAYER_INPUT_TYPES, true)) {
        return null;
    }
    if (preg_match('/\A(?<speaker>[^:\n]{1,128}): (?<body>.+)\z/s', (string) ($row['data'] ?? ''), $match) !== 1) {
        return null;
    }
    $speaker = trim($match['speaker']);
    $text = trim($match['body']);
    $listener = '';
    if ($type === 'chat' && preg_match('/\A(?<text>.*) \(talking to (?<listener>[^()\n]{1,128})\)\z/s', $text, $target) === 1) {
        $text = trim($target['text']);
        $listener = trim($target['listener']);
    }
    if ($type !== 'chat' && $speaker !== $playerName) {
        return null;
    }
    if ($text === '') {
        return null;
    }
    return [
        'event_id' => (int) $row['rowid'],
        'gamets' => (float) $row['gamets'],
        'type' => $type,
        'speaker' => $speaker,
        'listener' => $listener,
        'people' => parsePeople($row['people'] ?? null),
        'text' => $text,
        'localts' => (int) ($row['localts'] ?? 0),
    ];
}
```

- [ ] **Step 4: Run it to verify it passes**

Expected: `rows checks passed`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
git -c safe.directory='*' add server/rows.php tests/rows_test.php
git -c safe.directory='*' commit -m "Parse eventlog rows into speech lines"
```

---

### Task 4: Scan window and settled batches

**Files:**
- Create: `server/batches.php`
- Test: `tests/batches_test.php`

- [ ] **Step 1: Write the failing test `tests/batches_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/batches.php';

use function ChimSwornAndScorned\{perspectivesOf, scanWindow, settledBatches};

function bline(int $id, string $speaker, string $listener, array $people, int $localts): array
{
    return ['event_id' => $id, 'gamets' => (float) $id, 'type' => 'chat', 'speaker' => $speaker,
        'listener' => $listener, 'people' => $people, 'text' => "line {$id}", 'localts' => $localts];
}

$cast = ['Lidia Sobieska' => 5124, 'Aela the Huntress' => 2892];
$lines = [
    bline(41, 'Farkas', 'Lidia Sobieska', ['Farkas', 'Lidia Sobieska', 'Hawke'], 1000),
    ['type' => 'inputtext', 'listener' => ''] + bline(42, 'Hawke', '', ['Lidia Sobieska', 'Hawke'], 1005),
    bline(43, 'Lidia Sobieska', 'Hawke', ['Lidia Sobieska', 'Hawke'], 1006),
    bline(44, 'Aela the Huntress', 'Farkas', ['Aela the Huntress', 'Farkas', 'Lidia Sobieska'], 1100),
];

check(perspectivesOf($lines[0], $cast) === [['Lidia Sobieska', 'Farkas']], 'addressed cast listener views the speaker');
check(perspectivesOf($lines[1], $cast) === [['Lidia Sobieska', 'Hawke']], 'unaddressed line: cast witnesses view the speaker');
check(perspectivesOf($lines[2], $cast) === [['Lidia Sobieska', 'Hawke']], 'cast speaker views the listener');
check(perspectivesOf($lines[3], $cast) === [['Aela the Huntress', 'Farkas']], 'third-party witnesses get no perspective');
check(perspectivesOf(bline(45, 'Lidia Sobieska', 'explicit_disable_rechat', ['Lidia Sobieska'], 1), $cast) === [], 'solo reflection is not an exchange');

$batch = settledBatches($lines, $cast, [], 1030, 44);
check($batch['jobs'] === [
    ['owner_npc_id' => 5124, 'owner_name' => 'Lidia Sobieska', 'counterpart_name' => 'Farkas', 'event_ids' => [41]],
    ['owner_npc_id' => 5124, 'owner_name' => 'Lidia Sobieska', 'counterpart_name' => 'Hawke', 'event_ids' => [42, 43]],
], 'quiet groups settle, the active one waits');
check($batch['cursor'] === 43, 'cursor stops before the first unsettled line');

$marked = settledBatches($lines, $cast, ['5124|Farkas' => 41], 1030, 44);
check(array_column($marked['jobs'], 'counterpart_name') === ['Hawke'], 'marks prevent re-emitting lines');

$busy = [];
for ($n = 1; $n <= 6; $n++) {
    $busy[] = bline(50 + $n, 'Farkas', 'Lidia Sobieska', ['Farkas', 'Lidia Sobieska'], 2000);
}
check(count(settledBatches($busy, $cast, [], 2000, 56)['jobs']) === 1, 'six lines settle immediately');

$rows = [
    ['rowid' => '60', 'type' => 'chat', 'delivery_state' => 'spoken', 'localts' => '1000'],
    ['rowid' => '61', 'type' => 'chat', 'delivery_state' => 'emitted', 'localts' => '1990'],
    ['rowid' => '62', 'type' => 'chat', 'delivery_state' => 'spoken', 'localts' => '1995'],
];
$window = scanWindow($rows, 2000);
check(array_column($window['rows'], 'rowid') === ['60'] && $window['read_up_to'] === 60, 'young unacknowledged line holds the window');
check(scanWindow($rows, 2200)['read_up_to'] === 62, 'old unacknowledged lines are skipped');
check(scanWindow([], 2000) === ['rows' => [], 'read_up_to' => 0], 'empty window');

echo "batches checks passed\n";
```

- [ ] **Step 2: Run it to verify it fails**

Expected: fatal `Failed opening required '.../server/batches.php'`, `exit=255`.

- [ ] **Step 3: Create `server/batches.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/witness.php';

const SETTLE_SECONDS = 20;
const SETTLE_LINES = 6;
const EMITTED_GRACE_SECONDS = 120;

/**
 * Cut the scan before the first young unacknowledged NPC line, so it is not skipped
 * before its ACK turns it into 'spoken'.
 *
 * @param list<array> $rows raw eventlog rows ordered by rowid
 * @return array{rows: list<array>, read_up_to: int}
 */
function scanWindow(array $rows, int $now): array
{
    foreach ($rows as $index => $row) {
        if (($row['type'] ?? '') === 'chat'
            && ($row['delivery_state'] ?? null) === 'emitted'
            && $now - (int) ($row['localts'] ?? 0) < EMITTED_GRACE_SECONDS) {
            return ['rows' => array_slice($rows, 0, $index), 'read_up_to' => (int) $row['rowid'] - 1];
        }
    }
    return ['rows' => $rows, 'read_up_to' => $rows === [] ? 0 : (int) $rows[array_key_last($rows)]['rowid']];
}

/**
 * Perspectives (owner in cast, counterpart) a line contributes to.
 * @param array<string, int> $castIds cast NPC name => npc id
 * @return list<array{0: string, 1: string}>
 */
function perspectivesOf(array $line, array $castIds): array
{
    $speaker = $line['speaker'];
    $listener = $line['listener'];
    if ($listener === SENTINEL_LISTENER) {
        return [];
    }
    $perspectives = [];
    if ($listener !== '') {
        if (isset($castIds[$speaker]) && $listener !== $speaker) {
            $perspectives[] = [$speaker, $listener];
        }
        if (isset($castIds[$listener]) && $listener !== $speaker && in_array($listener, $line['people'], true)) {
            $perspectives[] = [$listener, $speaker];
        }
        return $perspectives;
    }
    foreach ($line['people'] as $person) {
        if ($person !== $speaker && isset($castIds[$person])) {
            $perspectives[] = [$person, $speaker];
        }
    }
    return $perspectives;
}

/**
 * Group lines per perspective and emit the groups that settled (quiet for SETTLE_SECONDS,
 * or SETTLE_LINES long). The cursor stops before the first unsettled line.
 *
 * @param array<string, int> $marks "ownerId|counterpartName" => last emitted event id
 * @return array{jobs: list<array>, cursor: int}
 */
function settledBatches(array $lines, array $castIds, array $marks, int $now, int $readUpTo): array
{
    $groups = [];
    foreach ($lines as $line) {
        foreach (perspectivesOf($line, $castIds) as [$owner, $counterpart]) {
            $key = $castIds[$owner] . '|' . $counterpart;
            if ($line['event_id'] <= ($marks[$key] ?? 0)) {
                continue;
            }
            $groups[$key] ??= ['owner_npc_id' => $castIds[$owner], 'owner_name' => $owner, 'counterpart_name' => $counterpart, 'lines' => []];
            $groups[$key]['lines'][] = $line;
        }
    }
    $jobs = [];
    $cursor = $readUpTo;
    foreach ($groups as $group) {
        $last = $group['lines'][array_key_last($group['lines'])];
        if (count($group['lines']) >= SETTLE_LINES || $now - $last['localts'] >= SETTLE_SECONDS) {
            $jobs[] = [
                'owner_npc_id' => $group['owner_npc_id'],
                'owner_name' => $group['owner_name'],
                'counterpart_name' => $group['counterpart_name'],
                'event_ids' => array_column($group['lines'], 'event_id'),
            ];
        } else {
            $cursor = min($cursor, $group['lines'][0]['event_id'] - 1);
        }
    }
    return ['jobs' => $jobs, 'cursor' => $cursor];
}
```

- [ ] **Step 4: Run it to verify it passes**

Expected: `batches checks passed`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
git -c safe.directory='*' add server/batches.php tests/batches_test.php
git -c safe.directory='*' commit -m "Group spoken lines into settled per-perspective batches"
```

---

### Task 5: OpenRouter Decisions backend

**Files:**
- Create: `server/decisions_backend.php`
- Test: `tests/decisions_backend_test.php`

- [ ] **Step 1: Write the failing test `tests/decisions_backend_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/decisions_backend.php';
require __DIR__ . '/../server/questions.php';

use ChimSwornAndScorned\{DecisionBackendError, OpenRouterDecisionBackend};
use function ChimSwornAndScorned\{choice, noul};

function failsWith(callable $call, string $reason, bool $retryable): bool
{
    try {
        $call();
    } catch (DecisionBackendError $error) {
        return $error->reasonCode === $reason && $error->retryable === $retryable;
    }
    return false;
}

$questions = [noul('commitment', 'Commit?'), noul('wronged', 'Wronged?'), choice('commitment.who', 'Who?', ['Lydia', 'Farkas'])];
$captured = [];
$reply = ['status' => 200, 'body' => json_encode(['answers' => [
    'commitment' => ['type' => 'noul', 'noul' => 0.96],
    'wronged' => ['type' => 'noul', 'noul' => 0.1],
    'commitment.who' => ['type' => 'choice', 'choice' => 'Farkas', 'confidence' => 0.7, 'probabilities' => ['Lydia' => 0.12, 'Farkas' => 0.88]],
]])];
$transport = function (string $url, array $headers, string $body) use (&$captured, &$reply): array {
    $captured[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
    return $reply;
};
$backend = new OpenRouterDecisionBackend('test-key', 'typesafe/jev-1.13', $transport);

check($backend->ask(['owner' => 'Lydia'], $questions) === [
    'commitment' => ['value' => true, 'p' => 0.96],
    'wronged' => ['value' => false, 'p' => 0.9],
    'commitment.who' => ['value' => 'Farkas', 'p' => 0.88],
], 'noul probability becomes value plus confidence; choice uses its probability');

$sent = json_decode($captured[0]['body'], true);
check($captured[0]['url'] === 'https://openrouter.ai/api/alpha/decisions', 'decisions endpoint');
check(in_array('Authorization: Bearer test-key', $captured[0]['headers'], true), 'bearer header');
check($sent['model'] === 'typesafe/jev-1.13' && $sent['state'] === ['owner' => 'Lydia'], 'model and state');
check($sent['questions']['commitment'] === ['type' => 'noul', 'instructions' => 'Commit?'], 'noul question shape');
check($sent['questions']['commitment.who'] === ['type' => 'choice', 'instructions' => 'Who?', 'criteria' => ['Lydia' => null, 'Farkas' => null]], 'choice question shape');
check($backend->name() === 'decisions:typesafe/jev-1.13', 'backend name records the model');

$reply = ['status' => 429, 'body' => '{}'];
check(failsWith(fn () => $backend->ask([], $questions), 'http_429', true), '429 is retryable');
$reply = ['status' => 503, 'body' => '{}'];
check(failsWith(fn () => $backend->ask([], $questions), 'http_503', true), '5xx is retryable');
$reply = ['status' => 401, 'body' => '{}'];
check(failsWith(fn () => $backend->ask([], $questions), 'http_401', false), '401 is not retryable');
$reply = ['status' => 200, 'body' => 'not json'];
check(failsWith(fn () => $backend->ask([], $questions), 'response_invalid_json', true), 'invalid JSON is retryable');
$reply = ['status' => 200, 'body' => '{"answers": "nope"}'];
check($backend->ask([], $questions) === [], 'missing answers map gives no answers');

echo "decisions backend checks passed\n";
```

- [ ] **Step 2: Run it to verify it fails**

Expected: fatal `Failed opening required '.../server/decisions_backend.php'`, `exit=255`.

- [ ] **Step 3: Create `server/decisions_backend.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/backend.php';
require_once __DIR__ . '/backend_error.php';

const OPENROUTER_DECISIONS_URL = 'https://openrouter.ai/api/alpha/decisions';

final class OpenRouterDecisionBackend implements DecisionBackend
{
    /**
     * @param callable(string $url, list<string> $headers, string $body): array{status: int, body: string} $transport
     */
    public function __construct(private string $apiKey, private string $model, private $transport)
    {
    }

    public function name(): string
    {
        return 'decisions:' . $this->model;
    }

    public function ask(array $state, array $questions): mixed
    {
        $body = json_encode(
            ['model' => $this->model, 'state' => $state, 'questions' => decisionQuestions($questions)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $response = ($this->transport)(
            OPENROUTER_DECISIONS_URL,
            ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json'],
            $body
        );
        $status = (int) $response['status'];
        if ($status === 429 || $status >= 500) {
            throw new DecisionBackendError("http_{$status}", true);
        }
        if ($status !== 200) {
            throw new DecisionBackendError("http_{$status}", false);
        }
        try {
            $decoded = json_decode((string) $response['body'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new DecisionBackendError('response_invalid_json', true, $error);
        }
        return answersFromDecisions($questions, is_array($decoded) ? ($decoded['answers'] ?? null) : null);
    }
}

/** Our questions -> the Decisions API `questions` map (id -> {type, instructions, criteria}). */
function decisionQuestions(array $questions): array
{
    $mapped = [];
    foreach ($questions as $question) {
        $mapped[$question['id']] = $question['type'] === 'noul'
            ? ['type' => 'noul', 'instructions' => $question['text']]
            : ['type' => 'choice', 'instructions' => $question['text'], 'criteria' => array_fill_keys($question['options'], null)];
    }
    return $mapped;
}

/**
 * Decisions answers -> ['id' => ['value' => bool|string, 'p' => float]].
 * A noul gives P(true): value is P >= 0.5 and confidence is the larger side.
 */
function answersFromDecisions(array $questions, mixed $answers): array
{
    if (!is_array($answers)) {
        return [];
    }
    $mapped = [];
    foreach ($questions as $question) {
        $answer = $answers[$question['id']] ?? null;
        if (!is_array($answer)) {
            continue;
        }
        if ($question['type'] === 'noul') {
            $p = $answer['noul'] ?? null;
            if (!is_int($p) && !is_float($p)) {
                continue;
            }
            $mapped[$question['id']] = ['value' => $p >= 0.5, 'p' => max((float) $p, 1.0 - (float) $p)];
            continue;
        }
        $choice = $answer['choice'] ?? null;
        $p = is_string($choice) && is_array($answer['probabilities'] ?? null) ? ($answer['probabilities'][$choice] ?? null) : null;
        if (!is_string($choice) || (!is_int($p) && !is_float($p))) {
            continue;
        }
        $mapped[$question['id']] = ['value' => $choice, 'p' => (float) $p];
    }
    return $mapped;
}

/** Production HTTP transport. */
function curlTransport(int $timeoutSeconds = 15): callable
{
    return static function (string $url, array $headers, string $body) use ($timeoutSeconds): array {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        curl_close($handle);
        if ($response === false || $errno !== 0) {
            throw new DecisionBackendError('transport_failed', true);
        }
        return ['status' => $status, 'body' => (string) $response];
    };
}
```

- [ ] **Step 4: Run it to verify it passes**

Expected: `decisions backend checks passed`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
git -c safe.directory='*' add server/decisions_backend.php tests/decisions_backend_test.php
git -c safe.directory='*' commit -m "Add OpenRouter Decisions backend"
```

---

### Task 6: LLM fallback backend and CHIM connector call

**Files:**
- Create: `server/llm_backend.php`, `server/llm_connector.php`, `tests/support/llm_stubs.php`
- Test: `tests/llm_backend_test.php`, `tests/llm_connector_test.php`

- [ ] **Step 1: Write the failing test `tests/llm_backend_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/llm_backend.php';
require __DIR__ . '/../server/questions.php';

use ChimSwornAndScorned\{DecisionBackendError, LlmFallbackBackend};
use function ChimSwornAndScorned\{choice, noul};

$questions = [noul('commitment', 'Commit?'), noul('wronged', 'Wronged?'), choice('commitment.who', 'Who?', ['Lydia', 'Farkas'])];
$seen = [];
$response = "Sure!\n{\"answers\":{\"commitment\":{\"value\":true,\"confidence\":\"high\"},\"commitment.who\":{\"value\":\"Farkas\",\"confidence\":\"medium\"},\"wronged\":{\"value\":false,\"confidence\":\"banana\"}}}";
$backend = new LlmFallbackBackend(function (array $messages) use (&$seen, &$response): string {
    $seen[] = $messages;
    return $response;
});

check($backend->ask(['owner' => 'Lydia'], $questions) === [
    'commitment' => ['value' => true, 'p' => 0.95],
    'commitment.who' => ['value' => 'Farkas', 'p' => 0.6],
], 'confidence words map to numbers; unknown words are dropped');
check($seen[0][0]['role'] === 'system' && str_contains($seen[0][0]['content'], '"confidence":"high|medium|low"'), 'system prompt asks for confidence');
$user = json_decode($seen[0][1]['content'], true);
check($user['state'] === ['owner' => 'Lydia'], 'state forwarded');
check($user['questions'][2] === ['id' => 'commitment.who', 'question' => 'Who?', 'answer_type' => 'one_of', 'options' => ['Lydia', 'Farkas']], 'question spec');
check($backend->name() === 'llm-fallback', 'name');

$response = 'I cannot answer that.';
check($backend->ask([], $questions) === [], 'non-JSON response gives no answers');
$response = '{"answers":{"commitment":{"value":true,"confidence":["high"]}}}';
check($backend->ask([], $questions) === [], 'non-string confidence ignored');

$broken = new LlmFallbackBackend(static function (array $messages): string {
    throw new RuntimeException('connector down');
});
try {
    $broken->ask([], $questions);
    check(false, 'connector failure must throw');
} catch (DecisionBackendError $error) {
    check($error->reasonCode === 'llm_request_failed' && $error->retryable, 'connector failure is retryable');
}

echo "llm backend checks passed\n";
```

- [ ] **Step 2: Run it to verify it fails**

Expected: fatal `Failed opening required '.../server/llm_backend.php'`, `exit=255`.

- [ ] **Step 3: Create `server/llm_backend.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/backend.php';
require_once __DIR__ . '/backend_error.php';

// Self-reported confidence is not calibrated; POLICY_LLM_FALLBACK lets only "high" pass.
const LLM_CONFIDENCE = ['high' => 0.95, 'medium' => 0.6, 'low' => 0.3];

final class LlmFallbackBackend implements DecisionBackend
{
    /** @param callable(array $messages): string $complete */
    public function __construct(private $complete)
    {
    }

    public function name(): string
    {
        return 'llm-fallback';
    }

    public function ask(array $state, array $questions): mixed
    {
        try {
            $raw = ($this->complete)(llmMessages($state, $questions));
        } catch (\Throwable $error) {
            throw new DecisionBackendError('llm_request_failed', true, $error);
        }
        return answersFromLlm($questions, $raw);
    }
}

function llmMessages(array $state, array $questions): array
{
    $spec = [];
    foreach ($questions as $question) {
        $spec[] = [
            'id' => $question['id'],
            'question' => $question['text'],
            'answer_type' => $question['type'] === 'noul' ? 'true_or_false' : 'one_of',
            'options' => $question['options'],
        ];
    }
    $system = 'You classify a short Skyrim conversation. Answer every question using only the given lines; '
        . 'do not assume anything that is not said. Reply with JSON only: '
        . '{"answers":{"<id>":{"value":<true|false or one option exactly>,"confidence":"high|medium|low"}}}. '
        . 'Use "low" when the lines do not clearly say.';
    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => json_encode(['state' => $state, 'questions' => $spec], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
    ];
}

function answersFromLlm(array $questions, string $raw): array
{
    if (preg_match('/\{.*\}/s', $raw, $match) !== 1) {
        return [];
    }
    $decoded = json_decode($match[0], true, 64);
    $answers = is_array($decoded) ? ($decoded['answers'] ?? null) : null;
    if (!is_array($answers)) {
        return [];
    }
    $mapped = [];
    foreach ($questions as $question) {
        $answer = $answers[$question['id']] ?? null;
        if (!is_array($answer) || !array_key_exists('value', $answer)) {
            continue;
        }
        $word = is_string($answer['confidence'] ?? null) ? $answer['confidence'] : '';
        $p = LLM_CONFIDENCE[$word] ?? null;
        if ($p === null) {
            continue;
        }
        $mapped[$question['id']] = ['value' => $answer['value'], 'p' => $p];
    }
    return $mapped;
}
```

- [ ] **Step 4: Run it to verify it passes**

Expected: `llm backend checks passed`, `exit=0`.

- [ ] **Step 5: Create `tests/support/llm_stubs.php`**

```php
<?php
declare(strict_types=1);

final class LLMConnector
{
    public static ?array $row = null;

    public function readOne(int $id): ?array
    {
        return self::$row;
    }

    public function setOldGlobals(array $row): void
    {
        $GLOBALS['CONNECTOR'][$row['driver']] = ['API_KEY' => $row['test_api_key'] ?? ''];
    }

    public function getConnector(array $row): object
    {
        return new StubDriver();
    }
}

final class StubDriver
{
    public static array $calls = [];
    public static string $reply = '{"answers":{}}';

    public function fast_request(array $messages, array $params, string $name): string
    {
        self::$calls[] = ['params' => $params, 'name' => $name, 'timeout' => $GLOBALS['HTTP_TIMEOUT'] ?? null];
        return self::$reply;
    }
}
```

- [ ] **Step 6: Write the failing test `tests/llm_connector_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/llm_stubs.php';
require __DIR__ . '/../server/llm_connector.php';

use function ChimSwornAndScorned\{relationshipConnectorAvailable, relationshipConnectorCompletion};

$GLOBALS['HTTP_TIMEOUT'] = 5;
$GLOBALS['RELLLM_CONNECTOR'] = '3';
LLMConnector::$row = ['id' => 3, 'driver' => 'openrouterjson', 'model' => 'm', 'url' => 'u', 'test_api_key' => 'k'];

$complete = relationshipConnectorCompletion();
check($complete([['role' => 'user', 'content' => 'x']]) === '{"answers":{}}', 'completion returns the driver text');
check(StubDriver::$calls[0]['params'] === ['MAX_TOKENS' => 1024] && StubDriver::$calls[0]['name'] === 'sworn_and_scorned', 'token budget and caller name');
check(StubDriver::$calls[0]['timeout'] === 20, 'timeout set during the call');
check($GLOBALS['HTTP_TIMEOUT'] === 5, 'globals restored afterwards');
check(relationshipConnectorAvailable(), 'available with a valid connector id');

LLMConnector::$row = ['id' => 3, 'driver' => 'openai', 'model' => 'm', 'url' => 'u', 'test_api_key' => 'k'];
try {
    $complete([]);
    check(false, 'unsupported driver must throw');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'connector_driver_unsupported', 'unsupported driver rejected');
}

LLMConnector::$row = ['id' => 3, 'driver' => 'openrouterjson', 'model' => 'm', 'url' => 'u', 'test_api_key' => ''];
try {
    $complete([]);
    check(false, 'missing key must throw');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'connector_api_key_missing', 'missing key rejected');
}

StubDriver::$reply = '   ';
LLMConnector::$row = ['id' => 3, 'driver' => 'openrouterjson', 'model' => 'm', 'url' => 'u', 'test_api_key' => 'k'];
try {
    $complete([]);
    check(false, 'empty reply must throw');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'model_response_empty', 'empty reply rejected');
}

$GLOBALS['RELLLM_CONNECTOR'] = '0';
check(!relationshipConnectorAvailable(), 'unavailable without a connector id');

echo "llm connector checks passed\n";
```

- [ ] **Step 7: Run it to verify it fails**

Expected: fatal `Failed opening required '.../server/llm_connector.php'`, `exit=255`.

- [ ] **Step 8: Create `server/llm_connector.php`** (adapted from Mind Poisoning's `requestJudgments`)

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

const SAVED_CONNECTOR_GLOBALS = [
    'CONNECTOR', 'HTTP_TIMEOUT', 'FORCE_MAX_TOKENS', 'DEBUG_DATA',
    'PATCH_PROMPT_ENFORCE_ACTIONS', 'COMMAND_PROMPT_ENFORCE_ACTIONS',
    'FUNC_LIST', 'responseTemplate', 'structuredOutputTemplate', 'grammar',
    'PROMPT_ACTIONS_LIST', 'CHIM_JSON_RESPONSE_EXT_LOADED',
    'FUNCTIONS_ARE_ENABLED', 'LLM_LANG',
];

function relationshipConnectorId(): ?int
{
    $id = filter_var($GLOBALS['RELLLM_CONNECTOR'] ?? null, FILTER_VALIDATE_INT);
    return is_int($id) && $id > 0 ? $id : null;
}

function relationshipConnectorAvailable(): bool
{
    if (relationshipConnectorId() === null) {
        return false;
    }
    if (function_exists('chimIsGlobalLlmConnectorEnabled')) {
        return \chimIsGlobalLlmConnectorEnabled('RELLLM_CONNECTOR') === true;
    }
    return true;
}

/**
 * Completion through CHIM's relationship LLM connector (openrouterjson drivers only).
 * Restores every connector global it touches.
 */
function relationshipConnectorCompletion(int $maxTokens = 1024): callable
{
    return static function (array $messages) use ($maxTokens): string {
        $saved = [];
        foreach (SAVED_CONNECTOR_GLOBALS as $name) {
            $saved[$name] = [array_key_exists($name, $GLOBALS), $GLOBALS[$name] ?? null];
        }
        try {
            $connectorId = relationshipConnectorId();
            if ($connectorId === null) {
                throw new \RuntimeException('connector_id_invalid');
            }
            if (!class_exists('\\LLMConnector', false)) {
                $file = rtrim((string) ($GLOBALS['ENGINE_PATH'] ?? ''), '/\\') . '/lib/core/llm_connector.class.php';
                if (!is_file($file)) {
                    throw new \RuntimeException('connector_unavailable');
                }
                require_once $file;
            }
            $llm = new \LLMConnector();
            $row = $llm->readOne($connectorId);
            if (!is_array($row) || (int) ($row['id'] ?? 0) !== $connectorId) {
                throw new \RuntimeException('connector_not_found');
            }
            $driver = $row['driver'] ?? null;
            if (!in_array($driver, ['openrouterjson', 'openrouterjsoncached'], true)) {
                throw new \RuntimeException('connector_driver_unsupported');
            }
            $base = $row;
            $base['driver'] = 'openrouterjson';
            $llm->setOldGlobals($base);
            $config = $GLOBALS['CONNECTOR']['openrouterjson'] ?? null;
            if (!is_array($config) || trim((string) ($config['API_KEY'] ?? '')) === '') {
                throw new \RuntimeException('connector_api_key_missing');
            }
            if ($driver === 'openrouterjsoncached') {
                $GLOBALS['CONNECTOR']['openrouterjsoncached'] = $config;
            }
            $connector = $llm->getConnector($row);
            $GLOBALS['HTTP_TIMEOUT'] = 20;
            $GLOBALS['FORCE_MAX_TOKENS'] = $maxTokens;
            $response = $connector->fast_request($messages, ['MAX_TOKENS' => $maxTokens], 'sworn_and_scorned');
            if (!is_string($response) || trim($response) === '') {
                throw new \RuntimeException('model_response_empty');
            }
            return $response;
        } finally {
            foreach ($saved as $name => [$existed, $value]) {
                if ($existed) {
                    $GLOBALS[$name] = $value;
                } else {
                    unset($GLOBALS[$name]);
                }
            }
        }
    };
}
```

- [ ] **Step 9: Run both tests to verify they pass**

Run: `… run-php-tests.sh tests/llm_backend_test.php tests/llm_connector_test.php`
Expected: `llm backend checks passed`, `llm connector checks passed`, both `exit=0`.

- [ ] **Step 10: Commit**

```bash
git -c safe.directory='*' add server/llm_backend.php server/llm_connector.php tests/support/llm_stubs.php tests/llm_backend_test.php tests/llm_connector_test.php
git -c safe.directory='*' commit -m "Add CHIM LLM fallback backend"
```

---

### Task 7: Job orchestration and retry policy

**Files:**
- Create: `server/jobs.php`
- Test: `tests/jobs_test.php`

- [ ] **Step 1: Write the failing test `tests/jobs_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/jobs.php';
require __DIR__ . '/support/scripted_backend.php';

use ChimSwornAndScorned\{DecisionBackend, DecisionBackendError, JobDeps};
use function ChimSwornAndScorned\{emptyLedger, issueId, nextStep, processJob};
use const ChimSwornAndScorned\POLICY_DECISION_MODEL;

final class FakeDeps implements JobDeps
{
    public ?string $playthrough = 'pt-1';
    public array $lines = [];
    public array $ledger;
    public array $keys = ['Farkas' => 'npc:3', 'Hawke' => 'player'];
    public ?array $backendPair = null;
    public string $commitStatus = 'committed';
    public array $commits = [];

    public function __construct()
    {
        $this->ledger = emptyLedger('pt-1');
    }

    public function currentPlaythroughId(): ?string { return $this->playthrough; }

    public function linesFor(array $eventIds): array
    {
        return array_values(array_filter($this->lines, static fn (array $line): bool => in_array($line['event_id'], $eventIds, true)));
    }

    public function ledgerFor(int $ownerNpcId, string $playthroughId): array { return $this->ledger; }

    public function counterpartKey(string $counterpartName): ?string { return $this->keys[$counterpartName] ?? null; }

    public function backend(string $kind): ?array { return $this->backendPair; }

    public function commitLedger(array $request, callable $mutate): string
    {
        $result = $mutate($this->ledger);
        $this->commits[] = ['request' => $request, 'result' => $result];
        if ($result['status'] !== 'applied') {
            return $result['status'];
        }
        if ($this->commitStatus === 'committed') {
            $this->ledger = $result['ledger'];
        }
        return $this->commitStatus;
    }
}

final class ThrowingBackend implements DecisionBackend
{
    public function __construct(private DecisionBackendError $error) {}
    public function name(): string { return 'throwing'; }
    public function ask(array $state, array $questions): mixed { throw $this->error; }
}

$promiseAnswers = [
    'commitment' => ['value' => true, 'p' => 0.95],
    'wronged' => ['value' => false, 'p' => 0.9],
    'commitment.who' => ['value' => 'Farkas', 'p' => 0.9],
    'commitment.strength' => ['value' => 'will', 'p' => 0.88],
    'commitment.acceptance' => ['value' => 'accepted', 'p' => 0.85],
    'commitment.line' => ['value' => 'L2', 'p' => 0.97],
];
$job = ['id' => 7, 'playthrough_id' => 'pt-1', 'owner_npc_id' => 5124, 'owner_name' => 'Lydia',
    'counterpart_name' => 'Farkas', 'event_ids' => [4, 5], 'backend' => 'decisions', 'failures' => 0];
$freshDeps = static function () use ($promiseAnswers): FakeDeps {
    $deps = new FakeDeps();
    $deps->lines = [sampleLine(4, 'Lydia', 'Will you defend me before Aela?'), sampleLine(5, 'Farkas', 'I will stand with you before Aela.')];
    $deps->backendPair = [new ScriptedBackend($promiseAnswers), POLICY_DECISION_MODEL];
    return $deps;
};

$deps = $freshDeps();
check(processJob($job, $deps) === ['outcome' => 'done', 'reason' => 'committed'], 'job commits');
check(issueById($deps->ledger, issueId('e:5', 'npc:3', 'promise'))['direction'] === 'owed_to_me', 'promise stored for counterpart key');
check($deps->commits[0]['request']['owner_npc_id'] === 5124, 'commit request carries the owner');
check(processJob($job, $deps) === ['outcome' => 'done', 'reason' => 'replay'], 'second run is a replay');

$deps = $freshDeps();
$deps->playthrough = 'pt-2';
check(processJob($job, $deps)['reason'] === 'playthrough_changed', 'other playthrough is stale');

$deps = $freshDeps();
$deps->lines = [$deps->lines[0]];
check(processJob($job, $deps) === ['outcome' => 'stale', 'reason' => 'events_missing'], 'pruned lines are stale');

$deps = $freshDeps();
check(processJob(['counterpart_name' => 'Nobody'] + $job, $deps)['reason'] === 'counterpart_unresolved', 'unknown counterpart is stale');

$deps = $freshDeps();
$deps->backendPair = null;
check(processJob($job, $deps) === ['outcome' => 'failed', 'reason' => 'backend_unavailable'], 'no backend fails');

$deps = $freshDeps();
$deps->backendPair = [new ThrowingBackend(new DecisionBackendError('http_429', true)), POLICY_DECISION_MODEL];
check(processJob($job, $deps) === ['outcome' => 'retry', 'reason' => 'http_429'], 'retryable provider error');

$deps = $freshDeps();
$deps->backendPair = [new ThrowingBackend(new DecisionBackendError('http_401', false)), POLICY_DECISION_MODEL];
check(processJob($job, $deps) === ['outcome' => 'failed', 'reason' => 'http_401'], 'permanent provider error');

$deps = $freshDeps();
$deps->commitStatus = 'busy';
check(processJob($job, $deps) === ['outcome' => 'retry', 'reason' => 'owner_busy'], 'busy owner retries');

$deps = $freshDeps();
$deps->commitStatus = 'restore_policy';
check(processJob($job, $deps) === ['outcome' => 'done', 'reason' => 'restore_policy'], 'restore policy skips writes');

check(nextStep('retry', 1, 'decisions', true) === ['action' => 'retry', 'delay' => 30, 'backend' => 'decisions', 'failures' => 1], 'first retry after 30 s');
check(nextStep('retry', 3, 'decisions', true)['delay'] === 600, 'third retry after 10 min');
check(nextStep('retry', 4, 'decisions', true) === ['action' => 'retry', 'delay' => 0, 'backend' => 'llm', 'failures' => 0], 'then the fallback');
check(nextStep('retry', 4, 'decisions', false) === ['action' => 'fail'], 'no fallback: fail');
check(nextStep('retry', 4, 'llm', true) === ['action' => 'fail'], 'fallback exhausted: fail');
check(nextStep('failed', 1, 'decisions', true)['backend'] === 'llm', 'permanent decisions error switches to fallback');
check(nextStep('failed', 1, 'llm', true) === ['action' => 'fail'], 'permanent fallback error fails');

echo "jobs checks passed\n";
```

- [ ] **Step 2: Run it to verify it fails**

Expected: fatal `Failed opening required '.../server/jobs.php'`, `exit=255`.

- [ ] **Step 3: Create `server/jobs.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/apply.php';
require_once __DIR__ . '/evaluator.php';
require_once __DIR__ . '/witness.php';
require_once __DIR__ . '/backend_error.php';

const RETRY_DELAYS = [30, 120, 600];

interface JobDeps
{
    public function currentPlaythroughId(): ?string;

    /** @return list<array> parsed lines for those event ids that still exist */
    public function linesFor(array $eventIds): array;

    public function ledgerFor(int $ownerNpcId, string $playthroughId): array;

    public function counterpartKey(string $counterpartName): ?string;

    /** @return array{0: DecisionBackend, 1: array}|null backend and its policy */
    public function backend(string $kind): ?array;

    /**
     * Locked commit; $mutate receives the fresh ledger and returns an applyProposal()-shaped result.
     * @param array $request {owner_npc_id, owner_name, playthrough_id, event_ids, counterpart_name}
     * @return string committed | replay | unchanged | busy | restore_policy | owner_changed | events_missing | edge_invalid
     */
    public function commitLedger(array $request, callable $mutate): string;
}

/** @return array{outcome: string, reason: string} outcome: done | stale | retry | failed */
function processJob(array $job, JobDeps $deps): array
{
    $result = static fn (string $outcome, string $reason): array => ['outcome' => $outcome, 'reason' => $reason];

    $playthroughId = $deps->currentPlaythroughId();
    if ($playthroughId === null || $playthroughId !== $job['playthrough_id']) {
        return $result('stale', 'playthrough_changed');
    }
    $lines = $deps->linesFor($job['event_ids']);
    if (count($lines) !== count($job['event_ids'])) {
        return $result('stale', 'events_missing');
    }
    $counterpart = $deps->counterpartKey($job['counterpart_name']);
    if ($counterpart === null) {
        return $result('stale', 'counterpart_unresolved');
    }
    $ledger = $deps->ledgerFor($job['owner_npc_id'], $playthroughId);
    if (isReplay($ledger, $job['event_ids'])) {
        return $result('done', 'replay');
    }
    $chosen = $deps->backend($job['backend']);
    if ($chosen === null) {
        return $result('failed', 'backend_unavailable');
    }
    [$backend, $policy] = $chosen;
    $open = array_values(array_filter(
        $ledger['issues'],
        static fn (array $issue): bool => isOpen($issue) && $issue['counterpart'] === $counterpart
    ));
    try {
        $proposal = evaluatePerspective([
            'owner' => $job['owner_name'],
            'counterpart' => $job['counterpart_name'],
            'lines' => pairLinesWitnessedBy($lines, $job['owner_name'], $job['counterpart_name']),
            'open_issues' => $open,
        ], $backend, $policy);
    } catch (DecisionBackendError $error) {
        return $result($error->retryable ? 'retry' : 'failed', $error->reasonCode);
    }
    $meta = ['event_ids' => $job['event_ids'], 'counterpart' => $counterpart, 'backend' => $backend->name(), 'policy' => $policy];
    $status = $deps->commitLedger($job, static fn (array $fresh): array => applyProposal($fresh, $proposal, $meta));
    return match ($status) {
        'committed', 'replay', 'unchanged' => $result('done', $status),
        'restore_policy' => $result('done', 'restore_policy'),
        'busy' => $result('retry', 'owner_busy'),
        default => $result('stale', $status),
    };
}

/**
 * What to do after a 'retry' or 'failed' outcome. $failures counts this failure.
 * Spec §9: retries at 30 s, 2 min, 10 min; then the fallback backend; then failed.
 */
function nextStep(string $outcome, int $failures, string $backendKind, bool $fallbackAvailable): array
{
    if ($outcome === 'retry' && $failures <= count(RETRY_DELAYS)) {
        return ['action' => 'retry', 'delay' => RETRY_DELAYS[$failures - 1], 'backend' => $backendKind, 'failures' => $failures];
    }
    if ($backendKind === 'decisions' && $fallbackAvailable) {
        return ['action' => 'retry', 'delay' => 0, 'backend' => 'llm', 'failures' => 0];
    }
    return ['action' => 'fail'];
}
```

- [ ] **Step 4: Run it to verify it passes**

Expected: `jobs checks passed`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
git -c safe.directory='*' add server/jobs.php tests/jobs_test.php
git -c safe.directory='*' commit -m "Add job orchestration and retry policy"
```

---

### Task 8: Launcher (pure parts)

**Files:**
- Create: `server/launcher.php`
- Test: `tests/launcher_test.php`

- [ ] **Step 1: Write the failing test `tests/launcher_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/launcher.php';

use function ChimSwornAndScorned\{workerCommand, workerPidAlive};

check(
    workerCommand('/usr/bin/php', "/var/www/html/HerikaServer/ext/sworn_and_scorned/worker.php")
        === "'/usr/bin/php' '/var/www/html/HerikaServer/ext/sworn_and_scorned/worker.php' --daemon",
    'command is shell-escaped'
);

$root = sys_get_temp_dir() . '/sas-launcher-' . bin2hex(random_bytes(4));
mkdir("{$root}/proc/123", 0777, true);
file_put_contents("{$root}/proc/123/cmdline", "/usr/bin/php\0/x/ext/sworn_and_scorned/worker.php\0--daemon\0");
mkdir("{$root}/proc/124", 0777, true);
file_put_contents("{$root}/proc/124/cmdline", "/usr/bin/php\0/x/ext/relationship_system/worker.php\0");
file_put_contents("{$root}/pid", "123\n");
check(workerPidAlive("{$root}/pid", "{$root}/proc"), 'live worker detected');
file_put_contents("{$root}/pid", '124');
check(!workerPidAlive("{$root}/pid", "{$root}/proc"), 'a recycled pid of another program is not our worker');
file_put_contents("{$root}/pid", '999');
check(!workerPidAlive("{$root}/pid", "{$root}/proc"), 'dead pid');
check(!workerPidAlive("{$root}/missing", "{$root}/proc"), 'missing pid file');

echo "launcher checks passed\n";
```

- [ ] **Step 2: Run it to verify it fails**

Expected: fatal `Failed opening required '.../server/launcher.php'`, `exit=255`.

- [ ] **Step 3: Create `server/launcher.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

function workerCommand(string $phpBinary, string $workerPath): string
{
    return escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerPath) . ' --daemon';
}

function workerPidAlive(string $pidFile, string $procRoot = '/proc'): bool
{
    $pid = is_file($pidFile) ? (int) trim((string) @file_get_contents($pidFile)) : 0;
    if ($pid < 1 || !is_dir("{$procRoot}/{$pid}")) {
        return false;
    }
    $cmdline = (string) @file_get_contents("{$procRoot}/{$pid}/cmdline");
    return str_contains($cmdline, 'sworn_and_scorned') && str_contains($cmdline, 'worker.php');
}

/** Spawn the daemon (it forks and detaches itself). Pattern of CHIM's relationship worker. */
function ensureWorkerRunning(string $engineRoot, string $pluginDir, string $phpBinary = '/usr/bin/php'): string
{
    if (workerPidAlive($engineRoot . '/log/sworn_scorned_worker.pid')) {
        return 'running';
    }
    $log = $engineRoot . '/log/sworn_scorned_worker.log';
    $process = @proc_open(
        workerCommand($phpBinary, $pluginDir . '/worker.php'),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes
    );
    if (!is_resource($process)) {
        return 'spawn_failed';
    }
    proc_close($process);
    return 'spawned';
}
```

- [ ] **Step 4: Run it to verify it passes**

Expected: `launcher checks passed`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
git -c safe.directory='*' add server/launcher.php tests/launcher_test.php
git -c safe.directory='*' commit -m "Add worker launcher with pid identity check"
```

---

### Task 9: Database access, tables, settings and the ledger store

**Files:**
- Create: `server/pg.php`, `server/tables.php`, `server/store.php`
- Test: `tests/settings_test.php` (unit), `tests/integration/store_it.php` (clone database)

- [ ] **Step 1: Write the failing unit test `tests/settings_test.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/tables.php';

use function ChimSwornAndScorned\{normalizeSettings, parsePgIntArray, pgIntArray, sasTable};
use const ChimSwornAndScorned\DEFAULT_SETTINGS;

check(normalizeSettings(DEFAULT_SETTINGS) === ['enabled' => false, 'cast' => [], 'model' => 'typesafe/jev-1.13', 'api_key' => ''], 'defaults: disabled');
check(normalizeSettings(['enabled' => 'yes'] + DEFAULT_SETTINGS)['enabled'] === false, 'only true enables');
check(normalizeSettings(['cast' => ['5124', 5124, -1, 'x', 2892, 1, 2, 3, 4, 5, 6, 7]] + DEFAULT_SETTINGS)['cast'] === [5124, 2892, 1, 2, 3, 4, 5, 6], 'cast: positive unique ids, at most 8');
check(normalizeSettings(['model' => 'bad model; drop'] + DEFAULT_SETTINGS)['model'] === 'typesafe/jev-1.13', 'invalid model falls back');
check(normalizeSettings(['model' => '~typesafe/jev-latest'] + DEFAULT_SETTINGS)['model'] === '~typesafe/jev-latest', 'alias model accepted');
check(normalizeSettings(['api_key' => ' k '] + DEFAULT_SETTINGS)['api_key'] === 'k', 'key trimmed');

check(sasTable('jobs') === 'plugins.sworn_scorned_jobs', 'default schema');
putenv('SAS_TEST_SCHEMA=sas_it');
check(sasTable('jobs') === 'sas_it.sworn_scorned_jobs', 'test schema override');
putenv('SAS_TEST_SCHEMA=bad;name');
rejected(fn () => sasTable('jobs'), 'invalid schema rejected');
putenv('SAS_TEST_SCHEMA');

check(pgIntArray([3, '4', 5]) === '{3,4,5}' && parsePgIntArray('{3,4,5}') === [3, 4, 5] && parsePgIntArray('{}') === [], 'array helpers');

echo "settings checks passed\n";
```

- [ ] **Step 2: Run it to verify it fails**

Expected: fatal `Failed opening required '.../server/tables.php'`, `exit=255`.

- [ ] **Step 3: Create `server/pg.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

final class Pg
{
    public function __construct(public readonly \PgSql\Connection $connection)
    {
    }

    /** @return list<array<string, ?string>> */
    public function all(string $sql, array $params = []): array
    {
        $result = $params === []
            ? @pg_query($this->connection, $sql)
            : @pg_query_params($this->connection, $sql, $params);
        if ($result === false) {
            throw new \RuntimeException('sql_failed: ' . substr(pg_last_error($this->connection), 0, 180));
        }
        $rows = pg_fetch_all($result) ?: [];
        pg_free_result($result);
        return $rows;
    }

    public function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    public function exec(string $sql, array $params = []): void
    {
        $this->all($sql, $params);
    }
}

function pgIntArray(array $ids): string
{
    return '{' . implode(',', array_map('intval', $ids)) . '}';
}

function parsePgIntArray(string $text): array
{
    $inner = trim($text, '{}');
    return $inner === '' ? [] : array_map('intval', explode(',', $inner));
}

/** A private connection for the worker and the page, from CHIM's sql class default DSN. */
function openEngineConnection(string $engineRoot): Pg
{
    if (!class_exists('sql', false)) {
        require_once rtrim($engineRoot, '/') . '/lib/postgresql.class.php';
    }
    $dsn = (new \ReflectionClass('sql'))->getDefaultProperties()['connString'] ?? null;
    if (!is_string($dsn) || $dsn === '' || str_contains($dsn, "\0")) {
        throw new \RuntimeException('dsn_unavailable');
    }
    $connection = @pg_connect($dsn . ' connect_timeout=5', PGSQL_CONNECT_FORCE_NEW);
    if (!$connection instanceof \PgSql\Connection) {
        throw new \RuntimeException('connection_failed');
    }
    return new Pg($connection);
}

/** CHIM's own request connection, for autocommit reads and small writes in hooks. */
function sharedConnection(): Pg
{
    if (!class_exists(\sql::class, false)) {
        throw new \RuntimeException('sql_unavailable');
    }
    $property = new \ReflectionProperty(\sql::class, 'link');
    $property->setAccessible(true);
    $connection = $property->getValue();
    if (!$connection instanceof \PgSql\Connection
        || pg_connection_status($connection) !== PGSQL_CONNECTION_OK
        || pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
        throw new \RuntimeException('connection_unavailable');
    }
    return new Pg($connection);
}
```

- [ ] **Step 4: Create `server/tables.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

use UnexpectedValueException;

require_once __DIR__ . '/pg.php';

const DEFAULT_SETTINGS = ['enabled' => false, 'cast' => [], 'model' => 'typesafe/jev-1.13', 'api_key' => ''];
const MAX_CAST = 8;

function sasTable(string $name): string
{
    $schema = getenv('SAS_TEST_SCHEMA') ?: 'plugins';
    if (preg_match('/\A[a-z_][a-z0-9_]{0,40}\z/D', $schema) !== 1 || preg_match('/\A[a-z_]{1,32}\z/D', $name) !== 1) {
        throw new UnexpectedValueException('table_name_invalid');
    }
    return "{$schema}.sworn_scorned_{$name}";
}

function ensureSchema(Pg $pg): void
{
    $schema = explode('.', sasTable('jobs'))[0];
    $pg->exec("CREATE SCHEMA IF NOT EXISTS {$schema}");
    $pg->exec('CREATE TABLE IF NOT EXISTS ' . sasTable('jobs') . " (
        id bigserial PRIMARY KEY,
        playthrough_id text NOT NULL,
        owner_npc_id bigint NOT NULL,
        owner_name text NOT NULL,
        counterpart_name text NOT NULL,
        event_ids bigint[] NOT NULL,
        backend text NOT NULL DEFAULT 'decisions',
        status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'failed')),
        failures int NOT NULL DEFAULT 0,
        next_attempt_at timestamptz NOT NULL DEFAULT now(),
        lease_until timestamptz,
        last_error text,
        created_at timestamptz NOT NULL DEFAULT now(),
        UNIQUE (playthrough_id, owner_npc_id, counterpart_name, event_ids)
    )");
    $pg->exec('CREATE TABLE IF NOT EXISTS ' . sasTable('marks') . ' (
        playthrough_id text NOT NULL,
        owner_npc_id bigint NOT NULL,
        counterpart_name text NOT NULL,
        last_rowid bigint NOT NULL,
        PRIMARY KEY (playthrough_id, owner_npc_id, counterpart_name)
    )');
    $pg->exec('CREATE TABLE IF NOT EXISTS ' . sasTable('cursor') . ' (
        playthrough_id text PRIMARY KEY,
        last_rowid bigint NOT NULL
    )');
    $pg->exec('CREATE TABLE IF NOT EXISTS ' . sasTable('settings') . ' (
        key text PRIMARY KEY,
        value jsonb NOT NULL
    )');
    $pg->exec('CREATE TABLE IF NOT EXISTS ' . sasTable('pacing') . ' (
        npc_id bigint NOT NULL,
        issue_id text NOT NULL,
        surfaced_at bigint NOT NULL,
        evidence_count int NOT NULL,
        PRIMARY KEY (npc_id, issue_id)
    )');
}

function normalizeSettings(array $settings): array
{
    $cast = [];
    foreach (is_array($settings['cast'] ?? null) ? $settings['cast'] : [] as $id) {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if (is_int($id) && $id > 0 && !in_array($id, $cast, true)) {
            $cast[] = $id;
        }
    }
    $model = $settings['model'] ?? null;
    return [
        'enabled' => ($settings['enabled'] ?? false) === true,
        'cast' => array_slice($cast, 0, MAX_CAST),
        'model' => is_string($model) && preg_match('~\A[A-Za-z0-9~._/-]{3,100}\z~D', $model) === 1 ? $model : DEFAULT_SETTINGS['model'],
        'api_key' => is_string($settings['api_key'] ?? null) ? trim($settings['api_key']) : '',
    ];
}

function loadSettings(Pg $pg): array
{
    $settings = DEFAULT_SETTINGS;
    foreach ($pg->all('SELECT key, value::text AS value FROM ' . sasTable('settings')) as $row) {
        if (array_key_exists($row['key'], DEFAULT_SETTINGS)) {
            $settings[$row['key']] = json_decode((string) $row['value'], true);
        }
    }
    return normalizeSettings($settings);
}

function saveSetting(Pg $pg, string $key, mixed $value): void
{
    if (!array_key_exists($key, DEFAULT_SETTINGS)) {
        throw new UnexpectedValueException('setting_unknown');
    }
    $pg->exec(
        'INSERT INTO ' . sasTable('settings') . ' (key, value) VALUES ($1, $2::jsonb)
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value',
        [$key, json_encode($value, JSON_FLAGS)]
    );
}

/** Same rule as Mind Poisoning: unprofiled, or exactly one active profile. */
function currentPlaythrough(Pg $pg): ?array
{
    $player = $pg->one("SELECT value FROM core_player WHERE id = 'player_name' LIMIT 1");
    $playerName = trim((string) ($player['value'] ?? ''));
    if ($playerName === '') {
        return null;
    }
    $table = $pg->one("SELECT to_regclass('chim_meta.playthrough_profiles') AS t");
    if (($table['t'] ?? null) === null) {
        return ['id' => 'unprofiled', 'player_name' => $playerName];
    }
    $rows = $pg->all('SELECT id::text AS id FROM chim_meta.playthrough_profiles WHERE is_active IS TRUE ORDER BY id LIMIT 2');
    if ($rows === []) {
        return ['id' => 'unprofiled', 'player_name' => $playerName];
    }
    return count($rows) === 1 ? ['id' => $rows[0]['id'], 'player_name' => $playerName] : null;
}
```

- [ ] **Step 5: Run the unit test to verify it passes**

Expected: `settings checks passed`, `exit=0`.

- [ ] **Step 6: Write the failing integration test `tests/integration/store_it.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../support/check.php';
require __DIR__ . '/../support/fixtures.php';
require __DIR__ . '/../../server/store.php';
require __DIR__ . '/../../server/backend.php';

use ChimSwornAndScorned\{Pg, PerspectiveStore};
use function ChimSwornAndScorned\{applyProposal, issueId, openEngineConnection};
use const ChimSwornAndScorned\POLICY_DECISION_MODEL;

if (getenv('SAS_IT') !== '1') {
    echo "store integration skipped (set SAS_IT=1 inside the test clone)\n";
    exit(0);
}
check(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'integration tests run only in the test clone');

$engine = '/var/www/html/HerikaServer';
$pg = openEngineConnection($engine);
foreach ([
    'DROP SCHEMA IF EXISTS sas_it CASCADE',
    'CREATE SCHEMA sas_it',
    'CREATE TABLE sas_it.core_npc_master (LIKE public.core_npc_master INCLUDING ALL)',
    'CREATE TABLE sas_it.core_npc_master_history (LIKE public.core_npc_master_history INCLUDING ALL)',
    'CREATE TABLE sas_it.eventlog (LIKE public.eventlog INCLUDING ALL)',
    'SET search_path = sas_it, public',
] as $sql) {
    $pg->exec($sql);
}

try {
    $template = $pg->one("SELECT id FROM public.core_npc_master WHERE npc_name <> 'The Narrator' ORDER BY id LIMIT 1");
    check($template !== null, 'clone has an NPC row to copy');
    $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id = $1', [$template['id']]);
    $pg->exec("UPDATE sas_it.core_npc_master SET id = 900001, npc_name = 'Lydia IT', lock_profile = 0,
        extended_data = jsonb_build_object('relationships', jsonb_build_object('Farkas IT', jsonb_build_object('aff', 10, 'type', 'friend'))),
        plugin_extended_data = jsonb_build_object('mind_poisoning', jsonb_build_object('marker', 1)),
        gamets_last_updated = 0");

    $event = static function (string $data) use ($pg): int {
        $row = $pg->one("INSERT INTO sas_it.eventlog (type, data, gamets, localts, people, delivery_state)
            VALUES ('chat', $1, 292500100, extract(epoch from now())::bigint, '|Lydia IT|Farkas IT|', 'spoken') RETURNING rowid", [$data]);
        return (int) $row['rowid'];
    };
    $historyCount = static fn (): int => (int) $pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history WHERE npc_id = 900001')['n'];

    $store = new PerspectiveStore($pg);
    $e1 = $event('Farkas IT: I will stand with you before Aela. (talking to Lydia IT)');
    $request = ['owner_npc_id' => 900001, 'owner_name' => 'Lydia IT', 'playthrough_id' => 'pt-it', 'event_ids' => [$e1], 'counterpart_name' => 'Farkas IT'];
    $meta = ['event_ids' => [$e1], 'counterpart' => 'npc:900002', 'backend' => 'it', 'policy' => POLICY_DECISION_MODEL];
    $vow = sampleLine($e1, 'Farkas IT', 'I will stand with you before Aela.', ['Lydia IT', 'Farkas IT']);
    $promise = ['new_issues' => [['kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'wants' => 'keep_promise', 'line' => $vow, 'label' => 'commitment', 'p' => 0.95]], 'updates' => []];

    $before = $historyCount();
    check($store->commitLedger($request, fn (array $ledger) => applyProposal($ledger, $promise, $meta)) === 'committed', 'commit succeeds');
    $plugins = json_decode($pg->one('SELECT plugin_extended_data::text AS p FROM sas_it.core_npc_master WHERE id = 900001')['p'], true);
    check($plugins['mind_poisoning'] === ['marker' => 1], 'other plugin data untouched');
    check(count($plugins['sworn_and_scorned']['issues']) === 1 && $plugins['sworn_and_scorned']['processed'] === [$e1], 'ledger written');
    $snapshot = $pg->one("SELECT extended_data->>'_chim_history_source' AS source, plugin_extended_data->'sworn_and_scorned'->>'playthrough_id' AS pt
        FROM sas_it.core_npc_master_history WHERE npc_id = 900001 ORDER BY history_id DESC LIMIT 1");
    check($historyCount() === $before + 1 && $snapshot['source'] === 'relationship' && $snapshot['pt'] === 'pt-it', 'snapshot written');

    check($store->commitLedger($request, fn (array $ledger) => applyProposal($ledger, $promise, $meta)) === 'replay', 'replay is a no-op');
    check($historyCount() === $before + 1, 'replay adds no snapshot');

    $e2 = $event('Lydia IT: You stayed silent. (talking to Farkas IT)');
    $promiseId = issueId('e:' . $e1, 'npc:900002', 'promise');
    $break = ['new_issues' => [], 'updates' => [['issue_id' => $promiseId, 'status' => 'broken', 'status_p' => 0.95, 'trajectory' => 'worsening', 'wants' => null, 'line' => null]]];
    check($store->commitLedger(['event_ids' => [$e2]] + $request, fn (array $ledger) => applyProposal($ledger, $break, ['event_ids' => [$e2]] + $meta)) === 'committed', 'broken promise commits');
    $edge = json_decode($pg->one("SELECT (extended_data->'relationships'->'Farkas IT')::text AS e FROM sas_it.core_npc_master WHERE id = 900001")['e'], true);
    check($edge === ['aff' => 7, 'type' => 'friend'], 'affinity 10 - 3 = 7 with the type kept');

    $e3 = $event('Farkas IT: gone line (talking to Lydia IT)');
    $pg->exec('DELETE FROM sas_it.eventlog WHERE rowid = $1', [$e3]);
    $empty = ['new_issues' => [], 'updates' => []];
    check($store->commitLedger(['event_ids' => [$e3]] + $request, fn (array $ledger) => applyProposal($ledger, $empty, ['event_ids' => [$e3]] + $meta)) === 'events_missing', 'pruned events are rejected');

    $e4 = $event('Farkas IT: busy line (talking to Lydia IT)');
    $other = openEngineConnection($engine);
    $other->exec('SELECT pg_advisory_lock($1)', [1002000000 + 900001]);
    check($store->commitLedger(['event_ids' => [$e4]] + $request, fn (array $ledger) => applyProposal($ledger, $empty, ['event_ids' => [$e4]] + $meta)) === 'busy', 'busy when another worker holds the owner');
    $other->exec('SELECT pg_advisory_unlock($1)', [1002000000 + 900001]);

    $e5 = $event('Farkas IT: I will bring the mead. (talking to Lydia IT)');
    $mead = sampleLine($e5, 'Farkas IT', 'I will bring the mead.', ['Lydia IT', 'Farkas IT']);
    $store->commitLedger(['event_ids' => [$e5]] + $request, fn (array $ledger) => applyProposal($ledger, ['new_issues' => [['kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'wants' => 'keep_promise', 'line' => $mead, 'label' => 'commitment', 'p' => 0.95]], 'updates' => []], ['event_ids' => [$e5]] + $meta));
    $pg->exec('UPDATE sas_it.core_npc_master SET lock_profile = 1 WHERE id = 900001');
    $e6 = $event('Farkas IT: Here is the mead. (talking to Lydia IT)');
    $kept = ['new_issues' => [], 'updates' => [['issue_id' => issueId('e:' . $e5, 'npc:900002', 'promise'), 'status' => 'fulfilled', 'status_p' => 0.95, 'trajectory' => 'improving', 'wants' => null, 'line' => null]]];
    check($store->commitLedger(['event_ids' => [$e6]] + $request, fn (array $ledger) => applyProposal($ledger, $kept, ['event_ids' => [$e6]] + $meta)) === 'committed', 'locked profile still records the issue');
    $aff = (int) $pg->one("SELECT extended_data->'relationships'->'Farkas IT'->>'aff' AS a FROM sas_it.core_npc_master WHERE id = 900001")['a'];
    check($aff === 7, 'locked profile gets no affinity change');

    $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = true;
    check($store->commitLedger($request, fn (array $ledger) => applyProposal($ledger, $empty, $meta)) === 'restore_policy', 'restore policy blocks writes');
    unset($GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA']);
} finally {
    $pg->exec('DROP SCHEMA IF EXISTS sas_it CASCADE');
}

echo "store integration checks passed\n";
```

- [ ] **Step 7: Run it to verify it fails** (clone database up, see Ground rules)

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- env SAS_IT=1 bash scripts/run-php-tests.sh tests/integration/store_it.php`
Expected: fatal `Failed opening required '.../server/store.php'`, `exit=255`.

- [ ] **Step 8: Create `server/store.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/pg.php';
require_once __DIR__ . '/ledger.php';

// Our own advisory range: serialises our writers only. Cross-plugin safety is the row lock.
const SAS_ADVISORY_BASE = 1002000000;

final class PerspectiveStore
{
    public function __construct(private Pg $pg)
    {
    }

    /**
     * Lock the owner, revalidate the source events, apply $mutate to the fresh ledger,
     * write ledger + relative affinity, snapshot to history, commit (spec §5, §9).
     *
     * @param array $request {owner_npc_id, owner_name, playthrough_id, event_ids: list<int>, counterpart_name: ?string}
     * @param callable(array $ledger): array{status: string, ledger: array, affinity_delta: int} $mutate
     */
    public function commitLedger(array $request, callable $mutate): string
    {
        if (filter_var($GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return 'restore_policy';
        }
        $ownerId = (int) $request['owner_npc_id'];
        $lockKey = SAS_ADVISORY_BASE + $ownerId;
        $acquired = $this->pg->one('SELECT pg_try_advisory_lock($1) AS acquired', [$lockKey]);
        if (($acquired['acquired'] ?? null) !== 't') {
            return 'busy';
        }
        $committed = false;
        try {
            $this->pg->exec('BEGIN');
            $this->pg->exec("SET LOCAL lock_timeout = '1000ms'");
            $this->pg->exec("SET LOCAL statement_timeout = '3000ms'");
            $row = $this->pg->one(
                'SELECT npc_name, lock_profile, extended_data::text AS extended_data,
                        plugin_extended_data::text AS plugin_extended_data, gamets_last_updated
                 FROM core_npc_master WHERE id = $1 FOR UPDATE',
                [$ownerId]
            );
            if ($row === null || $row['npc_name'] !== $request['owner_name']) {
                return 'owner_changed';
            }
            $gamets = (float) ($row['gamets_last_updated'] ?? 0);
            $eventIds = $request['event_ids'];
            if ($eventIds !== []) {
                $events = $this->pg->one('SELECT count(*) AS n, max(gamets) AS g FROM eventlog WHERE rowid = ANY($1::bigint[])', [pgIntArray($eventIds)]);
                if ((int) $events['n'] !== count($eventIds)) {
                    return 'events_missing';
                }
                $gamets = max($gamets, (float) $events['g']);
            }
            $plugins = json_decode($row['plugin_extended_data'] ?? '{}', true);
            $ledger = normalizeLedger(is_array($plugins) ? ($plugins['sworn_and_scorned'] ?? null) : null, $request['playthrough_id']);
            $result = $mutate($ledger);
            if ($result['status'] !== 'applied') {
                return $result['status'];
            }

            $params = [$ownerId, json_encode($result['ledger'], JSON_FLAGS), $gamets];
            $extendedSql = 'extended_data';
            $counterpartName = $request['counterpart_name'] ?? null;
            if ((int) $result['affinity_delta'] !== 0 && is_string($counterpartName)) {
                $edge = $this->nextEdge($row, $counterpartName, (int) $result['affinity_delta']);
                if ($edge === false) {
                    return 'edge_invalid';
                }
                if ($edge !== null) {
                    $params[] = $counterpartName;
                    $params[] = json_encode($edge, JSON_FLAGS);
                    $extendedSql = "jsonb_set(jsonb_set(COALESCE(extended_data, '{}'::jsonb), '{relationships}',"
                        . " COALESCE(extended_data->'relationships', '{}'::jsonb), true), ARRAY['relationships', \$4::text], \$5::jsonb, true)";
                }
            }
            $updated = $this->pg->one(
                "UPDATE core_npc_master
                 SET extended_data = {$extendedSql},
                     plugin_extended_data = jsonb_set(COALESCE(plugin_extended_data, '{}'::jsonb), ARRAY['sworn_and_scorned']::text[], \$2::jsonb, true),
                     gamets_last_updated = \$3
                 WHERE id = \$1 RETURNING id",
                $params
            );
            if ($updated === null) {
                return 'owner_changed';
            }
            $this->snapshot($ownerId);
            $this->pg->exec('COMMIT');
            $committed = true;
            return 'committed';
        } finally {
            if (!$committed) {
                try {
                    $this->pg->exec('ROLLBACK');
                } catch (\Throwable) {
                }
            }
            try {
                $this->pg->exec('SELECT pg_advisory_unlock($1)', [$lockKey]);
            } catch (\Throwable) {
            }
        }
    }

    /** New edge object, null when the profile or relationships are locked, false when invalid. */
    private function nextEdge(array $row, string $name, int $delta): \stdClass|false|null
    {
        $extended = json_decode($row['extended_data'] ?? '{}');
        if (!$extended instanceof \stdClass) {
            $extended = new \stdClass();
        }
        if ((int) ($row['lock_profile'] ?? 0) !== 0 || !empty($extended->relationships_locked)) {
            return null;
        }
        $relationships = $extended->relationships ?? new \stdClass();
        $edge = $relationships instanceof \stdClass && isset($relationships->{$name})
            ? $relationships->{$name}
            : (object) ['aff' => 0, 'type' => 'neutral'];
        if (!$edge instanceof \stdClass) {
            return false;
        }
        $edge = clone $edge;
        $affinity = $edge->aff ?? 0;
        if (!is_int($affinity) && !is_float($affinity) && !(is_string($affinity) && is_numeric($affinity))) {
            return false;
        }
        $next = max(-100.0, min(100.0, (float) $affinity + $delta));
        $edge->aff = floor($next) === $next ? (int) $next : $next;
        return $edge;
    }

    /** Full-row history snapshot, as Mind Poisoning does, so CHIM restore rolls our ledger back. */
    private function snapshot(int $npcId): void
    {
        $row = $this->pg->one('SELECT * FROM core_npc_master WHERE id = $1', [$npcId]);
        if ($row === null) {
            throw new \RuntimeException('snapshot_source_missing');
        }
        $extended = json_decode($row['extended_data'] ?? '{}');
        if (!$extended instanceof \stdClass) {
            $extended = new \stdClass();
        }
        $extended->_chim_history_source = 'relationship';
        $row['extended_data'] = json_encode($extended, JSON_FLAGS);
        unset($row['id']);
        $row['npc_id'] = $npcId;
        $row['created'] = date('Y-m-d H:i:s');
        $columns = array_keys($row);
        foreach ($columns as $column) {
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', (string) $column) !== 1) {
                throw new \RuntimeException('snapshot_column_invalid');
            }
        }
        $slots = array_map(static fn (int $index): string => '$' . ($index + 1), array_keys($columns));
        $inserted = $this->pg->one(
            'INSERT INTO core_npc_master_history (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $slots) . ') RETURNING history_id',
            array_values($row)
        );
        if ((int) ($inserted['history_id'] ?? 0) < 1) {
            throw new \RuntimeException('snapshot_failed');
        }
    }
}
```

- [ ] **Step 9: Run the integration test to verify it passes**

Expected: `store integration checks passed`, `exit=0`. Afterwards, `psql … -c '\dn'` shows no `sas_it` schema.

- [ ] **Step 10: Commit**

```bash
git -c safe.directory='*' add server/pg.php server/tables.php server/store.php tests/settings_test.php tests/integration/store_it.php
git -c safe.directory='*' commit -m "Add database access, settings and the locked snapshotting ledger store"
```

---

### Task 10: Scanner, queue and worker tick

**Files:**
- Create: `server/queue.php`
- Test: `tests/integration/queue_it.php`

- [ ] **Step 1: Write the failing integration test `tests/integration/queue_it.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../support/check.php';
require __DIR__ . '/../../server/queue.php';

use function ChimSwornAndScorned\{claimJob, ensureSchema, finishJob, normalizeSettings, openEngineConnection, scanOnce};

if (getenv('SAS_IT') !== '1') {
    echo "queue integration skipped (set SAS_IT=1 inside the test clone)\n";
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
    $template = $pg->one("SELECT id FROM public.core_npc_master WHERE npc_name <> 'The Narrator' ORDER BY id LIMIT 1");
    $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id = $1', [$template['id']]);
    $pg->exec("UPDATE sas_it.core_npc_master SET id = 900001, npc_name = 'Lydia IT'");
    ensureSchema($pg);

    $event = static function (string $data, int $localts, string $state = 'spoken') use ($pg): int {
        $row = $pg->one("INSERT INTO sas_it.eventlog (type, data, gamets, localts, people, delivery_state)
            VALUES ('chat', $1, 292500100, $2, '|Lydia IT|Farkas IT|', $3) RETURNING rowid", [$data, $localts, $state]);
        return (int) $row['rowid'];
    };
    $pt = ['id' => 'pt-it', 'player_name' => 'Hawke IT'];
    $settings = normalizeSettings(['enabled' => true, 'cast' => [900001], 'model' => 'typesafe/jev-1.13', 'api_key' => '']);
    $now = time();

    check(scanOnce($pg, $pt, $settings, $now) === 0, 'first scan only initialises the cursor');
    $e1 = $event('Farkas IT: I will stand with you. (talking to Lydia IT)', $now - 60);
    $e2 = $event('Lydia IT: You had better. (talking to Farkas IT)', $now - 50);
    check(scanOnce($pg, $pt, $settings, $now) === 1, 'one settled perspective job');
    check(scanOnce($pg, $pt, $settings, $now) === 0, 'marks prevent re-emitting');

    $job = claimJob($pg, 'pt-it');
    check($job !== null && $job['event_ids'] === [$e1, $e2] && $job['owner_npc_id'] === 900001 && $job['counterpart_name'] === 'Farkas IT', 'claimed job');
    check(claimJob($pg, 'pt-it') === null, 'a running job is not claimed twice');

    finishJob($pg, $job, ['outcome' => 'retry', 'reason' => 'http_429'], true);
    $row = $pg->one('SELECT status, failures, backend, last_error FROM sas_it.sworn_scorned_jobs WHERE id = $1', [$job['id']]);
    check($row['status'] === 'pending' && $row['failures'] === '1' && $row['backend'] === 'decisions' && $row['last_error'] === 'http_429', 'retry scheduled');
    check(claimJob($pg, 'pt-it') === null, 'retry waits for its delay');

    $e3 = $event('Farkas IT: Fine. (talking to Lydia IT)', $now - 40);
    check(scanOnce($pg, $pt, $settings, $now) === 1, 'newer job queued');
    check(claimJob($pg, 'pt-it') === null, 'newer job waits behind the older one (chronology)');

    $pg->exec("UPDATE sas_it.sworn_scorned_jobs SET next_attempt_at = now() WHERE id = $1", [$job['id']]);
    $again = claimJob($pg, 'pt-it');
    check($again['id'] === $job['id'], 'older job runs first');
    finishJob($pg, $again, ['outcome' => 'done', 'reason' => 'committed'], true);
    check((int) $pg->one('SELECT count(*) AS n FROM sas_it.sworn_scorned_jobs WHERE id = $1', [$job['id']])['n'] === 0, 'done jobs are deleted');
    $newer = claimJob($pg, 'pt-it');
    check($newer['event_ids'] === [$e3], 'newer job now runs');
    finishJob($pg, $newer, ['outcome' => 'failed', 'reason' => 'http_401'], false);
    check($pg->one('SELECT status FROM sas_it.sworn_scorned_jobs WHERE id = $1', [$newer['id']])['status'] === 'failed', 'failed job stays visible');

    $cursorBefore = (int) $pg->one('SELECT last_rowid FROM sas_it.sworn_scorned_cursor WHERE playthrough_id = $1', ['pt-it'])['last_rowid'];
    $e4 = $event('Farkas IT: (still speaking) (talking to Lydia IT)', $now, 'emitted');
    $event('Farkas IT: later line (talking to Lydia IT)', $now - 30);
    check(scanOnce($pg, $pt, $settings, $now) === 0, 'young unacknowledged line holds the scan');
    $cursorAfter = (int) $pg->one('SELECT last_rowid FROM sas_it.sworn_scorned_cursor WHERE playthrough_id = $1', ['pt-it'])['last_rowid'];
    check($cursorAfter >= $cursorBefore && $cursorAfter < $e4, 'cursor does not pass the unacknowledged line');
} finally {
    $pg->exec('DROP SCHEMA IF EXISTS sas_it CASCADE');
    putenv('SAS_TEST_SCHEMA');
}

echo "queue integration checks passed\n";
```

- [ ] **Step 2: Run it to verify it fails**

Run: `… env SAS_IT=1 bash scripts/run-php-tests.sh tests/integration/queue_it.php`
Expected: fatal `Failed opening required '.../server/queue.php'`, `exit=255`.

- [ ] **Step 3: Create `server/queue.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/tables.php';
require_once __DIR__ . '/rows.php';
require_once __DIR__ . '/batches.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/decisions_backend.php';
require_once __DIR__ . '/llm_backend.php';
require_once __DIR__ . '/llm_connector.php';
require_once __DIR__ . '/logging.php';

const SCAN_LIMIT = 500;
const LEASE_SECONDS = 120;

/** Read new speech rows, emit settled per-perspective jobs, advance the cursor. Returns jobs created. */
function scanOnce(Pg $pg, array $playthrough, array $settings, int $now): int
{
    $pt = $playthrough['id'];
    $cursorTable = sasTable('cursor');
    $cursorRow = $pg->one("SELECT last_rowid FROM {$cursorTable} WHERE playthrough_id = $1", [$pt]);
    if ($cursorRow === null) {
        $max = $pg->one('SELECT COALESCE(max(rowid), 0) AS m FROM eventlog');
        $pg->exec("INSERT INTO {$cursorTable} (playthrough_id, last_rowid) VALUES ($1, $2) ON CONFLICT (playthrough_id) DO NOTHING", [$pt, (int) $max['m']]);
        return 0;
    }
    if ($settings['cast'] === []) {
        return 0;
    }
    $castIds = [];
    foreach ($pg->all('SELECT id, npc_name FROM core_npc_master WHERE id = ANY($1::bigint[])', [pgIntArray($settings['cast'])]) as $row) {
        $castIds[$row['npc_name']] = (int) $row['id'];
    }
    $rows = $pg->all(
        "SELECT rowid, type, data, people, gamets, localts, delivery_state FROM eventlog
         WHERE rowid > $1 AND type IN ('chat', 'inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s')
         ORDER BY rowid LIMIT " . SCAN_LIMIT,
        [(int) $cursorRow['last_rowid']]
    );
    $window = scanWindow($rows, $now);
    if ($window['rows'] === []) {
        return 0;
    }
    $lines = [];
    foreach ($window['rows'] as $row) {
        $line = lineFromEventRow($row, $playthrough['player_name']);
        if ($line !== null) {
            $lines[] = $line;
        }
    }
    $marksTable = sasTable('marks');
    $marks = [];
    foreach ($pg->all("SELECT owner_npc_id, counterpart_name, last_rowid FROM {$marksTable} WHERE playthrough_id = $1", [$pt]) as $mark) {
        $marks[$mark['owner_npc_id'] . '|' . $mark['counterpart_name']] = (int) $mark['last_rowid'];
    }
    $batch = settledBatches($lines, $castIds, $marks, $now, $window['read_up_to']);
    $jobsTable = sasTable('jobs');
    foreach ($batch['jobs'] as $job) {
        $pg->exec(
            "INSERT INTO {$jobsTable} (playthrough_id, owner_npc_id, owner_name, counterpart_name, event_ids)
             VALUES ($1, $2, $3, $4, $5::bigint[]) ON CONFLICT DO NOTHING",
            [$pt, $job['owner_npc_id'], $job['owner_name'], $job['counterpart_name'], pgIntArray($job['event_ids'])]
        );
        $pg->exec(
            "INSERT INTO {$marksTable} AS m (playthrough_id, owner_npc_id, counterpart_name, last_rowid) VALUES ($1, $2, $3, $4)
             ON CONFLICT (playthrough_id, owner_npc_id, counterpart_name) DO UPDATE SET last_rowid = GREATEST(m.last_rowid, EXCLUDED.last_rowid)",
            [$pt, $job['owner_npc_id'], $job['counterpart_name'], max($job['event_ids'])]
        );
    }
    $pg->exec("UPDATE {$cursorTable} SET last_rowid = GREATEST(last_rowid, $2) WHERE playthrough_id = $1", [$pt, $batch['cursor']]);
    return count($batch['jobs']);
}

/** Claim the oldest due job whose perspective has no older unfinished job (chronology). */
function claimJob(Pg $pg, string $playthroughId): ?array
{
    $jobs = sasTable('jobs');
    $row = $pg->one(
        "UPDATE {$jobs} SET status = 'running', lease_until = now() + make_interval(secs => " . LEASE_SECONDS . ")
         WHERE id = (
             SELECT j.id FROM {$jobs} j
             WHERE j.playthrough_id = $1
               AND ((j.status = 'pending' AND j.next_attempt_at <= now()) OR (j.status = 'running' AND j.lease_until < now()))
               AND NOT EXISTS (
                   SELECT 1 FROM {$jobs} o
                   WHERE o.playthrough_id = j.playthrough_id AND o.owner_npc_id = j.owner_npc_id
                     AND o.counterpart_name = j.counterpart_name AND o.id < j.id AND o.status <> 'failed')
             ORDER BY j.id FOR UPDATE SKIP LOCKED LIMIT 1)
         RETURNING id, playthrough_id, owner_npc_id, owner_name, counterpart_name, event_ids::text AS event_ids, backend, failures",
        [$playthroughId]
    );
    if ($row === null) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'playthrough_id' => $row['playthrough_id'],
        'owner_npc_id' => (int) $row['owner_npc_id'],
        'owner_name' => $row['owner_name'],
        'counterpart_name' => $row['counterpart_name'],
        'event_ids' => parsePgIntArray($row['event_ids']),
        'backend' => $row['backend'],
        'failures' => (int) $row['failures'],
    ];
}

function finishJob(Pg $pg, array $job, array $result, bool $fallbackAvailable): void
{
    $jobs = sasTable('jobs');
    if (in_array($result['outcome'], ['done', 'stale'], true)) {
        $pg->exec("DELETE FROM {$jobs} WHERE id = $1", [$job['id']]);
        return;
    }
    $step = nextStep($result['outcome'], $job['failures'] + 1, $job['backend'], $fallbackAvailable);
    if ($step['action'] === 'retry') {
        $pg->exec(
            "UPDATE {$jobs} SET status = 'pending', lease_until = NULL, backend = $2, failures = $3,
                 next_attempt_at = now() + make_interval(secs => $4::double precision), last_error = $5
             WHERE id = $1",
            [$job['id'], $step['backend'], $step['failures'], $step['delay'], $result['reason']]
        );
        return;
    }
    $pg->exec("UPDATE {$jobs} SET status = 'failed', lease_until = NULL, last_error = $2 WHERE id = $1", [$job['id'], $result['reason']]);
}

final class PgJobDeps implements JobDeps
{
    public function __construct(
        private Pg $pg,
        private PerspectiveStore $store,
        private array $playthrough,
        private array $settings,
        private $transport,
        private $completion
    ) {
    }

    public function currentPlaythroughId(): ?string
    {
        return currentPlaythrough($this->pg)['id'] ?? null;
    }

    public function linesFor(array $eventIds): array
    {
        $rows = $this->pg->all(
            'SELECT rowid, type, data, people, gamets, localts, delivery_state FROM eventlog WHERE rowid = ANY($1::bigint[]) ORDER BY rowid',
            [pgIntArray($eventIds)]
        );
        $lines = [];
        foreach ($rows as $row) {
            $line = lineFromEventRow($row, $this->playthrough['player_name']);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    public function ledgerFor(int $ownerNpcId, string $playthroughId): array
    {
        $row = $this->pg->one("SELECT (plugin_extended_data->'sworn_and_scorned')::text AS ledger FROM core_npc_master WHERE id = $1", [$ownerNpcId]);
        $raw = isset($row['ledger']) ? json_decode($row['ledger'], true) : null;
        return normalizeLedger($raw, $playthroughId);
    }

    public function counterpartKey(string $counterpartName): ?string
    {
        if (strcasecmp($counterpartName, $this->playthrough['player_name']) === 0) {
            return 'player';
        }
        $rows = $this->pg->all('SELECT id FROM core_npc_master WHERE lower(npc_name) = lower($1) LIMIT 2', [$counterpartName]);
        return count($rows) === 1 ? 'npc:' . (int) $rows[0]['id'] : null;
    }

    public function backend(string $kind): ?array
    {
        if ($kind === 'decisions') {
            return $this->settings['api_key'] === ''
                ? null
                : [new OpenRouterDecisionBackend($this->settings['api_key'], $this->settings['model'], $this->transport), POLICY_DECISION_MODEL];
        }
        return relationshipConnectorAvailable() ? [new LlmFallbackBackend($this->completion), POLICY_LLM_FALLBACK] : null;
    }

    public function commitLedger(array $request, callable $mutate): string
    {
        return $this->store->commitLedger($request, $mutate);
    }
}

/** One worker iteration: scan, then process at most one job. Returns whether a job ran. */
function workerTick(Pg $pg, array $settings, int $now, ?callable $transport = null, ?callable $completion = null): bool
{
    $playthrough = currentPlaythrough($pg);
    if ($playthrough === null) {
        return false;
    }
    $pg->exec('BEGIN');
    try {
        $created = scanOnce($pg, $playthrough, $settings, $now);
        $pg->exec('COMMIT');
    } catch (\Throwable $error) {
        $pg->exec('ROLLBACK');
        throw $error;
    }
    if ($created > 0) {
        sasLog('jobs_queued', 'debug', ['jobs' => $created]);
    }
    $job = claimJob($pg, $playthrough['id']);
    if ($job === null) {
        return false;
    }
    $deps = new PgJobDeps($pg, new PerspectiveStore($pg), $playthrough, $settings, $transport ?? curlTransport(), $completion ?? relationshipConnectorCompletion());
    try {
        $result = processJob($job, $deps);
    } catch (\Throwable) {
        $result = ['outcome' => 'retry', 'reason' => 'internal_error'];
    }
    finishJob($pg, $job, $result, relationshipConnectorAvailable());
    sasLog('job_finished', $result['outcome'] === 'failed' ? 'warning' : 'info', [
        'job_id' => $job['id'], 'owner_npc_id' => $job['owner_npc_id'], 'outcome' => $result['outcome'],
        'reason' => $result['reason'], 'backend' => $job['backend'], 'failures' => $job['failures'],
    ]);
    return true;
}
```

- [ ] **Step 4: Run it to verify it passes**

Expected: `queue integration checks passed`, `exit=0`. The `sas_it` schema is gone afterwards.

- [ ] **Step 5: Commit**

```bash
git -c safe.directory='*' add server/queue.php tests/integration/queue_it.php
git -c safe.directory='*' commit -m "Add eventlog scanner, chronological job queue and worker tick"
```

---

### Task 11: Worker daemon and launch hook

**Files:**
- Create: `server/worker.php`, `server/context_pre.php`

- [ ] **Step 1: Create `server/worker.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

// Background worker (spec §5). `--daemon` forks and detaches; `--once` runs one tick (tests).
// Exits by itself when the plugin is disabled or uninstalled, or after repeated errors.

$engineRoot = realpath(__DIR__ . '/../..');
$arguments = $argv ?? [];
$daemon = in_array('--daemon', $arguments, true);
$once = in_array('--once', $arguments, true);
if ($engineRoot === false || !is_file($engineRoot . '/main.php')) {
    fwrite(STDERR, "engine root not found\n");
    exit(1);
}

if ($daemon) {
    if (!function_exists('pcntl_fork') || !function_exists('posix_setsid')) {
        exit(1);
    }
    $child = pcntl_fork();
    if ($child < 0) {
        exit(1);
    }
    if ($child > 0) {
        exit(0);
    }
    posix_setsid();
}

$lockHandle = @fopen($engineRoot . '/log/sworn_scorned_worker.lock', 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit(0); // another worker owns the lock
}
$pidFile = $engineRoot . '/log/sworn_scorned_worker.pid';
file_put_contents($pidFile, (string) getmypid());
register_shutdown_function(static function () use ($pidFile): void {
    if ((int) @file_get_contents($pidFile) === getmypid()) {
        @unlink($pidFile);
    }
});

$GLOBALS['ENGINE_PATH'] = $engineRoot . '/';
require_once $engineRoot . '/conf/conf.php';
require_once $engineRoot . '/lib/postgresql.class.php';
$GLOBALS['db'] = new \sql();
require_once $engineRoot . '/lib/logger.php';
require_once $engineRoot . '/lib/settings.php';
require_once __DIR__ . '/queue.php';

$pg = openEngineConnection($engineRoot);
ensureSchema($pg);
sasLog('worker_started', 'info', ['pid' => getmypid()]);
$errors = 0;

while (true) {
    if (!is_file(__FILE__)) {
        sasLog('worker_stopped', 'info', ['reason' => 'uninstalled']);
        exit(0);
    }
    $worked = false;
    try {
        if (function_exists('chimLoadGeneralSettingsIntoGlobals')) {
            \chimLoadGeneralSettingsIntoGlobals();
        }
        $settings = loadSettings($pg);
        if (!$settings['enabled']) {
            sasLog('worker_stopped', 'info', ['reason' => 'disabled']);
            exit(0);
        }
        $worked = workerTick($pg, $settings, time());
        $errors = 0;
    } catch (\Throwable $error) {
        sasLog('worker_error', 'error', ['reason' => substr(get_class($error), 0, 60)]);
        if (++$errors >= 5) {
            sasLog('worker_stopped', 'error', ['reason' => 'repeated_errors']);
            exit(1);
        }
        try {
            $pg = openEngineConnection($engineRoot);
        } catch (\Throwable) {
        }
    }
    if ($once) {
        exit(0);
    }
    if (!$worked) {
        sleep(2);
    }
}
```

- [ ] **Step 2: Create `server/context_pre.php`**

```php
<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

// Launch the worker when the plugin is enabled. Never blocks or changes the request.
try {
    require_once __DIR__ . '/tables.php';
    require_once __DIR__ . '/launcher.php';
    require_once __DIR__ . '/logging.php';
    $sasEngineRoot = realpath(__DIR__ . '/../..');
    if ($sasEngineRoot !== false && !workerPidAlive($sasEngineRoot . '/log/sworn_scorned_worker.pid')) {
        if (loadSettings(sharedConnection())['enabled']) {
            sasLog('worker_launch', 'info', ['status' => ensureWorkerRunning($sasEngineRoot, __DIR__)]);
        }
    }
} catch (\Throwable) {
    // Silent by design: plugin problems must never affect CHIM's request.
}
```

- [ ] **Step 3: Lint both files**

Run: `wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash -c 'php -l server/worker.php && php -l server/context_pre.php'`
Expected: `No syntax errors detected` twice. Behaviour is exercised in Task 13.

- [ ] **Step 4: Commit**

```bash
git -c safe.directory='*' add server/worker.php server/context_pre.php
git -c safe.directory='*' commit -m "Add self-managing worker daemon and launch hook"
```

---

### Task 12: Full unit suite and package

- [ ] **Step 1: Lint every server file and run all unit tests**

```bash
wsl.exe -d DwemerAI4Skyrim3-test --cd /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev -- bash -c 'for f in server/*.php; do php -l "$f" || exit 1; done && bash scripts/run-php-tests.sh tests/*_test.php'
```

Expected: every file lint clean, and every `tests/*_test.php` prints its `… checks passed` line with `exit=0`.

- [ ] **Step 2: Build and check the package**

```bash
cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev && python tests/package_check.py && python scripts/build-package.py
```

Expected: `OK`, then `Built and verified dist/sworn_and_scorned-0.1.0.dwpkg (sworn_and_scorned 0.1.0, sha256 <hash>)`. Record the hash for Task 13.

- [ ] **Step 3: Commit** (dist is not committed)

```bash
printf 'dist/\n' >> .gitignore
git -c safe.directory='*' add .gitignore
git -c safe.directory='*' commit -m "Plan 2 unit suite and package build pass"
```

---

### Task 13: End-to-end on the clone

Uses the clone's real CHIM, real database and the LLM fallback through the clone's configured `RELLLM_CONNECTOR`. That costs a few model calls: keep to the inputs listed. Steps run from PowerShell unless shown as bash.

- [ ] **Step 1: Bring the clone up and note the state you will change**

```powershell
$S = "K:\ActorwrightExchange\projects\CHIM-PrivateConversation-dev\scripts\live-test\sim.ps1"
wsl -l -v
& $S -Script env.sh up
```

```bash
wsl.exe -d DwemerAI4Skyrim3-test -- psql -h localhost -U dwemer -d dwemer -At -c "select id, extended_data->'relationships'->'Hawke' from core_npc_master where id = 5124"
```

Record Lidia's (5124) current edge toward the player. The user keeps the clone's Lidia/Bruce state for a rematch, so do not reset anything else.

- [ ] **Step 2: Install the package through CHIM's installer**

```powershell
& $S -Script install_pkg.py /mnt/k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev/dist/sworn_and_scorned-0.1.0.dwpkg sworn_and_scorned 0.1.0 <sha256 from Task 12>
```

Expected: the package is listed as installed.

- [ ] **Step 3: Create the schema with one worker run, then enable it with Lidia as the cast**

```bash
wsl.exe -d DwemerAI4Skyrim3-test -- su -s /bin/sh www-data -c "php /var/www/html/HerikaServer/ext/sworn_and_scorned/worker.php --once"
wsl.exe -d DwemerAI4Skyrim3-test -- psql -h localhost -U dwemer -d dwemer -c "insert into plugins.sworn_scorned_settings (key, value) values ('enabled','true'::jsonb), ('cast','[5124]'::jsonb) on conflict (key) do update set value = excluded.value"
wsl.exe -d DwemerAI4Skyrim3-test -- su -s /bin/sh www-data -c "php /var/www/html/HerikaServer/ext/sworn_and_scorned/worker.php --once"
```

Expected: the first run exits because the plugin is disabled, after creating the `plugins.sworn_scorned_*` tables. The second run initialises the cursor.

To also exercise the decision model, the **user** may add their key themselves:

```bash
psql … -c "insert into plugins.sworn_scorned_settings values ('api_key', to_jsonb('<key>'::text)) on conflict (key) do update set value = excluded.value"
```

Never type the key yourself.

- [ ] **Step 4: Drive a promise exchange**

```powershell
& $S heartbeat "Lidia Sobieska"
Start-Sleep 10
& $S heartbeat "Lidia Sobieska"
& $S input "Lidia Sobieska" "Lidia, promise me you will guard the gate tonight." "Lidia Sobieska"
```

Read Lidia's reply line and its utterance id:

```bash
wsl.exe -d DwemerAI4Skyrim3-test -- psql -h localhost -U dwemer -d dwemer -At -c "select utterance_id||'|'||data from eventlog where type='chat' and utterance_id is not null order by rowid desc limit 1"
```

Acknowledge it:

```powershell
& $S ack "Lidia Sobieska" "Hawke" "<speech text>" <utterance_id>
```

- [ ] **Step 5: Let it settle, then run worker ticks**

Wait at least 25 seconds, then:

```bash
wsl.exe -d DwemerAI4Skyrim3-test -- su -s /bin/sh www-data -c "for i in 1 2 3; do php /var/www/html/HerikaServer/ext/sworn_and_scorned/worker.php --once; done"
wsl.exe -d DwemerAI4Skyrim3-test -- psql -h localhost -U dwemer -d dwemer -At -c "select id, status, backend, failures, last_error from plugins.sworn_scorned_jobs"
wsl.exe -d DwemerAI4Skyrim3-test -- psql -h localhost -U dwemer -d dwemer -At -c "select jsonb_pretty(plugin_extended_data->'sworn_and_scorned') from core_npc_master where id = 5124"
wsl.exe -d DwemerAI4Skyrim3-test -- bash -c "grep sworn_and_scorned /var/www/html/HerikaServer/log/chim.log | tail -5"
```

Expected:
- the job is gone (done);
- Lidia's ledger lists the processed event ids;
- if the model judged a promise, there is an issue whose evidence quotes Lidia's line;
- `job_finished` log lines carry no dialogue text;
- a new history row has `_chim_history_source = relationship`.

Record whatever happened, including "no promise detected". This proves plumbing, not judgment quality.

- [ ] **Step 6: Check daemon launch and self-exit**

Send one more input (Step 4's `input` command), then:

```bash
wsl.exe -d DwemerAI4Skyrim3-test -- bash -c "cat /var/www/html/HerikaServer/log/sworn_scorned_worker.pid; pgrep -af sworn_and_scorned"
```

Expected: one worker process. Now disable it and confirm it exits within a few seconds:

```bash
wsl.exe -d DwemerAI4Skyrim3-test -- psql -h localhost -U dwemer -d dwemer -c "update plugins.sworn_scorned_settings set value='false'::jsonb where key='enabled'"
sleep 6
wsl.exe -d DwemerAI4Skyrim3-test -- bash -c "pgrep -af sworn_and_scorned || echo 'no worker'"
```

Expected: `no worker`.

- [ ] **Step 7: Clean up the clone and record the evidence**

```bash
wsl.exe -d DwemerAI4Skyrim3-test -- psql -h localhost -U dwemer -d dwemer -c "update core_npc_master set plugin_extended_data = plugin_extended_data - 'sworn_and_scorned' where id = 5124"
```

Remove the plugin through CHIM's plugin manager (PCV `docs/live-server-testing.md` §6, `delete_plugin=sworn_and_scorned`). Then drop our tables:

```bash
psql … -c "drop table if exists plugins.sworn_scorned_jobs, plugins.sworn_scorned_marks, plugins.sworn_scorned_cursor, plugins.sworn_scorned_settings, plugins.sworn_scorned_pacing"
```

Confirm Lidia's edge toward the player matches Step 1 (lifecycle affinity needs a status transition, which one exchange does not create). Then:

```powershell
& $S -Script env.sh down
wsl --terminate DwemerAI4Skyrim3-test
wsl -l -v
```

Write `docs/verification/2026-10-plan-2-e2e.md` with the commands run, outputs, costs seen, and "live-server, simulated client evidence; not gameplay evidence". Commit:

```bash
git -c safe.directory='*' add docs/verification/2026-10-plan-2-e2e.md
git -c safe.directory='*' commit -m "Plan 2 end-to-end evidence on the test clone"
```
