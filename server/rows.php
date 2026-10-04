<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

const PLAYER_INPUT_TYPES = ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'];

function parsePeople(?string $people): array
{
    if ($people === null || $people === '') {
        return [];
    }
    return array_values(array_filter(
        array_map('trim', explode('|', $people)),
        static fn (string $name): bool => $name !== ''
    ));
}

/**
 * eventlog row -> Line, or null when the row is not usable speech.
 * NPC lines count only once acknowledged (delivery_state 'spoken'); instruction, background
 * and action rows never count (spec §2a, Amendment A).
 *
 * @param array $row {rowid, type, data, people, gamets, localts, delivery_state} as strings from PostgreSQL
 */
function lineFromEventRow(array $row, string $playerName): ?array
{
    $type = $row['type'] ?? null;
    $data = $row['data'] ?? null;
    $people = $row['people'] ?? null;
    $eventId = filter_var($row['rowid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $gameTime = $row['gamets'] ?? null;
    $localTime = filter_var($row['localts'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if (!is_string($type) || !is_string($data) || ($people !== null && !is_string($people))
        || $eventId === false || !is_numeric($gameTime) || !is_finite((float) $gameTime) || $localTime === false) {
        return null;
    }
    if ($type === 'chat') {
        if (($row['delivery_state'] ?? null) !== 'spoken') {
            return null;
        }
    } elseif (!in_array($type, PLAYER_INPUT_TYPES, true)) {
        return null;
    }
    if (preg_match('/\A(?<speaker>[^:\n]{1,128}): (?<body>.+)\z/s', $data, $match) !== 1) {
        return null;
    }
    $speaker = trim($match['speaker']);
    $text = trim($match['body']);
    if ($speaker === '') {
        return null;
    }
    $listener = '';
    if ($type === 'chat') {
        $hasListenerMarker = str_contains($text, ' (talking to ');
        if (preg_match('/\A(?<text>.*) \(talking to (?<listener>[^()\n]{1,128})\)\z/s', $text, $target) === 1) {
            $text = trim($target['text']);
            $listener = trim($target['listener']);
            if ($listener === '') {
                return null;
            }
        } elseif ($hasListenerMarker) {
            return null;
        }
    }
    if ($type !== 'chat' && $speaker !== $playerName) {
        return null;
    }
    if ($text === '') {
        return null;
    }
    return [
        'event_id' => $eventId,
        'gamets' => (float) $gameTime,
        'type' => $type,
        'speaker' => $speaker,
        'listener' => $listener,
        'people' => parsePeople($people),
        'text' => $text,
        'localts' => $localTime,
    ];
}
