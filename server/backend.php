<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

/**
 * A decision backend answers typed questions about a structured state.
 * Adapters (OpenRouter Decisions API, CHIM LLM fallback) arrive in Plan 2.
 */
interface DecisionBackend
{
    public function name(): string;

    /**
     * @param array $state JSON-serialisable state (owner, counterpart, labelled lines, open issues)
     * @param list<array{id: string, type: string, text: string, options: list<string>}> $questions
     * @return mixed Raw answers keyed by question id: ['id' => ['value' => bool|string, 'p' => float]].
     *               Validated by validatedAnswers(); anything else is ignored.
     */
    public function ask(array $state, array $questions): mixed;
}

// Thresholds per backend (spec §6.3, §6.4). The LLM adapter maps its self-reported
// high/medium/low to numbers so that only "high" clears these minimums.
const POLICY_DECISION_MODEL = ['detect_min' => 0.80, 'status_min' => 0.80, 'affinity_min' => 0.90];
const POLICY_LLM_FALLBACK = ['detect_min' => 0.90, 'status_min' => 0.90, 'affinity_min' => 0.90];

/**
 * Keep only answers to asked questions whose value is allowed for the question type
 * and whose probability is a finite number in [0, 1].
 */
function validatedAnswers(array $questions, mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $valid = [];
    foreach ($questions as $question) {
        $answer = $raw[$question['id']] ?? null;
        if (!is_array($answer)) {
            continue;
        }
        $p = $answer['p'] ?? null;
        if (!(is_int($p) || is_float($p)) || !is_finite((float) $p) || $p < 0 || $p > 1) {
            continue;
        }
        $value = $answer['value'] ?? null;
        $allowed = $question['type'] === 'noul'
            ? is_bool($value)
            : is_string($value) && in_array($value, $question['options'], true);
        if (!$allowed) {
            continue;
        }
        $valid[$question['id']] = ['value' => $value, 'p' => (float) $p];
    }
    return $valid;
}

function confident(array $answers, string $id, float $min): bool|string|null
{
    $answer = $answers[$id] ?? null;
    return ($answer !== null && $answer['p'] >= $min) ? $answer['value'] : null;
}
