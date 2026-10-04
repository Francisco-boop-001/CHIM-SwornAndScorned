<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/jobs.php';
require __DIR__ . '/support/scripted_backend.php';

use ChimSwornAndScorned\{DecisionBackend, DecisionBackendError, JobDeps};
use function ChimSwornAndScorned\{emptyLedger, issueId, nextStep, processJob};
use const ChimSwornAndScorned\{POLICY_DECISION_MODEL, POLICY_LLM_FALLBACK};

final class FakeDeps implements JobDeps
{
    public ?string $playthrough = 'pt-1';
    public array $lines = [];
    public array $ledger;
    public ?array $ledgerAtCommit = null;
    public array $keys = ['Farkas' => 'npc:3', 'Hawke' => 'player'];
    public array $backends = [];
    public array $backendRequests = [];
    public string $commitStatus = 'committed';
    public array $commits = [];
    public bool $insideCommit = false;

    public function __construct()
    {
        $this->ledger = emptyLedger('pt-1');
    }

    public function currentPlaythroughId(): ?string { return $this->playthrough; }

    public function linesFor(array $eventIds): array
    {
        return array_values(array_filter($this->lines, static fn (array $line): bool => in_array($line['event_id'], $eventIds, true)));
    }

    public function ledgerFor(int $ownerNpcId, string $playthroughId): array { return $this->ledger; }

    public function counterpartKey(string $counterpartName): ?string { return $this->keys[$counterpartName] ?? null; }

    public function backend(string $kind): ?array
    {
        $this->backendRequests[] = $kind;
        return $this->backends[$kind] ?? null;
    }

    public function commitLedger(array $request, callable $mutate): string
    {
        $this->commits[] = ['request' => $request];
        $this->insideCommit = true;
        try {
            if ($this->commitStatus !== 'committed') {
                return $this->commitStatus;
            }
            $result = $mutate($this->ledgerAtCommit ?? $this->ledger);
            $this->commits[array_key_last($this->commits)]['result'] = $result;
            if ($result['status'] !== 'applied') {
                return $result['status'];
            }
            $this->ledger = $result['ledger'];
            return 'committed';
        } finally {
            $this->insideCommit = false;
        }
    }
}

final class ObservingBackend implements DecisionBackend
{
    /** @var list<list<string>> */
    public array $calls = [];

    public function __construct(private FakeDeps $deps, private array $answers, private string $label = 'scripted')
    {
    }

    public function name(): string { return $this->label; }

    public function ask(array $state, array $questions): mixed
    {
        check(!$this->deps->insideCommit, 'model calls happen before the commit boundary');
        $ids = array_column($questions, 'id');
        $this->calls[] = $ids;
        return array_intersect_key($this->answers, array_flip($ids));
    }
}

$promiseAnswers = [
    'commitment' => ['value' => true, 'p' => 0.95],
    'wronged' => ['value' => false, 'p' => 0.9],
    'commitment.who' => ['value' => 'Farkas', 'p' => 0.9],
    'commitment.strength' => ['value' => 'will', 'p' => 0.88],
    'commitment.acceptance' => ['value' => 'accepted', 'p' => 0.85],
    'commitment.line' => ['value' => 'L2', 'p' => 0.97],
];
$fallbackAnswers = [
    'commitment' => ['value' => true, 'p' => 0.96],
    'wronged' => ['value' => false, 'p' => 0.96],
    'commitment.who' => ['value' => 'Farkas', 'p' => 0.96],
    'commitment.strength' => ['value' => 'will', 'p' => 0.96],
    'commitment.acceptance' => ['value' => 'accepted', 'p' => 0.96],
    'commitment.line' => ['value' => 'L2', 'p' => 0.96],
];
$job = [
    'id' => 7, 'playthrough_id' => 'pt-1', 'owner_npc_id' => 5124, 'owner_name' => 'Lydia',
    'counterpart_name' => 'Farkas', 'event_ids' => [4, 5], 'backend' => 'decisions', 'failures' => 0,
];
$freshDeps = static function (array $answers = [], string $backendName = 'decisions:test-model'): FakeDeps {
    $deps = new FakeDeps();
    $deps->lines = [
        sampleLine(4, 'Lydia', 'Will you defend me before Aela?'),
        sampleLine(5, 'Farkas', 'I will stand with you before Aela.'),
    ];
    $backend = new ObservingBackend($deps, $answers, $backendName);
    $deps->backends['decisions'] = [$backend, POLICY_DECISION_MODEL];
    return $deps;
};

$deps = $freshDeps($promiseAnswers);
$deps->ledgerAtCommit = emptyLedger('pt-1');
$deps->ledgerAtCommit['issues'][] = sampleIssue(['counterpart' => 'npc:4']);
check(processJob($job, $deps) === ['outcome' => 'done', 'reason' => 'committed'], 'job commits its evaluated batch');
$promiseId = issueId('e:5', 'npc:3', 'promise');
$promise = issueById($deps->ledger, $promiseId);
check($promise['direction'] === 'owed_to_me' && $promise['evidence'][0]['ref'] === 'e:5', 'selected speaker and in-batch line support the promise');
check($promise['evidence'][0]['backend'] === 'decisions:test-model', 'ledger records the backend and requested model');
check(count($deps->ledger['issues']) === 2, 'commit mutates the fresh ledger supplied at commit time');
check($deps->commits[0]['request'] === [
    'owner_npc_id' => 5124,
    'owner_name' => 'Lydia',
    'playthrough_id' => 'pt-1',
    'event_ids' => [4, 5],
    'counterpart_name' => 'Farkas',
    'counterpart_key' => 'npc:3',
], 'commit receives the complete stale-validation request');
check(processJob($job, $deps) === ['outcome' => 'done', 'reason' => 'replay'], 'replay does not duplicate issues');

