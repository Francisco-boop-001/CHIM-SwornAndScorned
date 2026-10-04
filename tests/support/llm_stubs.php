<?php
declare(strict_types=1);

final class LLMConnector
{
    public static ?array $row = null;
    public static array $requestedRows = [];
    public static array $readIds = [];
    public static int $setGlobalsCalls = 0;

    public function readOne(int $id): ?array
    {
        self::$readIds[] = $id;
        return self::$row;
    }

    public function setOldGlobals(array $row): void
    {
        self::$setGlobalsCalls++;
        $GLOBALS['CONNECTOR'][$row['driver']] = ['API_KEY' => $row['test_api_key'] ?? ''];
    }

    public function getConnector(array $row): object
    {
        self::$requestedRows[] = $row;
        return new StubDriver();
    }
}

final class StubDriver
{
    public static array $calls = [];
    public static string $reply = '{"answers":{}}';

    public function fast_request(array $messages, array $params, string $name): string
    {
        self::$calls[] = [
            'messages' => $messages,
            'params' => $params,
            'name' => $name,
            'timeout' => $GLOBALS['HTTP_TIMEOUT'] ?? null,
            'max_tokens' => $GLOBALS['FORCE_MAX_TOKENS'] ?? null,
            'connector' => $GLOBALS['CONNECTOR'] ?? null,
            'actor' => $GLOBALS['HERIKA_NAME'] ?? null,
            'player' => $GLOBALS['PLAYER_NAME'] ?? null,
        ];
        return self::$reply;
    }
}
