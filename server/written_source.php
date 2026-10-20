<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__.'/pg.php';
require_once __DIR__.'/tables.php';
require_once __DIR__.'/ledger.php';
require_once __DIR__.'/rows.php';
require_once __DIR__.'/written_evidence.php';

/** Internal persisted-source adapter; the speech parser and its ACK rules remain unchanged. */
function evidenceLineFromEventRow(Pg $pg, array $row, array $playthrough, bool $lock = false): ?array
{
    if (($row['type'] ?? null) !== 'info' || ($row['sess'] ?? null) !== 'written_evidence_v1') return lineFromEventRow($row, $playthrough['player_name']);
    $line = writtenLineFromEventRow($row, $playthrough);
    if ($line === null) return null;
    $w = $line['written'];
    foreach ([$w['recipient'], $w['claimed_sender']] as $actor) {
        if ($actor['kind'] === 'player') continue;
        $identities = $pg->all('SELECT id,npc_name FROM core_npc_master WHERE lower(npc_name)=lower($1) ORDER BY id LIMIT 2'.($lock ? ' FOR SHARE' : ''), [$actor['name']]);
        if (count($identities) !== 1 || (int)$identities[0]['id'] !== $actor['id'] || $identities[0]['npc_name'] !== $actor['name']) return null;
    }
    // Ordinary info is plaintext; force the reserved-marker filter before any JSON cast.
    $collision = $pg->one("WITH written_rows AS MATERIALIZED (
        SELECT rowid,data FROM eventlog WHERE type='info' AND sess='written_evidence_v1'
    ) SELECT rowid FROM written_rows WHERE rowid<>$1
        AND ((data::jsonb->>'document_id'=$2 AND data::jsonb#>>'{recipient,kind}'=$3 AND data::jsonb#>>'{recipient,id}'=$4)
            OR data::jsonb#>>'{receipt,receipt_id}'=$5 OR data::jsonb#>>'{receipt,operation_id}'=$6) LIMIT 1",
        [$line['event_id'],$w['document_id'],$w['recipient']['kind'],(string)$w['recipient']['id'],$w['receipt']['receipt_id'],$w['receipt']['operation_id']]);
    if ($collision !== null) return null;
    return $line;
}

function readEvidenceLine(Pg $pg, int $eventId, array $playthrough, bool $lock = false): ?array
{
    if ($eventId < 1) return null;
    $row = $pg->one('SELECT rowid,type,sess,data,people,gamets,localts,delivery_state FROM eventlog WHERE rowid=$1'.($lock ? ' FOR SHARE' : ''), [$eventId]);
    return $row === null ? null : evidenceLineFromEventRow($pg,$row,$playthrough,$lock);
}

/** Public readback: processed requires a fresh durable source plus a committed replay marker. */
function writtenEvidenceStatus(Pg $pg, int $eventId): array
{
    $reply = static fn(string $status,string $reason): array => ['status'=>$status,'reason'=>$reason];
    $pt = currentPlaythrough($pg);
    if ($pt === null) return $reply('invalid','playthrough_changed');
    $line = readEvidenceLine($pg,$eventId,$pt);
    if ($line === null || !isset($line['written'])) return $reply('invalid','written_source_invalid');
    $owner = $line['written']['recipient']['id'];
    $row = $pg->one("SELECT (plugin_extended_data->'sworn_and_scorned')::text AS ledger FROM core_npc_master WHERE id=$1",[$owner]);
    $ledger = normalizeLedger(isset($row['ledger']) ? json_decode($row['ledger'],true,512,JSON_THROW_ON_ERROR) : null,$pt['id']);
    $state = writtenSourceState($ledger,[$line]);
    if ($state === 'written_source_changed') return $reply('invalid',$state);
    if ($state === 'replay') {
        foreach ($ledger['written_sources'] as $source) {
            if ($source['event_id'] === $eventId) {
                return $source['result'] === 'substance_budget'
                    ? $reply('abstained','substance_budget')
                    : $reply('processed',$source['result']);
            }
        }
    }
    if ($state === 'written_capacity') return $reply('abstained',$state);
    $settings = writtenQueueSettings($pg,$pt);
    $pause = workerPauseReason($settings,false);
    $readiness = ['credential_readiness'=>'unchecked'];
    if ($pause !== null) return $reply('paused',$pause) + $readiness;
    if (!in_array($owner,$settings['cast'],true)) return $reply('paused','cast_changed') + $readiness;
    $job = $pg->one('SELECT id,status FROM '.sasTable('jobs').' WHERE playthrough_id=$1 AND owner_npc_id=$2 AND $3::bigint=ANY(event_ids) ORDER BY id LIMIT 1',[$pt['id'],$owner,$eventId]);
    if ($job !== null) return $reply($job['status'] === 'failed' ? 'failed' : 'queued',$job['status'] === 'failed' ? 'worker_failed' : 'awaiting_worker') + $readiness + ['job_id'=>(int)$job['id']];
    $cursor = $pg->one('SELECT last_rowid FROM '.sasTable('cursor').' WHERE playthrough_id=$1',[$pt['id']]);
    if ($cursor !== null && (int)$cursor['last_rowid'] >= $eventId) return $reply('abstained','source_skipped');
    return $reply('pending','awaiting_scan') + $readiness;
}

/** Public server-only request. Model work remains in the existing chronological worker. */
function enqueueWrittenEvidence(Pg $pg, int $eventId): array
{
    require_once __DIR__.'/queue.php';
    if (pg_transaction_status($pg->connection) !== PGSQL_TRANSACTION_IDLE) return ['status'=>'invalid','reason'=>'transaction_active'];
    $status = writtenEvidenceStatus($pg,$eventId);
    if ($status['status'] !== 'pending') return $status;
    $pt = currentPlaythrough($pg);
    if ($pt === null) return ['status'=>'invalid','reason'=>'playthrough_changed'];
    $settings = writtenQueueSettings($pg,$pt);
    $pg->exec('BEGIN');
    try {
        scanOnce($pg,$pt,$settings,time(),false);
        $pg->exec('COMMIT');
    } catch (\Throwable $error) {
        $pg->exec('ROLLBACK');
        throw $error;
    }
    return writtenEvidenceStatus($pg,$eventId);
}

/** Preserve eligible letters at first scan without retrospectively evaluating old speech. */
function initialEvidenceCursor(Pg $pg, array $playthrough, array $settings, int $fallback): int
{
    foreach ($pg->all("SELECT rowid,type,sess,data,people,gamets,localts,delivery_state FROM eventlog
        WHERE type='info' AND sess='written_evidence_v1' AND rowid<=$1 ORDER BY rowid",[$fallback]) as $row) {
        $line = evidenceLineFromEventRow($pg,$row,$playthrough);
        if ($line !== null && in_array($line['written']['recipient']['id'],$settings['cast'],true)) return $line['event_id']-1;
    }
    return $fallback;
}

/** Queue/status projection: never select, open or migrate a dedicated/connector key. */
function writtenQueueSettings(Pg $pg, array $playthrough): array
{
    $settings = DEFAULT_SETTINGS;
    foreach ($pg->all("SELECT key,value::text AS value FROM ".sasTable('settings')." WHERE key IN ('enabled','use_jev','cast','model')") as $row) {
        $settings[$row['key']] = json_decode($row['value'],true,512,JSON_THROW_ON_ERROR);
    }
    $cast = $settings['cast'];
    $settings['cast'] = castForPlaythrough($cast,$playthrough['id']);
    $settings = normalizeSettings($settings);
    $settings['_cast_playthrough'] = is_array($cast) ? ($cast['playthrough_id'] ?? null) : null;
    return $settings;
}
