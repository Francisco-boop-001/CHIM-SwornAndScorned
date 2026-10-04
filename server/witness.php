<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

// Speech rows only. Instruction rows (PCV "The Narrator: <direction>") and action rows
// (e.g. SHARMAT Consume) are never evidence. Exact values are Plan 0 question 6.
const SPEECH_EVENT_TYPES = ['chat', 'inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'];

// PCV addresses solo reflection and wrap-up lines here; they are not exchanges.
const SENTINEL_LISTENER = 'explicit_disable_rechat';

/**
 * Lines spoken by either member of the pair that the perspective owner witnessed
 * (owner spoke them or is in the line's people list), ordered by event id.
 */
function pairLinesWitnessedBy(array $lines, string $owner, string $counterpart): array
{
    $kept = array_values(array_filter($lines, static function (array $line) use ($owner, $counterpart): bool {
        if (!in_array($line['type'], SPEECH_EVENT_TYPES, true) || $line['listener'] === SENTINEL_LISTENER) {
            return false;
        }
        if ($line['speaker'] !== $owner && $line['speaker'] !== $counterpart) {
            return false;
        }
        return $line['speaker'] === $owner || in_array($owner, $line['people'], true);
    }));
    usort($kept, static fn (array $a, array $b): int => $a['event_id'] <=> $b['event_id']);
    return $kept;
}
