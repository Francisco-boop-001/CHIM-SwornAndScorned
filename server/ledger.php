<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

use UnexpectedValueException;

require_once __DIR__ . '/vocab.php';

const MAX_SEEDED = 512;

function emptyLedger(string $playthroughId): array
{
    return ['playthrough_id' => $playthroughId, 'floor_event_id' => 0, 'processed' => [], 'issues' => [], 'seeded' => []];
}

/**
 * Decode stored ledger data. A missing ledger or one from another playthrough starts empty;
 * a corrupt ledger throws so the caller fails closed instead of overwriting it.
 */
function normalizeLedger(mixed $raw, string $playthroughId): array
{
    if ($raw === null) {
        return emptyLedger($playthroughId);
    }
    if (!is_array($raw) || !is_string($raw['playthrough_id'] ?? null) || $raw['playthrough_id'] === '') {
        throw new UnexpectedValueException('ledger_identity_invalid');
    }
    if ($raw['playthrough_id'] !== $playthroughId) {
        return emptyLedger($playthroughId);
    }
    $floor = $raw['floor_event_id'] ?? null;
    if (!is_int($floor) || $floor < 0) {
        throw new UnexpectedValueException('ledger_floor_invalid');
    }
    $processed = $raw['processed'] ?? null;
    if (!is_array($processed) || !array_is_list($processed) || count($processed) > MAX_PROCESSED || count(array_unique($processed, SORT_REGULAR)) !== count($processed)) {
        throw new UnexpectedValueException('ledger_processed_invalid');
    }
    foreach ($processed as $eventId) {
        if (!is_int($eventId) || $eventId <= $floor) {
            throw new UnexpectedValueException('ledger_processed_invalid');
        }
    }
    $issues = $raw['issues'] ?? null;
    if (!is_array($issues) || !array_is_list($issues)) {
        throw new UnexpectedValueException('ledger_issues_invalid');
    }
    $validated = array_map(validateIssue(...), $issues);
    if (count(array_unique(array_column($validated, 'id'))) !== count($validated)
        || count(array_filter($validated, isOpen(...))) > MAX_OPEN_ISSUES
        || count(array_filter($validated, static fn (array $issue): bool => !isOpen($issue))) > MAX_CLOSED_ISSUES) {
        throw new UnexpectedValueException('ledger_issues_invalid');
    }
    $seeded = array_key_exists('seeded', $raw) ? requireSeededIds($raw) : [];
    return [
        'playthrough_id' => $playthroughId,
        'floor_event_id' => $floor,
        'processed' => $processed,
        'issues' => $validated,
        'seeded' => $seeded,
    ];
}

function validateIssue(mixed $issue): array
{
    if (!is_array($issue)) {
        throw new UnexpectedValueException('issue_not_object');
    }
    $kind = requireEnum($issue, 'kind', KINDS);
    $isPromise = $kind === 'promise';
    $evidence = $issue['evidence'] ?? null;
    if (!is_array($evidence) || !array_is_list($evidence) || count($evidence) > MAX_EVIDENCE) {
        throw new UnexpectedValueException('issue_evidence_invalid');
    }
    $changed = $issue['changed_event_id'] ?? null;
    if (!is_int($changed) || $changed < 0) {
        throw new UnexpectedValueException('issue_changed_event_id_invalid');
    }
    $id = $issue['id'] ?? null;
    if (!is_string($id) || $id === '') {
        throw new UnexpectedValueException('issue_id_invalid');
    }
    foreach (['muted', 'dismissed'] as $flag) {
        if (array_key_exists($flag, $issue) && !is_bool($issue[$flag])) {
            throw new UnexpectedValueException('issue_flag_invalid');
        }
    }
    return [
        'id' => $id,
        'origin' => requireEnum($issue, 'origin', ORIGINS),
        'kind' => $kind,
        'counterpart' => requireCounterpart($issue['counterpart'] ?? null),
        'direction' => $isPromise ? requireEnum($issue, 'direction', DIRECTIONS) : null,
        'strength' => $isPromise ? requireEnum($issue, 'strength', STRENGTHS) : null,
        'status' => requireEnum($issue, 'status', $isPromise ? PROMISE_STATUSES : GRIEVANCE_STATUSES),
        'trajectory' => requireEnum($issue, 'trajectory', TRAJECTORIES),
        'wants' => requireEnum($issue, 'wants', WANTS),
        'evidence' => array_map(validateEvidence(...), $evidence),
        'links' => requireStringList($issue, 'links'),
        'affinity_applied' => requireStringList($issue, 'affinity_applied'),
        'muted' => ($issue['muted'] ?? false) === true,
        'dismissed' => ($issue['dismissed'] ?? false) === true,
        'changed_event_id' => $changed,
    ];
}

