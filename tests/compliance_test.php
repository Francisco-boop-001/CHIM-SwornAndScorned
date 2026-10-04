<?php
declare(strict_types=1);
require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/evaluator.php';
require __DIR__ . '/support/scripted_backend.php';
use function ChimSwornAndScorned\evaluatePerspective;
use const ChimSwornAndScorned\POLICY_DECISION_MODEL;
$issue = sampleIssue();
$ctx = ['owner' => 'Lydia', 'counterpart' => 'Farkas', 'lines' => [sampleLine(11, 'Lydia', 'Hello.')], 'open_issues' => [$issue]];
$audit = [];
$proposal = evaluatePerspective($ctx, new ScriptedBackend(['addresses:' . $issue['id'] => ['value' => false, 'p' => .95]]), POLICY_DECISION_MODEL,
    static function (array $entry) use (&$audit): void { $audit[] = $entry; });
check($proposal === ['new_issues' => [], 'updates' => []], 'audit preserves proposal semantics');
check($audit === [['issue_id' => $issue['id'], 'addresses' => false, 'p' => .95]], 'negative pass1 compliance is observable without dialogue');
echo "compliance checks passed\n";
