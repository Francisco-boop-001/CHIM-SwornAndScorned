<?php
declare(strict_types=1);
require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/evaluator.php';
require __DIR__ . '/../server/apply.php';
require __DIR__ . '/../server/surfacing.php';
require __DIR__ . '/support/scripted_backend.php';
use function ChimSwornAndScorned\{applyProposal, emptyLedger, evaluatePerspective, normalizeLedger, renderNote, selectIssueToSurface, validateIssue};
use const ChimSwornAndScorned\POLICY_DECISION_MODEL;

$failures = [];
$test = static function (string $name, callable $run) use (&$failures): void {
    try { $run(); echo "PASS $name\n"; }
    catch (Throwable $e) { $failures[] = $name; echo "FAIL $name: ", $e->getMessage(), "\n"; }
};
$ledger = emptyLedger('pt-1');
$ledger['issues'] = [sampleIssue()];
$line = sampleLine(11, 'Lydia', 'You made amends.');
$meta = ['event_ids' => [11], 'counterpart' => 'npc:3', 'backend' => 'scripted', 'policy' => POLICY_DECISION_MODEL];
$update = ['issue_id' => 'abc123abc123abc1', 'status' => 'resolved', 'status_p' => 0.95, 'trajectory' => null, 'wants' => null, 'line' => $line];
$proposal = ['updates' => [$update], 'new_issues' => []];
$ctx = ['owner' => 'Lydia', 'counterpart' => 'Farkas', 'lines' => [$line], 'open_issues' => [sampleIssue()]];
$answers = [
    'addresses:abc123abc123abc1' => ['value' => true, 'p' => 0.95],
    'issue:abc123abc123abc1.status' => ['value' => 'resolved', 'p' => 0.99],
];
$test('missing selected evidence cannot close an issue', static function () use ($ctx, $answers): void {
    $p = evaluatePerspective($ctx, new ScriptedBackend($answers), POLICY_DECISION_MODEL);
    check($p['updates'] === [] || $p['updates'][0]['status'] === null, 'status proposed without evidence');
});
$test('weak selected evidence cannot award affinity', static function () use ($ctx, $answers, $ledger, $meta): void {
    $answers['issue:abc123abc123abc1.line'] = ['value' => 'L1', 'p' => 0.85];
    $result = applyProposal($ledger, evaluatePerspective($ctx, new ScriptedBackend($answers), POLICY_DECISION_MODEL), $meta);
    check($result['ledger']['issues'][0]['status'] === 'resolved' && $result['affinity_delta'] === 0, 'weak evidence awarded affinity');
});
$test('commitment actor must match evidence speaker', static function () use ($ctx): void {
    $a = ['commitment' => ['value' => true, 'p' => 0.95], 'commitment.who' => ['value' => 'Farkas', 'p' => 0.95], 'commitment.strength' => ['value' => 'will', 'p' => 0.95], 'commitment.acceptance' => ['value' => 'accepted', 'p' => 0.95], 'commitment.line' => ['value' => 'L1', 'p' => 0.95], 'commitment.matter' => ['value' => 'L1', 'p' => 0.95], 'commitment.settlement' => ['value' => 'unknown', 'p' => 0.95]];
    check(evaluatePerspective($ctx, new ScriptedBackend($a), POLICY_DECISION_MODEL)['new_issues'] === [], 'promise attributed to another speaker');
});
$test('grievance evidence must be spoken by its owner', static function () use ($ctx): void {
    $ctx['lines'] = [sampleLine(11, 'Farkas', 'I was wronged.')];
    $a = ['wronged' => ['value' => true, 'p' => 0.95], 'grievance.line' => ['value' => 'L1', 'p' => 0.95], 'grievance.matter' => ['value' => 'L1', 'p' => 0.95], 'grievance.nature' => ['value' => 'harm', 'p' => 0.95], 'grievance.settlement' => ['value' => 'unknown', 'p' => 0.95]];
    check(evaluatePerspective($ctx, new ScriptedBackend($a), POLICY_DECISION_MODEL)['new_issues'] === [], 'grievance attributed to another speaker');
});
$test('apply enforces status threshold', static function () use ($ledger, $proposal, $meta): void {
    $proposal['updates'][0]['status_p'] = 0.1;
    check(applyProposal($ledger, $proposal, $meta)['ledger']['issues'][0]['status'] === 'open', 'low confidence closed issue');
});
$test('apply requires evidence in the current batch', static function () use ($ledger, $proposal, $meta): void {
    $proposal['updates'][0]['line']['event_id'] = 999;
    rejected(static fn () => applyProposal($ledger, $proposal, $meta), 'foreign event evidence accepted');
});
$test('corrupt stored data fails closed', static function (): void {
    rejected(static fn () => normalizeLedger('corrupt', 'pt-1'), 'corrupt ledger erased');
});
$test('duplicate issue identities fail closed', static function () use ($ledger): void {
    $ledger['issues'][] = $ledger['issues'][0];
    rejected(static fn () => normalizeLedger($ledger, 'pt-1'), 'duplicate issue id accepted');
});
$test('stored evidence caps enforced', static function (): void {
    $issue = sampleIssue(); $issue['evidence'] = array_fill(0, 9, $issue['evidence'][0]);
    rejected(static fn () => validateIssue($issue), 'oversized evidence accepted');
});
$test('nonfinite game time rejected', static function (): void {
    $issue = sampleIssue(); $issue['evidence'][0]['gamets'] = INF;
    rejected(static fn () => validateIssue($issue), 'infinite game time accepted');
});
$test('stored bounds and flags enforced', static function () use ($ledger): void {
    $tooMany = $ledger; $tooMany['processed'] = range(1, 129);
    rejected(static fn () => normalizeLedger($tooMany, 'pt-1'), 'processed cap ignored');
    $tooMany = $ledger; $tooMany['issues'] = [];
    for ($n = 0; $n < 25; $n++) { $tooMany['issues'][] = sampleIssue(['id' => 'issue' . $n]); }
    rejected(static fn () => normalizeLedger($tooMany, 'pt-1'), 'open cap ignored');
    $issue = sampleIssue(); $issue['evidence'][0]['quote'] = str_repeat('é', 161);
    rejected(static fn () => validateIssue($issue), 'quote cap ignored');
    rejected(static fn () => validateIssue(sampleIssue(['muted' => 'yes'])), 'nonboolean flag accepted');
});
$test('invalid proposal kind fails closed', static function () use ($meta): void {
    $p = ['updates' => [], 'new_issues' => [['kind' => 'bogus', 'direction' => null, 'strength' => null, 'wants' => 'unknown', 'line' => sampleLine(11, 'Farkas', 'Hello.'), 'label' => 'wronged', 'p' => 0.95]]];
    rejected(static fn () => applyProposal(emptyLedger('pt-1'), $p, $meta), 'invalid kind accepted');
});
$test('new evidence breaks cooldown at the cap', static function () use ($ledger): void {
    $issue = $ledger['issues'][0]; $issue['evidence'] = array_fill(0, 8, $issue['evidence'][0]);
    $issue['evidence'][7]['ref'] = 'e:11'; $issue['changed_event_id'] = 11; $ledger['issues'] = [$issue];
    $pacing = [$issue['id'] => ['at' => 9950, 'evidence_count' => 8, 'evidence_ref' => 'e:10']];
    check(selectIssueToSurface($ledger, ['npc:3'], [], $pacing, 10000) !== null, 'new capped evidence remained cooling');
});
$test('unrelated new promises do not link to resolved grievances', static function () use ($ledger, $proposal, $meta): void {
    $proposal['new_issues'][] = ['kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'wants' => 'keep_promise', 'line' => sampleLine(11, 'Farkas', 'I will bring bread.'), 'label' => 'commitment', 'p' => 0.95];
    foreach (applyProposal($ledger, $proposal, $meta)['ledger']['issues'] as $issue) { check($issue['links'] === [], 'unrelated matters linked'); }
});
$test('long names preserve the note closing instruction', static function (): void {
    $note = renderNote(sampleIssue(), 'Lydia', str_repeat('Long name ', 40));
    check(mb_strlen($note) <= 240 && str_ends_with($note, 'Raise it only if it fits the moment.'), 'closing instruction clipped');
});
$test('evidence retains verbatim whitespace', static function () use ($meta): void {
    $text = "I will  bring\nthe bread.";
    $p = ['updates' => [], 'new_issues' => [['kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'wants' => 'keep_promise', 'line' => sampleLine(11, 'Farkas', $text), 'label' => 'commitment', 'p' => 0.95]]];
    check(applyProposal(emptyLedger('pt-1'), $p, $meta)['ledger']['issues'][0]['evidence'][0]['quote'] === $text, 'source quote rewritten');
});
if ($failures !== []) { throw new RuntimeException(count($failures) . ' review regressions failed'); }
echo "review regression checks passed\n";
