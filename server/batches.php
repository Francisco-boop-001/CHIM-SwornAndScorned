<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/witness.php';

const SETTLE_SECONDS = 20;
const SETTLE_LINES = 6;
const EMITTED_GRACE_SECONDS = 120;

/**
 * Cut the scan before the first young unacknowledged NPC line, so it is not skipped
 * before its ACK turns it into 'spoken'.
 *
 * @param list<array> $rows raw eventlog rows ordered by rowid
 * @return array{rows: list<array>, read_up_to: int}
 */
function scanWindow(array $rows, int $now): array
{
    foreach ($rows as $index => $row) {
        if (($row['type'] ?? '') === 'chat'
            && ($row['delivery_state'] ?? null) === 'emitted'
            && $now - (int) ($row['localts'] ?? 0) < EMITTED_GRACE_SECONDS) {
            return ['rows' => array_slice($rows, 0, $index), 'read_up_to' => max(0, (int) $row['rowid'] - 1)];
        }
    }
    return ['rows' => $rows, 'read_up_to' => $rows === [] ? 0 : (int) $rows[array_key_last($rows)]['rowid']];
}

/**
 * Perspectives (owner in cast, counterpart) a line contributes to.
 * @param array<string, int> $castIds cast NPC name => npc id
 * @return list<array{0: string, 1: string}>
 */
function perspectivesOf(array $line, array $castIds): array
{
    if (($line['type'] ?? null) === 'written_evidence') {
        $w = $line['written'] ?? null;
        return is_array($w) && ($castIds[$w['recipient']['name']] ?? null) === $w['recipient']['id']
            ? [[$w['recipient']['name'], $w['claimed_sender']['name']]] : [];
    }
    $speaker = $line['speaker'];
    $listener = $line['listener'];
    if ($listener === SENTINEL_LISTENER) {
        return [];
    }
    $perspectives = [];
    if ($listener !== '') {
        if (isset($castIds[$speaker]) && $listener !== $speaker) {
            $perspectives[] = [$speaker, $listener];
        }
        if (isset($castIds[$listener]) && $listener !== $speaker && in_array($listener, $line['people'], true)) {
            $perspectives[] = [$listener, $speaker];
        }
        return $perspectives;
    }
    foreach ($line['people'] as $person) {
        if ($person !== $speaker && isset($castIds[$person])) {
            $perspectives[] = [$person, $speaker];
        }
    }
    return $perspectives;
}

/**
 * Determine pair settlement, then emit consecutive per-owner counterpart segments.
 * Later segments wait behind the owner's first unsettled pair. Evaluation context
 * is the segment; an intervening counterpart can separate commitment and acceptance.
 *
 * @param array<string, int> $marks "ownerId|counterpartName" => last emitted event id
 * @return array{jobs: list<array>, cursor: int}
 */
function settledBatches(array $lines, array $castIds, array $marks, int $now, int $readUpTo): array
{
    $groups = [];
    foreach ($lines as $line) {
        foreach (perspectivesOf($line, $castIds) as [$owner, $counterpart]) {
            $key = $castIds[$owner] . '|' . $counterpart;
            if ($line['event_id'] <= ($marks[$key] ?? 0)) {
                continue;
            }
            $groups[$key] ??= ['owner_npc_id' => $castIds[$owner], 'owner_name' => $owner, 'counterpart_name' => $counterpart, 'lines' => []];
            $groups[$key]['lines'][] = $line;
        }
    }
    $ready = [];
    foreach ($groups as $group) {
        $last = $group['lines'][array_key_last($group['lines'])];
        $ready[$group['owner_npc_id'] . '|' . $group['counterpart_name']] =
            count($group['lines']) >= SETTLE_LINES || $now - $last['localts'] >= SETTLE_SECONDS;
    }
    // Each owner's processed floor spans counterparts, so job ranges cannot overlap.
    $segments = [];
    $lastSegment = [];
    foreach ($lines as $line) {
        foreach (perspectivesOf($line, $castIds) as [$owner, $counterpart]) {
            $ownerId = $castIds[$owner];
            $key = $ownerId . '|' . $counterpart;
            if ($line['event_id'] <= ($marks[$key] ?? 0)) continue;
            $index = $lastSegment[$ownerId] ?? null;
            if ($index === null || $segments[$index]['counterpart_name'] !== $counterpart) {
                $segments[] = ['owner_npc_id' => $ownerId, 'owner_name' => $owner,
                    'counterpart_name' => $counterpart, 'event_ids' => []];
                $index = array_key_last($segments);
                $lastSegment[$ownerId] = $index;
            }
            $segments[$index]['event_ids'][] = $line['event_id'];
        }
    }
    $jobs = [];
    $blocked = [];
    $cursor = $readUpTo;
    foreach ($segments as $segment) {
        $ownerId = $segment['owner_npc_id'];
        if (isset($blocked[$ownerId]) || !$ready[$ownerId . '|' . $segment['counterpart_name']]) {
            $blocked[$ownerId] = true;
            $cursor = min($cursor, $segment['event_ids'][0] - 1);
        } else {
            $jobs[] = $segment;
        }
    }
    return ['jobs' => $jobs, 'cursor' => $cursor];
}
