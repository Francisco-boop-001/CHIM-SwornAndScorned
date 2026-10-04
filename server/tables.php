<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

use UnexpectedValueException;

require_once __DIR__ . '/pg.php';

const DEFAULT_SETTINGS = ['enabled' => false, 'cast' => [], 'model' => 'typesafe/jev-1.13', 'api_key' => ''];
const MAX_CAST = 8;

function sasTable(string $name): string
{
    $schema = getenv('SAS_TEST_SCHEMA') ?: 'plugins';
    if (preg_match('/\A[a-z_][a-z0-9_]{0,40}\z/D', $schema) !== 1 || preg_match('/\A[a-z_]{1,32}\z/D', $name) !== 1) {
        throw new UnexpectedValueException('table_name_invalid');
    }
    return "{$schema}.sworn_scorned_{$name}";
}

function ensureSchema(Pg $pg): void
{
    $schema = explode('.', sasTable('jobs'))[0];
    $pg->exec("CREATE SCHEMA IF NOT EXISTS {$schema}");
    $pg->exec('CREATE TABLE IF NOT EXISTS ' . sasTable('jobs') . " (
        id bigserial PRIMARY KEY,
        playthrough_id text NOT NULL,
        owner_npc_id bigint NOT NULL,
        owner_name text NOT NULL,
        counterpart_name text NOT NULL,
        event_ids bigint[] NOT NULL,
        backend text NOT NULL DEFAULT 'decisions',
        status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'failed')),
        failures int NOT NULL DEFAULT 0,
        next_attempt_at timestamptz NOT NULL DEFAULT now(),
        lease_until timestamptz,
        last_error text,
        created_at timestamptz NOT NULL DEFAULT now(),
        UNIQUE (playthrough_id, owner_npc_id, counterpart_name, event_ids)
    )");
    $pg->exec('CREATE TABLE IF NOT EXISTS ' . sasTable('marks') . ' (
        playthrough_id text NOT NULL,
        owner_npc_id bigint NOT NULL,
        counterpart_name text NOT NULL,
        last_rowid bigint NOT NULL,
        PRIMARY KEY (playthrough_id, owner_npc_id, counterpart_name)
    )');
    $pg->exec('CREATE TABLE IF NOT EXISTS ' . sasTable('cursor') . ' (
        playthrough_id text PRIMARY KEY,
        last_rowid bigint NOT NULL
    )');
    $pg->exec('CREATE TABLE IF NOT EXISTS ' . sasTable('settings') . ' (
        key text PRIMARY KEY,
        value jsonb NOT NULL
    )');
    $pg->exec('CREATE TABLE IF NOT EXISTS ' . sasTable('pacing') . ' (
        npc_id bigint NOT NULL,
        issue_id text NOT NULL,
        surfaced_at bigint NOT NULL,
        evidence_count int NOT NULL,
        evidence_ref text NOT NULL DEFAULT $$$$,
        PRIMARY KEY (npc_id, issue_id)
    )');
}

function normalizeSettings(array $settings): array
{
    $cast = [];
    foreach (is_array($settings['cast'] ?? null) ? $settings['cast'] : [] as $id) {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if (is_int($id) && $id > 0 && !in_array($id, $cast, true)) {
            $cast[] = $id;
        }
    }
    $model = $settings['model'] ?? null;
    return [
        'enabled' => ($settings['enabled'] ?? false) === true,
        'cast' => array_slice($cast, 0, MAX_CAST),
        'model' => is_string($model) && preg_match('#\A[A-Za-z0-9~._/-]{3,100}\z#D', $model) === 1 ? $model : DEFAULT_SETTINGS['model'],
        'api_key' => is_string($settings['api_key'] ?? null) ? trim($settings['api_key']) : '',
    ];
}

function loadSettings(Pg $pg): array
{
    $settings = DEFAULT_SETTINGS;
    foreach ($pg->all('SELECT key, value::text AS value FROM ' . sasTable('settings')) as $row) {
        if (array_key_exists($row['key'], DEFAULT_SETTINGS)) {
            $settings[$row['key']] = json_decode((string) $row['value'], true);
        }
    }
    return normalizeSettings($settings);
}

function saveSetting(Pg $pg, string $key, mixed $value): void
{
    if (!array_key_exists($key, DEFAULT_SETTINGS)) {
        throw new UnexpectedValueException('setting_unknown');
    }
    $pg->exec(
        'INSERT INTO ' . sasTable('settings') . ' (key, value) VALUES ($1, $2::jsonb)
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value',
        [$key, json_encode($value, JSON_FLAGS)]
    );
}

/** Same rule as Mind Poisoning: unprofiled, or exactly one active profile. */
function currentPlaythrough(Pg $pg): ?array
{
    $player = $pg->one("SELECT value FROM core_player WHERE id = 'player_name' LIMIT 1");
    $playerName = trim((string) ($player['value'] ?? ''));
    if ($playerName === '') {
        return null;
    }
    $table = $pg->one("SELECT to_regclass('chim_meta.playthrough_profiles') AS t");
    if (($table['t'] ?? null) === null) {
        return ['id' => 'unprofiled', 'player_name' => $playerName];
    }
    $rows = $pg->all('SELECT id::text AS id FROM chim_meta.playthrough_profiles WHERE is_active IS TRUE ORDER BY id LIMIT 2');
    if ($rows === []) {
        return ['id' => 'unprofiled', 'player_name' => $playerName];
    }
    return count($rows) === 1 ? ['id' => $rows[0]['id'], 'player_name' => $playerName] : null;
}
