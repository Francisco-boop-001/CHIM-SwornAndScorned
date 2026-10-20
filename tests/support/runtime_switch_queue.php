<?php
declare(strict_types=1);
namespace ChimSwornAndScorned;
require_once getenv('SAS_RUNTIME_PLUGIN') . '/jobs.php';
require_once getenv('SAS_RUNTIME_FIXTURES');
const LORE_INTERVAL_SECONDS = 60;
function fixturePath(string $name): string { return getenv('SAS_RUNTIME_FIXTURE') . '/' . $name; }
function openEngineConnection(string $root): \stdClass { return new \stdClass(); }
function ensureSchema(\stdClass $pg): void {}
function loadSeeds(string $plugin, string $root): array { return []; }
function loadSettings(\stdClass $pg): array { return ['enabled' => !is_file(fixturePath('result.json'))]; }
function workerPauseReason(array $settings): ?string { return null; }
final class SwitchBackend implements DecisionBackend
{
    private int $calls = 0;
    public function name(): string { return 'synthetic:switch'; }
    public function ask(array $state, array $questions): mixed
    {
        ++$this->calls;
        file_put_contents(fixturePath('calls'), (string) $this->calls);
        touch(fixturePath('ask-' . $this->calls));
        if (str_starts_with(getenv('SAS_RUNTIME_CASE'), 'pass' . $this->calls)) {
            $deadline = microtime(true) + 5;
            while (!is_file(fixturePath('release-model')) && microtime(true) < $deadline) usleep(10000);
            if (!is_file(fixturePath('release-model'))) throw new \RuntimeException('synthetic_model_barrier_timeout');
            if (str_ends_with(getenv('SAS_RUNTIME_CASE'), '_error')) throw new DecisionBackendError('llm_request_failed', true);
        }
        return ['commitment' => ['value' => true, 'p' => 0.99], 'wronged' => ['value' => false, 'p' => 0.99]];
    }
}
final class SwitchDeps implements JobDeps
{
    public function currentPlaythroughId(): ?string { return 'pt-switch'; }
    public function linesFor(array $eventIds): array { return [\sampleLine(4, 'Lydia', 'Will you help me?'), \sampleLine(5, 'Farkas', 'I will help.')]; }
    public function ledgerFor(int $ownerNpcId, string $playthroughId): array { return emptyLedger($playthroughId); }
    public function counterpartKey(string $counterpartName): ?string { return 'npc:3'; }
    public function backend(string $kind): ?array { return [new SwitchBackend(), POLICY_DECISION_MODEL]; }
    public function commitLedger(array $request, callable $mutate): string { touch(fixturePath('commit')); return 'committed'; }
}
function workerTick(\stdClass $pg, array $settings, int $now): bool
{
    touch(fixturePath('tick'));
    file_put_contents(fixturePath('seen-generation'), $GLOBALS['ptr_runtime_generation']);
    if (getenv('SAS_RUNTIME_CASE') === 'idle') { touch(fixturePath('idle-ready')); return false; }
    $result = processJob(['id' => 7, 'playthrough_id' => 'pt-switch', 'owner_npc_id' => 2,
        'owner_name' => 'Lydia', 'counterpart_name' => 'Farkas', 'event_ids' => [4, 5], 'backend' => 'llm', 'failures' => 2], new SwitchDeps());
    file_put_contents(fixturePath('result.json'), json_encode($result, JSON_THROW_ON_ERROR));
    return true;
}
