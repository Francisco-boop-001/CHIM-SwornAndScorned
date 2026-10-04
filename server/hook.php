<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/pg.php';
require_once __DIR__ . '/tables.php';
require_once __DIR__ . '/rows.php';
require_once __DIR__ . '/lore.php';
require_once __DIR__ . '/surfacing.php';
require_once __DIR__ . '/logging.php';

const GENERATING_REQUEST_TYPES = ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s', 'rechat', 'continue', 'continue_group', 'instruction'];
const NOTE_PRIORITY = 900;

function pgTextArray(array $values): string
{
    return '{' . implode(',', array_map(static fn (string $value): string => '"' . addcslashes($value, "\"\\") . '"', $values)) . '}';
}

/** Names in CHIM's pipe audience (PCV narrows it to scene members), minus the speaker. */
function audienceNames(?string $cachePeople, string $speaker): array
{
    return array_values(array_filter(parsePeople($cachePeople), static fn (string $name): bool => strcasecmp($name, $speaker) !== 0));
}

/** @param array<string, int> $npcIdsByLowerName */
function presentKeys(array $names, string $playerName, array $npcIdsByLowerName): array
{
    $keys = [];
    foreach ($names as $name) {
        if (strcasecmp($name, $playerName) === 0) {
            $keys[] = 'player';
            continue;
        }
        $id = $npcIdsByLowerName[mb_strtolower($name)] ?? null;
        if ($id !== null) {
            $keys[] = 'npc:' . $id;
        }
    }
    return array_values(array_unique($keys));
}

/** @param array<string, string> $candidates counterpart key => display name */
function mentionedKeys(string $text, array $candidates): array
{
    $found = [];
    foreach ($candidates as $key => $name) {
        if ($name !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $text) === 1) {
            $found[] = $key;
        }
    }
    return $found;
}

function displayName(string $key, string $playerName, array $npcNamesById): ?string
{
    if ($key === 'player') {
        return $playerName;
    }
    if (str_starts_with($key, 'daedra:')) {
        return DAEDRA_NAMES[substr($key, 7)] ?? null;
    }
    if (str_starts_with($key, 'npc:')) {
        return $npcNamesById[(int) substr($key, 4)] ?? null;
    }
    return null;
}

function playerInputText(array $gameRequest, string $playerName): string
{
    if (!in_array($gameRequest[0] ?? '', PLAYER_INPUT_TYPES, true)) {
        return '';
    }
    $data = (string) ($gameRequest[3] ?? '');
    $prefix = $playerName . ': ';
    return str_starts_with($data, $prefix) ? substr($data, strlen($prefix)) : $data;
}

function recentSpeechText(Pg $pg, int $now, string $playerName, string $speaker, array $audience): string
{
    $rows = $pg->all(
        "SELECT rowid, type, data, people, gamets, localts, delivery_state FROM eventlog
         WHERE type IN ('chat', 'inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s')
         AND localts >= $1 ORDER BY rowid DESC LIMIT 24",
        [$now - 120]
    );
    $scope = array_values(array_unique(array_map('mb_strtolower', [$speaker, ...$audience])));
    sort($scope);
    $text = [];
    foreach ($rows as $row) {
        $line = lineFromEventRow($row, $playerName);
        if ($line === null || $line['speaker'] === 'The Narrator'
            || $line['listener'] === 'explicit_disable_rechat') {
            continue;
        }
        $people = array_values(array_unique(array_map('mb_strtolower', $line['people'])));
        sort($people);
        if ($people !== $scope || !in_array(mb_strtolower($speaker), $people, true)) {
            continue;
        }
        $text[] = $line['text'];
        if (count($text) === 4) {
            break;
        }
    }
    return implode("\n", $text);
}

