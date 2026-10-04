<?php
declare(strict_types=1);
require __DIR__ . '/support/check.php';
require __DIR__ . '/support/llm_stubs.php';
require __DIR__ . '/../server/tables.php';
require_once __DIR__ . '/../server/llm_connector.php';

use function ChimSwornAndScorned\{decisionApiKey, normalizeSettings, workerPauseReason};

$GLOBALS['RELLLM_CONNECTOR'] = 3;
$GLOBALS['CONNECTOR'] = ['existing' => ['API_KEY' => 'unchanged']];
LLMConnector::$row = ['id'=>3, 'driver'=>'openrouterjson', 'model'=>'google/gemma-4-31b-it',
    'url'=>'https://openrouter.ai/api/v1/chat/completions', 'test_api_key'=>'fixture-chim-only'];
$before = $GLOBALS['CONNECTOR'];
$off = normalizeSettings(['enabled'=>true, 'api_key'=>'fixture-override']);
check(decisionApiKey($off) === null && LLMConnector::$readIds === [], 'default mode ignores a saved override and never resolves Jev credentials');
check(workerPauseReason($off + ['_key_error'=>'key_storage_unavailable']) === null, 'unused broken Jev override cannot block default CHIM mode');
$on = normalizeSettings(['enabled'=>true, 'use_jev'=>true]);
check(decisionApiKey($on) === 'fixture-chim-only', 'experimental Jev reuses selected CHIM OpenRouter credential');
check($GLOBALS['CONNECTOR'] === $before && LLMConnector::$requestedRows === [], 'credential resolution restores globals and never invokes the chat driver');
check(LLMConnector::$row['model'] === 'google/gemma-4-31b-it', 'reuse never changes the configured CHIM model');
check(decisionApiKey(array_replace($on, ['api_key'=>'fixture-override'])) === 'fixture-override', 'optional dedicated override takes precedence');
LLMConnector::$row['driver'] = 'openrouterjsoncached';
check(decisionApiKey($on) === 'fixture-chim-only' && $GLOBALS['CONNECTOR'] === $before, 'cached OpenRouter credential reuse restores globals');
LLMConnector::$row['url'] = 'https://example.invalid/api/v1/chat/completions';
check(decisionApiKey($on) === null, 'an OpenRouter driver pointing elsewhere cannot supply a key to OpenRouter');
LLMConnector::$row['url'] = 'https://openrouter.ai/api/v1/chat/completions';
LLMConnector::$row['test_api_key'] = '';
check(workerPauseReason($on) === 'jev_credentials_unavailable', 'missing reused credential pauses before paid work');
LLMConnector::$row['driver'] = 'openai';
check(decisionApiKey($on) === null, 'unsupported connector credentials are not reused');
check($GLOBALS['CONNECTOR'] === $before, 'failed resolution restores connector globals');
echo "Jev selection checks passed\n";
