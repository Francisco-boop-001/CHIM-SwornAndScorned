<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require_once __DIR__ . '/../server/jobs.php';
require __DIR__ . '/support/scripted_backend.php';

use ChimSwornAndScorned\JobDeps;
use function ChimSwornAndScorned\{applyProposal, emptyLedger, evaluatePerspective, issueId, processJob};
use const ChimSwornAndScorned\POLICY_DECISION_MODEL;

function disputeLedger(string $status = 'disputed', string $direction = 'owed_to_me'): array
{
    $ledger = emptyLedger('pt-dispute');
    $ledger['issues'][] = sampleIssue([
        'id' => 'aaaa000000000001', 'kind' => 'promise', 'status' => $status,
        'direction' => $direction, 'strength' => 'will', 'wants' => 'keep_promise',
    ]);
    return $ledger;
}

function disputeLines(int $eventId = 20): array
{
    return [
        sampleLine($eventId, 'Lydia', 'You deny agreeing to repay my 50 septims.'),
        sampleLine($eventId + 1, 'Farkas', 'I dispute that those were the terms.'),
    ];
}

function disputeBackend(string $status = 'disputed'): ScriptedBackend
{
    return new ScriptedBackend([
        'commitment' => ['value' => false, 'p' => 0.95],
        'wronged' => ['value' => true, 'p' => 0.95],
        'addresses:aaaa000000000001' => ['value' => true, 'p' => 0.95],
        'grievance.line' => ['value' => 'L1', 'p' => 0.95],
        'grievance.wants' => ['value' => 'explanation', 'p' => 0.95],
        'grievance.matter' => ['value' => 'L1', 'p' => 0.95],
        'grievance.nature' => ['value' => 'betrayal', 'p' => 0.95],
        'grievance.settlement' => ['value' => 'unknown', 'p' => 0.95],
        'grievance.related' => ['value' => 'aaaa000000000001', 'p' => 0.95],
        'issue:aaaa000000000001.status' => ['value' => $status, 'p' => 0.95],
        'issue:aaaa000000000001.trajectory' => ['value' => 'worsening', 'p' => 0.95],
        'issue:aaaa000000000001.line' => ['value' => 'L1', 'p' => 0.95],
    ]);
}

function disputeProposal(array $ledger, array $lines, string $status = 'disputed'): array
{
    return evaluatePerspective([
        'owner' => 'Lydia', 'counterpart' => 'Farkas', 'lines' => $lines,
        'open_issues' => $ledger['issues'],
    ], disputeBackend($status), POLICY_DECISION_MODEL);
}

function disputeMeta(array $lines): array
{
    return ['event_ids' => array_column($lines, 'event_id'), 'counterpart' => 'npc:3',
        'backend' => 'scripted', 'policy' => POLICY_DECISION_MODEL];
}

final class DisputeJobDeps implements JobDeps
{
    public array $ledger;
    public array $lines;
    public ScriptedBackend $scripted;
    public ?array $ledgerAtCommit = null;
    public int $commits = 0;
    public int $affinity = 0;

    public function __construct()
    {
        $this->ledger = disputeLedger();
        $this->lines = disputeLines();
        $this->scripted = disputeBackend();
    }
    public function currentPlaythroughId(): ?string { return 'pt-dispute'; }
    public function linesFor(array $eventIds): array { return $this->lines; }
    public function ledgerFor(int $ownerNpcId, string $playthroughId): array { return $this->ledger; }
    public function counterpartKey(string $counterpartName): ?string { return $counterpartName === 'Farkas' ? 'npc:3' : null; }
    public function backend(string $kind): ?array { return [$this->scripted, POLICY_DECISION_MODEL]; }
    public function commitLedger(array $request, callable $mutate): string
    {
        ++$this->commits;
        $result = $mutate($this->ledgerAtCommit ?? $this->ledger);
        if ($result['status'] !== 'applied') return $result['status'];
        $this->ledger = $result['ledger'];
        $this->affinity += $result['affinity_delta'];
        return 'committed';
    }
}

