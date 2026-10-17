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
    $candidates = $state['substance_candidates'];
    $substanceContext = substanceContext($ctx['lines']);
    $lineFor = static fn (string $id): ?array => $lines[(string) confident($answers2, $id, $detect)] ?? null;
    $sourceFor = static function (string $id) use ($answers2, $detect, $candidates): ?array {
        $label = confident($answers2, $id, $detect);
        return is_string($label) ? ($candidates[$label] ?? null) : null;
    };
    $relatedFor = static function (string $id) use ($answers2, $detect): ?string {
        $relatedId = confident($answers2, $id, $detect);
        return is_string($relatedId) && $relatedId !== 'none' ? $relatedId : null;
    };

    if (in_array('commitment', $positives, true)) {
        $who = confident($answers2, 'commitment.who', $detect);
        $strength = confident($answers2, 'commitment.strength', $detect);
        $acceptance = confident($answers2, 'commitment.acceptance', $detect);
        $line = $lineFor('commitment.line');
        $matter = $sourceFor('commitment.matter');
        $settlement = $sourceFor('commitment.settlement');
        if (is_string($who) && is_string($strength) && $acceptance === 'accepted' && $line !== null && $line['speaker'] === $who && $matter !== null && $substanceContext !== null) {
            $mine = $who === $ctx['owner'];
            $proposal['new_issues'][] = [
                'kind' => 'promise',
                'direction' => $mine ? 'owed_by_me' : 'owed_to_me',
                'strength' => $strength,
                'wants' => $mine ? 'unknown' : 'keep_promise',
                'line' => $line,
                'label' => 'commitment',
                'p' => min($answers1['commitment']['p'], $answers2['commitment.who']['p'], $answers2['commitment.strength']['p'], $answers2['commitment.acceptance']['p'], $answers2['commitment.line']['p'], $answers2['commitment.matter']['p']),
                'substance' => [
                    'version' => 1,
                    'nature' => 'obligation',
                    'matter' => $matter,
                    'settlement' => [
                        'mode' => $settlement === null ? 'complete_obligation' : 'explicit',
                        'source' => $settlement,
                    ],
                    'context' => $substanceContext,
                ],
                'substance_lines' => $ctx['lines'],
                'related_issue_id' => $relatedFor('commitment.related'),
            ];
        }
    }

    if (in_array('wronged', $positives, true)) {
        $line = $lineFor('grievance.line');
        $wants = confident($answers2, 'grievance.wants', $detect);
        $matter = $sourceFor('grievance.matter');
        $nature = confident($answers2, 'grievance.nature', $detect);
        $settlement = $sourceFor('grievance.settlement');
        if ($line !== null && $line['speaker'] === $ctx['owner'] && $matter !== null && $substanceContext !== null) {
            $proposal['new_issues'][] = [
                'kind' => 'grievance',
                'direction' => null,
                'strength' => null,
                'wants' => is_string($wants) ? $wants : 'unknown',
                'line' => $line,
                'label' => 'wronged',
                'p' => min($answers1['wronged']['p'], $answers2['grievance.line']['p'], $answers2['grievance.matter']['p']),
                'substance' => [
                    'version' => 1,
                    'nature' => is_string($nature) ? $nature : 'unknown',
                    'matter' => $matter,
                    'settlement' => [
                        'mode' => $settlement === null ? 'unknown' : 'explicit',
                        'source' => $settlement,
                    ],
                    'context' => $substanceContext,
                ],
                'substance_lines' => $ctx['lines'],
                'related_issue_id' => $relatedFor('grievance.related'),
            ];
        }
    }

    foreach ($ctx['open_issues'] as $issue) {
        if (!in_array("addresses:{$issue['id']}", $positives, true)) {
            continue;
        }
        $prefix = "issue:{$issue['id']}";
        $lineAnswer = $answers2["{$prefix}.line"] ?? null;
        $line = $lineFor("{$prefix}.line");
        $status = $answers2["{$prefix}.status"] ?? null;
        $addressAnswer = $answers1["addresses:{$issue['id']}"] ?? null;
        $statusP = min($status['p'] ?? 0.0, $addressAnswer['p'] ?? 0.0, $lineAnswer['p'] ?? 0.0);
        if ($line === null) {
            $relatedAnswer = $answers2['commitment.related'] ?? null;
            $replacement = null;
            $lineWasUncertain = $lineAnswer === null || $lineAnswer['p'] < $detect;
            if ($lineWasUncertain && $issue['kind'] === 'promise'
                && ($status['value'] ?? null) === 'renegotiated'
                && ($status['p'] ?? 0.0) >= $policy['status_min']
                && ($addressAnswer['value'] ?? null) === true
                && ($addressAnswer['p'] ?? 0.0) >= $detect
                && ($relatedAnswer['value'] ?? null) === $issue['id']
                && ($relatedAnswer['p'] ?? 0.0) >= $detect) {
                foreach ($proposal['new_issues'] as $newIssue) {
                    $context = $newIssue['substance']['context'] ?? null;
                    if ($newIssue['kind'] === 'promise'
                        && $newIssue['related_issue_id'] === $issue['id']
                        && $newIssue['direction'] === $issue['direction']
                        && isset($newIssue['p']) && $newIssue['p'] >= $detect
                        && is_array($context) && $context !== []
                        && is_array($newIssue['line'] ?? null)) {
                        $replacement = $newIssue;
                        break;
                    }
                }
            }
            if ($replacement === null) {
                continue;
            }
            $line = $replacement['line'];
            $statusP = min(
                $status['p'],
                $addressAnswer['p'],
                $relatedAnswer['p'],
                $replacement['p']
            );
        }
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

    $openPromises = [];
    foreach ($ctx['open_issues'] as $issue) {
        if ($issue['kind'] === 'promise' && in_array($issue['status'], OPEN_STATUSES, true)) {
            $openPromises[$issue['id']] = $issue;
        }
    }
    $updatesById = [];
    foreach ($proposal['updates'] as $update) {
        $updatesById[$update['issue_id']] = $update;
    }
    foreach ($proposal['new_issues'] as &$newIssue) {
        $relatedId = $newIssue['related_issue_id'];
        $originalPromise = $relatedId === null ? null : ($openPromises[$relatedId] ?? null);
        $relatedUpdate = $relatedId === null ? null : ($updatesById[$relatedId] ?? null);
        $qualifies = $originalPromise !== null && $relatedUpdate !== null && match ($newIssue['kind']) {
            'promise' => $relatedUpdate['status'] === 'renegotiated'
                && $originalPromise['direction'] === $newIssue['direction'],
            'grievance' => in_array($relatedUpdate['status'], ['broken', 'disputed'], true),
            default => false,
        };
        if (!$qualifies) {
            $newIssue['related_issue_id'] = null;
        }
    }
    unset($newIssue);

    return $proposal;
}
