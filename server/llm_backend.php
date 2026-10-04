<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/backend.php';
require_once __DIR__ . '/backend_error.php';

// The fallback's self-reported confidence is intentionally stricter than Decisions API confidence.
const LLM_CONFIDENCE = ['high' => 0.95, 'medium' => 0.6, 'low' => 0.3];

final class LlmFallbackBackend implements DecisionBackend
{
    /** @param callable(list<array{role: string, content: string}>): string $complete */
    public function __construct(private $complete, private ?string $modelId = null)
    {
    }

    public function name(): string
    {
        return $this->modelId === null || trim($this->modelId) === ''
            ? 'llm-fallback'
            : 'llm-fallback:' . $this->modelId;
    }

    public function ask(array $state, array $questions): mixed
    {
        try {
            $raw = ($this->complete)(llmMessages($state, $questions));
        } catch (\Throwable $error) {
            throw new DecisionBackendError('llm_request_failed', true, $error);
        }
        if (!is_string($raw)) {
            throw new DecisionBackendError('llm_request_failed', true);
        }
        return answersFromLlm($questions, $raw);
    }
}

function llmMessages(array $state, array $questions): array
{
    $spec = [];
    foreach ($questions as $question) {
        $spec[] = [
            'id' => $question['id'],
            'question' => $question['text'],
            'answer_type' => $question['type'] === 'noul' ? 'true_or_false' : 'one_of',
            'options' => $question['options'],
        ];
    }
    $system = 'You classify a short Skyrim conversation. Answer every question using only the given lines; '
        . 'do not assume anything that is not said. Reply with JSON only: '
        . '{"answers":{"<id>":{"value":<true|false or one option exactly>,"confidence":"high|medium|low"}}}. '
        . 'Use "low" when the lines do not clearly say.';
    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => json_encode(
            ['state' => $state, 'questions' => $spec],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )],
    ];
}

function answersFromLlm(array $questions, string $raw): array
{
    $start = strpos($raw, '{');
    $end = strrpos($raw, '}');
    if ($start === false || $end === false || $end < $start) {
        return [];
    }
    $decoded = json_decode(substr($raw, $start, $end - $start + 1), true, 64);
    $answers = is_array($decoded) ? ($decoded['answers'] ?? null) : null;
    if (!is_array($answers)) {
        return [];
    }
    $mapped = [];
    foreach ($questions as $question) {
        $answer = $answers[$question['id']] ?? null;
        if (!is_array($answer) || !is_string($answer['confidence'] ?? null)) {
            continue;
        }
        $p = LLM_CONFIDENCE[$answer['confidence']] ?? null;
        if ($p === null) {
            continue;
        }
        $value = $answer['value'] ?? null;
        $allowed = $question['type'] === 'noul'
            ? is_bool($value)
            : $question['type'] === 'choice' && is_string($value) && in_array($value, $question['options'], true);
        if ($allowed) {
            $mapped[$question['id']] = ['value' => $value, 'p' => $p];
        }
    }
    return $mapped;
}
