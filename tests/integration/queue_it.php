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
check($pg->one("SELECT to_regnamespace('sas_it') AS s")['s'] === null, 'fixture schema must be absent');
foreach ([
    'CREATE SCHEMA sas_it',
    'CREATE TABLE sas_it.core_npc_master (LIKE public.core_npc_master INCLUDING ALL)',
    'CREATE TABLE sas_it.eventlog (LIKE public.eventlog INCLUDING ALL)',
    'CREATE SEQUENCE sas_it.eventlog_id_seq',
    "ALTER TABLE sas_it.eventlog ALTER rowid SET DEFAULT nextval('sas_it.eventlog_id_seq')",
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

    $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = true;
    check(scanOnce($pg, $pt, $settings, $now) === 0, 'restore pause does not scan');
    check($pg->one('SELECT * FROM sas_it.sworn_scorned_cursor') === null, 'restore pause preserves an absent cursor');
    check(!ChimSwornAndScorned\workerTick($pg, $settings, $now, static function (): never { throw new RuntimeException('provider must not run'); }), 'restore pause does not claim or call provider');
    unset($GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA']);

    check(scanOnce($pg, $pt, $settings, $now) === 0, 'first scan only initialises the cursor');
    $staleCast = $settings + ['_cast_playthrough' => 'old-profile'];
    check(scanOnce($pg, $pt, $staleCast, $now) === 0, 'in-flight cast from another profile never scans');
    check($pg->one('SELECT count(*) AS n FROM sas_it.sworn_scorned_cursor')["n"] === '1', 'in-flight profile mismatch never creates a new cursor');
    $e1 = $event('Farkas IT: I will stand with you. (talking to Lydia IT)', $now - 60);
    $e2 = $event('Lydia IT: You had better. (talking to Farkas IT)', $now - 50);
    check(scanOnce($pg, $pt, $settings, $now) === 1, 'one settled perspective job');
    check(scanOnce($pg, $pt, $settings, $now) === 0, 'marks prevent re-emitting');

    $job = claimJob($pg, 'pt-it');
    check($job !== null && $job['event_ids'] === [$e1, $e2] && $job['owner_npc_id'] === 900001 && $job['counterpart_name'] === 'Farkas IT', 'claimed job');
    check($job['backend'] === 'llm', 'no key queues the configured LLM fallback directly');

    finishJob($pg, $job, ['outcome' => 'paused', 'reason' => 'restore_policy'], true);
    $pausedJob = $pg->one('SELECT status, failures, backend FROM sas_it.sworn_scorned_jobs WHERE id = $1', [$job['id']]);
    check($pausedJob['status'] === 'pending' && $pausedJob['failures'] === '0' && $pausedJob['backend'] === 'llm', 'commit-time restore pause retains job without penalizing retries');
    $pg->exec("UPDATE sas_it.sworn_scorned_jobs SET next_attempt_at = now() WHERE id = $1", [$job['id']]);
    $job = claimJob($pg, 'pt-it');
    check(claimJob($pg, 'pt-it') === null, 'a running job is not claimed twice');

    finishJob($pg, $job, ['outcome' => 'retry', 'reason' => 'http_429'], true);
    $row = $pg->one('SELECT status, failures, backend, last_error FROM sas_it.sworn_scorned_jobs WHERE id = $1', [$job['id']]);
    check($row['status'] === 'pending' && $row['failures'] === '1' && $row['backend'] === 'llm' && $row['last_error'] === 'http_429', 'fallback retry scheduled');
    check(claimJob($pg, 'pt-it') === null, 'retry waits for its delay');

    $e3 = $event('Farkas IT: Fine. (talking to Lydia IT)', $now - 40);
    $settings['api_key'] = 'fixture-key';
    $settings['use_jev'] = true;
    check(scanOnce($pg, $pt, $settings, $now) === 1, 'newer job queued');
    $otherJobId = (int) $pg->one("INSERT INTO sas_it.sworn_scorned_jobs (playthrough_id, owner_npc_id, owner_name, counterpart_name, event_ids)
        VALUES ('pt-it', 900001, 'Lydia IT', 'Other IT', $1::bigint[]) RETURNING id", [ChimSwornAndScorned\pgIntArray([$e3])])['id'];
    check(claimJob($pg, 'pt-it') === null, 'newer job waits behind the older one (chronology)');

    $pg->exec("UPDATE sas_it.sworn_scorned_jobs SET next_attempt_at = now() WHERE id = $1", [$job['id']]);
    $again = claimJob($pg, 'pt-it');
    check($again['id'] === $job['id'], 'older job runs first');
    finishJob($pg, $again, ['outcome' => 'done', 'reason' => 'committed'], true);
    check((int) $pg->one('SELECT count(*) AS n FROM sas_it.sworn_scorned_jobs WHERE id = $1', [$job['id']])['n'] === 0, 'done jobs are deleted');
    $newer = claimJob($pg, 'pt-it');
    check($newer['event_ids'] === [$e3], 'newer job now runs');
    check($newer['backend'] === 'decisions', 'explicit experimental toggle queues Decisions');
    finishJob($pg, $newer, ['outcome' => 'failed', 'reason' => 'http_401'], false);
    check($pg->one('SELECT status FROM sas_it.sworn_scorned_jobs WHERE id = $1', [$newer['id']])['status'] === 'failed', 'failed job stays visible');
    $other = claimJob($pg, 'pt-it');
    check($other['id'] === $otherJobId, 'another counterpart waits behind older jobs for the same ledger owner');
    finishJob($pg, $other, ['outcome' => 'done', 'reason' => 'committed'], true);

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
