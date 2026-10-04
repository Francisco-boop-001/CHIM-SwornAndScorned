<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/llm_stubs.php';
require __DIR__ . '/../server/llm_connector.php';

use function ChimSwornAndScorned\{relationshipConnectorAvailable, relationshipConnectorDirectCompletion as relationshipConnectorCompletion, relationshipConnectorIdentity};

$GLOBALS['HTTP_TIMEOUT'] = 5;
$GLOBALS['FORCE_MAX_TOKENS'] = 512;
$GLOBALS['CONNECTOR'] = ['existing' => ['API_KEY' => 'keep']];
$GLOBALS['RELLLM_CONNECTOR'] = '3';
LLMConnector::$row = ['id' => 3, 'driver' => 'openrouterjson', 'model' => 'm', 'url' => 'u', 'test_api_key' => 'k'];
StubDriver::$reply = '{"answers":{}}';
StubDriver::$calls = [];
LLMConnector::$requestedRows = [];
LLMConnector::$readIds = [];
LLMConnector::$setGlobalsCalls = 0;

check(relationshipConnectorIdentity() === ['id' => 3, 'driver' => 'openrouterjson', 'model' => 'm'], 'configured connector identity is resolved');
check(LLMConnector::$readIds === [3] && LLMConnector::$setGlobalsCalls === 0, 'identity lookup only reads connector metadata');

$before = [];
$globalNames = [
    'CONNECTOR', 'HTTP_TIMEOUT', 'FORCE_MAX_TOKENS', 'DEBUG_DATA',
    'PATCH_PROMPT_ENFORCE_ACTIONS', 'COMMAND_PROMPT_ENFORCE_ACTIONS', 'FUNC_LIST',
    'responseTemplate', 'structuredOutputTemplate', 'grammar', 'PROMPT_ACTIONS_LIST',
    'CHIM_JSON_RESPONSE_EXT_LOADED', 'FUNCTIONS_ARE_ENABLED', 'LLM_LANG',
    'HERIKA_NAME', 'PLAYER_NAME',
];
foreach ($globalNames as $name) {
    $before[$name] = [array_key_exists($name, $GLOBALS), $GLOBALS[$name] ?? null];
}

$complete = relationshipConnectorCompletion(1024, 'Lydia', 'Hawke');
check($complete([['role' => 'user', 'content' => 'x']]) === '{"answers":{}}', 'completion returns driver text');
check(StubDriver::$calls[0]['params'] === ['MAX_TOKENS' => 1024] && StubDriver::$calls[0]['name'] === 'sworn_and_scorned', 'token budget and caller name');
check(StubDriver::$calls[0]['timeout'] === 20 && StubDriver::$calls[0]['max_tokens'] === 1024, 'bounded timeout and token globals are active only during the call');
check(StubDriver::$calls[0]['connector']['openrouterjson']['API_KEY'] === 'k', 'configured connector key reaches CHIM driver');
check(StubDriver::$calls[0]['actor'] === 'Lydia' && StubDriver::$calls[0]['player'] === 'Hawke', 'background connector gets required scoped names');
foreach ($before as $name => [$existed, $value]) {
    check(array_key_exists($name, $GLOBALS) === $existed, "global presence restored: {$name}");
    if ($existed) {
        check($GLOBALS[$name] === $value, "global value restored: {$name}");
    }
}
check(relationshipConnectorAvailable(), 'available with a valid connector id');

LLMConnector::$row = ['id' => 3, 'driver' => 'openrouterjsoncached', 'model' => 'm', 'url' => 'u', 'test_api_key' => 'k'];
check($complete([]) === '{"answers":{}}', 'cached OpenRouter JSON driver is supported');
check(StubDriver::$calls[1]['connector']['openrouterjsoncached']['API_KEY'] === 'k', 'cached driver receives its compatible configuration');
check(LLMConnector::$requestedRows[1]['driver'] === 'openrouterjsoncached', 'selected connector row is passed to CHIM driver');

LLMConnector::$row = ['id' => 4, 'driver' => 'openrouterjson', 'model' => 'wrong', 'url' => 'u', 'test_api_key' => 'k'];
check(relationshipConnectorIdentity() === null, 'identity lookup rejects a mismatched connector row');
LLMConnector::$row = ['id' => 3, 'driver' => 'openai', 'model' => 'unsupported', 'url' => 'u', 'test_api_key' => 'k'];
check(relationshipConnectorIdentity() === null, 'identity lookup rejects unsupported drivers');

LLMConnector::$row = ['id' => 3, 'driver' => 'openai', 'model' => 'm', 'url' => 'u', 'test_api_key' => 'k'];
try {
    $complete([]);
    check(false, 'unsupported driver must throw');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'connector_driver_unsupported', 'unsupported driver rejected with fixed reason');
}

LLMConnector::$row = ['id' => 3, 'driver' => 'openrouterjson', 'model' => 'm', 'url' => 'u', 'test_api_key' => ''];
try {
    $complete([]);
    check(false, 'missing key must throw');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'connector_api_key_missing', 'missing key rejected with fixed reason');
}

LLMConnector::$row = ['id' => 3, 'driver' => 'openrouterjson', 'model' => 'm', 'url' => 'u', 'test_api_key' => 'k'];
StubDriver::$reply = '   ';
try {
    $complete([]);
    check(false, 'empty reply must throw');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'model_response_empty', 'empty reply rejected with fixed reason');
}
check($GLOBALS['HTTP_TIMEOUT'] === 5 && $GLOBALS['FORCE_MAX_TOKENS'] === 512, 'globals restored after provider errors');
check($GLOBALS['CONNECTOR'] === ['existing' => ['API_KEY' => 'keep']], 'connector globals restored after provider errors');

check(function_exists('pcntl_alarm') && function_exists('pcntl_signal_get_handler') && function_exists('pcntl_async_signals'), 'test runtime supports bounded connector signals');
$GLOBALS['RELLLM_CONNECTOR'] = 'not-an-id';
check(!relationshipConnectorAvailable(), 'unavailable without a valid connector id');
try {
    $complete([]);
    check(false, 'invalid connector id must throw');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'connector_id_invalid', 'invalid connector id rejected');
}

echo "llm connector checks passed\n";
