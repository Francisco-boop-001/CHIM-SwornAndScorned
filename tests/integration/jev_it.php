<?php
declare(strict_types=1);
require __DIR__ . '/../support/check.php';
require __DIR__ . '/../support/llm_stubs.php';
require __DIR__ . '/../../server/queue.php';
use ChimSwornAndScorned\{PgJobDeps, PerspectiveStore};
use function ChimSwornAndScorned\{openEngineConnection, ensureSchema, currentPlaythrough, normalizeSettings, loadSettings, noul};

if (getenv('SAS_IT') !== '1') { echo "Jev integration skipped\n"; exit(0); }
check(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'only disposable clone');
putenv('SAS_TEST_SCHEMA=sas_it');
$pg = openEngineConnection('/var/www/html/HerikaServer');
check($pg->one("SELECT to_regnamespace('sas_it') AS s")['s'] === null, 'fixture schema absent');
$pg->exec('CREATE SCHEMA sas_it');
try {
    ensureSchema($pg);
    $pt = currentPlaythrough($pg);
    check($pt !== null, 'clone scope available');
    $GLOBALS['RELLLM_CONNECTOR'] = 3;
    $GLOBALS['CONNECTOR'] = ['existing'=>['API_KEY'=>'keep']];
    LLMConnector::$row = ['id'=>3,'driver'=>'openrouterjson','model'=>'google/gemma-4-31b-it',
        'url'=>'https://openrouter.ai/api/v1/chat/completions','test_api_key'=>'fixture-chim-only'];
    $calls = 0;
    $llmCalls = 0;
    $expectedKey = 'fixture-chim-only';
    $transport = static function (string $url, array $headers, string $body) use (&$calls, &$expectedKey): array {
        ++$calls;
        check($url === 'https://openrouter.ai/api/alpha/decisions', 'reused credential goes to Decisions endpoint');
        check($headers[0] === 'Authorization: Bearer '.$expectedKey, 'selected credential authenticates Decisions request');
        $request = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        check($request['model'] === 'typesafe/jev-1.13', 'Jev selection does not inherit the Gemma model');
        return ['status'=>200,'body'=>'{"answers":{"commitment":{"type":"noul","noul":0.98}}}'];
    };
    $completion = static function (array $messages) use (&$llmCalls): string {
        ++$llmCalls;
        return '{"answers":{"commitment":{"value":true,"confidence":"high"}}}';
    };
    $depsFor = static fn (array $settings): PgJobDeps => new PgJobDeps($pg, new PerspectiveStore($pg), $pt, $settings, $transport, $completion);
    $questions = [noul('commitment', 'Fixture commitment?')];
    $off = normalizeSettings(['api_key'=>'unused-override']);
    $default = $depsFor($off);
    check($default->backend('decisions') === null, 'default mode cannot select Jev even with a saved key');
    check($default->backend('llm')[0]->ask([], $questions)['commitment']['value'] === true, 'default mode uses relationship LLM');
    check($calls === 0 && $llmCalls === 1, 'default mode sends zero Decisions requests');
    $on = normalizeSettings(['use_jev'=>true]);
    $selected = $depsFor($on)->backend('decisions');
    check($selected[0]->ask([], $questions) === ['commitment'=>['value'=>true,'p'=>0.98]], 'CHIM credential reaches real Jev adapter with scripted transport');
    check($calls === 1 && $llmCalls === 1 && $GLOBALS['CONNECTOR'] === ['existing'=>['API_KEY'=>'keep']], 'Jev bypasses LLM and restores globals');
    check(LLMConnector::$row['model'] === 'google/gemma-4-31b-it', 'CHIM model unchanged');
    check($pg->one('SELECT count(*) AS n FROM '.ChimSwornAndScorned\sasTable('settings'))['n'] === '0', 'reuse persists no key or setting');
    check(!loadSettings($pg)['_key_present'], 'reused key never appears as a plugin override');
    $expectedKey = 'fixture-dedicated';
    $override = $depsFor(array_replace($on, ['api_key'=>$expectedKey]))->backend('decisions');
    $override[0]->ask([], $questions);
    check($calls === 2 && $llmCalls === 1, 'dedicated override authenticates without a Gemma request');
    check($depsFor($off)->backend('decisions') === null && $calls === 2, 'switching off blocks queued Decisions selection too');
} finally {
    $pg->exec('DROP SCHEMA sas_it CASCADE');
    putenv('SAS_TEST_SCHEMA');
    unset($GLOBALS['RELLLM_CONNECTOR'], $GLOBALS['CONNECTOR']);
}
echo "Jev integration checks passed\n";
