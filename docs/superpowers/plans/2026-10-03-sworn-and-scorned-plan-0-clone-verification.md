# Sworn & Scorned — Plan 0: Clone Verification — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Confirm on the **installed** CHIM (the disposable clone `DwemerAI4Skyrim3-test`) the facts that Plans 2 and 3 rely on. Upstream research (spec Amendment A) read the `HerikaServer` dev branch, but the installed server may differ. The output is one findings document. No product code is written in this plan.

**Architecture:** Read-only inspection of the clone's HerikaServer files and database, plus one temporary probe extension that logs a few globals and is removed afterwards. One live Decisions API call is made, **run by the user**.

**Tech Stack:** WSL clone, bash, `grep`, `psql`, PHP CLI, PCV's existing live-test helpers (`CHIM-PrivateConversation-dev/scripts/live-test/`), which are used, not modified.

**Run after:** Plan 1. **Feeds:** Plans 2 and 3. If a finding contradicts an assumption, record it under "Plan changes required" and stop before starting Plan 2.

---

## Safety rules (non-negotiable)

- Run nothing against `DwemerAI4Skyrim3` (the live gaming distro). Even a read boots it. Before starting, `wsl.exe -l -v` must show it `Stopped`. If it is `Running`, stop and ask the user.
- Never run the clone while the gaming server runs; they share ports.
- Never run `/etc/start_env` in the clone. Use PCV's `env.sh up`/`down`.
- Never type, print or log API keys. The live Decisions call (Task 7) is run by the user.
- Finish every session with `wsl.exe --terminate DwemerAI4Skyrim3-test`, and confirm `(Get-Item D:\DwemerAI4Skyrim3\ext4.vhdx).LastWriteTime` is unchanged.

Shorthand used below (from Git Bash):

```bash
CLONE="wsl.exe -d DwemerAI4Skyrim3-test --"
HS=/var/www/html/HerikaServer
PCV=/k/ActorwrightExchange/projects/CHIM-PrivateConversation-dev
```

Database shell inside the clone: `psql -h localhost -U dwemer -d dwemer` (the password is in `/home/dwemer/.pgpass`).

## Files

- Create: `docs/verification/2026-10-plan-0-findings.md` (findings, one section per task)
- Create, then delete, inside the clone only: `$HS/ext/zz_sas_probe/context_pre.php`

---

### Task 1: Bring up the clone and record the PHP environment

- [ ] **Step 1: Check the distros, then start the clone services**

```bash
wsl.exe -l -v
```

Expected: `DwemerAI4Skyrim3` is `Stopped`.

```powershell
K:\ActorwrightExchange\projects\CHIM-PrivateConversation-dev\scripts\live-test\sim.ps1 -Script env.sh up
```

Expected: postgres and apache listening on 5432 and 8081; background workers disabled.

- [ ] **Step 2: Record PHP version and extensions**

```bash
$CLONE bash -c 'php -v | head -1; php -m | grep -E -i "^(pcntl|posix|curl|pgsql|mbstring|json)$"; command -v php'
```

