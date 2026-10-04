<?php
declare(strict_types=1);

require __DIR__ . '/../support/check.php';
require __DIR__ . '/../support/fixtures.php';
require __DIR__ . '/../../server/page_actions.php';
require __DIR__ . '/../../server/page_model.php';

use function ChimSwornAndScorned\{buildPageModel, currentPlaythrough, emptyLedger, knownNpcIds, openEngineConnection, pageCounterpartName, parsePageAction, performPageAction};

check(pageCounterpartName('daedra:hermaeus_mora', null, []) === 'Hermaeus Mora', 'Daedric counterpart uses the lore name catalog');

if (getenv('SAS_IT') !== '1') {
    echo "page integration skipped (set SAS_IT=1 inside the test clone)\n";
    exit(0);
}
check(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'integration tests run only in the test clone');
putenv('SAS_TEST_SCHEMA=sas_it');

$engineRoot = '/var/www/html/HerikaServer';
$pg = openEngineConnection($engineRoot);
check($pg->one("SELECT to_regnamespace('sas_it') AS s")['s'] === null, 'fixture schema must be absent');
foreach ([
    'CREATE SCHEMA sas_it',
    'CREATE TABLE sas_it.core_npc_master (LIKE public.core_npc_master INCLUDING ALL)',
    'CREATE TABLE sas_it.core_npc_master_history (LIKE public.core_npc_master_history INCLUDING ALL)',
    'CREATE SEQUENCE sas_it.history_id_seq',
    "ALTER TABLE sas_it.core_npc_master_history ALTER history_id SET DEFAULT nextval('sas_it.history_id_seq')",
    'SET search_path = sas_it, public',
] as $sql) {
    $pg->exec($sql);
}

