<?php
declare(strict_types=1);

require __DIR__ . '/../support/check.php';
require __DIR__ . '/../support/fixtures.php';
require __DIR__ . '/../../server/store.php';
require __DIR__ . '/../../server/backend.php';
require __DIR__ . '/../../server/apply.php';

use ChimSwornAndScorned\{Pg, PerspectiveStore};
use function ChimSwornAndScorned\{applyProposal, currentPlaythrough, issueId, openEngineConnection};
use function ChimSwornAndScorned\{ensureSchema, saveSetting};
use const ChimSwornAndScorned\POLICY_DECISION_MODEL;

if (getenv('SAS_IT') !== '1') {
    echo "store integration skipped (set SAS_IT=1 inside the test clone)\n";
    exit(0);
}
check(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'integration tests run only in the test clone');
putenv('SAS_TEST_SCHEMA=sas_it');

$engine = '/var/www/html/HerikaServer';
$pg = openEngineConnection($engine);
check($pg->one("SELECT to_regnamespace('sas_it') AS s")['s'] === null, 'fixture schema must be absent');
foreach ([
    'CREATE SCHEMA sas_it',
    'CREATE TABLE sas_it.core_npc_master (LIKE public.core_npc_master INCLUDING ALL)',
    'CREATE TABLE sas_it.core_npc_master_history (LIKE public.core_npc_master_history INCLUDING ALL)',
    'CREATE TABLE sas_it.eventlog (LIKE public.eventlog INCLUDING ALL)',
    'CREATE SEQUENCE sas_it.eventlog_id_seq',
    "ALTER TABLE sas_it.eventlog ALTER rowid SET DEFAULT nextval('sas_it.eventlog_id_seq')",
    'CREATE SEQUENCE sas_it.history_id_seq',
    "ALTER TABLE sas_it.core_npc_master_history ALTER history_id SET DEFAULT nextval('sas_it.history_id_seq')",
    'SET search_path = sas_it, public',
] as $sql) {
    $pg->exec($sql);
}

