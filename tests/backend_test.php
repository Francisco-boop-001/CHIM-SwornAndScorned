<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/backend.php';
require __DIR__ . '/support/scripted_backend.php';

use function ChimSwornAndScorned\{confident, validatedAnswers};
use const ChimSwornAndScorned\{POLICY_DECISION_MODEL, POLICY_LLM_FALLBACK};

$questions = [
    ['id' => 'a', 'type' => 'noul', 'text' => '?', 'options' => []],
    ['id' => 'b', 'type' => 'choice', 'text' => '?', 'options' => ['will', 'will_try']],
];

$valid = validatedAnswers($questions, [
    'a' => ['value' => true, 'p' => 0.93],
    'b' => ['value' => 'maybe', 'p' => 0.99],
    'zzz' => ['value' => true, 'p' => 1],
]);
check($valid === ['a' => ['value' => true, 'p' => 0.93]], 'unknown ids and labels are dropped');
check(validatedAnswers($questions, ['a' => ['value' => 'yes', 'p' => 0.9]]) === [], 'noul needs a bool');
check(validatedAnswers($questions, ['a' => ['value' => true, 'p' => 1.5]]) === [], 'p must be within 0..1');
check(validatedAnswers($questions, ['a' => ['value' => true, 'p' => NAN]]) === [], 'p must be finite');
check(validatedAnswers($questions, ['b' => ['value' => 'will', 'p' => 1]]) === ['b' => ['value' => 'will', 'p' => 1.0]], 'integer p is cast to float');
check(validatedAnswers($questions, 'garbage') === [], 'non-array raw answers are ignored');

check(confident($valid, 'a', 0.9) === true, 'above threshold returns the value');
check(confident($valid, 'a', 0.95) === null, 'below threshold returns null');
check(confident($valid, 'missing', 0.1) === null, 'missing answer returns null');

check(POLICY_DECISION_MODEL === ['detect_min' => 0.80, 'status_min' => 0.80, 'affinity_min' => 0.90], 'decision model policy');
check(POLICY_LLM_FALLBACK === ['detect_min' => 0.90, 'status_min' => 0.90, 'affinity_min' => 0.90], 'LLM fallback policy is stricter');

$backend = new ScriptedBackend(['a' => ['value' => true, 'p' => 0.9], 'c' => ['value' => true, 'p' => 0.9]]);
check($backend->ask([], $questions) === ['a' => ['value' => true, 'p' => 0.9]], 'scripted backend answers only asked ids');
check($backend->calls === [['a', 'b']] && $backend->name() === 'scripted', 'scripted backend records calls');

echo "backend checks passed\n";
