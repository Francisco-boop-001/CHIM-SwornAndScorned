<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/tables.php';
require_once __DIR__ . '/rows.php';
require_once __DIR__ . '/written_source.php';
require_once __DIR__ . '/batches.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/decisions_backend.php';
require_once __DIR__ . '/llm_backend.php';
require_once __DIR__ . '/llm_connector.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/lore.php';
require_once __DIR__ . '/hook.php';

const SCAN_LIMIT = 500;
const LEASE_SECONDS = 120;

/** Read new speech rows, emit settled per-perspective jobs, advance the cursor. Returns jobs created. */
function scanOnce(Pg $pg, array $playthrough, #[\SensitiveParameter] array $settings, int $now, bool $checkCredentials = true): int
{
    if (workerPauseReason($settings, $checkCredentials) !== null) return 0;
    if (array_key_exists('_cast_playthrough', $settings) && $settings['_cast_playthrough'] !== $playthrough['id']) return 0;
    $pt = $playthrough['id'];
    $cursorTable = sasTable('cursor');
    $cursorRow = $pg->one("SELECT last_rowid FROM {$cursorTable} WHERE playthrough_id = $1 FOR UPDATE", [$pt]);
    if ($cursorRow === null) {
        $max = $pg->one('SELECT COALESCE(max(rowid), 0) AS m FROM eventlog');
        $pg->exec("INSERT INTO {$cursorTable} (playthrough_id, last_rowid) VALUES ($1, $2) ON CONFLICT (playthrough_id) DO NOTHING", [$pt, initialEvidenceCursor($pg, $playthrough, $settings, (int) $max['m'])]);
        $cursorRow = $pg->one("SELECT last_rowid FROM {$cursorTable} WHERE playthrough_id = $1 FOR UPDATE", [$pt]);
        if ($cursorRow === null) return 0;
    }
    if ($settings['cast'] === []) {
        return 0;
    }
    $castIds = [];
    foreach ($pg->all('SELECT id, npc_name FROM core_npc_master WHERE id = ANY($1::bigint[])', [pgIntArray($settings['cast'])]) as $row) {
        $castIds[$row['npc_name']] = (int) $row['id'];
    }
    $rows = $pg->all(
        "SELECT rowid, type, sess, data, people, gamets, localts, delivery_state FROM eventlog
         WHERE rowid > $1 AND (type IN ('chat', 'inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s')
           OR (type='info' AND sess='written_evidence_v1'))
         ORDER BY rowid LIMIT " . SCAN_LIMIT,
        [(int) $cursorRow['last_rowid']]
    );
    $window = scanWindow($rows, $now);
    if ($window['rows'] === []) {
        return 0;
    }
    $lines = [];
    foreach ($window['rows'] as $row) {
        $line = evidenceLineFromEventRow($pg, $row, $playthrough);
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
            "INSERT INTO {$jobsTable} (playthrough_id, owner_npc_id, owner_name, counterpart_name, event_ids, backend)
             VALUES ($1, $2, $3, $4, $5::bigint[], $6) ON CONFLICT DO NOTHING",
            [$pt, $job['owner_npc_id'], $job['owner_name'], $job['counterpart_name'], pgIntArray($job['event_ids']), empty($settings['use_jev']) ? 'llm' : 'decisions']
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

/** Keep the owner's ledger chronological: its processed-event floor spans all counterparts. */
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
                     AND o.id < j.id AND o.status <> 'failed')
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
    if ($result['outcome'] === 'paused') {
        $pg->exec("UPDATE {$jobs} SET status = 'pending', lease_until = NULL, next_attempt_at = now() + interval '30 seconds', last_error = $2 WHERE id = $1", [$job['id'], $result['reason']]);
        return;
    }
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
        #[\SensitiveParameter] private array $settings,
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
            'SELECT rowid, type, sess, data, people, gamets, localts, delivery_state FROM eventlog WHERE rowid = ANY($1::bigint[]) ORDER BY rowid',
            [pgIntArray($eventIds)]
        );
        $lines = [];
        foreach ($rows as $row) {
            $line = evidenceLineFromEventRow($this->pg, $row, $this->playthrough);
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
            $key = decisionApiKey($this->settings);
            return $key === null
                ? null
                : [new OpenRouterDecisionBackend($key, $this->settings['model'], $this->transport), POLICY_DECISION_MODEL];
        }
        $identity = relationshipConnectorIdentity();
        return relationshipConnectorAvailable() && $identity !== null
            ? [new LlmFallbackBackend($this->completion, $identity['model']), POLICY_LLM_FALLBACK]
            : null;
    }

    public function commitLedger(array $request, callable $mutate): string
    {
        if (array_key_exists('_interaction_generation', $this->settings)) {
            $request['interaction_generation'] = $this->settings['_interaction_generation'];
        }
        return $this->store->commitLedger($request, $mutate);
    }
}

