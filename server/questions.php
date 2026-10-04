<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/vocab.php';

const PROMISE_STATUS_CHOICES = ['fulfilled', 'broken', 'renegotiated', 'released', 'disputed', 'no_change'];
const GRIEVANCE_STATUS_CHOICES = ['resolved', 'let_go', 'no_change'];
const ACCEPTANCE_CHOICES = ['accepted', 'declined', 'unclear'];

function noul(string $id, string $text): array
{
    return ['id' => $id, 'type' => 'noul', 'text' => $text, 'options' => []];
}

function choice(string $id, string $text, array $options): array
{
    return ['id' => $id, 'type' => 'choice', 'text' => $text, 'options' => array_values($options)];
}

/** @return array<string, array> lines keyed L1..Ln in the given order */
function lineLabels(array $lines): array
{
    $labelled = [];
    foreach (array_values($lines) as $index => $line) {
        $labelled['L' . ($index + 1)] = $line;
    }
    return $labelled;
}

function issueSummary(array $issue, string $owner, string $counterpart): string
{
    $what = match (true) {
        $issue['kind'] === 'grievance' => "{$owner} feels wronged by {$counterpart}",
        $issue['direction'] === 'owed_by_me' => "{$owner} promised {$counterpart} something",
        default => "{$counterpart} promised {$owner} something",
    };
    $what .= ' (status: ' . $issue['status'] . ($issue['kind'] === 'promise' ? '; strength: ' . $issue['strength'] : '') . ')';
    if ($issue['evidence'] === []) return $what;
    $origin = $issue['evidence'][0];
    $latest = $issue['evidence'][array_key_last($issue['evidence'])];
    return "{$what}; originally: \"{$origin['quote']}\""
        . (count($issue['evidence']) > 1 ? "; latest: \"{$latest['quote']}\"" : '');
}

/**
 * @param array $ctx {owner: string, counterpart: string, lines: list<line>, open_issues: list<issue>}
 */
function buildState(array $ctx): array
{
    $lines = [];
    foreach (lineLabels($ctx['lines']) as $label => $line) {
        $lines[$label] = $line['speaker'] . ': ' . $line['text'];
    }
    $issues = [];
    foreach ($ctx['open_issues'] as $issue) {
        $issues[$issue['id']] = issueSummary($issue, $ctx['owner'], $ctx['counterpart']);
    }
    return ['owner' => $ctx['owner'], 'counterpart' => $ctx['counterpart'], 'lines' => $lines, 'open_issues' => $issues];
}

function pass1Questions(array $ctx): array
{
    $owner = $ctx['owner'];
    $counterpart = $ctx['counterpart'];
    $questions = [];
    foreach ($ctx['open_issues'] as $issue) {
        $questions[] = noul(
            "addresses:{$issue['id']}",
            "Do these lines address this open matter between {$owner} and {$counterpart}: "
            . issueSummary($issue, $owner, $counterpart) . '?'
        );
    }
    $questions[] = noul('commitment', "In these lines, does {$owner} or {$counterpart} commit to doing something for the other?");
    $questions[] = noul('wronged', "In these lines, does {$owner} express feeling wronged by {$counterpart}?");
    return $questions;
}

/**
 * @param list<string> $positives pass 1 question ids answered true with enough confidence
 */
function pass2Questions(array $ctx, array $positives): array
{
    $owner = $ctx['owner'];
    $counterpart = $ctx['counterpart'];
    $labels = array_keys(lineLabels($ctx['lines']));
    $questions = [];
    if (in_array('commitment', $positives, true)) {
        $questions[] = choice('commitment.who', 'Who made the commitment?', [$owner, $counterpart]);
        $questions[] = choice('commitment.strength', 'Is it a firm commitment ("I will") or only an attempt ("I will try")?', STRENGTHS);
        $questions[] = choice('commitment.acceptance', 'Did the other person accept the commitment?', ACCEPTANCE_CHOICES);
        $questions[] = choice('commitment.line', 'Which line contains the commitment?', $labels);
    }
    if (in_array('wronged', $positives, true)) {
        $questions[] = choice('grievance.line', "Which line shows {$owner} feeling wronged?", $labels);
        $questions[] = choice('grievance.wants', "What does {$owner} want from {$counterpart} about it?", WANTS);
    }
    foreach ($ctx['open_issues'] as $issue) {
        if (!in_array("addresses:{$issue['id']}", $positives, true)) {
            continue;
        }
        $prefix = "issue:{$issue['id']}";
        $isPromise = $issue['kind'] === 'promise';
        $questions[] = choice("{$prefix}.status", 'After these lines, what is the state of this matter?', $isPromise ? PROMISE_STATUS_CHOICES : GRIEVANCE_STATUS_CHOICES);
        $questions[] = choice("{$prefix}.trajectory", 'Did these lines make it better, worse, or neither?', TRAJECTORIES);
        $questions[] = choice("{$prefix}.line", 'Which line matters most for it?', $labels);
        if (!$isPromise) {
            $questions[] = choice("{$prefix}.wants", "What does {$owner} now want from {$counterpart} about it?", WANTS);
        }
    }
    return $questions;
}