/** The note for this speaker's turn, or null. Records pacing when a note is returned. */
function surfaceForSpeaker(Pg $pg, array $gameRequest, string $speaker, mixed $cachePeople, int $now): ?string
{
    if ($speaker === '') {
        return null;
    }
    if (!loadSettings($pg)['enabled']) {
        return null;
    }
    $playthrough = currentPlaythrough($pg);
    if ($playthrough === null) {
        return null;
    }
    $rows = $pg->all("SELECT id, (plugin_extended_data->'sworn_and_scorned')::text AS ledger FROM core_npc_master WHERE lower(npc_name) = lower($1) LIMIT 2", [$speaker]);
    if (count($rows) !== 1 || $rows[0]['ledger'] === null) {
        return null;
    }
    $speakerId = (int) $rows[0]['id'];
    $ledger = normalizeLedger(json_decode($rows[0]['ledger'], true), $playthrough['id']);
    if ($ledger['issues'] === []) {
        return null;
    }

    $names = audienceNames(is_string($cachePeople) ? $cachePeople : null, $speaker);
    $issueNpcIds = [];
    foreach ($ledger['issues'] as $issue) {
        if (str_starts_with($issue['counterpart'], 'npc:')) {
            $issueNpcIds[] = (int) substr($issue['counterpart'], 4);
        }
    }
    $npcNamesById = [];
    $npcIdsByLowerName = [];
    $lookup = $pg->all(
        'SELECT id, npc_name FROM core_npc_master WHERE lower(npc_name) = ANY($1::text[]) OR id = ANY($2::bigint[])',
        [pgTextArray(array_map('mb_strtolower', $names)), pgIntArray($issueNpcIds)]
    );
    foreach ($lookup as $row) {
        $npcNamesById[(int) $row['id']] = $row['npc_name'];
        $lower = mb_strtolower($row['npc_name']);
        $npcIdsByLowerName[$lower] = isset($npcIdsByLowerName[$lower]) ? -1 : (int) $row['id'];
    }
    $npcIdsByLowerName = array_filter($npcIdsByLowerName, static fn (int $id): bool => $id > 0);

    $candidates = [];
    foreach ($ledger['issues'] as $issue) {
        $name = displayName($issue['counterpart'], $playthrough['player_name'], $npcNamesById);
        if ($name !== null) {
            $candidates[$issue['counterpart']] = $name;
        }
    }
    $playerText = in_array($playthrough['player_name'], $names, true)
        ? playerInputText($gameRequest, $playthrough['player_name']) : '';
    $text = $playerText . "\n" . recentSpeechText($pg, $now, $playthrough['player_name'], $speaker, $names);
    $pacing = [];
    foreach ($pg->all('SELECT issue_id, surfaced_at, evidence_count, evidence_ref FROM ' . sasTable('pacing') . ' WHERE npc_id = $1', [$speakerId]) as $row) {
        $pacing[$row['issue_id']] = ['at' => (int) $row['surfaced_at'], 'evidence_count' => (int) $row['evidence_count'], 'evidence_ref' => $row['evidence_ref']];
    }
    $issue = selectIssueToSurface(
        $ledger,
        presentKeys($names, $playthrough['player_name'], $npcIdsByLowerName),
        mentionedKeys($text, $candidates),
        $pacing,
        $now,
        SURFACE_COOLDOWN_SECONDS,
        mentionedKeys($playerText, $candidates)
    );
    if ($issue === null || !isset($candidates[$issue['counterpart']])) {
        return null;
    }
    // Pacing is transient. A contended row must not make the dialogue wait.
    $pg->exec('BEGIN');
    try {
        $pg->exec("SET LOCAL lock_timeout = '10ms'");
        $pg->exec("SET LOCAL statement_timeout = '30ms'");
        $pg->exec(
            'INSERT INTO ' . sasTable('pacing') . ' (npc_id, issue_id, surfaced_at, evidence_count, evidence_ref) VALUES ($1, $2, $3, $4, $5)
             ON CONFLICT (npc_id, issue_id) DO UPDATE SET surfaced_at = EXCLUDED.surfaced_at, evidence_count = EXCLUDED.evidence_count, evidence_ref = EXCLUDED.evidence_ref',
            [$speakerId, $issue['id'], $now, count($issue['evidence']), $issue['evidence'][array_key_last($issue['evidence'])]['ref'] ?? '']
        );
        $pg->exec('COMMIT');
    } catch (\Throwable) {
        $pg->exec('ROLLBACK');
        return null;
    }
    sasLog('issue_surfaced', 'info', ['npc_id' => $speakerId, 'issue_id' => $issue['id'], 'turn' => (string) ($gameRequest[1] ?? '')]);
    return renderNote($issue, $speaker, $candidates[$issue['counterpart']]);
}
