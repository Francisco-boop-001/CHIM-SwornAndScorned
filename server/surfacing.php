<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/ledger.php';

const SURFACE_COOLDOWN_SECONDS = 600;

const WANTS_PHRASES = [
    'explanation' => 'You want an explanation.',
    'apology' => 'You want an apology.',
    'restitution' => 'You want amends.',
    'keep_promise' => 'You want that promise kept.',
    'thanks' => 'You want to give thanks.',
    'unknown' => '',
];

/**
 * Pick at most one issue for the speaker's turn (spec §7.1).
 *
 * @param list<string> $presentCounterparts counterpart keys present this turn (npc:<id>, player)
 * @param list<string> $mentionedCounterparts counterpart keys named in the current exchange or player input
 * @param array<string, array{at: int, evidence_count: int, evidence_ref?: string}> $pacing last surfacing per issue id
 */
function selectIssueToSurface(
    array $ledger,
    array $presentCounterparts,
    array $mentionedCounterparts,
    array $pacing,
    int $now,
    int $cooldownSeconds = SURFACE_COOLDOWN_SECONDS,
    ?array $playerMentionedCounterparts = null
): ?array {
    $playerMentionedCounterparts ??= $mentionedCounterparts;
    $eligible = [];
    foreach ($ledger['issues'] as $issue) {
        if (!isOpen($issue) || $issue['muted']) {
            continue;
        }
        $counterpart = $issue['counterpart'];
        $mentioned = in_array($counterpart, $mentionedCounterparts, true);
        $reachable = str_starts_with($counterpart, 'daedra:')
            ? $mentioned
            : in_array($counterpart, $presentCounterparts, true);
        if (!$reachable) {
            continue;
        }
        $last = $pacing[$issue['id']] ?? null;
        $cooling = $last !== null
            && $now - $last['at'] < $cooldownSeconds
            && (isset($last['evidence_ref'])
                ? $last['evidence_ref'] === ($issue['evidence'][array_key_last($issue['evidence'])]['ref'] ?? '')
                : $last['evidence_count'] === count($issue['evidence']));
        if ($cooling && !in_array($counterpart, $playerMentionedCounterparts, true)) {
            continue;
        }
        $eligible[] = $issue;
    }
    if ($eligible === []) {
        return null;
    }
    usort($eligible, static fn (array $a, array $b): int => [$b['changed_event_id'], $a['id']] <=> [$a['changed_event_id'], $b['id']]);
    return $eligible[0];
}

/** Private, speaker-side context line of at most MAX_NOTE_CHARS characters. */
function renderNote(array $issue, string $ownerName, string $counterpartName): string
{
    $counterpartName = clipText($counterpartName, 40);
    $head = match (true) {
        $issue['kind'] === 'grievance' => "On your mind: unresolved with {$counterpartName}.",
        $issue['direction'] === 'owed_by_me' => "On your mind: you promised {$counterpartName} something.",
        default => "On your mind: {$counterpartName} promised you something.",
    };
    $tail = trim(WANTS_PHRASES[$issue['wants']] . ' Raise it only if it fits the moment.');
    if ($issue['evidence'] === []) {
        return clipText("{$head} {$tail}", MAX_NOTE_CHARS);
    }
    $evidence = $issue['evidence'][array_key_last($issue['evidence'])];
    $who = match (true) {
        $issue['origin'] === 'lore' => 'Old grudge',
        $evidence['speaker'] === $ownerName => 'You said',
        default => clipText($evidence['speaker'], 40) . ' said',
    };
    $fixed = mb_strlen("{$head} {$who}: \"\" {$tail}");
    $quote = clipText($evidence['quote'], max(1, MAX_NOTE_CHARS - $fixed));
    return clipText("{$head} {$who}: \"{$quote}\" {$tail}", MAX_NOTE_CHARS);
}
