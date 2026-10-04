<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/backend.php';
require_once __DIR__ . '/backend_error.php';

const OPENROUTER_DECISIONS_URL = 'https://openrouter.ai/api/alpha/decisions';

final class OpenRouterDecisionBackend implements DecisionBackend
{
    /** @param callable(string, list<string>, string): array{status: int, body: string} $transport */
    public function __construct(private string $apiKey, private string $model, private $transport)
    {
    }

    public function name(): string
    {
        return 'decisions:' . $this->model;
    }

    public function ask(array $state, array $questions): mixed
    {
        try {
            $body = json_encode(
                ['model' => $this->model, 'state' => $state, 'questions' => decisionQuestions($questions)],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (\JsonException $error) {
            throw new DecisionBackendError('request_invalid_json', false, $error);
        }

        try {
            $response = ($this->transport)(
                OPENROUTER_DECISIONS_URL,
                ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json'],
                $body
            );
        } catch (DecisionBackendError $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new DecisionBackendError('transport_failed', true, $error);
        }
        if (!is_array($response) || !is_int($response['status'] ?? null) || !is_string($response['body'] ?? null)) {
            throw new DecisionBackendError('transport_invalid_response', true);
        }

        $status = $response['status'];
        if ($status === 429 || $status >= 500) {
            throw new DecisionBackendError("http_{$status}", true);
        }
        if ($status !== 200) {
            throw new DecisionBackendError("http_{$status}", false);
        }
        try {
            $decoded = json_decode($response['body'], false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new DecisionBackendError('response_invalid_json', true, $error);
        }
        if (!$decoded instanceof \stdClass || !($decoded->answers ?? null) instanceof \stdClass) {
            throw new DecisionBackendError('response_invalid_shape', true);
        }
        return answersFromDecisions($questions, get_object_vars($decoded->answers));
    }
}

/** Map our typed questions to the Decisions API's id-keyed request shape. */
function decisionQuestions(array $questions): array
{
    $mapped = [];
    foreach ($questions as $question) {
        if ($question['type'] === 'noul') {
            $mapped[$question['id']] = ['type' => 'noul', 'instructions' => $question['text']];
        } elseif ($question['type'] === 'choice') {
            $mapped[$question['id']] = [
                'type' => 'choice',
                'instructions' => $question['text'],
                'criteria' => array_fill_keys($question['options'], null),
            ];
        }
    }
    return $mapped;
}

/** Return validated answers keyed by question id, using the API's selected-choice probabilities. */
function answersFromDecisions(array $questions, mixed $answers): array
{
    if (!is_array($answers)) {
        return [];
    }
    $mapped = [];
    foreach ($questions as $question) {
        $answer = $answers[$question['id']] ?? null;
        if ($answer instanceof \stdClass) {
            $answer = get_object_vars($answer);
        }
        if (!is_array($answer) || ($answer['type'] ?? null) !== $question['type']) {
            continue;
        }
        if ($question['type'] === 'noul') {
            $p = $answer['noul'] ?? null;
            if (!is_int($p) && !is_float($p)) {
                continue;
            }
            if (!is_finite((float) $p) || $p < 0 || $p > 1) {
                continue;
            }
            $p = (float) $p;
            $mapped[$question['id']] = ['value' => $p >= 0.5, 'p' => max($p, 1.0 - $p)];
            continue;
        }
        if ($question['type'] !== 'choice') {
            continue;
        }
        $choice = $answer['choice'] ?? null;
        if (!is_string($choice) || !in_array($choice, $question['options'], true)) {
            continue;
        }
        $probabilities = $answer['probabilities'] ?? null;
        if ($probabilities instanceof \stdClass) {
            $probabilities = get_object_vars($probabilities);
        }
        $p = is_array($probabilities) ? ($probabilities[$choice] ?? null) : null;
        if ((!is_int($p) && !is_float($p)) || !is_finite((float) $p) || $p < 0 || $p > 1) {
            continue;
        }
        $mapped[$question['id']] = ['value' => $choice, 'p' => (float) $p];
    }
    return $mapped;
}

/** Production HTTP transport with fixed, bounded failures. */
function curlTransport(int $timeoutSeconds = 15): callable
{
    return static function (string $url, array $headers, string $body) use ($timeoutSeconds): array {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new DecisionBackendError('transport_failed', true);
        }
        try {
            if (!curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 5,
            ])) {
                throw new DecisionBackendError('transport_failed', true);
            }
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($response === false || curl_errno($handle) !== 0) {
                throw new DecisionBackendError('transport_failed', true);
            }
            return ['status' => $status, 'body' => (string) $response];
        } catch (DecisionBackendError $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new DecisionBackendError('transport_failed', true, $error);
        } finally {
            curl_close($handle);
        }
    };
}
