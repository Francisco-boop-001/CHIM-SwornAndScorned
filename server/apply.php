<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

use UnexpectedValueException;

require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/transitions.php';

/**
 * Apply an evaluator proposal to one perspective ledger. Pure: the caller persists the
 * returned ledger and affinity delta together (Plan 2 store).
 *
 * @param array $meta {event_ids: list<int>, counterpart: string, backend: string, policy: array}
 * @return array{status: string, ledger: array, affinity_delta: int, transitions: list<string>, skipped: list<string>}
 */
function applyProposal(array $ledger, array $proposal, array $meta): array
{
    $eventIds = $meta['event_ids'] ?? null;
    if (!is_array($eventIds) || !array_is_list($eventIds) || $eventIds === []) {
        throw new UnexpectedValueException('batch_empty');
    }
    foreach ($eventIds as $eventId) {
        if (!is_int($eventId) || $eventId <= 0) {
            throw new UnexpectedValueException('batch_event_invalid');
        }
    }
    requireCounterpart($meta['counterpart'] ?? null);
    if (isReplay($ledger, $eventIds)) {
        return ['status' => 'replay', 'ledger' => $ledger, 'affinity_delta' => 0, 'transitions' => [], 'skipped' => []];
    }
    $batchMax = max($eventIds);
    $delta = 0;
    $transitions = [];
    $skipped = [];
    $changedIds = [];
    $newIds = [];
    $richNewIds = [];
    $relatedIssueIds = [];
    $issues = $ledger['issues'];
    $index = [];
    foreach ($issues as $position => $issue) {
        $index[$issue['id']] = $position;
    }

    foreach ($proposal['updates'] as $update) {
        $position = $index[$update['issue_id']] ?? null;
        if ($position === null || !isOpen($issues[$position]) || $issues[$position]['counterpart'] !== $meta['counterpart']) {
            $skipped[] = 'update_target_missing';
            continue;
        }
        $issue = $issues[$position];
        validateProbability($update['status_p'] ?? null);
        if ($update['line'] === null) {
            $skipped[] = 'update_evidence_missing';
            continue;
        }
        validateProposalLine($update['line'], $eventIds);
        foreach (['trajectory' => TRAJECTORIES, 'wants' => WANTS] as $field => $allowed) {
            if ($update[$field] !== null) {
                requireEnum($update, $field, $allowed);
            }
        }
        $label = 'addressed';
        $to = $update['status'];
        if ($to !== null) {
            requireEnum($update, 'status', array_merge($issue['kind'] === 'promise' ? PROMISE_STATUSES : GRIEVANCE_STATUSES, $issue['kind'] === 'promise' ? ['renegotiated', 'no_change'] : ['no_change']));
        }
        if ($update['status_p'] < $meta['policy']['status_min']) {
            $to = null;
        }
        if (is_string($to) && $to !== 'no_change') {
            $label = $to;
            if (transitionAllowed($issue['kind'], $issue['status'], $to)) {
                $key = transitionKey($issue['id'], $issue['status'], $to);
                if ($update['status_p'] >= $meta['policy']['affinity_min'] && !in_array($key, $issue['affinity_applied'], true)) {
                    $delta += lifecycleAffinityDelta($issue, $to);
                    $issue['affinity_applied'][] = $key;
                }
                $issue['status'] = $to;
                $transitions[] = $key;
                $changedIds[$issue['id']] = $to;
            } elseif ($to === 'renegotiated') {
                // Terms changed; legacy proposals may link this promise when the match is unambiguous.
                $changedIds[$issue['id']] = $to;
            } else {
                $skipped[] = 'transition_not_allowed';
            }
        }
        if (is_string($update['trajectory'])) {
            $issue['trajectory'] = $update['trajectory'];
        }
        if (is_string($update['wants'])) {
            $issue['wants'] = $update['wants'];
        }
        if ($update['line'] !== null) {
            $issue['evidence'] = appendEvidence(
                $issue['evidence'],
                evidenceFromLine($update['line'], $label, $update['status_p'], $meta['backend'])
            );
        }
        $issue['changed_event_id'] = $batchMax;
        $issues[$position] = $issue;
    }

    foreach ($proposal['new_issues'] as $new) {
        requireEnum($new, 'kind', KINDS);
        requireEnum($new, 'wants', WANTS);
        if ($new['kind'] === 'promise') {
            requireEnum($new, 'direction', DIRECTIONS);
            requireEnum($new, 'strength', STRENGTHS);
        }
        validateProbability($new['p'] ?? null);
        validateProposalLine($new['line'], $eventIds);
        if (!is_string($new['label'] ?? null)) {
            throw new UnexpectedValueException('proposal_label_invalid');
        }
        $hasSubstance = array_key_exists('substance', $new);
        $substance = null;
        $relatedIssueId = null;
        if ($hasSubstance) {
            $substanceLines = $new['substance_lines'] ?? null;
            if (!is_array($substanceLines) || !array_is_list($substanceLines)) {
                throw new UnexpectedValueException('proposal_substance_lines_invalid');
            }
            foreach ($substanceLines as $substanceLine) {
                validateProposalLine($substanceLine, $eventIds);
            }
            $substance = validateSubstanceForLines($new['substance'], $substanceLines);
            if ($substance === null || $substance['matter'] === null
                || ($substance['settlement']['mode'] === 'complete_obligation' && $new['kind'] !== 'promise')) {
                throw new UnexpectedValueException('proposal_substance_invalid');
            }
            $relatedIssueId = $new['related_issue_id'] ?? null;
            if ($relatedIssueId !== null && (!is_string($relatedIssueId) || $relatedIssueId === '')) {
                throw new UnexpectedValueException('proposal_related_issue_invalid');
            }
        }
        if ($new['p'] < $meta['policy']['detect_min']) {
            $skipped[] = 'new_confidence_low';
            continue;
        }
        $id = issueId('e:' . $new['line']['event_id'], $meta['counterpart'], $new['kind']);
        if (isset($index[$id])) {
            $skipped[] = 'issue_exists';
            continue;
        }
        if (count(array_filter($issues, isOpen(...))) >= MAX_OPEN_ISSUES) {
            $skipped[] = 'open_cap';
            continue;
        }
        $isPromise = $new['kind'] === 'promise';
        $issue = [
            'id' => $id,
            'origin' => 'event',
            'kind' => $new['kind'],
            'counterpart' => $meta['counterpart'],
            'direction' => $isPromise ? $new['direction'] : null,
            'strength' => $isPromise ? $new['strength'] : null,
            'status' => $isPromise ? 'pending' : 'open',
            'trajectory' => 'unchanged',
            'wants' => $new['wants'],
            'evidence' => [evidenceFromLine($new['line'], $new['label'], $new['p'], $meta['backend'])],
            'links' => [],
            'affinity_applied' => [],
            'muted' => false,
            'dismissed' => false,
            'changed_event_id' => $batchMax,
        ];
        if ($hasSubstance) {
            $issue['substance'] = $substance;
        }
        $issues[] = $issue;
        $index[$id] = array_key_last($issues);
        $newIds[] = $id;
        if ($hasSubstance) {
            $richNewIds[$id] = true;
            $relatedIssueIds[$id] = $relatedIssueId;
        }
    }

    // Rich records link only to their explicitly selected, qualifying predecessor.
    foreach ($newIds as $newId) {
        if (isset($richNewIds[$newId])) {
            $relatedId = $relatedIssueIds[$newId];
            if ($relatedId === null) {
                continue;
            }
            $position = $index[$relatedId] ?? null;
            $change = $changedIds[$relatedId] ?? null;
            $newKind = $issues[$index[$newId]]['kind'];
            if ($position === null || !isset($changedIds[$relatedId])
                || $issues[$position]['kind'] !== 'promise'
                || $issues[$position]['counterpart'] !== $meta['counterpart']
                || ($newKind === 'promise' && $issues[$position]['direction'] !== $issues[$index[$newId]]['direction'])
                || ($newKind === 'promise' ? $change !== 'renegotiated' : !in_array($change, ['broken', 'disputed'], true))) {
                throw new UnexpectedValueException('proposal_related_issue_invalid');
            }
            $issues[$index[$newId]]['links'][] = $relatedId;
            $issues[$position]['links'][] = $newId;
            if ($newKind === 'promise' && transitionAllowed('promise', $issues[$position]['status'], 'released')) {
                $transitions[] = transitionKey($relatedId, $issues[$position]['status'], 'released');
                $issues[$position]['status'] = 'released';
            }
            continue;
        }

        // Legacy proposals keep the original unambiguous-link behavior.
        $kind = $issues[$index[$newId]]['kind'];
        $candidates = [];
        foreach ($changedIds as $changedId => $change) {
            if ($issues[$index[$changedId]]['kind'] === 'promise'
                && ($kind === 'promise' ? $change === 'renegotiated' : in_array($change, ['disputed', 'broken'], true))) {
                $candidates[] = $changedId;
            }
        }
        if (count($candidates) === 1) {
            $other = $candidates[0];
            $issues[$index[$newId]]['links'][] = $other;
            $issues[$index[$other]]['links'][] = $newId;
        }
    }

    $ledger['issues'] = enforceClosedCap($issues);
    $ledger = markProcessed($ledger, $eventIds);
    return ['status' => 'applied', 'ledger' => $ledger, 'affinity_delta' => $delta, 'transitions' => $transitions, 'skipped' => $skipped];
}

