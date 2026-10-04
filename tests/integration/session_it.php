<?php
declare(strict_types=1);
require __DIR__ . '/../support/check.php';
require __DIR__ . '/../../server/queue.php';

use ChimSwornAndScorned\{PgJobDeps, PerspectiveStore};
use function ChimSwornAndScorned\{openEngineConnection, ensureSchema, currentPlaythrough, saveSetting, normalizeSettings, claimJob, processJob, finishJob, scanOnce, workerTick};

if (getenv('SAS_IT') !== '1') { echo "session integration skipped\n"; exit(0); }
check(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'only disposable clone');
putenv('SAS_TEST_SCHEMA=sas_it');
$pg = openEngineConnection('/var/www/html/HerikaServer');
check($pg->one("SELECT to_regnamespace('sas_it') AS s")['s'] === null, 'fixture schema absent');
$pg->exec('CREATE SCHEMA sas_it');
try {
    foreach ([
        'CREATE TABLE sas_it.core_npc_master (LIKE public.core_npc_master INCLUDING ALL)',
        'CREATE TABLE sas_it.core_npc_master_history (LIKE public.core_npc_master_history INCLUDING ALL)',
        'CREATE SEQUENCE sas_it.history_id_seq',
        "ALTER TABLE sas_it.core_npc_master_history ALTER history_id SET DEFAULT nextval('sas_it.history_id_seq')",
        'CREATE TABLE sas_it.eventlog (LIKE public.eventlog INCLUDING ALL)',
        'CREATE SEQUENCE sas_it.eventlog_id_seq',
        "ALTER TABLE sas_it.eventlog ALTER rowid SET DEFAULT nextval('sas_it.eventlog_id_seq')",
        'SET search_path=sas_it,public',
    ] as $sql) $pg->exec($sql);
    $template = $pg->one("SELECT id FROM public.core_npc_master WHERE npc_name <> 'The Narrator' ORDER BY id LIMIT 1");
    $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id=$1', [$template['id']]);
    $pg->exec("UPDATE sas_it.core_npc_master SET id=900001, npc_name='Session Owner', lock_profile=0,
        extended_data='{}', plugin_extended_data='{\"mind_poisoning\":{\"marker\":1}}'");
    ensureSchema($pg);
    $pt = currentPlaythrough($pg);
    check($pt !== null, 'one active fixture scope');
    saveSetting($pg, 'enabled', true);
    saveSetting($pg, 'cast', [900001]);
    $settings = normalizeSettings(['enabled'=>true, 'use_jev'=>true, 'cast'=>[900001], 'api_key'=>'dummy-transport-only']);
    $calls = 0;
    $transport = static function (string $url, array $headers, string $body) use (&$calls): array {
        ++$calls;
        $request = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $answers = [];
        foreach ($request['questions'] as $id => $question) {
            check($question['type'] === 'noul', 'negative fixture has only initial evidence questions');
            $answers[$id] = ['type'=>'noul', 'noul'=>0.01];
        }
        return ['status'=>200, 'body'=>json_encode(['answers'=>$answers], JSON_THROW_ON_ERROR)];
    };
    $store = new PerspectiveStore($pg);
    $deps = new PgJobDeps($pg, $store, $pt, $settings, $transport, static fn (): string => '');
    scanOnce($pg, $pt, $settings, time());
    $timings = [];
    for ($i=0; $i<240; ++$i) {
        $event = (int) $pg->one("INSERT INTO sas_it.eventlog(type,data,people,gamets,localts,delivery_state)
            VALUES ('chat',$1,$2,$3,$4,'spoken') RETURNING rowid",
            ['Session Owner: Pleasant weather. (talking to '.$pt['player_name'].')',
             '|Session Owner|'.$pt['player_name'].'|', 292500100+$i, time()-60])['rowid'];
        check(scanOnce($pg, $pt, $settings, time()) === 1, 'one chronological batch queued');
        $job = claimJob($pg, $pt['id']);
        check($job !== null && $job['event_ids'] === [$event], 'one job claimed');
        $started = hrtime(true);
        check(processJob($job, $deps) === ['outcome'=>'done','reason'=>'committed'], 'negative evidence still commits replay bookkeeping');
        $timings[] = (hrtime(true)-$started)/1e6;
        // Simulate death after COMMIT but before finishJob; the same lease must replay safely.
        $pg->exec("UPDATE sas_it.sworn_scorned_jobs SET lease_until=now()-interval '1 second' WHERE id=$1", [$job['id']]);
        $retry = claimJob($pg, $pt['id']);
        check($retry !== null && processJob($retry, $deps) === ['outcome'=>'done','reason'=>'replay'], 'post-commit crash does not reevaluate negative evidence');
        finishJob($pg, $retry, ['outcome'=>'done','reason'=>'replay'], false);
    }
    check($calls === 240, 'exactly one provider pass per negative batch, including crash replays');
    $history = $pg->one('SELECT count(*) AS n, COALESCE(sum(pg_column_size(h)),0) AS bytes FROM sas_it.core_npc_master_history h');
    check((int)$history['n'] === 240, 'replays never add snapshots');
    $row = $pg->one('SELECT extended_data::text AS e, plugin_extended_data::text AS p FROM sas_it.core_npc_master WHERE id=900001');
    $plugins = json_decode($row['p'], true, 64, JSON_THROW_ON_ERROR);
    check($plugins['mind_poisoning']['marker'] === 1 && $plugins['sworn_and_scorned']['issues'] === [], 'negative session preserves unrelated plugin state and creates no issues');
    check(json_decode($row['e'], true) === [], 'negative session never creates relationship deltas');
    $before = $pg->all('SELECT * FROM sas_it.sworn_scorned_cursor');
    $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = true;
    for ($i=0; $i<20; ++$i) check(!workerTick($pg, $settings, time(), $transport), 'paused tick does no work');
    unset($GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA']);
    $brokenKey = $settings + ['_key_error'=>'key_storage_unavailable'];
    for ($i=0; $i<20; ++$i) check(!workerTick($pg, $brokenKey, time(), $transport), 'unreadable key tick does no work');
    check($calls === 240 && $before === $pg->all('SELECT * FROM sas_it.sworn_scorned_cursor'), 'repeated pauses neither call providers nor move cursor');

    // Core writers do not use our advisory lock: a separate connection's row lock must still protect the owner.
    $other = openEngineConnection('/var/www/html/HerikaServer');
    $other->exec('BEGIN');
    $other->exec('SELECT id FROM sas_it.core_npc_master WHERE id=900001 FOR UPDATE');
    $request = ['owner_npc_id'=>900001,'owner_name'=>'Session Owner','playthrough_id'=>$pt['id'],'event_ids'=>[], 'counterpart_name'=>null];
    $mutate = static function (array $ledger): array {
        $ledger['seeded'][] = 'concurrent-fixture';
        return ['status'=>'applied','ledger'=>$ledger,'affinity_delta'=>0];
    };
    $blocked = false;
    try { $store->commitLedger($request, $mutate); } catch (RuntimeException $error) { $blocked = $error->getMessage() === 'sql_failed'; }
    finally { $other->exec('ROLLBACK'); }
    check($blocked, 'core-style row lock prevents concurrent mutation within bounded timeout');
    check((int)$pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history')['n'] === 240, 'blocked commit adds no snapshot');
    check($store->commitLedger($request, $mutate) === 'committed', 'retry after core writer release succeeds; advisory lock was released');
    sort($timings);
    echo json_encode(['negative_batches'=>240,'crash_replays'=>240,'provider_calls'=>$calls,'pause_ticks'=>40,
        'history_rows_before_lock_test'=>(int)$history['n'],'history_bytes'=>(int)$history['bytes'],
        'commit_ms_median'=>$timings[120],'commit_ms_p95'=>$timings[228]], JSON_THROW_ON_ERROR), "\n";
} finally {
    unset($GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA']);
    $pg->exec('DROP SCHEMA IF EXISTS sas_it CASCADE');
    putenv('SAS_TEST_SCHEMA');
}
echo "session integration checks passed\n";
