<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

use UnexpectedValueException;

require_once __DIR__ . '/tables.php';
require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/launcher.php';
require_once __DIR__ . '/llm_connector.php';
require_once __DIR__ . '/lore.php';
require_once __DIR__ . '/vocab.php';

function knownNpcIds(Pg $pg): array
{
    return array_map('intval', array_column(
        $pg->all("SELECT id FROM core_npc_master WHERE npc_name <> 'The Narrator' ORDER BY id"),
        'id'
    ));
}

function pageFailureCode(mixed $failure): ?string
{
    if (!is_string($failure)) {
        return null;
    }
    $known = [
        'backend_unavailable', 'commit_rejected', 'counterpart_unresolved', 'edge_invalid',
        'events_missing', 'internal_error', 'job_invalid', 'llm_request_failed', 'owner_busy',
        'owner_changed', 'playthrough_changed', 'request_invalid_json', 'response_invalid_json',
        'response_invalid_shape', 'transport_failed', 'transport_invalid_response',
    ];
    if (in_array($failure, $known, true) || preg_match('/\Ahttp_[1-5][0-9]{2}\z/D', $failure) === 1) {
        return $failure;
    }
    return 'unknown_failure';
}

function pageCounterpartName(string $key, ?array $playthrough, array $npcNamesById): ?string
{
    if ($key === 'player') {
        return $playthrough['player_name'] ?? null;
    }
    if (str_starts_with($key, 'npc:')) {
        return $npcNamesById[(int) substr($key, 4)] ?? null;
    }
    if (str_starts_with($key, 'daedra:')) {
        return DAEDRA_NAMES[substr($key, 7)] ?? null;
    }
    return null;
}