$deps = $freshDeps($promiseAnswers);
$deps->playthrough = 'pt-2';
check(processJob($job, $deps) === ['outcome' => 'stale', 'reason' => 'playthrough_changed'], 'job from another playthrough is stale before evaluation');
check($deps->backendRequests === [] && $deps->commits === [], 'stale preflight does not call a backend or commit');

$deps = $freshDeps($promiseAnswers);
$deps->lines = [$deps->lines[0]];
check(processJob($job, $deps) === ['outcome' => 'stale', 'reason' => 'events_missing'], 'pruned source rows are stale');

$deps = $freshDeps($promiseAnswers);
$unknownJob = $job;
$unknownJob['counterpart_name'] = 'Nobody';
check(processJob($unknownJob, $deps) === ['outcome' => 'stale', 'reason' => 'counterpart_unresolved'], 'unknown counterpart is stale');

$deps = $freshDeps($promiseAnswers);
$deps->ledger['processed'] = [4, 5];
check(processJob($job, $deps) === ['outcome' => 'done', 'reason' => 'replay'], 'already processed source batch is idempotent');
check($deps->backendRequests === [] && $deps->commits === [], 'replay skips provider and commit');

$deps = $freshDeps($fallbackAnswers, 'llm-fallback');
$fallbackBackend = $deps->backends['decisions'][0];
$deps->backends['decisions'] = null;
$deps->backends['llm'] = [$fallbackBackend, POLICY_LLM_FALLBACK];
check(processJob($job, $deps) === ['outcome' => 'done', 'reason' => 'committed'], 'missing Decisions key uses the CHIM fallback');
check($deps->backendRequests === ['decisions', 'llm'], 'fallback is requested after Decisions is unavailable');
check(issueById($deps->ledger, issueId('e:5', 'npc:3', 'promise'))['evidence'][0]['backend'] === 'llm-fallback', 'fallback identity is recorded');

$deps = $freshDeps($promiseAnswers);
$deps->backends = [];
check(processJob($job, $deps) === ['outcome' => 'failed', 'reason' => 'backend_unavailable'], 'job fails when no backend is available');

$deps = $freshDeps($promiseAnswers);
$deps->commitStatus = 'events_missing';
check(processJob($job, $deps) === ['outcome' => 'stale', 'reason' => 'events_missing'], 'commit-time source revalidation rejects a pruned batch');
check(count($deps->commits) === 1 && count($deps->backends['decisions'][0]->calls) === 2, 'commit revalidation follows both model passes');
check($deps->ledger['issues'] === [], 'stale commit does not write evaluated proposals');

$deps = $freshDeps($promiseAnswers);
$deps->commitStatus = 'playthrough_changed';
check(processJob($job, $deps) === ['outcome' => 'stale', 'reason' => 'playthrough_changed'], 'commit-time playthrough change is stale');

$deps = $freshDeps($promiseAnswers);
$deps->commitStatus = 'busy';
check(processJob($job, $deps) === ['outcome' => 'retry', 'reason' => 'owner_busy'], 'busy owner retries');

$deps = $freshDeps($promiseAnswers);
$deps->commitStatus = 'restore_policy';
check(processJob($job, $deps) === ['outcome' => 'done', 'reason' => 'restore_policy'], 'restore policy skips writes');

$deps = $freshDeps($promiseAnswers);
$deps->backends['decisions'] = [new class implements DecisionBackend {
    public function name(): string { return 'throwing'; }
    public function ask(array $state, array $questions): mixed { throw new DecisionBackendError('http_429', true); }
}, POLICY_DECISION_MODEL];
check(processJob($job, $deps) === ['outcome' => 'retry', 'reason' => 'http_429'], 'retryable provider error is mapped');

$deps = $freshDeps($promiseAnswers);
$deps->backends['decisions'] = [new class implements DecisionBackend {
    public function name(): string { return 'throwing'; }
    public function ask(array $state, array $questions): mixed { throw new DecisionBackendError('http_401', false); }
}, POLICY_DECISION_MODEL];
check(processJob($job, $deps) === ['outcome' => 'failed', 'reason' => 'http_401'], 'permanent provider error is mapped');

check(nextStep('retry', 1, 'decisions', true) === ['action' => 'retry', 'delay' => 30, 'backend' => 'decisions', 'failures' => 1], 'first primary retry after 30 seconds');
check(nextStep('retry', 2, 'decisions', true) === ['action' => 'retry', 'delay' => 120, 'backend' => 'decisions', 'failures' => 2], 'second primary retry after 2 minutes');
check(nextStep('retry', 3, 'decisions', true) === ['action' => 'retry', 'delay' => 600, 'backend' => 'decisions', 'failures' => 3], 'third primary retry after 10 minutes');
check(nextStep('retry', 4, 'decisions', true) === ['action' => 'retry', 'delay' => 0, 'backend' => 'llm', 'failures' => 0], 'then schedule the fallback with a reset failure count');
check(nextStep('retry', 4, 'decisions', false) === ['action' => 'fail'], 'no available fallback ends after primary retries');
check(nextStep('failed', 1, 'decisions', true) === ['action' => 'retry', 'delay' => 0, 'backend' => 'llm', 'failures' => 0], 'permanent Decisions error switches directly to fallback');
check(nextStep('retry', 1, 'llm', true)['delay'] === 30, 'fallback starts its own retry schedule');
check(nextStep('retry', 4, 'llm', true) === ['action' => 'fail'], 'exhausted fallback fails');
check(nextStep('failed', 1, 'llm', true) === ['action' => 'fail'], 'permanent fallback error fails immediately');

echo "jobs checks passed\n";