try {
    $template = $pg->one("SELECT id FROM public.core_npc_master WHERE npc_name <> 'The Narrator' ORDER BY id LIMIT 1");
    check($template !== null, 'clone has a source NPC');
    $scope = currentPlaythrough($pg);
    check($scope !== null, 'clone has one current playthrough');
    $scopeId = $scope['id'];
    $ownerId = 900001;
    $counterpartId = 900002;

    $cloneNpc = static function (int $id, string $name, array $ledger) use ($pg, $template): void {
        $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id = $1', [$template['id']]);
        $plugins = json_encode(['mind_poisoning' => ['marker' => 1], 'sworn_and_scorned' => $ledger], JSON_THROW_ON_ERROR);
        $extended = json_encode(['relationships' => ['Farkas Fixture' => ['aff' => 17, 'type' => 'friend']]], JSON_THROW_ON_ERROR);
        $pg->exec(
            'UPDATE sas_it.core_npc_master SET id = $1, npc_name = $2, lock_profile = 0, extended_data = $3::jsonb, plugin_extended_data = $4::jsonb WHERE id = $5',
            [$id, $name, $extended, $plugins, $template['id']]
        );
    };

    $ledger = emptyLedger($scopeId);
    $ledger['issues'][] = sampleIssue(['counterpart' => 'npc:' . $counterpartId]);
    $ledger['issues'][] = sampleIssue([
        'id' => 'def456def456def4',
        'counterpart' => 'daedra:hermaeus_mora',
        'changed_event_id' => 11,
    ]);
    $cloneNpc($ownerId, 'Z Cast Target', $ledger);
    $cloneNpc($counterpartId, 'Farkas Fixture', emptyLedger($scopeId));
    for ($i = 0; $i < 61; $i++) {
        $cloneNpc(910000 + $i, sprintf('A Seed %03d', $i), emptyLedger($scopeId));
    }

    ChimSwornAndScorned\ensureSchema($pg);
    $known = knownNpcIds($pg);
    $settingsAction = parsePageAction(
        ['action' => 'save_settings', 'cast' => [(string) $ownerId], 'model' => 'typesafe/jev-1.13'],
        $known
    );
    check(performPageAction($pg, $settingsAction, $engineRoot, __DIR__) === 'Settings saved.', 'disabled settings save succeeds without starting a worker');

    $pg->exec("CREATE FUNCTION sas_it.reject_model_setting() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.key = 'model' THEN RAISE EXCEPTION 'fixture rejection'; END IF; RETURN NEW; END \$\$");
    $pg->exec("CREATE TRIGGER reject_model BEFORE INSERT OR UPDATE ON sas_it.sworn_scorned_settings FOR EACH ROW EXECUTE FUNCTION sas_it.reject_model_setting()");
    $beforeSettings = \ChimSwornAndScorned\loadSettings($pg);
    $failed = false;
    try {
        performPageAction($pg, parsePageAction(
            ['action' => 'save_settings', 'enabled' => '1', 'cast' => [(string) $counterpartId], 'model' => 'other/model'],
            $known
        ), $engineRoot, __DIR__);
    } catch (\RuntimeException) {
        $failed = true;
    }
    check($failed, 'injected final settings write fails');
    check(\ChimSwornAndScorned\loadSettings($pg) === $beforeSettings, 'settings fields roll back together');
    $pg->exec('DROP TRIGGER reject_model ON sas_it.sworn_scorned_settings');
    $pg->exec('DROP FUNCTION sas_it.reject_model_setting()');

    $pg->exec(
        "INSERT INTO sas_it.sworn_scorned_jobs (playthrough_id, owner_npc_id, owner_name, counterpart_name, event_ids, status, last_error)
         VALUES ($1, $2, 'Z Cast Target', 'Pending Fixture', '{}'::bigint[], 'pending', NULL),
                ($1, $2, 'Z Cast Target', 'Failed Fixture', '{1}'::bigint[], 'failed', 'http_401'),
                ('old-playthrough', $2, 'Z Cast Target', 'Old Fixture', '{2}'::bigint[], 'failed', 'http_503')",
        [$scopeId, $ownerId]
    );
    $model = buildPageModel($pg, $engineRoot, false);
    $castEntry = null;
    foreach ($model['journal'] as $entry) {
        if ($entry['npc_id'] === $ownerId) {
            $castEntry = $entry;
            break;
        }
    }
    check($castEntry !== null, 'cast row is not lost behind noncast seed rows');
    check($castEntry['evaluations'] === ['pending' => 1, 'running' => 0, 'failed' => 1], 'per-cast evaluations are scoped to the current playthrough');
    check($model['worker']['jobs'] === ['pending' => 1, 'running' => 0, 'failed' => 1], 'worker counts exclude another playthrough');
    check($model['worker']['last_failure'] === 'http_401', 'last failure is selected from the current playthrough');
    check(count($castEntry['issues']) === 2 && $castEntry['issues'][0]['counterpart_name'] === 'Farkas Fixture', 'NPC counterpart is resolved safely');
    check($castEntry['issues'][1]['counterpart_name'] === 'Hermaeus Mora', 'Daedric counterpart is resolved from lore names');

    $historyCount = static fn (): int => (int) $pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history WHERE npc_id = $1', [$ownerId])['n'];
    $historyBefore = $historyCount();
    $flag = ['action' => 'issue_flag', 'npc_id' => $ownerId, 'issue_id' => 'abc123abc123abc1', 'flag' => 'mute'];
    check(performPageAction($pg, $flag, $engineRoot, __DIR__) === 'Updated.', 'OOC mute commits');
    $row = $pg->one('SELECT plugin_extended_data::text AS p, extended_data->\'relationships\'->\'Farkas Fixture\'->>\'aff\' AS aff FROM sas_it.core_npc_master WHERE id = $1', [$ownerId]);
    $plugins = json_decode($row['p'], true);
    check($plugins['mind_poisoning'] === ['marker' => 1] && $plugins['sworn_and_scorned']['issues'][0]['muted'] === true, 'mute changes only the owned ledger flag');
    check((int) $row['aff'] === 17 && $historyCount() === $historyBefore + 1, 'mute leaves affinity unchanged and snapshots the row');

    $historyBefore = $historyCount();
    $flag['flag'] = 'dismiss';
    check(performPageAction($pg, $flag, $engineRoot, __DIR__) === 'Updated.', 'OOC dismiss commits');
    $row = $pg->one('SELECT plugin_extended_data::text AS p, extended_data->\'relationships\'->\'Farkas Fixture\'->>\'aff\' AS aff FROM sas_it.core_npc_master WHERE id = $1', [$ownerId]);
    $plugins = json_decode($row['p'], true);
    check(\issueById($plugins['sworn_and_scorned'], 'abc123abc123abc1')['dismissed'] === true, 'dismissed issue remains in the ledger');
    check((int) $row['aff'] === 17 && $historyCount() === $historyBefore + 1, 'dismiss leaves affinity unchanged and snapshots the row');

    $pg->exec("UPDATE sas_it.core_npc_master SET plugin_extended_data = jsonb_set(plugin_extended_data, '{sworn_and_scorned}', '{}'::jsonb) WHERE id = $1", [$ownerId]);
    $historyBefore = $historyCount();
    $corruptRejected = false;
    try {
        performPageAction($pg, $flag, $engineRoot, __DIR__);
    } catch (\UnexpectedValueException) {
        $corruptRejected = true;
    }
    check($corruptRejected, 'corrupt ledger is rejected by the locked store');
    check($historyCount() === $historyBefore, 'corrupt ledger creates no replacement snapshot');
    $corruptModel = buildPageModel($pg, $engineRoot, false);
    $castEntry = null;
    foreach ($corruptModel['journal'] as $entry) {
        if ($entry['npc_id'] === $ownerId) {
            $castEntry = $entry;
            break;
        }
    }
    check($castEntry !== null && $castEntry['ledger_invalid'] === true && $castEntry['issues'] === [], 'corrupt cast ledger is surfaced as unavailable, not empty or overwritten');
} finally {
    $pg->exec('DROP SCHEMA IF EXISTS sas_it CASCADE');
    putenv('SAS_TEST_SCHEMA');
}

echo "page integration checks passed\n";
