<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/apply.php';
require_once __DIR__ . '/evaluator.php';
require_once __DIR__ . '/witness.php';
require_once __DIR__ . '/backend_error.php';
require_once __DIR__ . '/logging.php';

const RETRY_DELAYS = [30, 120, 600];

interface JobDeps
{
    public function currentPlaythroughId(): ?string;

    /** @return list<array> parsed lines for those event ids that still exist */
    public function linesFor(array $eventIds): array;

    public function ledgerFor(int $ownerNpcId, string $playthroughId): array;

    public function counterpartKey(string $counterpartName): ?string;

    /** @return array{0: DecisionBackend, 1: array}|null backend and its policy */
    public function backend(string $kind): ?array;

    /**
     * Revalidate and commit under the store's locks. The model call must happen before this method.
     * @param array $request {owner_npc_id, owner_name, playthrough_id, event_ids, counterpart_name, counterpart_key}
     * @return string committed | replay | unchanged | busy | restore_policy | owner_changed | playthrough_changed | events_missing | edge_invalid
     */
    public function commitLedger(array $request, callable $mutate): string;
}

/** Evaluate one chronological per-perspective job and commit only against freshly validated state. */
function processJob(array $job, JobDeps $deps): array
{
    $result = static fn (string $outcome, string $reason): array => ['outcome' => $outcome, 'reason' => $reason];
    $eventIds = $job['event_ids'] ?? null;
    if (!is_array($eventIds) || !array_is_list($eventIds) || $eventIds === []) {
        return $result('stale', 'events_missing');
    }
    foreach ($eventIds as $eventId) {
        if (!is_int($eventId) || $eventId < 1) {
            return $result('stale', 'events_missing');
        }
    }
    $playthroughId = $deps->currentPlaythroughId();
    if ($playthroughId === null || $playthroughId !== ($job['playthrough_id'] ?? null)) {
        return $result('stale', 'playthrough_changed');
    }
    $ownerId = $job['owner_npc_id'] ?? null;
    $ownerName = $job['owner_name'] ?? null;
    $counterpartName = $job['counterpart_name'] ?? null;
    if (!is_int($ownerId) || $ownerId < 1 || !is_string($ownerName) || $ownerName === ''
        || !is_string($counterpartName) || $counterpartName === '') {
        return $result('failed', 'job_invalid');
    }

    $lines = $deps->linesFor($eventIds);
    if (!is_array($lines) || count($lines) !== count($eventIds)) {
        return $result('stale', 'events_missing');
    }
    $lineIds = [];
    foreach ($lines as $line) {
        if (!is_array($line) || !is_int($line['event_id'] ?? null)) {
            return $result('stale', 'events_missing');
        }
        $lineIds[] = $line['event_id'];
    }
    $expectedIds = $eventIds;
    sort($lineIds, SORT_NUMERIC);
    sort($expectedIds, SORT_NUMERIC);
    if ($lineIds !== $expectedIds) {
        return $result('stale', 'events_missing');
    }

    $counterpart = $deps->counterpartKey($counterpartName);
    if ($counterpart === null) {
        return $result('stale', 'counterpart_unresolved');
    }
    $ledger = $deps->ledgerFor($ownerId, $playthroughId);
    $writtenLines = array_values(array_filter($lines, static fn(array $line): bool => isset($line['written'])));
    foreach ($writtenLines as $line) {
        $w = $line['written'];
        if ($w['playthrough_id'] !== $playthroughId || $w['recipient']['id'] !== $ownerId
            || $w['recipient']['name'] !== $ownerName || writtenActorKey($w['claimed_sender']) !== $counterpart) {
            return $result('stale', 'written_source_invalid');
        }
    }
    $writtenState = writtenSourceState($ledger, $writtenLines);
    if ($writtenState === 'written_source_changed') return $result('stale', $writtenState);
    if ($writtenState === 'written_capacity') {
        $lines = array_values(array_filter($lines, static fn(array $line): bool => !isset($line['written'])));
        if ($lines === []) return $result('stale', 'written_capacity');
        $eventIds = array_column($lines, 'event_id');
        $writtenLines = [];
    }
    if ($writtenLines === [] && isReplay($ledger, $eventIds)) {
        return $result('done', 'replay');
    }

    if ($writtenLines !== [] && $writtenState === 'replay') {
        $status = $deps->commitLedger(['owner_npc_id'=>$ownerId,'owner_name'=>$ownerName,'playthrough_id'=>$playthroughId,
            'event_ids'=>$eventIds,'counterpart_name'=>$counterpartName,'counterpart_key'=>$counterpart,'source_lines'=>$lines],
            static fn(array $fresh): array => ['status'=>writtenSourceState($fresh, $writtenLines) === 'replay' ? 'replay' : 'written_source_changed']);
        return $result($status === 'replay' ? 'done' : 'stale', $status);
    }
    $backendKind = $job['backend'] ?? 'decisions';
    if (!in_array($backendKind, ['decisions', 'llm'], true)) {
        return $result('failed', 'backend_unavailable');
    }
    $chosen = $deps->backend($backendKind);
    if ($chosen === null && $backendKind === 'decisions') {
        $chosen = $deps->backend('llm');
        $backendKind = 'llm';
    }
    if (!is_array($chosen)) {
        return $result('failed', 'backend_unavailable');
    }
    $backend = $chosen[0] ?? null;
    $policy = $chosen[1] ?? null;
    if (!$backend instanceof DecisionBackend || !is_array($policy)) {
        return $result('failed', 'backend_unavailable');
    }

    $open = array_values(array_filter(
        $ledger['issues'],
        static fn (array $issue): bool => isOpen($issue) && $issue['counterpart'] === $counterpart
    ));
    try {
        $proposal = evaluatePerspective([
            'owner' => $ownerName,
            'counterpart' => $counterpartName,
            'lines' => pairLinesWitnessedBy($lines, $ownerName, $counterpartName),
            'open_issues' => $open,
        ], $backend, $policy, static function (array $entry) use ($job, $ownerId, $backend): void {
            sasLog('issue_evaluated', 'info', ['job_id' => $job['id'], 'owner_npc_id' => $ownerId, 'backend' => $backend->name()] + $entry);
        });
    } catch (DecisionBackendError $error) {
        return $result($error->retryable ? 'retry' : 'failed', $error->reasonCode);
    }

    $request = [
        'owner_npc_id' => $ownerId,
        'owner_name' => $ownerName,
        'playthrough_id' => $playthroughId,
        'event_ids' => $eventIds,
        'counterpart_name' => $counterpartName,
        'counterpart_key' => $counterpart,
        'source_lines' => $lines,
    ];
    $meta = [
        'event_ids' => $eventIds,
        'counterpart' => $counterpart,
        'backend' => $backend->name(),
        'policy' => $policy,
    ];
    if ($writtenLines !== []) $meta['source_lines'] = $lines;
    $status = $deps->commitLedger($request, static fn (array $fresh): array => applyProposal($fresh, $proposal, $meta));
    return match ($status) {
        'committed', 'replay', 'unchanged' => $result('done', $status),
        'restore_policy' => $result('paused', 'restore_policy'),
        'busy' => $result('retry', 'owner_busy'),
        'owner_changed', 'playthrough_changed', 'events_missing', 'edge_invalid', 'counterpart_changed', 'disabled', 'cast_changed', 'written_source_changed', 'written_capacity', 'written_source_invalid' => $result('stale', $status),
        default => $result('stale', 'commit_rejected'),
    };
}

/** Choose the next backend attempt after a retryable or permanent provider outcome. */
function nextStep(string $outcome, int $failures, string $backendKind, bool $fallbackAvailable): array
{
    if ($outcome === 'retry' && $failures >= 1 && $failures <= count(RETRY_DELAYS)) {
        return ['action' => 'retry', 'delay' => RETRY_DELAYS[$failures - 1], 'backend' => $backendKind, 'failures' => $failures];
    }
    if (in_array($outcome, ['retry', 'failed'], true) && $backendKind === 'decisions' && $fallbackAvailable) {
        return ['action' => 'retry', 'delay' => 0, 'backend' => 'llm', 'failures' => 0];
    }
    return ['action' => 'fail'];
}
