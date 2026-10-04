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

/** Completion through CHIM's relationship connector; every touched global is restored. */
function relationshipConnectorCompletion(int $maxTokens = 1024, string $actorName = 'The Narrator', string $playerName = 'Player'): callable
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