function validateEvidence(mixed $entry): array
{
    if (!is_array($entry)) {
        throw new UnexpectedValueException('evidence_invalid');
    }
    foreach (['ref', 'speaker', 'quote', 'label', 'backend'] as $key) {
        if (!is_string($entry[$key] ?? null)) {
            throw new UnexpectedValueException('evidence_invalid');
        }
    }
    $p = $entry['p'] ?? null;
    if ($p !== null && !((is_int($p) || is_float($p)) && is_finite((float) $p) && $p >= 0 && $p <= 1)) {
        throw new UnexpectedValueException('evidence_invalid');
    }
    $gamets = $entry['gamets'] ?? null;
    if ($gamets !== null && (!(is_int($gamets) || is_float($gamets)) || !is_finite((float) $gamets))) {
        throw new UnexpectedValueException('evidence_invalid');
    }
    if (mb_strlen($entry['quote']) > MAX_QUOTE_CHARS) {
        throw new UnexpectedValueException('evidence_quote_invalid');
    }
    return [
        'ref' => $entry['ref'],
        'gamets' => $gamets === null ? null : (float) $gamets,
        'speaker' => $entry['speaker'],
        'quote' => $entry['quote'],
        'label' => $entry['label'],
        'p' => $p === null ? null : (float) $p,
        'backend' => $entry['backend'],
    ];
}

function requireEnum(array $data, string $key, array $allowed): string
{
    $value = $data[$key] ?? null;
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        throw new UnexpectedValueException("issue_{$key}_invalid");
    }
    return $value;
}

function requireCounterpart(mixed $value): string
{
    if (!is_string($value) || preg_match('/^(player|npc:[1-9][0-9]*|daedra:[a-z][a-z_]*)$/D', $value) !== 1) {
        throw new UnexpectedValueException('issue_counterpart_invalid');
    }
    return $value;
}

function requireStringList(array $data, string $key): array
{
    $value = $data[$key] ?? null;
    if (!is_array($value) || !array_is_list($value)) {
        throw new UnexpectedValueException("issue_{$key}_invalid");
    }
    foreach ($value as $item) {
        if (!is_string($item)) {
            throw new UnexpectedValueException("issue_{$key}_invalid");
        }
    }
    return $value;
}

function requireSeededIds(array $data): array
{
    $seeded = requireStringList($data, 'seeded');
    if (count($seeded) > MAX_SEEDED || count(array_unique($seeded, SORT_STRING)) !== count($seeded)) {
        throw new UnexpectedValueException('ledger_seeded_invalid');
    }
    foreach ($seeded as $seedId) {
        if (preg_match('/\A[a-z0-9-]{3,80}\z/D', $seedId) !== 1) {
            throw new UnexpectedValueException('ledger_seeded_invalid');
        }
    }
    return $seeded;
}

function issueId(string $originRef, string $counterpart, string $kind): string
{
    return substr(hash('sha256', $originRef . '|' . $counterpart . '|' . $kind), 0, 16);
}

function isOpen(array $issue): bool
{
    return !$issue['dismissed'] && in_array($issue['status'], OPEN_STATUSES, true);
}

function isReplay(array $ledger, array $eventIds): bool
{
    foreach ($eventIds as $eventId) {
        if ($eventId <= $ledger['floor_event_id'] || in_array($eventId, $ledger['processed'], true)) {
            return true;
        }
    }
    return false;
}

function markProcessed(array $ledger, array $eventIds): array
{
    $all = array_values(array_unique(array_merge($ledger['processed'], $eventIds)));
    sort($all);
    $floor = $ledger['floor_event_id'];
    while (count($all) > MAX_PROCESSED) {
        $floor = max($floor, array_shift($all));
    }
    $ledger['processed'] = $all;
    $ledger['floor_event_id'] = $floor;
    return $ledger;
}

function enforceClosedCap(array $issues): array
{
    $open = array_values(array_filter($issues, isOpen(...)));
    $closed = array_values(array_filter($issues, static fn (array $issue): bool => !isOpen($issue)));
    usort($closed, static fn (array $a, array $b): int => $b['changed_event_id'] <=> $a['changed_event_id']);
    return array_merge($open, array_slice($closed, 0, MAX_CLOSED_ISSUES));
}

function clipText(string $text, int $max): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $max - 1)) . '…';
}