Expected: PHP 8.2.x, all six extensions listed, and the php path (Plan 2's launcher assumes `/usr/bin/php`). Write the output into the findings under "1. Environment".

- [ ] **Step 3: Record the installed server revision**

```bash
$CLONE bash -c "cd $HS && (git rev-parse HEAD 2>/dev/null || echo no-git); ls ext"
```

Record the revision and installed extensions. Compare with MP's pin `cf5030f15781637498be86debe26fcf102f5690d`.

---

### Task 2: Hook loader, prompt injection and context globals

- [ ] **Step 1: Hook includes and their order in main.php**

```bash
$CLONE bash -c "cd $HS && grep -n 'requireFilesRecursively' main.php | head -20; grep -n 'function requireFilesRecursively' -A14 lib/data_functions.php"
```

Expected (dev): `preprocessing.php` (~201), `prerequest.php` (~1125), `context_pre.php` (~2554), `context.php` (~2647), `prepostrequest.php` (~2941), `postrequest.php` (~2943); the loader uses `scandir` (alphabetical). Record the actual lines.

- [ ] **Step 2: Prompt injection API and rendered slots**

```bash
$CLONE bash -c "cd $HS && grep -n 'function chimRegisterPromptInjection\|function chimRenderPromptInjections' lib/prompt_injections.php; grep -n 'chimRenderPromptInjections' main.php"
```

Expected: `chimRegisterPromptInjection(string $slot, string $id, $content, int $priority = 100)`, with the slots `character_bottom` and `prompt_bottom` rendered after `context_pre`. Record the line numbers. **If `character_bottom` is not rendered**, write "Plan changes required: injection slot".

- [ ] **Step 3: Install the probe extension (clone only)**

```bash
$CLONE bash -c "mkdir -p $HS/ext/zz_sas_probe && cat > $HS/ext/zz_sas_probe/context_pre.php <<'PHP'
<?php
\$probe = [
    'type' => \$GLOBALS['gameRequest'][0] ?? null,
    'herika' => \$GLOBALS['HERIKA_NAME'] ?? null,
    'cache_people' => \$GLOBALS['CACHE_PEOPLE'] ?? null,
    'player' => \$GLOBALS['PLAYER_NAME'] ?? null,
    'never_clear' => \$GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] ?? null,
    'injection_fn' => function_exists('chimRegisterPromptInjection'),
];
error_log('SAS_PROBE ' . json_encode(\$probe));
PHP
chown -R www-data:www-data $HS/ext/zz_sas_probe"
```

`zz_` sorts last, so the probe sees what PCV and other extensions left in the globals.

- [ ] **Step 4: Drive one ordinary input, without and with a PCV scene**

Follow PCV's simulator steps (`docs/live-server-testing.md` §7–9). Run each from PowerShell:

```powershell
$S = "K:\ActorwrightExchange\projects\CHIM-PrivateConversation-dev\scripts\live-test\sim.ps1"
& $S heartbeat "Lidia Sobieska" "Aela the Huntress"
Start-Sleep 10
& $S heartbeat "Lidia Sobieska" "Aela the Huntress"
& $S input "Lidia Sobieska" "Lidia, did Aela keep her word?" "Lidia Sobieska" "Aela the Huntress"
& $S arm pair 5124 2892
& $S input - "They talk about the hunt." "Lidia Sobieska" "Aela the Huntress"
& $S end
```

These make one or two model calls through the clone's configured connector, which costs credits. Keep it to these inputs.

- [ ] **Step 5: Read the probe lines**

```bash
$CLONE bash -c "grep 'SAS_PROBE' /var/log/apache2/error.log | tail -5"
```

Record for each request: `herika` (the speaker), `cache_people` (expected pipe list `|A|B|`, with the player present when included), and `injection_fn: true`. Confirm that with the PCV scene armed, `cache_people` equals the scene members and excludes the player when `exclude_player` is on. **If `CACHE_PEOPLE` is not a pipe list, or is empty at `context_pre`**, write "Plan changes required: presence source".

- [ ] **Step 6: Remove the probe**

```bash
$CLONE bash -c "rm -rf $HS/ext/zz_sas_probe && ls $HS/ext"
```

Expected: `zz_sas_probe` is gone.

---

### Task 3: eventlog shape, speech rows and player rows

- [ ] **Step 1: Columns**

```bash
$CLONE bash -c "psql -h localhost -U dwemer -d dwemer -c '\d public.eventlog'"
```

Record the columns. Expected: `type, data, gamets, localts, ts, rowid, people, utterance_id, delivery_state`.

- [ ] **Step 2: Types and delivery states seen**

```bash
$CLONE bash -c "psql -h localhost -U dwemer -d dwemer -c \"select type, delivery_state, count(*) from public.eventlog where type in ('chat','prechat','chat_background','inputtext','inputtext_s','ginputtext','ginputtext_s','narrator_inputtext','instruction','suggestion') group by 1,2 order by 1,2\""
```

Record the table. Expected: `chat` rows mostly `spoken` (some `aborted`, possibly `emitted`); player input types present. **If acknowledged NPC lines are not `spoken`**, write "Plan changes required: delivery state filter".

- [ ] **Step 3: Row formats and units**

```bash
$CLONE bash -c "psql -h localhost -U dwemer -d dwemer -At -c \"select rowid, type, gamets, localts, people, left(data,160) from public.eventlog where type in ('chat','inputtext') order by rowid desc limit 8\""
```

Confirm and record:
- `chat` data looks like `Speaker: text (talking to Listener)`;
- player input looks like `<PlayerName>: text`;
- `people` is a pipe list `|A|B|`;
- `localts` is **Unix seconds** (about 1.79e9 today), not milliseconds.

**If `localts` is not Unix seconds**, write "Plan changes required: settle clock".

- [ ] **Step 4: Relationship edge keys**

```bash
$CLONE bash -c "psql -h localhost -U dwemer -d dwemer -At -c \"select npc_name, jsonb_object_keys(extended_data->'relationships') from public.core_npc_master where extended_data ? 'relationships' limit 12\""
```

Record whether edges are keyed by the counterpart's display name and what key the player uses (expected: the player's name).