$cases = [];
$cases['repeated evaluator dispute preserves genuine grievance without invalid link'] = static function (): void {
    $ledger = disputeLedger();
    $lines = disputeLines();
    $proposal = disputeProposal($ledger, $lines);
    check(count($proposal['new_issues']) === 1, 'genuine grievance remains proposed');
    check($proposal['new_issues'][0]['related_issue_id'] === null, 'already-disputed promise cannot qualify as a new dispute transition');
    $result = applyProposal($ledger, $proposal, disputeMeta($lines));
    $grievanceId = issueId('e:20', 'npc:3', 'grievance');
    check($result['status'] === 'applied' && count($result['ledger']['issues']) === 2, 'repeated dispute commits grievance');
    check(issueById($result['ledger'], $grievanceId)['links'] === [], 'repeated dispute grievance remains unlinked');
    check(issueById($result['ledger'], 'aaaa000000000001')['status'] === 'disputed', 'promise remains disputed');
    check($result['affinity_delta'] === 0 && $result['transitions'] === [], 'repeated dispute has no lifecycle or affinity change');
    $replay = applyProposal($result['ledger'], $proposal, disputeMeta($lines));
    check($replay['status'] === 'replay' && $replay['ledger'] === $result['ledger'] && $replay['affinity_delta'] === 0, 'replayed batch cannot duplicate grievance or affinity');
};

$cases['direct already-disputed proposal drops only its stale semantic relation'] = static function (): void {
    $ledger = disputeLedger();
    $lines = disputeLines();
    $proposal = disputeProposal($ledger, $lines);
    $proposal['new_issues'][0]['related_issue_id'] = 'aaaa000000000001';
    $result = applyProposal($ledger, $proposal, disputeMeta($lines));
    $promise = issueById($result['ledger'], 'aaaa000000000001');
    $grievance = issueById($result['ledger'], issueId('e:20', 'npc:3', 'grievance'));
    check($result['status'] === 'applied' && count($result['ledger']['issues']) === 2, 'semantic mismatch must not roll back genuine grievance');
    check($promise['links'] === [] && $grievance['links'] === [], 'stale dispute relation is not persisted in either direction');
    check(in_array('related_transition_not_applied', $result['skipped'], true), 'semantic link omission has a fixed reason');
    check($promise['status'] === 'disputed' && $promise['affinity_applied'] === [], 'self-transition adds no lifecycle affinity marker');
    check($result['affinity_delta'] === 0 && $result['transitions'] === [], 'direct repeated dispute has zero affinity and transitions');
    check(count($promise['evidence']) === 2 && $grievance['substance']['matter']['text'] === $lines[0]['text'], 'new evidence and exact grievance substance survive');
};

$cases['valid dispute and breach retain reciprocal links and exactly-once affinity'] = static function (): void {
    foreach ([['pending', 'disputed', 0], ['disputed', 'broken', -3]] as [$from, $to, $delta]) {
        $ledger = disputeLedger($from);
        $lines = disputeLines();
        if ($to === 'broken') {
            $lines[0]['text'] = 'You refused to repay my 50 septims as promised.';
            $lines[1]['text'] = 'I refuse to repay them.';
        }
        $proposal = disputeProposal($ledger, $lines, $to);
        check($proposal['new_issues'][0]['related_issue_id'] === 'aaaa000000000001', 'genuine lifecycle change retains selected predecessor');
        $result = applyProposal($ledger, $proposal, disputeMeta($lines));
        $grievanceId = issueId('e:20', 'npc:3', 'grievance');
        $promise = issueById($result['ledger'], 'aaaa000000000001');
        check($promise['status'] === $to && $promise['links'] === [$grievanceId], 'valid transition links original promise');
        check(issueById($result['ledger'], $grievanceId)['links'] === ['aaaa000000000001'], 'valid transition links new grievance reciprocally');
        check($result['affinity_delta'] === $delta && $result['transitions'] === ["aaaa000000000001:{$from}>{$to}"], 'valid lifecycle affinity and transition are applied once');
        $replay = applyProposal($result['ledger'], $proposal, disputeMeta($lines));
        check($replay['status'] === 'replay' && $replay['affinity_delta'] === 0 && $replay['ledger'] === $result['ledger'], 'valid transition replay cannot repay affinity or add another grievance');
    }
    $ledger = disputeLedger('disputed', 'owed_by_me');
    $lines = disputeLines();
    $result = applyProposal($ledger, disputeProposal($ledger, $lines, 'broken'), disputeMeta($lines));
    check($result['affinity_delta'] === 0, 'broken obligation owed by owner does not penalize counterpart affinity');
};