function buildPageModel(Pg $pg, string $engineRoot, bool $director): array
{
    $settings = loadSettings($pg);
    $playthrough = currentPlaythrough($pg);
    $cast = $settings['cast'];

    $npcRows = $pg->all("SELECT id, npc_name FROM core_npc_master WHERE npc_name <> 'The Narrator' ORDER BY lower(npc_name)");
    $npcOptionsById = [];
    foreach ($npcRows as $row) {
        $npcOptionsById[(int) $row['id']] = ['id' => (int) $row['id'], 'name' => (string) $row['npc_name']];
    }
    if ($cast !== []) {
        foreach ($pg->all(
            "SELECT id, npc_name FROM core_npc_master WHERE id = ANY($1::bigint[]) AND npc_name <> 'The Narrator'",
            [pgIntArray($cast)]
        ) as $row) {
            $npcOptionsById[(int) $row['id']] = ['id' => (int) $row['id'], 'name' => (string) $row['npc_name']];
        }
    }
    $npcs = array_values($npcOptionsById);
    usort($npcs, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    $journalRows = [];
    if ($cast !== []) {
        $journalRows = $pg->all(
            "SELECT id, npc_name, (plugin_extended_data->'sworn_and_scorned')::text AS ledger
             FROM core_npc_master WHERE id = ANY($1::bigint[]) ORDER BY lower(npc_name)",
            [pgIntArray($cast)]
        );
    }
    if ($playthrough !== null) {
        $journalRows = array_merge($journalRows, $pg->all(
            "SELECT id, npc_name, (plugin_extended_data->'sworn_and_scorned')::text AS ledger
             FROM core_npc_master WHERE plugin_extended_data ? 'sworn_and_scorned'
               AND NOT (id = ANY($1::bigint[]))
             ORDER BY lower(npc_name) LIMIT 60",
            [pgIntArray($cast)]
        ));
    }

    $jobs = ['pending' => 0, 'running' => 0, 'failed' => 0];
    $perCast = [];
    foreach ($cast as $npcId) {
        $perCast[$npcId] = ['pending' => 0, 'running' => 0, 'failed' => 0];
    }
    $lastFailure = null;
    if ($playthrough !== null) {
        foreach ($pg->all(
            'SELECT status, count(*) AS n FROM ' . sasTable('jobs') . ' WHERE playthrough_id = $1 GROUP BY status',
            [$playthrough['id']]
        ) as $row) {
            if (array_key_exists($row['status'], $jobs)) {
                $jobs[$row['status']] = (int) $row['n'];
            }
        }
        if ($cast !== []) {
            foreach ($pg->all(
                'SELECT owner_npc_id, status, count(*) AS n FROM ' . sasTable('jobs')
                    . ' WHERE playthrough_id = $1 AND owner_npc_id = ANY($2::bigint[]) GROUP BY owner_npc_id, status',
                [$playthrough['id'], pgIntArray($cast)]
            ) as $row) {
                $ownerId = (int) $row['owner_npc_id'];
                if (isset($perCast[$ownerId]) && array_key_exists($row['status'], $perCast[$ownerId])) {
                    $perCast[$ownerId][$row['status']] = (int) $row['n'];
                }
            }
        }
        $failure = $pg->one(
            'SELECT last_error FROM ' . sasTable('jobs') . " WHERE playthrough_id = $1 AND status = 'failed' AND last_error IS NOT NULL ORDER BY id DESC LIMIT 1",
            [$playthrough['id']]
        );
        $lastFailure = pageFailureCode($failure['last_error'] ?? null);
    }

    $normalizedRows = [];
    $counterpartIds = [];
    foreach ($journalRows as $row) {
        $ownerId = (int) $row['id'];
        $isCast = in_array($ownerId, $cast, true);
        $ledgerInvalid = false;
        $issues = [];
        if ($playthrough !== null) {
            try {
                if ($row['ledger'] === null) {
                    $raw = null;
                } else {
                    $raw = json_decode($row['ledger'], true, 64, JSON_THROW_ON_ERROR);
                    if (!is_array($raw)) {
                        throw new UnexpectedValueException('ledger_invalid');
                    }
                }
                $ledger = normalizeLedger($raw, $playthrough['id']);
                $issues = $ledger['issues'];
            } catch (\JsonException|UnexpectedValueException) {
                if (!$isCast) {
                    continue;
                }
                $ledgerInvalid = true;
            }
        }
        foreach ($issues as $issue) {
            if (preg_match('/\Anpc:([1-9][0-9]*)\z/D', $issue['counterpart'], $match) === 1) {
                $counterpartIds[(int) $match[1]] = (int) $match[1];
            }
        }
        if ($isCast || $issues !== [] || $ledgerInvalid) {
            $normalizedRows[] = [
                'npc_id' => $ownerId,
                'name' => (string) $row['npc_name'],
                'issues' => $issues,
                'ledger_invalid' => $ledgerInvalid,
                'evaluations' => $perCast[$ownerId] ?? ['pending' => 0, 'running' => 0, 'failed' => 0],
            ];
        }
    }

    $npcNamesById = array_column($npcs, 'name', 'id');
    if ($counterpartIds !== []) {
        foreach ($pg->all(
            'SELECT id, npc_name FROM core_npc_master WHERE id = ANY($1::bigint[])',
            [pgIntArray(array_values($counterpartIds))]
        ) as $row) {
            $npcNamesById[(int) $row['id']] = (string) $row['npc_name'];
        }
    }
    foreach ($normalizedRows as &$entry) {
        foreach ($entry['issues'] as &$issue) {
            $issue['counterpart_name'] = pageCounterpartName($issue['counterpart'], $playthrough, $npcNamesById)
                ?? $issue['counterpart'];
        }
        unset($issue);
    }
    unset($entry);

    $backend = ['status' => 'No backend available', 'model' => ''];
    if ($settings['use_jev'] && $settings['_key_error'] !== null) {
        $backend['status'] = 'Key storage unavailable; evaluations blocked';
    } elseif ($settings['use_jev']) {
        $backend = ['status' => decisionApiKey($settings) === null ? 'Jev unavailable: OpenRouter credentials missing'
            : ($settings['api_key'] !== '' ? 'Jev configured: dedicated key' : 'Jev configured: CHIM OpenRouter credentials'), 'model' => $settings['model']];
    } else {
        try {
            $enabled = !function_exists('chimIsGlobalLlmConnectorEnabled')
                || \chimIsGlobalLlmConnectorEnabled('RELLLM_CONNECTOR') === true;
            $identity = $enabled ? relationshipConnectorIdentity() : null;
            if ($identity !== null) {
                $backend['status'] = 'CHIM relationship LLM available';
                $backend['model'] = $identity['model'];
            }
        } catch (\Throwable) {
        }
    }

    return [
        'playthrough_id' => $playthrough['id'] ?? null,
        'settings' => [
            'enabled' => $settings['enabled'],
            'use_jev' => $settings['use_jev'],
            'cast' => $cast,
            'cast_reselect' => $settings['_cast_reselect'],
            'cast_other_playthrough' => $settings['_cast_other_playthrough'],
            'model' => $settings['model'],
            'has_key' => $settings['_key_present'],
            'key_error' => $settings['_key_error'] !== null,
        ],
        'backend' => $backend,
        'npcs' => $npcs,
        'worker' => [
            'pause' => ($pause = workerPauseReason($settings)) === null ? null : workerPauseNotice($pause),
            'alive' => workerPidAlive($engineRoot . '/log/sworn_scorned_worker.pid'),
            'jobs' => $jobs,
            'last_failure' => $lastFailure,
        ],
        'journal' => $normalizedRows,
        'director' => $director,
        'art' => is_file(__DIR__ . '/dashboard-art.webp'),
    ];
}
