<?php
declare(strict_types=1);

require __DIR__ . '/../support/check.php';
require __DIR__ . '/../support/fixtures.php';
require __DIR__ . '/../../server/hook.php';

use function ChimSwornAndScorned\{currentPlaythrough, emptyLedger, ensureSchema, openEngineConnection, saveSetting, surfaceForSpeaker};

if (getenv('SAS_IT') !== '1') {
    echo "hook integration skipped (set SAS_IT=1 inside the test clone)\n";
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
    ensureSchema($pg);
    saveSetting($pg, 'enabled', true);
    $pt = currentPlaythrough($pg);
    check($pt !== null, 'clone has a resolvable playthrough');
    $template = $pg->one("SELECT id FROM public.core_npc_master WHERE npc_name <> 'The Narrator' ORDER BY id LIMIT 1");
    $ledger = emptyLedger($pt['id']);
    $ledger['issues'][] = sampleIssue(['id' => 'g000000000000001', 'counterpart' => 'npc:900002']);
    foreach ([[900001, 'Lydia IT', $ledger], [900002, 'Farkas IT', null]] as [$id, $name, $data]) {
        $pg->exec('INSERT INTO sas_it.core_npc_master SELECT * FROM public.core_npc_master WHERE id = $1', [$template['id']]);
        $pg->exec(
            "UPDATE sas_it.core_npc_master SET id = $1, npc_name = $2,
                plugin_extended_data = CASE WHEN $3::text IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('sworn_and_scorned', $3::jsonb) END
             WHERE id = $4",
            [$id, $name, $data === null ? null : json_encode($data), $template['id']]
        );
    }
    $request = ['inputtext', '1', '2', $pt['player_name'] . ': Hello there.'];
    $now = time();
    $issue = $ledger['issues'][0];
    $pg->exec(
        'INSERT INTO sas_it.sworn_scorned_pacing (npc_id, issue_id, surfaced_at, evidence_count, evidence_ref) VALUES ($1, $2, $3, $4, $5)',
        [900001, $issue['id'], $now - 1, count($issue['evidence']), $issue['evidence'][array_key_last($issue['evidence'])]['ref']]
    );

    check(surfaceForSpeaker($pg, $request, 'Lydia IT', '|Lydia IT|' . $pt['player_name'] . '|', $now) === null, 'no note when the counterpart is absent');
    $note = surfaceForSpeaker($pg, $request, 'Lydia IT', '|Lydia IT|Farkas IT|', $now);
    check(is_string($note) && str_starts_with($note, 'On your mind: unresolved with Farkas IT.'), 'note when the counterpart is present');
    $pacingIssueId = hash('sha256', $pt['id']) . ':' . $issue['id'];
    check((int) $pg->one('SELECT count(*) AS n FROM sas_it.sworn_scorned_pacing WHERE npc_id = $1 AND issue_id = $2', [900001, $pacingIssueId])['n'] === 1, 'pacing is stored under the current playthrough');
    check(surfaceForSpeaker($pg, $request, 'Lydia IT', '|Lydia IT|Farkas IT|', $now + 10) === null, 'cooldown suppresses a repeat');
    $named = ['inputtext', '1', '2', $pt['player_name'] . ': What is wrong between you and Farkas IT?'];
    check(is_string(surfaceForSpeaker($pg, $named, 'Lydia IT', '|Lydia IT|Farkas IT|' . mb_strtolower($pt['player_name']) . '|', $now + 20)), 'case-varied present player naming the counterpart overrides the cooldown');
    check(surfaceForSpeaker($pg, $named, 'Lydia IT', '|Lydia IT|Farkas IT|', $now + 21) === null, 'excluded player cannot override scene pacing');
    check(surfaceForSpeaker($pg, $request, 'Farkas IT', '|Lydia IT|Farkas IT|', $now) === null, 'other speakers do not get Lydia\'s note');
    $latest = $ledger;
    $latest['issues'][0]['evidence'][0]['ref'] = 'e:99';
    $pg->exec("UPDATE sas_it.core_npc_master SET plugin_extended_data=jsonb_build_object('sworn_and_scorned',$1::jsonb) WHERE id=900001", [json_encode($latest)]);
    check(is_string(surfaceForSpeaker($pg, $request, 'Lydia IT', '|Lydia IT|Farkas IT|', $now + 22)), 'new evidence reference bypasses pacing even at the same count');

    $daedra = emptyLedger($pt['id']);
    $daedra['issues'][] = sampleIssue(['id' => 'prince', 'counterpart' => 'daedra:molag_bal', 'origin' => 'lore']);
    $pg->exec("UPDATE sas_it.core_npc_master SET plugin_extended_data=jsonb_build_object('sworn_and_scorned',$1::jsonb) WHERE id=900001", [json_encode($daedra)]);
    $speech = static function (string $people, string $state, string $type = 'chat') use ($pg, $now): void {
        $pg->exec("INSERT INTO sas_it.eventlog(type,data,people,delivery_state,localts,gamets) VALUES($1,'Farkas IT: Molag Bal is cruel. (talking to Lydia IT)',$2,$3,$4,100)", [$type,$people,$state,$now]);
    };
    $instruction = ['instruction', '1', '2', 'The Narrator: Tell Lydia about Molag Bal.'];
    $scene = '|Lydia IT|Farkas IT|';
    $speech('|Other IT|Farkas IT|', 'spoken');
    $speech($scene, 'queued');
    $speech($scene, 'spoken', 'instruction');
    check(surfaceForSpeaker($pg, $instruction, 'Lydia IT', $scene, $now + 23) === null, 'other scenes, unspoken and Narrator instructions cannot provide prince eligibility');
    $speech($scene, 'spoken');
    check(is_string(surfaceForSpeaker($pg, $instruction, 'Lydia IT', $scene, $now + 24)), 'acknowledged current-scene mention makes prince eligible on instruction generation');
    check(surfaceForSpeaker($pg, $instruction, 'Lydia IT', $scene, $now + 25) === null, 'NPC prince mention does not override cooldown');
    $princeInput = ['inputtext', '1', '2', $pt['player_name'] . ': What about Molag Bal?'];
    check(is_string(surfaceForSpeaker($pg, $princeInput, 'Lydia IT', $scene . $pt['player_name'] . '|', $now + 26)), 'current included player prince mention overrides cooldown');
    check((int) $pg->one('SELECT count(*) AS n FROM sas_it.eventlog')['n'] === 4, 'surfacing never writes evidence to eventlog');
    saveSetting($pg, 'enabled', false);
    check(surfaceForSpeaker($pg, $named, 'Lydia IT', '|Lydia IT|Farkas IT|', $now + 999) === null, 'disabled plugin injects nothing');
} finally {
    $pg->exec('DROP SCHEMA IF EXISTS sas_it CASCADE');
    putenv('SAS_TEST_SCHEMA');
}

echo "hook integration checks passed\n";