try {
    ensureSchema($pg);
    saveSetting($pg, 'enabled', true);
    saveSetting($pg, 'cast', [900001]);
    $template = $pg->one("SELECT id FROM public.core_npc_master WHERE npc_name <> 'The Narrator' ORDER BY id LIMIT 1");
    check($template !== null, 'clone has an NPC row to copy');
    $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id = $1', [$template['id']]);
    $pg->exec("UPDATE sas_it.core_npc_master SET id = 900001, npc_name = 'Lydia IT', lock_profile = 0,
        extended_data = jsonb_build_object('relationships', jsonb_build_object('Farkas IT', jsonb_build_object('aff', 10, 'type', 'friend'))),
        plugin_extended_data = jsonb_build_object('mind_poisoning', jsonb_build_object('marker', 1)),
        gamets_last_updated = 0");
    $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id = $1', [$template['id']]);
    $pg->exec("UPDATE sas_it.core_npc_master SET id = 900002, npc_name = 'Farkas IT' WHERE id = $1", [$template['id']]);

    $event = static function (string $data) use ($pg): int {
        $row = $pg->one("INSERT INTO sas_it.eventlog (type, data, gamets, localts, people, delivery_state)
            VALUES ('chat', $1, 292500100, extract(epoch from now())::bigint, '|Lydia IT|Farkas IT|', 'spoken') RETURNING rowid", [$data]);
        return (int) $row['rowid'];
    };
    $historyCount = static fn (): int => (int) $pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history WHERE npc_id = 900001')['n'];

    $store = new PerspectiveStore($pg);
    $e1 = $event('Farkas IT: I will stand with you before Aela. (talking to Lydia IT)');
    $scope = currentPlaythrough($pg);
    check($scope !== null, 'clone has one current scope');
    $request = ['owner_npc_id' => 900001, 'owner_name' => 'Lydia IT', 'playthrough_id' => $scope['id'], 'event_ids' => [$e1], 'counterpart_name' => 'Farkas IT', 'counterpart_key' => 'npc:900002'];
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
    check($historyCount() === $before + 1 && $snapshot['source'] === 'relationship' && $snapshot['pt'] === $scope['id'], 'snapshot written');

    check($store->commitLedger($request, fn (array $ledger) => applyProposal($ledger, $promise, $meta)) === 'replay', 'replay is a no-op');
    check($historyCount() === $before + 1, 'replay adds no snapshot');

    $e2 = $event('Lydia IT: You stayed silent. (talking to Farkas IT)');
    $promiseId = issueId('e:' . $e1, 'npc:900002', 'promise');
    $break = ['new_issues' => [], 'updates' => [['issue_id' => $promiseId, 'status' => 'broken', 'status_p' => 0.95, 'trajectory' => 'worsening', 'wants' => null, 'line' => sampleLine($e2, 'Lydia IT', 'You stayed silent.')]]];
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
    $kept = ['new_issues' => [], 'updates' => [['issue_id' => issueId('e:' . $e5, 'npc:900002', 'promise'), 'status' => 'fulfilled', 'status_p' => 0.95, 'trajectory' => 'improving', 'wants' => null, 'line' => sampleLine($e6, 'Farkas IT', 'Here is the mead.')]]];
    check($store->commitLedger(['event_ids' => [$e6]] + $request, fn (array $ledger) => applyProposal($ledger, $kept, ['event_ids' => [$e6]] + $meta)) === 'committed', 'locked profile still records the issue');
    $aff = (int) $pg->one("SELECT extended_data->'relationships'->'Farkas IT'->>'aff' AS a FROM sas_it.core_npc_master WHERE id = 900001")['a'];
    check($aff === 7, 'locked profile gets no affinity change');
    $unchanged = $historyCount();
    check($store->commitLedger(['playthrough_id' => 'stale-playthrough'] + $request, fn (array $ledger) => applyProposal($ledger, $empty, $meta)) === 'playthrough_changed', 'scope rechecked within commit');
    check($historyCount() === $unchanged, 'stale scope adds no snapshot');

    $pg->exec("UPDATE sas_it.core_npc_master SET npc_name = 'Renamed IT' WHERE id = 900002");
    $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id = $1', [$template['id']]);
    $pg->exec("UPDATE sas_it.core_npc_master SET id = 900003, npc_name = 'Farkas IT' WHERE id = $1", [$template['id']]);
    $rejected['counterpart_changed'] = $store->commitLedger($request, fn (array $ledger) => applyProposal($ledger, $empty, $meta));
    $pg->exec('DELETE FROM sas_it.core_npc_master WHERE id = 900003');
    $pg->exec("UPDATE sas_it.core_npc_master SET npc_name = 'Farkas IT' WHERE id = 900002");
    saveSetting($pg, 'enabled', false);
    $rejected['disabled'] = $store->commitLedger($request, fn (array $ledger) => applyProposal($ledger, $empty, $meta));
    saveSetting($pg, 'enabled', true);
    saveSetting($pg, 'cast', []);
    $rejected['cast_changed'] = $store->commitLedger($request, fn (array $ledger) => applyProposal($ledger, $empty, $meta));
    saveSetting($pg, 'cast', [900001]);
    echo json_encode($rejected), "\n";
    check(array_keys($rejected) === array_values($rejected), 'in-flight counterpart, disable and cast changes are all rejected');
    check($store->commitLedger(['counterpart_key' => 'player', 'counterpart_name' => 'Retired Player Name'] + $request, fn (array $ledger) => applyProposal($ledger, $empty, $meta)) === 'counterpart_changed', 'stale player identity is rejected');
    check($historyCount() === $unchanged, 'rejected asynchronous commits add no snapshot');

    $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = true;
    check($store->commitLedger($request, fn (array $ledger) => applyProposal($ledger, $empty, $meta)) === 'restore_policy', 'restore policy blocks writes');
    unset($GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA']);
} finally {
    $pg->exec('DROP SCHEMA IF EXISTS sas_it CASCADE');
    putenv('SAS_TEST_SCHEMA');
}

echo "store integration checks passed\n";
