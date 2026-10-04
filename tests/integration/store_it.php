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
use const ChimSwornAndScorned\{JSON_FLAGS, POLICY_DECISION_MODEL};

if (!function_exists('chimLoadGeneralSettingsIntoGlobals')) {
    function chimLoadGeneralSettingsIntoGlobals(): void
    {
        $pg = $GLOBALS['sas_it_pg'] ?? null;
        if (!$pg instanceof \ChimSwornAndScorned\Pg) {
            return;
        }
        $setting = $pg->one("SELECT value FROM sas_it.general_settings WHERE id = 'NEVER_CLEAR_RELATIONSHIP_DATA'");
        if ($setting !== null) {
            $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = filter_var($setting['value'], FILTER_VALIDATE_BOOLEAN);
        }
    }
}

if (getenv('SAS_IT') !== '1') {
    echo "store integration skipped (set SAS_IT=1 inside the test clone)\n";
    exit(0);
}
check(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'integration tests run only in the test clone');
putenv('SAS_TEST_SCHEMA=sas_it');

$engine = '/var/www/html/HerikaServer';
require_once $engine . '/lib/relationship_manager.php';
$pg = openEngineConnection($engine);
$GLOBALS['sas_it_pg'] = $pg;
check($pg->one("SELECT to_regnamespace('sas_it') AS s")['s'] === null, 'fixture schema must be absent');
foreach ([
    'CREATE SCHEMA sas_it',
    'CREATE TABLE sas_it.general_settings (id text PRIMARY KEY, value text NOT NULL)',
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
    $pg->exec("INSERT INTO sas_it.general_settings (id, value) VALUES ('NEVER_CLEAR_RELATIONSHIP_DATA', 'false')");
    $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = false;
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

    $event = static function (string $data, string $people = '|Lydia IT|Farkas IT|') use ($pg): int {
        $row = $pg->one("INSERT INTO sas_it.eventlog (type, data, gamets, localts, people, delivery_state)
            VALUES ('chat', $1, 292500100, extract(epoch from now())::bigint, $2, 'spoken') RETURNING rowid", [$data, $people]);
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

    $pg->exec("UPDATE sas_it.core_npc_master SET extended_data = jsonb_build_object('relationships', '[]'::jsonb, 'n3_metadata', jsonb_build_object('keep', true)) WHERE id = 900001");
    $emptyNpcHistory = $historyCount();
    $emptyNpcOpenId = $event('Farkas IT: I will bring the shield. (talking to Lydia IT)');
    $emptyNpcOpenRequest = ['event_ids' => [$emptyNpcOpenId]] + $request;
    $emptyNpcOpenMeta = ['event_ids' => [$emptyNpcOpenId], 'counterpart' => 'npc:900002', 'backend' => 'it', 'policy' => POLICY_DECISION_MODEL];
    $emptyNpcPromise = ['new_issues' => [['kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'wants' => 'keep_promise', 'line' => sampleLine($emptyNpcOpenId, 'Farkas IT', 'I will bring the shield.', ['Lydia IT', 'Farkas IT']), 'label' => 'commitment', 'p' => 0.95]], 'updates' => []];
    check($store->commitLedger($emptyNpcOpenRequest, fn (array $ledger) => applyProposal($ledger, $emptyNpcPromise, $emptyNpcOpenMeta)) === 'committed', 'empty NPC relationships allow the opening promise commit');
    $emptyNpcBreakId = $event('Lydia IT: You did not bring the shield. (talking to Farkas IT)');
    $emptyNpcBreakRequest = ['event_ids' => [$emptyNpcBreakId]] + $request;
    $emptyNpcBreakMeta = ['event_ids' => [$emptyNpcBreakId], 'counterpart' => 'npc:900002', 'backend' => 'it', 'policy' => POLICY_DECISION_MODEL];
    $emptyNpcBreak = ['new_issues' => [], 'updates' => [['issue_id' => issueId('e:' . $emptyNpcOpenId, 'npc:900002', 'promise'), 'status' => 'broken', 'status_p' => 0.95, 'trajectory' => 'worsening', 'wants' => null, 'line' => sampleLine($emptyNpcBreakId, 'Lydia IT', 'You did not bring the shield.')]]];
    check($store->commitLedger($emptyNpcBreakRequest, fn (array $ledger) => applyProposal($ledger, $emptyNpcBreak, $emptyNpcBreakMeta)) === 'committed', 'empty NPC relationships allow the affinity transition to commit');
    $emptyNpcRelationships = json_decode($pg->one("SELECT extended_data::text AS e FROM sas_it.core_npc_master WHERE id = 900001")['e'], true);
    check($emptyNpcRelationships['relationships']['Farkas IT'] === ['aff' => -3, 'type' => 'neutral'], 'empty NPC relationships create the edge under the counterpart name');
    check($emptyNpcRelationships['n3_metadata'] === ['keep' => true] && $historyCount() === $emptyNpcHistory + 2, 'empty NPC relationship commit preserves owner metadata and snapshots both commits');
    $emptyNpcReplayHistory = $historyCount();
    check($store->commitLedger($emptyNpcBreakRequest, fn (array $ledger) => applyProposal($ledger, $emptyNpcBreak, $emptyNpcBreakMeta)) === 'replay', 'empty NPC relationship replay is a no-op');
    $emptyNpcRelationships = json_decode($pg->one("SELECT extended_data::text AS e FROM sas_it.core_npc_master WHERE id = 900001")['e'], true);
    check($emptyNpcRelationships['relationships']['Farkas IT']['aff'] === -3 && $historyCount() === $emptyNpcReplayHistory, 'empty NPC relationship replay adds no second affinity penalty or snapshot');

    $pg->exec('UPDATE sas_it.core_npc_master SET extended_data = jsonb_set(extended_data, \'{relationships}\', $1::jsonb, true) WHERE id = 900001', [json_encode([['aff' => 7, 'type' => 'friend']], JSON_FLAGS)]);
    $nonemptyArrayEvent = $event('Farkas IT: malformed relationship guard. (talking to Lydia IT)');
    $nonemptyArrayRequest = ['event_ids' => [$nonemptyArrayEvent]] + $request;
    $nonemptyArrayHistory = $historyCount();
    try {
        $nonemptyArrayStatus = $store->commitLedger(
            $nonemptyArrayRequest,
            static fn (array $ledger): array => ['status' => 'applied', 'ledger' => $ledger, 'affinity_delta' => -3]
        );
    } catch (\Throwable) {
        $nonemptyArrayStatus = 'threw';
    }
    $nonemptyArrayRelationships = json_decode($pg->one("SELECT (extended_data->'relationships')::text AS r FROM sas_it.core_npc_master WHERE id = 900001")['r'], true);
    check($nonemptyArrayStatus === 'edge_invalid' && $historyCount() === $nonemptyArrayHistory, 'non-empty NPC relationship arrays are rejected before commit or snapshot');
    check($nonemptyArrayRelationships === [['aff' => 7, 'type' => 'friend']], 'rejecting a non-empty NPC relationship array preserves its contents');

    $playerName = $scope['player_name'];
    $globalPlayerNameWasSet = array_key_exists('PLAYER_NAME', $GLOBALS);
    $globalPlayerNameBefore = $GLOBALS['PLAYER_NAME'] ?? null;
    $GLOBALS['PLAYER_NAME'] = $playerName;
    check(
        \RelationshipManager::normalizeTargetName(strtoupper($playerName)) === 'Player'
        && \RelationshipManager::normalizeTargetName('THE PLAYER') === 'Player'
        && \RelationshipManager::normalizeTargetName('Dragonborn') === 'Player',
        'test clone recognizes player name casing and legacy aliases'
    );
    $GLOBALS['PLAYER_NAME'] = 'Stale Player Name';
    $playerPeople = '|Lydia IT|' . $playerName . '|';
    $breakPlayerPromise = static function (string $requestName, string $label) use ($event, $store, $scope, $playerName, $playerPeople): array {
        $openText = $label . ' promise.';
        $openId = $event($playerName . ': I will keep the ' . $openText . ' (talking to Lydia IT)', $playerPeople);
        $openRequest = [
            'owner_npc_id' => 900001,
            'owner_name' => 'Lydia IT',
            'playthrough_id' => $scope['id'],
            'event_ids' => [$openId],
            'counterpart_name' => $requestName,
            'counterpart_key' => 'player',
        ];
        $openMeta = ['event_ids' => [$openId], 'counterpart' => 'player', 'backend' => 'it', 'policy' => POLICY_DECISION_MODEL];
        $promiseLine = sampleLine($openId, $playerName, 'I will keep the ' . $openText, ['Lydia IT', $playerName]);
        $promise = ['new_issues' => [['kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'wants' => 'keep_promise', 'line' => $promiseLine, 'label' => 'commitment', 'p' => 0.95]], 'updates' => []];
        check($store->commitLedger($openRequest, fn (array $ledger) => applyProposal($ledger, $promise, $openMeta)) === 'committed', $label . ' player promise commits');

        $breakText = $label . ' promise was broken.';
        $breakId = $event($playerName . ': You did not keep the ' . $breakText . ' (talking to Lydia IT)', $playerPeople);
        $request = $openRequest;
        $request['event_ids'] = [$breakId];
        $meta = ['event_ids' => [$breakId], 'counterpart' => 'player', 'backend' => 'it', 'policy' => POLICY_DECISION_MODEL];
        $break = ['new_issues' => [], 'updates' => [['issue_id' => issueId('e:' . $openId, 'player', 'promise'), 'status' => 'broken', 'status_p' => 0.95, 'trajectory' => 'worsening', 'wants' => null, 'line' => sampleLine($breakId, $playerName, 'You did not keep the ' . $breakText, ['Lydia IT', $playerName])]]];
        check($store->commitLedger($request, fn (array $ledger) => applyProposal($ledger, $break, $meta)) === 'committed', $label . ' player promise break commits');
        return ['request' => $request, 'proposal' => $break, 'meta' => $meta];
    };

    $relationships = [
        'Farkas IT' => ['aff' => 7, 'type' => 'friend'],
        'Player' => ['aff' => 50, 'type' => 'romantic', 'relation' => 'promised marriage', 'custom_info' => 'Keep this player-authored note', 'mod_extension' => ['source' => 'canonical']],
        strtoupper($playerName) => ['aff' => 51, 'type' => 'romantic'],
        'THE PLAYER' => ['aff' => 49, 'type' => 'romantic'],
        'Dragonborn' => ['aff' => 48, 'type' => 'romantic'],
    ];
    $pg->exec("UPDATE sas_it.core_npc_master SET extended_data = jsonb_set(COALESCE(extended_data, '{}'::jsonb), '{relationships}', $1::jsonb, true) WHERE id = 900001", [json_encode($relationships, JSON_FLAGS)]);
    $playerReplayCase = $breakPlayerPromise(strtoupper($playerName), 'first');
    check($GLOBALS['PLAYER_NAME'] === 'Stale Player Name', 'player normalization restores the existing PLAYER_NAME global');
    $storedRelationships = json_decode($pg->one("SELECT (extended_data->'relationships')::text AS r FROM sas_it.core_npc_master WHERE id = 900001")['r'], true);
    check($storedRelationships['Player']['aff'] === 47, 'canonical romantic player affinity 50 - 3 = 47');
    check(
        $storedRelationships['Player']['type'] === 'romantic'
        && $storedRelationships['Player']['relation'] === 'promised marriage'
        && $storedRelationships['Player']['custom_info'] === 'Keep this player-authored note'
        && $storedRelationships['Player']['mod_extension'] === ['source' => 'canonical'],
        'player affinity update preserves known and unknown relationship metadata'
    );
    check(
        !array_key_exists(strtoupper($playerName), $storedRelationships)
        && !array_key_exists('THE PLAYER', $storedRelationships)
        && !array_key_exists('Dragonborn', $storedRelationships),
        'canonical update removes duplicate player aliases'
    );
    $previousPlayerName = $GLOBALS['PLAYER_NAME'];
    $GLOBALS['PLAYER_NAME'] = $playerName;
    $normalizedRelationships = \RelationshipManager::normalizeRelationshipMap($storedRelationships);
    $GLOBALS['PLAYER_NAME'] = $previousPlayerName;
    check($normalizedRelationships['Player']['aff'] === 47, 'CHIM normalization cannot restore a stale player alias over the updated edge');
    check($storedRelationships['Farkas IT'] === ['aff' => 7, 'type' => 'friend'], 'player update leaves the NPC relationship intact');
    $playerReplayHistory = $historyCount();
    check($store->commitLedger($playerReplayCase['request'], fn (array $ledger) => applyProposal($ledger, $playerReplayCase['proposal'], $playerReplayCase['meta'])) === 'replay', 'player break replay is a no-op');
    $storedRelationships = json_decode($pg->one("SELECT (extended_data->'relationships')::text AS r FROM sas_it.core_npc_master WHERE id = 900001")['r'], true);
    check($storedRelationships['Player']['aff'] === 47 && $historyCount() === $playerReplayHistory, 'player replay adds no second affinity penalty or snapshot');

    $relationships = ['Farkas IT' => ['aff' => 7, 'type' => 'friend'], 'Player' => ['aff' => 1, 'type' => 'neutral', 'custom_info' => 'Keep second note']];
    $pg->exec("UPDATE sas_it.core_npc_master SET extended_data = jsonb_set(COALESCE(extended_data, '{}'::jsonb), '{relationships}', $1::jsonb, true) WHERE id = 900001", [json_encode($relationships, JSON_FLAGS)]);
    $breakPlayerPromise(strtolower($playerName), 'second');
    $storedRelationships = json_decode($pg->one("SELECT (extended_data->'relationships')::text AS r FROM sas_it.core_npc_master WHERE id = 900001")['r'], true);
    check($storedRelationships['Player']['aff'] === -2 && $storedRelationships['Player']['custom_info'] === 'Keep second note', 'case-variant player target updates the existing 1 affinity edge to -2');

    $relationships = [
        'Farkas IT' => ['aff' => 7, 'type' => 'friend'],
        'the player' => ['aff' => 12, 'type' => 'romantic', 'note' => 'Alias-only note', 'custom_info' => 'Alias-only player note', 'mod_extension' => ['source' => 'legacy alias']],
    ];
    $pg->exec("UPDATE sas_it.core_npc_master SET extended_data = jsonb_set(COALESCE(extended_data, '{}'::jsonb), '{relationships}', $1::jsonb, true) WHERE id = 900001", [json_encode($relationships, JSON_FLAGS)]);
    $breakPlayerPromise(strtoupper($playerName), 'alias-only');
    $storedRelationships = json_decode($pg->one("SELECT (extended_data->'relationships')::text AS r FROM sas_it.core_npc_master WHERE id = 900001")['r'], true);
    check(
        $storedRelationships['Player']['aff'] === 9
        && $storedRelationships['Player']['type'] === 'romantic'
        && $storedRelationships['Player']['note'] === 'Alias-only note'
        && $storedRelationships['Player']['custom_info'] === 'Alias-only player note'
        && $storedRelationships['Player']['mod_extension'] === ['source' => 'legacy alias']
        && !array_key_exists('the player', $storedRelationships),
        'alias-only player edge carries its metadata into canonical Player'
    );

    $relationships = [
        'Farkas IT' => ['aff' => 7, 'type' => 'friend'],
        'Player' => ['aff' => 1, 'type' => 'neutral', 'custom_info' => 'Keep canonical player note'],
        'Dragonborn' => ['aff' => 50, 'type' => 'romantic', 'note' => 'Stronger alias note', 'custom_info' => 'Do not replace canonical note', 'mod_extension' => ['source' => 'strong alias']],
    ];
    $pg->exec("UPDATE sas_it.core_npc_master SET extended_data = jsonb_set(COALESCE(extended_data, '{}'::jsonb), '{relationships}', $1::jsonb, true) WHERE id = 900001", [json_encode($relationships, JSON_FLAGS)]);
    $breakPlayerPromise(strtolower($playerName), 'weaker-canonical');
    $storedRelationships = json_decode($pg->one("SELECT (extended_data->'relationships')::text AS r FROM sas_it.core_npc_master WHERE id = 900001")['r'], true);
    check(
        $storedRelationships['Player']['aff'] === 47
        && $storedRelationships['Player']['type'] === 'romantic'
        && $storedRelationships['Player']['note'] === 'Stronger alias note'
        && $storedRelationships['Player']['custom_info'] === 'Keep canonical player note'
        && $storedRelationships['Player']['mod_extension'] === ['source' => 'strong alias']
        && !array_key_exists('Dragonborn', $storedRelationships),
        'CHIM weight chooses a stronger legacy alias while retaining canonical custom_info'
    );

    $relationships = ['Farkas IT' => ['aff' => 7, 'type' => 'friend']];
    $pg->exec("UPDATE sas_it.core_npc_master SET extended_data = jsonb_set(COALESCE(extended_data, '{}'::jsonb), '{relationships}', $1::jsonb, true) WHERE id = 900001", [json_encode($relationships, JSON_FLAGS)]);
    $noPlayerEdgeBreak = $breakPlayerPromise($playerName, 'third');
    $storedRelationships = json_decode($pg->one("SELECT (extended_data->'relationships')::text AS r FROM sas_it.core_npc_master WHERE id = 900001")['r'], true);
    check($storedRelationships['Player'] === ['aff' => -3, 'type' => 'neutral'], 'missing player edge starts at zero and receives the -3 penalty under Player');
    $noPlayerEdgeHistory = $historyCount();
    check($store->commitLedger($noPlayerEdgeBreak['request'], fn (array $ledger) => applyProposal($ledger, $noPlayerEdgeBreak['proposal'], $noPlayerEdgeBreak['meta'])) === 'replay', 'absent-edge player break replay is rejected');
    $storedRelationships = json_decode($pg->one("SELECT extended_data->'relationships'::text AS r FROM sas_it.core_npc_master WHERE id = 900001")['r'], true);
    check($storedRelationships['Player']['aff'] === -3 && $historyCount() === $noPlayerEdgeHistory, 'absent-edge replay does not apply another penalty');

    $pg->exec("UPDATE sas_it.core_npc_master SET extended_data = jsonb_build_object('relationships', '[]'::jsonb, 'n3_metadata', jsonb_build_object('keep', true)) WHERE id = 900001");
    $emptyPlayerHistory = $historyCount();
    $emptyPlayerReplayCase = $breakPlayerPromise($playerName, 'empty-relationships');
    $storedRelationships = json_decode($pg->one("SELECT extended_data::text AS e FROM sas_it.core_npc_master WHERE id = 900001")['e'], true);
    check($storedRelationships['relationships']['Player'] === ['aff' => -3, 'type' => 'neutral'], 'empty Player relationships create the canonical Player edge');
    check($storedRelationships['n3_metadata'] === ['keep' => true] && $historyCount() === $emptyPlayerHistory + 2, 'empty Player relationship commit preserves owner metadata and snapshots both commits');
    $emptyPlayerReplayHistory = $historyCount();
    check($store->commitLedger($emptyPlayerReplayCase['request'], fn (array $ledger) => applyProposal($ledger, $emptyPlayerReplayCase['proposal'], $emptyPlayerReplayCase['meta'])) === 'replay', 'empty Player relationship replay is a no-op');
    $storedRelationships = json_decode($pg->one("SELECT extended_data::text AS e FROM sas_it.core_npc_master WHERE id = 900001")['e'], true);
    check($storedRelationships['relationships']['Player']['aff'] === -3 && $historyCount() === $emptyPlayerReplayHistory, 'empty Player relationship replay adds no second affinity penalty or snapshot');

    $GLOBALS['PLAYER_NAME'] = $playerName;
    if ($globalPlayerNameWasSet) {
        $GLOBALS['PLAYER_NAME'] = $globalPlayerNameBefore;
    } else {
        unset($GLOBALS['PLAYER_NAME']);
    }

    $lockedPlayerRelationships = ['Farkas IT' => ['aff' => 7, 'type' => 'friend'], 'Player' => ['aff' => 5, 'type' => 'neutral']];
    $pg->exec("UPDATE sas_it.core_npc_master SET lock_profile = 1, extended_data = jsonb_set(COALESCE(extended_data, '{}'::jsonb), '{relationships}', $1::jsonb, true) WHERE id = 900001", [json_encode($lockedPlayerRelationships, JSON_FLAGS)]);
    $lockedPlayerEvent = $event($playerName . ': locked profile transition', $playerPeople);
    $lockedPlayerRequest = ['owner_npc_id' => 900001, 'owner_name' => 'Lydia IT', 'playthrough_id' => $scope['id'], 'event_ids' => [$lockedPlayerEvent], 'counterpart_name' => $playerName, 'counterpart_key' => 'player'];
    $applyLockedPenalty = static fn (array $ledger): array => ['status' => 'applied', 'ledger' => $ledger, 'affinity_delta' => -3];
    check($store->commitLedger($lockedPlayerRequest, $applyLockedPenalty) === 'committed', 'locked player edge still records the ledger commit');
    $lockedPlayerAffinity = (int) $pg->one("SELECT extended_data->'relationships'->'Player'->>'aff' AS a FROM sas_it.core_npc_master WHERE id = 900001")['a'];
    check($lockedPlayerAffinity === 5, 'profile-locked canonical player affinity stays unchanged');
    $pg->exec('UPDATE sas_it.core_npc_master SET lock_profile = 0, extended_data = jsonb_set(extended_data, \'{relationships_locked}\', \'true\'::jsonb, true) WHERE id = 900001');
    $lockedRelationshipsEvent = $event($playerName . ': locked relationships transition', $playerPeople);
    $lockedPlayerRequest['event_ids'] = [$lockedRelationshipsEvent];
    check($store->commitLedger($lockedPlayerRequest, $applyLockedPenalty) === 'committed', 'relationships-locked player edge still records the ledger commit');
    $lockedPlayerAffinity = (int) $pg->one("SELECT extended_data->'relationships'->'Player'->>'aff' AS a FROM sas_it.core_npc_master WHERE id = 900001")['a'];
    check($lockedPlayerAffinity === 5, 'relationships-locked canonical player affinity stays unchanged');
    $pg->exec("UPDATE sas_it.core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships_locked}', 'false'::jsonb, true) WHERE id = 900001");

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

    $policyEvent = $event('Farkas IT: policy refresh check (talking to Lydia IT)');
    $policyRequest = ['event_ids' => [$policyEvent]] + $request;
    $policyMutation = static fn (array $ledger): array => ['status' => 'applied', 'ledger' => $ledger, 'affinity_delta' => 0];
    $pg->exec("UPDATE sas_it.general_settings SET value = 'true' WHERE id = 'NEVER_CLEAR_RELATIONSHIP_DATA'");
    $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = false;
    $policyHistory = $historyCount();
    check($store->commitLedger($policyRequest, $policyMutation) === 'restore_policy', 'commit reloads a newly enabled restore policy');
    check($historyCount() === $policyHistory, 'refreshed restore policy prevents a commit and history snapshot');
} finally {
    $pg->exec('DROP SCHEMA IF EXISTS sas_it CASCADE');
    putenv('SAS_TEST_SCHEMA');
    unset($GLOBALS['sas_it_pg'], $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA']);
}

echo "store integration checks passed\n";
