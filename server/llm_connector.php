<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

const SAVED_CONNECTOR_GLOBALS = [
    'CONNECTOR', 'HTTP_TIMEOUT', 'FORCE_MAX_TOKENS', 'DEBUG_DATA',
    'PATCH_PROMPT_ENFORCE_ACTIONS', 'COMMAND_PROMPT_ENFORCE_ACTIONS', 'FUNC_LIST',
    'responseTemplate', 'structuredOutputTemplate', 'grammar', 'PROMPT_ACTIONS_LIST',
    'CHIM_JSON_RESPONSE_EXT_LOADED', 'FUNCTIONS_ARE_ENABLED', 'LLM_LANG',
    'HERIKA_NAME', 'PLAYER_NAME',
];

function relationshipConnectorId(): ?int
{
    $id = filter_var($GLOBALS['RELLLM_CONNECTOR'] ?? null, FILTER_VALIDATE_INT);
    return is_int($id) && $id > 0 ? $id : null;
}

/** Read safe identity fields from the selected CHIM connector without changing globals. */
function relationshipConnectorIdentity(): ?array
{
    $id = relationshipConnectorId();
    if ($id === null) {
        return null;
    }
    if (!class_exists('\\LLMConnector', false)) {
        $engineRoot = rtrim((string) ($GLOBALS['ENGINE_PATH'] ?? ''), '/\\');
        $file = $engineRoot . '/lib/core/llm_connector.class.php';
        if (!is_file($file)) {
            return null;
        }
        require_once $file;
    }
    try {
        $row = (new \LLMConnector())->readOne($id);
    } catch (\Throwable) {
        return null;
    }
    $driver = is_array($row) ? ($row['driver'] ?? null) : null;
    $model = is_array($row) ? ($row['model'] ?? null) : null;
    if (!is_array($row) || (int) ($row['id'] ?? 0) !== $id
        || !in_array($driver, ['openrouterjson', 'openrouterjsoncached'], true)
        || !is_string($model) || trim($model) === '' || strlen($model) > 120
        || preg_match('/[\\x00-\\x1f\\x7f]/', $model) === 1) {
        return null;
    }
    return ['id' => $id, 'driver' => $driver, 'model' => $model];
}

function relationshipConnectorAvailable(): bool
{
    if (PHP_OS_FAMILY !== 'Linux' || !is_executable('/usr/bin/timeout') || !function_exists('proc_open')) return false;
    if (relationshipConnectorId() === null) {
        return false;
    }
    if (function_exists('chimIsGlobalLlmConnectorEnabled')) {
        if (\chimIsGlobalLlmConnectorEnabled('RELLLM_CONNECTOR') !== true) {
            return false;
        }
    }
    return relationshipConnectorIdentity() !== null;
}

/** Resolve only the selected CHIM OpenRouter credential; never persist it here. */
function relationshipConnectorApiKey(): ?string
{
    if (function_exists('chimIsGlobalLlmConnectorEnabled') && \chimIsGlobalLlmConnectorEnabled('RELLLM_CONNECTOR') !== true) return null;
    $identity = relationshipConnectorIdentity();
    if ($identity === null) return null;
    $hadConnector = array_key_exists('CONNECTOR', $GLOBALS);
    $savedConnector = $GLOBALS['CONNECTOR'] ?? null;
    try {
        $llm = new \LLMConnector();
        $row = $llm->readOne($identity['id']);
        if (!is_array($row) || (int) ($row['id'] ?? 0) !== $identity['id'] || ($row['driver'] ?? null) !== $identity['driver']) return null;
        $row['driver'] = 'openrouterjson';
        $llm->setOldGlobals($row);
        $config = $GLOBALS['CONNECTOR']['openrouterjson'] ?? [];
        $url = parse_url((string) ($config['url'] ?? ''));
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || strtolower($url['host'] ?? '') !== 'openrouter.ai'
            || isset($url['user']) || isset($url['pass']) || ($url['port'] ?? 443) !== 443) return null;
        $key = $config['API_KEY'] ?? null;
        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    } catch (\Throwable) {
        return null;
    } finally {
        if ($hadConnector) $GLOBALS['CONNECTOR'] = $savedConnector;
        else unset($GLOBALS['CONNECTOR']);
    }
}

