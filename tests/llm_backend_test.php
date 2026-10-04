<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/llm_backend.php';
require __DIR__ . '/../server/questions.php';

use ChimSwornAndScorned\{DecisionBackendError, LlmFallbackBackend};
use function ChimSwornAndScorned\{choice, noul};

$questions = [
    noul('commitment', 'Commit?'),
    choice('commitment.who', 'Who?', ['Lydia', 'Farkas']),
    noul('wronged', 'Wronged?'),
];
$seen = [];
$response = "Sure!\n{\"answers\":{\"commitment\":{\"value\":true,\"confidence\":\"high\"},\"commitment.who\":{\"value\":\"Farkas\",\"confidence\":\"medium\"},\"wronged\":{\"value\":false,\"confidence\":\"banana\"}}}";
$backend = new LlmFallbackBackend(function (array $messages) use (&$seen, &$response): string {
    $seen[] = $messages;
    return $response;
});

check($backend->ask(['owner' => 'Lydia'], $questions) === [
    'commitment' => ['value' => true, 'p' => 0.95],
    'commitment.who' => ['value' => 'Farkas', 'p' => 0.6],
], 'recognized confidence words map to numbers; unknown confidence is ignored');
check($seen[0][0]['role'] === 'system' && str_contains($seen[0][0]['content'], '"confidence":"high|medium|low"'), 'system prompt asks for constrained confidence');
$user = json_decode($seen[0][1]['content'], true);
check($user['state'] === ['owner' => 'Lydia'], 'state forwarded');
check($user['questions'][1] === [
    'id' => 'commitment.who', 'question' => 'Who?', 'answer_type' => 'one_of', 'options' => ['Lydia', 'Farkas'],
], 'choice question shape');
check($user['questions'][0] === [
    'id' => 'commitment', 'question' => 'Commit?', 'answer_type' => 'true_or_false', 'options' => [],
], 'noul question shape');
check($backend->name() === 'llm-fallback', 'fallback backend name');
$identified = new LlmFallbackBackend(static fn (array $messages): string => '{}', 'typesafe/jev-1.13-20260917');
check($identified->name() === 'llm-fallback:typesafe/jev-1.13-20260917', 'configured fallback records its model id');

$response = '{"answers":{' .
    '"commitment":{"value":"true","confidence":"high"},' .
    '"commitment.who":{"value":"Nobody","confidence":"high"},' .
    '"wronged":{"value":false,"confidence":"HIGH"}' .
    '}}';
check($backend->ask([], $questions) === [], 'wrong value types, labels and confidence casing are ignored');
$response = 'I cannot answer that.';
check($backend->ask([], $questions) === [], 'non-JSON response gives no answers');
$response = '{"answers":{"commitment":{"value":true,"confidence":["high"]}}}';
check($backend->ask([], $questions) === [], 'non-string confidence is ignored');

$broken = new LlmFallbackBackend(static function (array $messages): string {
    throw new RuntimeException('secret connector detail');
});
try {
    $broken->ask([], $questions);
    check(false, 'connector failure must throw');
} catch (DecisionBackendError $error) {
    check($error->reasonCode === 'llm_request_failed' && $error->retryable, 'connector failure is retryable');
    check($error->getMessage() === 'llm_request_failed', 'connector error does not expose provider details');
}

echo "llm backend checks passed\n";
