<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

final class Pg
{
    public function __construct(public readonly \PgSql\Connection $connection)
    {
    }

    /** @return list<array<string, ?string>> */
    public function all(string $sql, array $params = []): array
    {
        $result = $params === []
            ? @pg_query($this->connection, $sql)
            : @pg_query_params($this->connection, $sql, $params);
        if ($result === false) {
            throw new \RuntimeException('sql_failed');
        }
        $rows = pg_fetch_all($result) ?: [];
        pg_free_result($result);
        return $rows;
    }

    public function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    public function exec(string $sql, array $params = []): void
    {
        $this->all($sql, $params);
    }
}

function pgIntArray(array $ids): string
{
    return '{' . implode(',', array_map('intval', $ids)) . '}';
}

function parsePgIntArray(string $text): array
{
    $inner = trim($text, '{}');
    return $inner === '' ? [] : array_map('intval', explode(',', $inner));
}

/** A private connection for the worker and the page, from CHIM's sql class default DSN. */
function openEngineConnection(string $engineRoot): Pg
{
    if (!class_exists('sql', false)) {
        require_once rtrim($engineRoot, '/') . '/lib/postgresql.class.php';
    }
    $dsn = (new \ReflectionClass('sql'))->getDefaultProperties()['connString'] ?? null;
    if (!is_string($dsn) || $dsn === '' || str_contains($dsn, "\0")) {
        throw new \RuntimeException('dsn_unavailable');
    }
    $connection = @pg_connect($dsn . ' connect_timeout=5', PGSQL_CONNECT_FORCE_NEW);
    if (!$connection instanceof \PgSql\Connection) {
        throw new \RuntimeException('connection_failed');
    }
    return new Pg($connection);
}

/** CHIM's own request connection, for autocommit reads and small writes in hooks. */
function sharedConnection(): Pg
{
    if (!class_exists(\sql::class, false)) {
        throw new \RuntimeException('sql_unavailable');
    }
    $property = new \ReflectionProperty(\sql::class, 'link');
    $property->setAccessible(true);
    $connection = $property->getValue();
    if (!$connection instanceof \PgSql\Connection
        || pg_connection_status($connection) !== PGSQL_CONNECTION_OK
        || pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
        throw new \RuntimeException('connection_unavailable');
    }
    return new Pg($connection);
}