/** Backend choice is explicit; an encrypted override is optional. */
function decisionApiKey(#[\SensitiveParameter] array $settings): ?string
{
    if (empty($settings['use_jev'])) return null;
    if (!empty($settings['_key_error'])) throw new KeyStorageError();
    return ($settings['api_key'] ?? '') !== '' ? $settings['api_key'] : relationshipConnectorApiKey();
}

/** Completion through CHIM's relationship connector; every touched global is restored. */
function relationshipConnectorDirectCompletion(int $maxTokens = 1024, string $actorName = 'The Narrator', string $playerName = 'Player'): callable
{
    return static function (array $messages) use ($maxTokens, $actorName, $playerName): string {
        $saved = [];
        foreach (SAVED_CONNECTOR_GLOBALS as $name) {
            $saved[$name] = [array_key_exists($name, $GLOBALS), $GLOBALS[$name] ?? null];
        }
        try {
            $GLOBALS['HERIKA_NAME'] = $actorName;
            $GLOBALS['PLAYER_NAME'] = $playerName;
            $connectorId = relationshipConnectorId();
            if ($connectorId === null) {
                throw new \RuntimeException('connector_id_invalid');
            }
            if (!class_exists('\\LLMConnector', false)) {
                $engineRoot = rtrim((string) ($GLOBALS['ENGINE_PATH'] ?? ''), '/\\');
                $file = $engineRoot . '/lib/core/llm_connector.class.php';
                if (!is_file($file)) {
                    throw new \RuntimeException('connector_unavailable');
                }
                require_once $file;
            }
            $llm = new \LLMConnector();
            $row = $llm->readOne($connectorId);
            if (!is_array($row) || (int) ($row['id'] ?? 0) !== $connectorId) {
                throw new \RuntimeException('connector_not_found');
            }
            $driver = $row['driver'] ?? null;
            if (!in_array($driver, ['openrouterjson', 'openrouterjsoncached'], true)) {
                throw new \RuntimeException('connector_driver_unsupported');
            }
            $base = $row;
            $base['driver'] = 'openrouterjson';
            $llm->setOldGlobals($base);
            $config = $GLOBALS['CONNECTOR']['openrouterjson'] ?? null;
            if (!is_array($config) || trim((string) ($config['API_KEY'] ?? '')) === '') {
                throw new \RuntimeException('connector_api_key_missing');
            }
            if ($driver === 'openrouterjsoncached') {
                $GLOBALS['CONNECTOR']['openrouterjsoncached'] = $config;
            }
            $connector = $llm->getConnector($row);
            $GLOBALS['HTTP_TIMEOUT'] = 20;
            $GLOBALS['FORCE_MAX_TOKENS'] = $maxTokens;
            $response = $connector->fast_request($messages, ['MAX_TOKENS' => $maxTokens], 'sworn_and_scorned');
            if (!is_string($response) || trim($response) === '') {
                throw new \RuntimeException('model_response_empty');
            }
            return $response;
        } finally {
            foreach ($saved as $name => [$existed, $value]) {
                if ($existed) {
                    $GLOBALS[$name] = $value;
                } else {
                    unset($GLOBALS[$name]);
                }
            }
        }
    };
}

/** The native stream reader cannot be interrupted reliably by a PHP signal handler. */
function relationshipConnectorCompletion(int $maxTokens = 1024, string $actorName = 'The Narrator', string $playerName = 'Player', int $timeoutSeconds = 20): callable
{
    return static function (array $messages) use ($maxTokens, $actorName, $playerName, $timeoutSeconds): string {
        $root = realpath((string) ($GLOBALS['ENGINE_PATH'] ?? ''));
        $id = relationshipConnectorId();
        if ($root === false || !is_file($root . '/main.php') || $id === null || $timeoutSeconds < 1 || PHP_SAPI !== 'cli'
            || PHP_OS_FAMILY !== 'Linux' || !is_executable('/usr/bin/timeout')) {
            throw new \RuntimeException('connector_timeout_unavailable');
        }
        // No credential passed in arguments or request IPC. The child resolves the CHIM connector itself.
        $wire = json_encode(['connector_id'=>$id, 'max_tokens'=>$maxTokens, 'actor'=>$actorName, 'player'=>$playerName, 'messages'=>$messages], JSON_THROW_ON_ERROR);
        if (strlen($wire) > 2097152) throw new \RuntimeException('connector_request_invalid');
        $process = @proc_open(
            ['/usr/bin/timeout', '--signal=KILL', $timeoutSeconds . 's', PHP_BINARY, __DIR__ . '/llm_request.php', $root],
            [0=>['pipe','r'], 1=>['pipe','w'], 2=>['file','/dev/null','a']], $pipes
        );
        if (!is_resource($process)) throw new \RuntimeException('connector_request_failed');
        try {
            $offset = 0;
            while ($offset < strlen($wire)) {
                $sent = @fwrite($pipes[0], substr($wire, $offset));
                if ($sent === false || $sent === 0) throw new \RuntimeException('connector_request_failed');
                $offset += $sent;
            }
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1], 262145);
        } finally {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            $status = proc_close($process);
        }
        if (in_array($status, [9, 124, 137], true)) throw new \RuntimeException('connector_timeout');
        if ($status !== 0 || !is_string($output) || strlen($output) > 262144) throw new \RuntimeException('connector_request_failed');
        try { $reply = json_decode($output, true, 8, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new \RuntimeException('connector_request_failed'); }
        if (!is_array($reply) || !is_string($reply['response'] ?? null) || trim($reply['response']) === '') throw new \RuntimeException('connector_request_failed');
        return $reply['response'];
    };
}