/** One worker iteration: scan, then process at most one job. Returns whether a job ran. */
function workerTick(Pg $pg, #[\SensitiveParameter] array $settings, int $now, ?callable $transport = null, ?callable $completion = null): bool
{
    if (workerPauseReason($settings) !== null) return false;
    $playthrough = currentPlaythrough($pg);
    if ($playthrough === null) {
        return false;
    }
    if (array_key_exists('_cast_playthrough', $settings) && $settings['_cast_playthrough'] !== $playthrough['id']) return false;
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
    $deps = new PgJobDeps($pg, new PerspectiveStore($pg), $playthrough, $settings, $transport ?? curlTransport(), $completion ?? relationshipConnectorCompletion(1024, $job['owner_name'], $playthrough['player_name'], 20, array_key_exists('_interaction_generation', $settings)));
    $started = hrtime(true);
    try {
        $result = processJob($job, $deps);
    } catch (\Throwable) {
        $result = ['outcome' => 'retry', 'reason' => 'internal_error'];
    }
    finishJob($pg, $job, $result, relationshipConnectorAvailable());
    sasLog('job_finished', $result['outcome'] === 'failed' ? 'warning' : 'info', [
        'job_id' => $job['id'], 'owner_npc_id' => $job['owner_npc_id'], 'outcome' => $result['outcome'],
        'reason' => $result['reason'], 'backend' => $job['backend'], 'failures' => $job['failures'],
        'duration_ms' => (int) ((hrtime(true) - $started) / 1000000),
    ]);
    return true;
}

const LORE_INTERVAL_SECONDS = 60;
const LORE_OVERRIDE_MAX_BYTES = 262144;

/** Built-in seeds plus the optional user override at <engine>/data/sworn_and_scorned_lore.json. */
function loadSeeds(string $pluginDir, string $engineRoot): array
{
    $builtIn = validateSeedFile(json_decode((string) file_get_contents($pluginDir . '/lore_grudges.json'), true, 512, JSON_THROW_ON_ERROR));
    $overridePath = $engineRoot . '/data/sworn_and_scorned_lore.json';
    $override = [];
    if (is_file($overridePath)) {
        try {
            if (is_link($overridePath) || filesize($overridePath) > LORE_OVERRIDE_MAX_BYTES) {
                throw new \UnexpectedValueException('lore_override_invalid');
            }
            $override = validateSeedFile(json_decode((string) file_get_contents($overridePath), true, 512, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
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
        if (function_exists('ptr_runtime_paused') && \ptr_runtime_paused()) return null;
        if (str_starts_with($counterpart, 'daedra:')) {
            return $counterpart;
        }
        $matches = $pg->all('SELECT id FROM core_npc_master WHERE lower(npc_name) = lower($1) LIMIT 2', [$counterpart]);
        return count($matches) === 1 ? 'npc:' . (int) $matches[0]['id'] : null;
    };
    $committed = 0;
    foreach ($rowsByName as $name => $matches) {
        if (function_exists('ptr_runtime_paused') && \ptr_runtime_paused()) break;
        if (count($matches) !== 1) {
            continue; // ambiguous owner name
        }
        $row = $matches[0];
        try {
            $current = normalizeLedger(isset($row['ledger']) ? json_decode($row['ledger'], true, 512, JSON_THROW_ON_ERROR) : null, $playthrough['id']);
        } catch (\Throwable) {
            sasLog('lore_owner_invalid', 'warning', ['npc_id' => (int) $row['id']]);
            continue;
        }
        $seeded = $current['seeded'];
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