function evidenceFromLine(array $line, string $label, float $p, string $backend): array
{
    return [
        'ref' => 'e:' . $line['event_id'],
        'gamets' => (float) $line['gamets'],
        'speaker' => $line['speaker'],
        'quote' => mb_strlen($line['text']) <= MAX_QUOTE_CHARS ? $line['text'] : mb_substr($line['text'], 0, MAX_QUOTE_CHARS - 1) . '…',
        'label' => $label,
        'p' => $p,
        'backend' => $backend,
    ];
}

function validateProbability(mixed $p): void
{
    if (!(is_int($p) || is_float($p)) || !is_finite((float) $p) || $p < 0 || $p > 1) {
        throw new UnexpectedValueException('proposal_probability_invalid');
    }
}

function validateProposalLine(mixed $line, array $eventIds): void
{
    if (!is_array($line) || !is_int($line['event_id'] ?? null) || !in_array($line['event_id'], $eventIds, true)
        || !is_string($line['speaker'] ?? null) || !is_string($line['text'] ?? null)
        || !(is_int($line['gamets'] ?? null) || is_float($line['gamets'] ?? null)) || !is_finite((float) $line['gamets'])) {
        throw new UnexpectedValueException('proposal_line_invalid');
    }
}

/** Keep the originating evidence plus the most recent entries. */
function appendEvidence(array $evidence, array $entry): array
{
    $evidence[] = $entry;
    if (count($evidence) > MAX_EVIDENCE) {
        $evidence = array_merge([$evidence[0]], array_slice($evidence, -(MAX_EVIDENCE - 1)));
    }
    return $evidence;
}
