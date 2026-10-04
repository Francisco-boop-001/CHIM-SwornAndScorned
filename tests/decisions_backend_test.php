<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/decisions_backend.php';
require __DIR__ . '/../server/questions.php';

use ChimSwornAndScorned\{DecisionBackendError, OpenRouterDecisionBackend};
use function ChimSwornAndScorned\{choice, noul};

function failsWith(callable $call, string $reason, bool $retryable): bool
{
    try {
        $call();
    } catch (DecisionBackendError $error) {
        return $error->reasonCode === $reason
            && $error->retryable === $retryable
            && $error->getMessage() === $reason;
    }
    return false;
}

$questions = [
    noul('commitment', 'Commit?'),
    choice('commitment.who', 'Who?', ['Lydia', 'Farkas']),
    choice('issue:abc123abc123abc1.status', 'Status?', ['resolved', 'let_go', 'no_change']),
];
$model = 'typesafe/jev-1.13-20260917';
$captured = [];
$reply = ['status' => 200, 'body' => json_encode(['answers' => [
    'commitment' => ['type' => 'noul', 'noul' => 0.97],
    'commitment.who' => [
        'type' => 'choice', 'choice' => 'Farkas', 'confidence' => 0.7,
        'probabilities' => ['Lydia' => 0.12, 'Farkas' => 0.88],
    ],
    'issue:abc123abc123abc1.status' => [
        'type' => 'choice', 'choice' => 'resolved', 'confidence' => 0.92,
        'probabilities' => ['resolved' => 0.95, 'let_go' => 0, 'no_change' => 0.05],
    ],
]])];
$transport = function (string $url, array $headers, string $body) use (&$captured, &$reply): array {
    $captured[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
    return $reply;
};
$backend = new OpenRouterDecisionBackend('test-key', $model, $transport);

check($backend->ask(['owner' => 'Lydia'], $questions) === [
    'commitment' => ['value' => true, 'p' => 0.97],
    'commitment.who' => ['value' => 'Farkas', 'p' => 0.88],
    'issue:abc123abc123abc1.status' => ['value' => 'resolved', 'p' => 0.95],
], 'noul maps P(true) to value and confidence; choice maps the selected option probability');

$sent = json_decode($captured[0]['body'], true);
check($captured[0]['url'] === 'https://openrouter.ai/api/alpha/decisions', 'decisions endpoint');
check(in_array('Authorization: Bearer test-key', $captured[0]['headers'], true), 'bearer header');
check($sent['model'] === $model && $sent['state'] === ['owner' => 'Lydia'], 'requested model identity and state');
check(!str_contains($captured[0]['body'], 'test-key'), 'key is not copied into the request body');
check($sent['questions']['commitment'] === ['type' => 'noul', 'instructions' => 'Commit?'], 'noul question shape');
check($sent['questions']['commitment.who'] === [
    'type' => 'choice', 'instructions' => 'Who?', 'criteria' => ['Lydia' => null, 'Farkas' => null],
], 'choice question shape');
check($backend->name() === 'decisions:' . $model, 'backend name preserves requested model id');

$reply = ['status' => 429, 'body' => '{}'];
check(failsWith(fn () => $backend->ask([], $questions), 'http_429', true), '429 is retryable and bounded');
$reply = ['status' => 503, 'body' => '{}'];
check(failsWith(fn () => $backend->ask([], $questions), 'http_503', true), '5xx is retryable and bounded');
$reply = ['status' => 401, 'body' => '{}'];
check(failsWith(fn () => $backend->ask([], $questions), 'http_401', false), '401 is not retryable and bounded');
$reply = ['status' => 200, 'body' => 'not json'];
check(failsWith(fn () => $backend->ask([], $questions), 'response_invalid_json', true), 'invalid JSON is retryable and bounded');
$reply = ['status' => 200, 'body' => '{"answers":{}}'];
check($backend->ask([], $questions) === [], 'valid empty answer map gives no answers');
$reply = ['status' => 200, 'body' => '{"answers":"nope"}'];
check(failsWith(fn () => $backend->ask([], $questions), 'response_invalid_shape', true), 'malformed answer envelope is retryable');

$reply = ['status' => 200, 'body' => json_encode(['answers' => [
    'commitment' => ['type' => 'noul', 'noul' => 1.1],
    'commitment.who' => ['type' => 'noul', 'choice' => 'Farkas', 'probabilities' => ['Farkas' => 1.0]],
    'issue:abc123abc123abc1.status' => ['type' => 'choice', 'choice' => 'unknown', 'probabilities' => ['unknown' => 1.0]],
]])];
check($backend->ask([], $questions) === [], 'out-of-range probabilities, mismatched types and unknown choices are ignored');

$broken = new OpenRouterDecisionBackend('secret-key', $model, static function (): array {
    throw new RuntimeException('secret response body');
});
check(failsWith(fn () => $broken->ask([], $questions), 'transport_failed', true), 'transport errors expose only a fixed reason');

echo "decisions backend checks passed\n";
