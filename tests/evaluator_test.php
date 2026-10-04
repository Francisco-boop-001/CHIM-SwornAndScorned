<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/evaluator.php';
require __DIR__ . '/support/scripted_backend.php';

use function ChimSwornAndScorned\evaluatePerspective;
use const ChimSwornAndScorned\{POLICY_DECISION_MODEL, POLICY_LLM_FALLBACK};

$ask = sampleLine(4, 'Lydia', 'Will you defend me before Aela?');
$vow = sampleLine(5, 'Farkas', 'I will stand with you before Aela.');
$ctx = ['owner' => 'Lydia', 'counterpart' => 'Farkas', 'lines' => [$ask, $vow], 'open_issues' => []];
$empty = ['new_issues' => [], 'updates' => []];

// No witnessed lines: no provider call.
$silent = new ScriptedBackend([]);
check(evaluatePerspective(['lines' => []] + $ctx, $silent, POLICY_DECISION_MODEL) === $empty, 'no lines, empty proposal');
check($silent->calls === [], 'no lines, no backend call');

// Nothing detected: one call only.
$quiet = new ScriptedBackend(['commitment' => ['value' => false, 'p' => 0.9], 'wronged' => ['value' => false, 'p' => 0.9]]);
check(evaluatePerspective($ctx, $quiet, POLICY_DECISION_MODEL) === $empty, 'nothing detected');
check(count($quiet->calls) === 1, 'pass 2 skipped when pass 1 is negative');

// Accepted firm promise from the counterpart.
$promiseAnswers = [
    'commitment' => ['value' => true, 'p' => 0.95],
    'wronged' => ['value' => false, 'p' => 0.9],
    'commitment.who' => ['value' => 'Farkas', 'p' => 0.9],
    'commitment.strength' => ['value' => 'will', 'p' => 0.88],
    'commitment.acceptance' => ['value' => 'accepted', 'p' => 0.85],
    'commitment.line' => ['value' => 'L2', 'p' => 0.97],
];
$proposal = evaluatePerspective($ctx, new ScriptedBackend($promiseAnswers), POLICY_DECISION_MODEL);
check($proposal['new_issues'] === [[
    'kind' => 'promise',
    'direction' => 'owed_to_me',
    'strength' => 'will',
    'wants' => 'keep_promise',
    'line' => $vow,
    'label' => 'commitment',
    'p' => 0.85,
]] && $proposal['updates'] === [], 'accepted promise from the counterpart becomes owed_to_me');

// Lydia's own promise is owed_by_me with unknown wants.
$ownCtx = ['lines' => [$ask, sampleLine(5, 'Lydia', 'I will stand with you before Aela.')]] + $ctx;
$own = evaluatePerspective($ownCtx, new ScriptedBackend(['commitment.who' => ['value' => 'Lydia', 'p' => 0.9]] + $promiseAnswers), POLICY_DECISION_MODEL);
check($own['new_issues'][0]['direction'] === 'owed_by_me' && $own['new_issues'][0]['wants'] === 'unknown', 'own promise is owed_by_me');

// Unclear acceptance creates nothing; a favour is not an agreed debt.
$unclear = evaluatePerspective($ctx, new ScriptedBackend(['commitment.acceptance' => ['value' => 'unclear', 'p' => 0.9]] + $promiseAnswers), POLICY_DECISION_MODEL);
check($unclear === $empty, 'unclear acceptance creates no promise');

// Low pass 1 confidence stops before pass 2.
$weak = new ScriptedBackend(['commitment' => ['value' => true, 'p' => 0.6]] + $promiseAnswers);
check(evaluatePerspective($ctx, $weak, POLICY_DECISION_MODEL) === $empty && count($weak->calls) === 1, 'low confidence detection ignored');

// The LLM fallback policy is stricter: 0.85 detection is not enough.
$llm = evaluatePerspective($ctx, new ScriptedBackend(['commitment' => ['value' => true, 'p' => 0.85]] + $promiseAnswers), POLICY_LLM_FALLBACK);
check($llm === $empty, 'LLM fallback needs high confidence');

// New grievance.
$grievance = evaluatePerspective($ctx, new ScriptedBackend([
    'commitment' => ['value' => false, 'p' => 0.9],
    'wronged' => ['value' => true, 'p' => 0.9],
    'grievance.line' => ['value' => 'L1', 'p' => 0.9],
    'grievance.wants' => ['value' => 'explanation', 'p' => 0.6],
]), POLICY_DECISION_MODEL);
check($grievance['new_issues'] === [[
    'kind' => 'grievance', 'direction' => null, 'strength' => null, 'wants' => 'unknown',
    'line' => $ask, 'label' => 'wronged', 'p' => 0.9,
]], 'grievance with low-confidence wants falls back to unknown');

// Addressed open promise.
$open = sampleIssue(['id' => 'p000000000000001', 'kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'status' => 'pending', 'wants' => 'keep_promise']);
$addressedCtx = ['open_issues' => [$open]] + $ctx;
$addressedAnswers = [
    'addresses:p000000000000001' => ['value' => true, 'p' => 0.9],
    'commitment' => ['value' => false, 'p' => 0.9],
    'wronged' => ['value' => false, 'p' => 0.9],
    'issue:p000000000000001.status' => ['value' => 'broken', 'p' => 0.92],
    'issue:p000000000000001.trajectory' => ['value' => 'worsening', 'p' => 0.85],
    'issue:p000000000000001.line' => ['value' => 'L1', 'p' => 0.9],
];
$broken = evaluatePerspective($addressedCtx, new ScriptedBackend($addressedAnswers), POLICY_DECISION_MODEL);
check($broken['updates'] === [[
    'issue_id' => 'p000000000000001', 'status' => 'broken', 'status_p' => 0.9,
    'trajectory' => 'worsening', 'wants' => null, 'line' => $ask,
]] && $broken['new_issues'] === [], 'addressed promise update');

$doubtful = evaluatePerspective($addressedCtx, new ScriptedBackend(['issue:p000000000000001.status' => ['value' => 'broken', 'p' => 0.7]] + $addressedAnswers), POLICY_DECISION_MODEL);
check($doubtful['updates'][0]['status'] === null && $doubtful['updates'][0]['status_p'] === 0.7, 'doubtful status is not proposed');

echo "evaluator checks passed\n";
