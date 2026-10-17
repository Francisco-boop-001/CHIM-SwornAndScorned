<?php
declare(strict_types=1);
require __DIR__ . '/../support/check.php';
require __DIR__ . '/../support/substance_cases.php';
require __DIR__ . '/../../server/evaluator.php';
require __DIR__ . '/../../server/apply.php';
require __DIR__ . '/../../server/decisions_backend.php';
require __DIR__ . '/../../server/llm_backend.php';
require __DIR__ . '/../../server/llm_connector.php';
require __DIR__ . '/../../server/pg.php';
use ChimSwornAndScorned\{OpenRouterDecisionBackend, LlmFallbackBackend};
use function ChimSwornAndScorned\{openEngineConnection, relationshipConnectorApiKey, relationshipConnectorIdentity, relationshipConnectorCompletion, curlTransport, evaluatePerspective};
use const ChimSwornAndScorned\{POLICY_DECISION_MODEL, POLICY_LLM_FALLBACK};

if (getenv('SAS_MODEL_TEST') !== '1') { echo "tone model checks skipped (explicit opt-in required)\n"; exit(0); }
check(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'only disposable clone');
ini_set('display_errors', '0');
// CHIM core may terminate with exit(0) on setup failure; that is not a passing test.
$completed = false;
register_shutdown_function(static function () use (&$completed): void {
    if (!$completed) {
        fwrite(STDERR, "tone_test_aborted_before_summary\n");
        exit(1);
    }
});
try {
    $engine = '/var/www/html/HerikaServer';
    $GLOBALS['ENGINE_PATH'] = $engine . '/';
    require_once $engine . '/conf/conf.php';
    require_once $engine . '/lib/postgresql.class.php';
    $dsn = (new ReflectionClass('sql'))->getDefaultProperties()['connString'] ?? '';
    check(is_string($dsn) && preg_match('~(?:^|\s)host=/var/run/postgresql(?:\s|$)~', $dsn) === 1, 'clone Unix-socket DSN required before any connection');
    $GLOBALS['db'] = new sql();
    require_once $engine . '/lib/settings.php';
    chimLoadGeneralSettingsIntoGlobals();
    $pg = openEngineConnection($engine);
    check($pg->one("SELECT current_setting('listen_addresses') AS value")['value'] === '', 'Unix-socket-only test database');
    $key = relationshipConnectorApiKey();
    check(is_string($key) && $key !== '', 'test CHIM OpenRouter credential available');
    $identity = relationshipConnectorIdentity();
    $mode = getenv('SAS_TONE_BACKEND') ?: 'decisions';
    check(in_array($mode, ['decisions', 'llm'], true), 'known backend');
    $calls = 0; $cost = 0.0;
    $curl = curlTransport();
    $transport = static function (string $url, array $headers, string $body) use ($curl, &$calls, &$cost): array {
        ++$calls;
        $response = $curl($url, $headers, $body);
        $parsed = json_decode($response['body'], true);
        $value = $parsed['usage']['cost'] ?? $parsed['cost'] ?? null;
        if (is_numeric($value)) $cost += (float) $value;
        return $response;
    };
    $backend = $mode === 'decisions' ? new OpenRouterDecisionBackend($key, 'typesafe/jev-1.13', $transport)
        : new LlmFallbackBackend(relationshipConnectorCompletion(1024, 'Lydia', 'Hawke'), $identity['model']);
    unset($key);
    $backend = new class($backend) implements ChimSwornAndScorned\DecisionBackend {
        public array $answers = [];
        public function __construct(private ChimSwornAndScorned\DecisionBackend $inner) {}
        public function name(): string { return $this->inner->name(); }
        public function ask(array $state, array $questions): mixed {
            $raw = $this->inner->ask($state, $questions);
            $this->answers += ChimSwornAndScorned\validatedAnswers($questions, $raw);
            return $raw;
        }
    };
    $policy = $mode === 'decisions' ? POLICY_DECISION_MODEL : POLICY_LLM_FALLBACK;
    $failed = 0;
    $set = getenv('SAS_SUBSTANCE_SET') ?: 'development';
    $cases = substanceCases($set);
    $categories = [];
    $repeat = getenv('SAS_TONE_REPEAT') ?: '1';
    check(ctype_digit($repeat) && (int) $repeat >= 1 && (int) $repeat <= 3, 'bounded repeat count');
    for ($round = 1; $round <= (int) $repeat; ++$round) {
        foreach ($cases as $name => $case) {
            $backend->answers = [];
            $start = hrtime(true);
            $proposal = evaluatePerspective(substanceContext($case), $backend, $policy);
            $failures = substanceFailures($case, $proposal);
            $ledger = ChimSwornAndScorned\emptyLedger('model-fixture');
            $ledger['issues'] = $case['open'] ?? [];
            $applied = ChimSwornAndScorned\applyProposal($ledger, $proposal, ['event_ids'=>array_column(substanceContext($case)['lines'], 'event_id'), 'counterpart'=>'npc:3', 'backend'=>$backend->name(), 'policy'=>$policy]);
            foreach ($case['open'] ?? [] as $old) {
                $saved = issueById($applied['ledger'], $old['id']);
                $expectedStatus = ($case['updates'] ?? [])[$old['id']] ?? $old['status'];
                $expectedStatus = match ($expectedStatus) {'no_change'=>$old['status'],'renegotiated'=>'released',default=>$expectedStatus};
                if ($saved['status'] !== $expectedStatus) $failures[] = 'wrong_issue_update';
                if (($saved['substance'] ?? null) !== ($old['substance'] ?? null) || $saved['counterpart'] !== $old['counterpart'] || $saved['direction'] !== $old['direction']) $failures[] = 'wrong_terms';
            }
            $replayed = ChimSwornAndScorned\applyProposal($applied['ledger'], $proposal, ['event_ids'=>array_column(substanceContext($case)['lines'], 'event_id'),'counterpart'=>'npc:3','backend'=>$backend->name(),'policy'=>$policy]);
            check($replayed['status'] === 'replay' && $replayed['affinity_delta'] === 0 && $replayed['ledger'] === $applied['ledger'], 'real decision replay preserves exact ledger and affinity');
            $expectedDelta = 0;
            foreach ($proposal['updates'] as $update) {
                $expectedStatus = ($case['updates'] ?? [])[$update['issue_id']] ?? null;
                if ($update['status_p'] < $policy['affinity_min']) continue;
                foreach ($case['open'] ?? [] as $old) {
                    if ($old['id'] !== $update['issue_id']) continue;
                    $expectedDelta += $old['kind'] === 'grievance'
                        ? (['resolved'=>2,'let_go'=>1][$expectedStatus] ?? 0)
                        : ($old['direction'] === 'owed_to_me' ? (['fulfilled'=>2,'broken'=>-3][$expectedStatus] ?? 0) : 0);
                }
            }
            if ($applied['affinity_delta'] !== $expectedDelta) $failures[] = 'wrong_affinity';
            $failures = array_values(array_unique($failures));
            $failed += (int) ($failures !== []);
            foreach ($failures as $category) $categories[$category] = ($categories[$category] ?? 0) + 1;
            echo json_encode(['case' => $name, 'round' => $round, 'backend' => $mode, 'failures' => $failures,
                'new_kinds' => array_column($proposal['new_issues'], 'kind'), 'affinity_delta'=>$applied['affinity_delta'],
                'updates' => array_column($proposal['updates'], 'status'), 'failed_case_answers' => $failures === [] ? [] : $backend->answers, 'duration_ms' => (int) ((hrtime(true) - $start) / 1000000)], JSON_THROW_ON_ERROR) . "\n";
            flush();
        }
    }
    echo json_encode(['set' => $set, 'categories' => $categories, 'failed_cases' => $failed, 'cases' => count($cases) * (int) $repeat, 'decisions_calls' => $calls, 'decisions_reported_cost_usd' => $cost, 'backend_name'=>$backend->name(), 'production_llm_max_tokens'=>1024, 'source_sha256'=>array_combine(['questions','evaluator','apply','substance'],array_map(static fn(string $n):string=>hash_file('sha256',__DIR__.'/../../server/'.$n.'.php'),['questions','evaluator','apply','substance']))], JSON_THROW_ON_ERROR) . "\n";
    $completed = true;
    exit($failed === 0 ? 0 : 1);
} catch (Throwable $error) {
    $completed = true;
    $cause = $error->getPrevious()?->getMessage();
    echo json_encode(['error_class'=>get_class($error),'case'=>$name ?? null,'reason'=>$error instanceof ChimSwornAndScorned\DecisionBackendError ? $error->reasonCode : 'test_setup_or_assertion_failed',
        'cause'=>in_array($cause,['connector_timeout','connector_request_failed','model_response_empty'],true) ? $cause : null],JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