---

### Task 4: Save load, pruning, restore and snapshot source

- [ ] **Step 1: eventlog pruning on init**

```bash
$CLONE bash -c "cd $HS && grep -n 'delete(\"eventlog\"' processor/comm.php"
```

Expected: `gamets>=` (init) and `localts>` deletes, plus the `playerdied` and `wipe` cases. Record them.

- [ ] **Step 2: Restore and the history source whitelist**

```bash
$CLONE bash -c "cd $HS && grep -n 'function restoreNPC\|function backupNpcById\|_chim_history_source' lib/core/npc_master.class.php | head -20"
```

Confirm `relationship` is an accepted `_chim_history_source` and that restore re-inserts `plugin_extended_data`. Record it.

- [ ] **Step 3: Playthrough guard**

```bash
$CLONE bash -c "cd $HS && grep -n 'pgr_skip_rollback' processor/comm.php lib/playthrough_guard.php | head"
```

Record whether the rollback can be skipped. This is informational: our commit-time check needs no change.

---

### Task 5: Plugin schema, migrations and the worker pattern

- [ ] **Step 1: The `plugins` schema and its owner**

```bash
$CLONE bash -c "psql -h localhost -U dwemer -d dwemer -c '\dn+ plugins'"
```

Record whether it exists and its owner. **If it is missing and `dwemer` cannot create it** (`create schema if not exists plugins` fails), write "Plan changes required: schema".

- [ ] **Step 2: Package manager migrations path**

```bash
$CLONE bash -c "cd $HS && grep -n 'migrations' lib/plugin_package_manager.php | head -20"
```

Informational: Plan 2 creates its tables idempotently from code. Record whether `server/migrations/*.sql` would also be honoured.

- [ ] **Step 3: Relationship worker launch and kill**

```bash
$CLONE bash -c "cd $HS && grep -n '_relEnsureWorkerRunning' -A30 ext/relationship_system/context_pre.php | head -60; grep -n 'pkill' service/start.sh*"
```

Record the spawn command (`proc_open` of `/usr/bin/php … --daemon`) and confirm `start.sh` only kills its own worker. Plan 2's worker therefore exits on its own (uninstall, disabled, or database lost).

---

### Task 6: Connection string and LLM connector

- [ ] **Step 1: The `sql` class connection string is a default property**

```bash
$CLONE bash -c "cd $HS && grep -n 'connString\|\$link' lib/postgresql.class.php | head"
```

Expected:
- `private $connString = "...";` is a literal default. Plan 2's worker reads it by reflection, as MP's dashboard does.
- A `private static $link` holds the shared connection. Plan 2's `sharedConnection()` reuses it in hooks, as MP's store does.

**If either differs**, write "Plan changes required: worker DSN / shared connection".

- [ ] **Step 2: LLM connector and fast_request**