$cases['semantic exception leaves invalid relation and provenance validation strict'] = static function (): void {
    $ledger = disputeLedger();
    $lines = disputeLines();
    $proposal = disputeProposal($ledger, $lines);
    $proposal['new_issues'][0]['related_issue_id'] = 'aaaa000000000001';
    foreach (['unknown', 'missing_update', 'wrong_kind', 'wrong_counterpart', 'forged_text', 'forged_speaker', 'forged_ref', 'off_batch'] as $variant) {
        $badLedger = $ledger;
        $badProposal = $proposal;
        if ($variant === 'unknown') $badProposal['new_issues'][0]['related_issue_id'] = 'unknown-issue';
        if ($variant === 'missing_update') $badProposal['updates'] = [];
        if ($variant === 'wrong_kind') $badLedger['issues'][0] = sampleIssue(['id' => 'aaaa000000000001']);
        if ($variant === 'wrong_counterpart') $badLedger['issues'][0]['counterpart'] = 'npc:99';
        if ($variant === 'forged_text') $badProposal['new_issues'][0]['substance']['matter']['text'] = 'An invented wrong.';
        if ($variant === 'forged_speaker') $badProposal['new_issues'][0]['substance']['matter']['speaker'] = 'Aela';
        if ($variant === 'forged_ref') $badProposal['new_issues'][0]['substance']['matter']['ref'] = 'e:999';
        if ($variant === 'off_batch') $badProposal['new_issues'][0]['substance_lines'][0]['event_id'] = 999;
        rejected(fn () => applyProposal($badLedger, $badProposal, disputeMeta($lines)), "{$variant} remains rejected");
    }
};

$cases['real processJob completes repeated dispute and replay avoids more backend calls'] = static function (): void {
    $deps = new DisputeJobDeps();
    $job = ['id' => 7, 'playthrough_id' => 'pt-dispute', 'owner_npc_id' => 2,
        'owner_name' => 'Lydia', 'counterpart_name' => 'Farkas', 'event_ids' => [20, 21], 'backend' => 'decisions'];
    check(processJob($job, $deps) === ['outcome' => 'done', 'reason' => 'committed'], 'repeated dispute job completes rather than retrying');
    check(count($deps->ledger['issues']) === 2 && $deps->affinity === 0, 'job commits grievance with zero affinity');
    check(count($deps->scripted->calls) === 2 && $deps->commits === 1, 'job uses only its two evaluation passes and one commit');
    $committed = $deps->ledger;
    check(processJob($job, $deps) === ['outcome' => 'done', 'reason' => 'replay'], 'replayed completed job is done');
    check(count($deps->scripted->calls) === 2 && $deps->commits === 1 && $deps->ledger === $committed, 'replay performs no extra backend call or commit');
};

$cases['real processJob preserves grievance when pending snapshot becomes disputed before commit'] = static function (): void {
    $deps = new DisputeJobDeps();
    $deps->ledger = disputeLedger('pending');
    $deps->ledgerAtCommit = disputeLedger('disputed');
    $job = ['id' => 8, 'playthrough_id' => 'pt-dispute', 'owner_npc_id' => 2,
        'owner_name' => 'Lydia', 'counterpart_name' => 'Farkas', 'event_ids' => [20, 21], 'backend' => 'decisions'];
    check(processJob($job, $deps) === ['outcome' => 'done', 'reason' => 'committed'], 'fresh disputed ledger must not roll back the pending-snapshot grievance');
    check(count($deps->ledger['issues']) === 2 && $deps->affinity === 0, 'state drift preserves genuine grievance without affinity');
    check(issueById($deps->ledger, 'aaaa000000000001')['status'] === 'disputed'
        && issueById($deps->ledger, 'aaaa000000000001')['links'] === []
        && issueById($deps->ledger, issueId('e:20', 'npc:3', 'grievance'))['links'] === [], 'state drift omits invalid links in both directions');
    check(count($deps->scripted->calls) === 2 && $deps->commits === 1, 'state drift causes no repeat evaluation or retry');
};
$cases['contradictory duplicate updates cannot reuse the repeated-dispute exception after closing promise'] = static function (): void {
    $ledger = disputeLedger();
    $lines = disputeLines();
    foreach (['fulfilled', 'released'] as $closedStatus) {
        $proposal = disputeProposal($ledger, $lines);
        $proposal['new_issues'][0]['related_issue_id'] = 'aaaa000000000001';
        $closingUpdate = $proposal['updates'][0];
        $closingUpdate['status'] = $closedStatus;
        $proposal['updates'][] = $closingUpdate;
        rejected(fn () => applyProposal($ledger, $proposal, disputeMeta($lines)), "duplicate {$closedStatus} update must not retain the repeated-dispute exception");
    }
};
$failed = 0;
foreach ($cases as $name => $test) {
    try {
        $test();
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
}
if ($failed !== 0) exit(1);
echo "repeated dispute checks passed\n";
