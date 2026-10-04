<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/pg.php';
require_once __DIR__ . '/tables.php';
require_once __DIR__ . '/ledger.php';

// Our own advisory range: serialises our writers only. Cross-plugin safety is the row lock.
const SAS_ADVISORY_BASE = 1002000000;

final class PerspectiveStore
{
    public function __construct(private Pg $pg)
    {
    }

    /**
     * Lock the owner, revalidate the source events, apply $mutate to the fresh ledger,
     * write ledger + relative affinity, snapshot to history, commit (spec §5, §9).
     *
     * @param array $request {owner_npc_id, owner_name, playthrough_id, event_ids: list<int>, counterpart_name: ?string, counterpart_key?: string}
     * @param callable(array $ledger): array{status: string, ledger: array, affinity_delta: int} $mutate
     */
    public function commitLedger(array $request, callable $mutate): string
    {
        if (filter_var($GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return 'restore_policy';
        }
        $ownerId = (int) $request['owner_npc_id'];
        $lockKey = SAS_ADVISORY_BASE + $ownerId;
        $acquired = $this->pg->one('SELECT pg_try_advisory_lock($1) AS acquired', [$lockKey]);
        if (($acquired['acquired'] ?? null) !== 't') {
            return 'busy';
        }
        $committed = false;
        try {
            $this->pg->exec('BEGIN');
            $this->pg->exec("SET LOCAL lock_timeout = '1000ms'");
            $this->pg->exec("SET LOCAL statement_timeout = '3000ms'");
            $row = $this->pg->one(
                'SELECT npc_name, lock_profile, extended_data::text AS extended_data,
                        plugin_extended_data::text AS plugin_extended_data, gamets_last_updated
                 FROM core_npc_master WHERE id = $1 FOR UPDATE',
                [$ownerId]
            );
            if ($row === null || $row['npc_name'] !== $request['owner_name']) {
                return 'owner_changed';
            }
            $playthrough = currentPlaythrough($this->pg);
            if (($playthrough['id'] ?? null) !== $request['playthrough_id']) {
                return 'playthrough_changed';
            }
            $gamets = (float) ($row['gamets_last_updated'] ?? 0);
            $eventIds = $request['event_ids'];
            if ($eventIds !== []) {
                $freshSettings = [];
                foreach ($this->pg->all('SELECT key, value::text AS value FROM ' . sasTable('settings') . " WHERE key IN ('enabled','cast') ORDER BY key FOR SHARE") as $setting) {
                    $freshSettings[$setting['key']] = json_decode($setting['value'], true);
                }
                $freshSettings = normalizeSettings($freshSettings);
                if (!$freshSettings['enabled']) return 'disabled';
                if (!in_array($ownerId, $freshSettings['cast'], true)) return 'cast_changed';
                $counterpartName = $request['counterpart_name'] ?? '';
                $counterpartKey = $request['counterpart_key'] ?? null;
                if ($counterpartKey === 'player') {
                    if (strcasecmp($counterpartName, $playthrough['player_name']) !== 0) return 'counterpart_changed';
                } else {
                    $counterparts = $this->pg->all('SELECT id FROM core_npc_master WHERE lower(npc_name) = lower($1) ORDER BY id LIMIT 2 FOR SHARE', [$counterpartName]);
                    if (count($counterparts) !== 1 || 'npc:' . (int) $counterparts[0]['id'] !== $counterpartKey) return 'counterpart_changed';
                }
                $events = $this->pg->all('SELECT rowid, gamets FROM eventlog WHERE rowid = ANY($1::bigint[]) FOR SHARE', [pgIntArray($eventIds)]);
                if (count($events) !== count($eventIds)) {
                    return 'events_missing';
                }
                $gamets = max($gamets, ...array_map('floatval', array_column($events, 'gamets')));
            }
            $plugins = json_decode($row['plugin_extended_data'] ?? '{}', true);
            $ledger = normalizeLedger(is_array($plugins) ? ($plugins['sworn_and_scorned'] ?? null) : null, $request['playthrough_id']);
            $result = $mutate($ledger);
            if ($result['status'] !== 'applied') {
                return $result['status'];
            }

            $params = [$ownerId, json_encode($result['ledger'], JSON_FLAGS), $gamets];
            $extendedSql = 'extended_data';
            $counterpartName = $request['counterpart_name'] ?? null;
            if ((int) $result['affinity_delta'] !== 0 && is_string($counterpartName)) {
                $edge = $this->nextEdge($row, $counterpartName, (int) $result['affinity_delta']);
                if ($edge === false) {
                    return 'edge_invalid';
                }
                if ($edge !== null) {
                    $params[] = $counterpartName;
                    $params[] = json_encode($edge, JSON_FLAGS);
                    $extendedSql = "jsonb_set(jsonb_set(COALESCE(extended_data, '{}'::jsonb), '{relationships}',"
                        . " COALESCE(extended_data->'relationships', '{}'::jsonb), true), ARRAY['relationships', \$4::text], \$5::jsonb, true)";
                }
            }
            $updated = $this->pg->one(
                "UPDATE core_npc_master
                 SET extended_data = {$extendedSql},
                     plugin_extended_data = jsonb_set(COALESCE(plugin_extended_data, '{}'::jsonb), ARRAY['sworn_and_scorned']::text[], \$2::jsonb, true),
                     gamets_last_updated = \$3
                 WHERE id = \$1 RETURNING id",
                $params
            );
            if ($updated === null) {
                return 'owner_changed';
            }
            $this->snapshot($ownerId);
            $this->pg->exec('COMMIT');
            $committed = true;
            return 'committed';
        } finally {
            if (!$committed) {
                try {
                    $this->pg->exec('ROLLBACK');
                } catch (\Throwable) {
                }
            }
            try {
                $this->pg->exec('SELECT pg_advisory_unlock($1)', [$lockKey]);
            } catch (\Throwable) {
            }
        }
    }

    /** New edge object, null when the profile or relationships are locked, false when invalid. */
    private function nextEdge(array $row, string $name, int $delta): \stdClass|false|null
    {
        $extended = json_decode($row['extended_data'] ?? '{}');
        if (!$extended instanceof \stdClass) {
            $extended = new \stdClass();
        }
        if ((int) ($row['lock_profile'] ?? 0) !== 0 || !empty($extended->relationships_locked)) {
            return null;
        }
        $relationships = $extended->relationships ?? new \stdClass();
        $edge = $relationships instanceof \stdClass && isset($relationships->{$name})
            ? $relationships->{$name}
            : (object) ['aff' => 0, 'type' => 'neutral'];
        if (!$edge instanceof \stdClass) {
            return false;
        }
        $edge = clone $edge;
        $affinity = $edge->aff ?? 0;
        if (!is_int($affinity) && !is_float($affinity) && !(is_string($affinity) && is_numeric($affinity))) {
            return false;
        }
        $next = max(-100.0, min(100.0, (float) $affinity + $delta));
        $edge->aff = floor($next) === $next ? (int) $next : $next;
        return $edge;
    }

    /** Full-row history snapshot, as Mind Poisoning does, so CHIM restore rolls our ledger back. */
    private function snapshot(int $npcId): void
    {
        $row = $this->pg->one('SELECT * FROM core_npc_master WHERE id = $1', [$npcId]);
        if ($row === null) {
            throw new \RuntimeException('snapshot_source_missing');
        }
        $extended = json_decode($row['extended_data'] ?? '{}');
        if (!$extended instanceof \stdClass) {
            $extended = new \stdClass();
        }
        $extended->_chim_history_source = 'relationship';
        $row['extended_data'] = json_encode($extended, JSON_FLAGS);
        unset($row['id']);
        $row['npc_id'] = $npcId;
        $row['created'] = date('Y-m-d H:i:s');
        $columns = array_keys($row);
        foreach ($columns as $column) {
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', (string) $column) !== 1) {
                throw new \RuntimeException('snapshot_column_invalid');
            }
        }
        $slots = array_map(static fn (int $index): string => '$' . ($index + 1), array_keys($columns));
        $inserted = $this->pg->one(
            'INSERT INTO core_npc_master_history (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $slots) . ') RETURNING history_id',
            array_values($row)
        );
        if ((int) ($inserted['history_id'] ?? 0) < 1) {
            throw new \RuntimeException('snapshot_failed');
        }
    }
}