```bash
$CLONE bash -c "cd $HS && grep -n 'function fast_request' connector/openrouterjson.php; grep -n 'function readOne\|function getConnector\|function setOldGlobals' lib/core/llm_connector.class.php"
```

Record the line numbers. Then check which connector drives `RELLLM_CONNECTOR`:

```bash
$CLONE bash -c "psql -h localhost -U dwemer -d dwemer -At -c \"select id, driver, model from public.core_llm_connector order by id\""
$CLONE bash -c "psql -h localhost -U dwemer -d dwemer -At -c \"select key, value from public.general_settings where key in ('RELLLM_CONNECTOR','NEVER_CLEAR_RELATIONSHIP_DATA')\""
```

Never print `url` or key columns. Record the driver of the `RELLLM_CONNECTOR` row. The fallback adapter requires `openrouterjson` or `openrouterjsoncached`.

---

### Task 7: Live Decisions API call (the user runs this)

- [ ] **Step 1: Write a request body file (no key inside)**

Create `docs/verification/decisions-probe.json`:

```json
{
  "model": "typesafe/jev-1.13",
  "state": {
    "owner": "Lydia",
    "counterpart": "Farkas",
    "lines": {"L1": "Lydia: Will you defend me before Aela?", "L2": "Farkas: I will stand with you before Aela."},
    "open_issues": {}
  },
  "questions": {
    "commitment": {"type": "noul", "instructions": "In these lines, does Lydia or Farkas commit to doing something for the other?"},
    "commitment.who": {"type": "choice", "instructions": "Who made the commitment?", "criteria": {"Lydia": null, "Farkas": null}},
    "issue:abc123abc123abc1.status": {"type": "choice", "instructions": "After these lines, what is the state of this matter?", "criteria": {"resolved": null, "let_go": null, "no_change": null}}
  }
}
```

- [ ] **Step 2: Ask the user to run the call**

Give the user this command and ask them to paste the **response body only**:

```bash
curl -s https://openrouter.ai/api/alpha/decisions -H "Authorization: Bearer $OPENROUTER_API_KEY" -H "Content-Type: application/json" --data @docs/verification/decisions-probe.json
```

Expected: `answers.commitment = {type: "noul", noul: <p>}` with p above 0.8; `answers["commitment.who"].choice = "Farkas"` with `probabilities`; question ids containing `.` and `:` are echoed unchanged. **If ids with `.` or `:` are rejected**, write "Plan changes required: question id encoding". Record the response (it contains no secrets) and the cost.

---

### Task 8: Write the findings and close the session

- [ ] **Step 1: Write `docs/verification/2026-10-plan-0-findings.md`**

Use this structure, filled with the outputs recorded above:

```markdown
# Plan 0 findings — <date>

Clone: DwemerAI4Skyrim3-test, HerikaServer <revision>. Gaming distro untouched (vhdx LastWriteTime <value> before and after).

## 1. Environment
## 2. Hooks, injection and context globals
## 3. eventlog
## 4. Save load, restore and snapshots
## 5. Plugin schema and worker pattern
## 6. Connection string and LLM connector
## 7. Decisions API live call

## Plan changes required
- None. (Or list each, with the affected Plan 2/3 task.)
```

- [ ] **Step 2: Stop the clone and check the gaming disk**

```powershell
K:\ActorwrightExchange\projects\CHIM-PrivateConversation-dev\scripts\live-test\sim.ps1 -Script env.sh down
wsl --terminate DwemerAI4Skyrim3-test
wsl -l -v
(Get-Item D:\DwemerAI4Skyrim3\ext4.vhdx).LastWriteTime
```

Expected: the gaming distro is `Stopped` and its LastWriteTime is unchanged from before the session.

- [ ] **Step 3: Commit**

```bash
cd /k/ActorwrightExchange/projects/CHIM-SwornAndScorned-dev
git -c safe.directory='*' add docs/verification
git -c safe.directory='*' commit -m "Plan 0: clone verification findings"
```

If "Plan changes required" lists anything, stop and report it to the user before starting Plan 2.
