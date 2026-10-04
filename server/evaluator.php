<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/backend.php';
require_once __DIR__ . '/questions.php';

/**
 * Judge one perspective (owner about counterpart) over the lines the owner witnessed.
 * Returns a proposal; nothing is persisted here. Backend exceptions propagate so the
 * worker can retry the job (Plan 2).
 *
 * @param array $ctx {owner, counterpart, lines: list<line> (already witness-filtered), open_issues: list<issue>}
 * @return array{new_issues: list<array>, updates: list<array>}
 */
function evaluatePerspective(array $ctx, DecisionBackend $backend, array $policy, ?callable $audit = null): array
{
    $proposal = ['new_issues' => [], 'updates' => []];
    if ($ctx['lines'] === []) {
        return $proposal;
    }
    $state = buildState($ctx);
    $detect = $policy['detect_min'];

    $pass1 = pass1Questions($ctx);
    $answers1 = validatedAnswers($pass1, $backend->ask($state, $pass1));
    if ($audit !== null) {
        foreach ($ctx['open_issues'] as $issue) {
            $answer = $answers1['addresses:' . $issue['id']] ?? null;
            $audit(['issue_id' => $issue['id'], 'addresses' => $answer['value'] ?? null, 'p' => $answer['p'] ?? null]);
        }
    }
    $positives = [];
    foreach ($pass1 as $question) {
        if (confident($answers1, $question['id'], $detect) === true) {
            $positives[] = $question['id'];
        }
    }
    if ($positives === []) {
        return $proposal;
    }

    $pass2 = pass2Questions($ctx, $positives);
    $answers2 = validatedAnswers($pass2, $backend->ask($state, $pass2));
    $lines = lineLabels($ctx['lines']);
    $lineFor = static fn (string $id): ?array => $lines[(string) confident($answers2, $id, $detect)] ?? null;

    if (in_array('commitment', $positives, true)) {
        $who = confident($answers2, 'commitment.who', $detect);
        $strength = confident($answers2, 'commitment.strength', $detect);
        $acceptance = confident($answers2, 'commitment.acceptance', $detect);
        $line = $lineFor('commitment.line');
        if (is_string($who) && is_string($strength) && $acceptance === 'accepted' && $line !== null && $line['speaker'] === $who) {
            $mine = $who === $ctx['owner'];
            $proposal['new_issues'][] = [
                'kind' => 'promise',
                'direction' => $mine ? 'owed_by_me' : 'owed_to_me',
                'strength' => $strength,
                'wants' => $mine ? 'unknown' : 'keep_promise',
                'line' => $line,
                'label' => 'commitment',
                'p' => min($answers1['commitment']['p'], $answers2['commitment.who']['p'], $answers2['commitment.strength']['p'], $answers2['commitment.acceptance']['p'], $answers2['commitment.line']['p']),
            ];
        }
    }

    if (in_array('wronged', $positives, true)) {
        $line = $lineFor('grievance.line');
        $wants = confident($answers2, 'grievance.wants', $detect);
        if ($line !== null && $line['speaker'] === $ctx['owner']) {
            $proposal['new_issues'][] = [
                'kind' => 'grievance',
                'direction' => null,
                'strength' => null,
                'wants' => is_string($wants) ? $wants : 'unknown',
                'line' => $line,
                'label' => 'wronged',
                'p' => min($answers1['wronged']['p'], $answers2['grievance.line']['p']),
            ];
        }
    }

    foreach ($ctx['open_issues'] as $issue) {
        if (!in_array("addresses:{$issue['id']}", $positives, true)) {
            continue;
        }
        $prefix = "issue:{$issue['id']}";
        $line = $lineFor("{$prefix}.line");
        if ($line === null) {
            continue;
        }
        $status = $answers2["{$prefix}.status"] ?? null;
        $statusP = min($status['p'] ?? 0.0, $answers1["addresses:{$issue['id']}"]['p'], $answers2["{$prefix}.line"]['p']);
        $wants = $issue['kind'] === 'grievance' ? confident($answers2, "{$prefix}.wants", $detect) : null;
        $trajectory = confident($answers2, "{$prefix}.trajectory", $detect);
        $proposal['updates'][] = [
            'issue_id' => $issue['id'],
            'status' => ($status !== null && $statusP >= $policy['status_min']) ? $status['value'] : null,
            'status_p' => $statusP,
            'trajectory' => is_string($trajectory) ? $trajectory : null,
            'wants' => is_string($wants) ? $wants : null,
            'line' => $line,
        ];
    }

    return $proposal;
}
