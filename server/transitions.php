<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

const PROMISE_TRANSITIONS = [
    'pending' => ['fulfilled', 'disputed', 'broken', 'released'],
    'disputed' => ['fulfilled', 'broken', 'released'],
];
const GRIEVANCE_TRANSITIONS = [
    'open' => ['resolved', 'let_go'],
];

// Spec §6.4. Only the perspective owner's view of the counterpart changes.
const GRIEVANCE_AFFINITY = ['resolved' => 2, 'let_go' => 1];
const PROMISE_OWED_TO_ME_AFFINITY = ['fulfilled' => 2, 'broken' => -3];

function transitionAllowed(string $kind, string $from, string $to): bool
{
    $table = match ($kind) {
        'promise' => PROMISE_TRANSITIONS,
        'grievance' => GRIEVANCE_TRANSITIONS,
        default => [],
    };
    return in_array($to, $table[$from] ?? [], true);
}

function transitionKey(string $issueId, string $from, string $to): string
{
    return "{$issueId}:{$from}>{$to}";
}

function lifecycleAffinityDelta(array $issue, string $to): int
{
    if ($issue['kind'] === 'grievance') {
        return GRIEVANCE_AFFINITY[$to] ?? 0;
    }
    if ($issue['direction'] !== 'owed_to_me') {
        return 0;
    }
    return PROMISE_OWED_TO_ME_AFFINITY[$to] ?? 0;
}
