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
    $addNpc = static function (int $id, string $name) use ($pg, $template): void {
        $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id = $1', [$template['id']]);
        $pg->exec("UPDATE sas_it.core_npc_master SET id = $1, npc_name = $2, plugin_extended_data = '{}'::jsonb, lock_profile = 0 WHERE id = $3", [$id, $name, $template['id']]);
    };
    $addNpc(900011, 'Olfrid IT');
    $edgeBefore = $pg->one('SELECT extended_data::text AS e FROM sas_it.core_npc_master WHERE id=900011')['e'];
    $seeds = [
        ['id' => 'it-feud', 'owner' => 'Olfrid IT', 'counterpart' => 'Eorlund IT', 'wants' => 'unknown', 'paraphrase' => 'Old feud.', 'source' => 'https://en.uesp.net/wiki/Skyrim:X'],
        ['id' => 'it-daedra', 'owner' => 'Olfrid IT', 'counterpart' => 'daedra:molag_bal', 'wants' => 'unknown', 'paraphrase' => 'Hates Molag Bal.', 'source' => 'https://en.uesp.net/wiki/Skyrim:Y'],
    ];
    $pt = \ChimSwornAndScorned\currentPlaythrough($pg);
    check($pt !== null, 'clone has a current scope');
    $store = new PerspectiveStore($pg);
    $ledger = static fn (): array => json_decode($pg->one("SELECT (plugin_extended_data->'sworn_and_scorned')::text AS l FROM sas_it.core_npc_master WHERE id = 900011")['l'] ?? 'null', true) ?? [];

    check(seedLoreOnce($pg, $store, $pt, $seeds) === 1, 'owner seeded once');
    check($ledger()['seeded'] === ['it-daedra'], 'daedra seed applied; unmet NPC counterpart deferred');
    check(seedLoreOnce($pg, $store, $pt, $seeds) === 0, 'nothing new to seed');
    $addNpc(900012, 'Eorlund IT');
    check(seedLoreOnce($pg, $store, $pt, $seeds) === 1, 'deferred seed applies once the counterpart exists');
    check($ledger()['seeded'] === ['it-daedra', 'it-feud'], 'both seeds marked');
    check((int) $pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history WHERE npc_id = 900011')['n'] === 2, 'one snapshot per applied seeding');
    check($pg->one('SELECT extended_data::text AS e FROM sas_it.core_npc_master WHERE id=900011')['e'] === $edgeBefore, 'lore seeding never changes affinity or other extended data');
    $addNpc(900013, 'OLFRID IT');
    check(seedLoreOnce($pg, $store, $pt, $seeds) === 0, 'ambiguous named owners are not seeded');
    $pg->exec('DELETE FROM sas_it.core_npc_master WHERE id=900013');
    $pg->exec("UPDATE sas_it.core_npc_master SET plugin_extended_data=jsonb_build_object('sworn_and_scorned',jsonb_build_object('playthrough_id',$1::text)) WHERE id=900011", [$pt['id']]);
    check(seedLoreOnce($pg, $store, $pt, $seeds) === 0, 'corrupt owner fails closed');
    check((int) $pg->one('SELECT count(*) AS n FROM sas_it.core_npc_master_history WHERE npc_id=900011')['n'] === 2, 'corrupt owner creates no history');
} finally {
    $pg->exec('DROP SCHEMA IF EXISTS sas_it CASCADE');
}

echo "lore integration checks passed\n";
