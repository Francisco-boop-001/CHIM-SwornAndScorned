<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

const LOG_FIELDS = [
    'job_id', 'owner_npc_id', 'npc_id', 'issue_id', 'outcome', 'reason', 'backend',
    'jobs', 'failures', 'pid', 'status', 'addresses', 'p', 'turn', 'cast_count',
    'enabled', 'use_jev', 'duration_ms',
];
const LOG_EVENTS = [
    'worker_launch', 'lore_seeds_invalid', 'worker_started', 'worker_stopped',
    'worker_paused', 'worker_resumed', 'worker_error', 'jobs_queued', 'job_finished',
    'lore_override_invalid', 'lore_owner_invalid', 'issue_surfaced', 'issue_evaluated',
    'settings_saved', 'settings_rejected', 'worker_restart_requested', 'key_updated',
    'key_removed', 'access_rejected', 'form_rejected',
];
const LOG_REASONS = [
    'access_denied', 'access_rejected', 'action_unknown', 'backend_unavailable',
    'cast_changed', 'cast_invalid', 'cast_scope_unavailable', 'commit_rejected',
    'connector_api_key_missing', 'connector_driver_unsupported', 'connector_id_invalid',
    'connector_not_found', 'connector_request_failed', 'connector_request_invalid',
    'connector_timeout', 'connector_timeout_unavailable', 'connector_unavailable',
    'committed', 'counterpart_changed', 'counterpart_unresolved', 'csrf_invalid', 'disabled',
    'edge_invalid', 'events_missing', 'form_invalid', 'http_429', 'internal_error',
    'issue_flag_invalid', 'jev_credentials_unavailable', 'job_invalid', 'key_invalid',
    'key_storage_unavailable', 'ledger_invalid', 'llm_request_failed', 'model_invalid',
    'model_response_empty', 'origin_invalid', 'owner_busy', 'owner_changed',
    'playthrough_changed', 'request_invalid_json', 'response_invalid_json',
    'response_invalid_shape', 'replay', 'restore_policy', 'repeated_errors', 'transport_failed',
    'transport_invalid_response', 'unchanged', 'uninstalled', 'unknown_failure',
];

/** Build the only record shape allowed at either the writer or reader boundary. */
function sasLogRecord(string $event, string $level, array $fields, ?string $timestamp = null): array
{
    $timestamp ??= gmdate('Y-m-d\TH:i:s\Z');
    if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z\z/D', $timestamp, $time) !== 1
        || !checkdate((int) $time[2], (int) $time[3], (int) $time[1])
        || (int) $time[4] > 23 || (int) $time[5] > 59 || (int) $time[6] > 59) {
        return [];
    }

    $record = [
        'plugin' => 'sworn_and_scorned',
        'schema_version' => 1,
        'timestamp' => $timestamp,
        'event' => in_array($event, LOG_EVENTS, true) ? $event : 'unknown_event',
        'level' => in_array($level, ['debug', 'info', 'warning', 'error'], true) ? $level : 'warning',
    ];
    foreach (LOG_FIELDS as $field) {
        if (!array_key_exists($field, $fields)) {
            continue;
        }
        $value = $fields[$field];
        $safe = null;
        if (in_array($field, ['job_id', 'owner_npc_id', 'npc_id', 'jobs', 'failures', 'pid', 'cast_count', 'duration_ms'], true)) {
            $minimum = in_array($field, ['job_id', 'owner_npc_id', 'npc_id', 'pid'], true) ? 1 : 0;
            $maximum = in_array($field, ['job_id', 'owner_npc_id', 'npc_id'], true) ? PHP_INT_MAX : 2147483647;
            $integer = is_int($value) ? $value : (is_string($value) && strlen($value) <= 19 ? filter_var($value, FILTER_VALIDATE_INT) : false);
            if (is_int($integer) && $integer >= $minimum && $integer <= $maximum) {
                $safe = $integer;
            }
        } elseif ($field === 'issue_id') {
            if (is_string($value) && preg_match('/\A[a-f0-9]{16}\z/iD', $value) === 1) {
                $safe = strtolower($value);
            }
        } elseif ($field === 'outcome') {
            $safe = is_string($value) && in_array($value, ['done', 'retry', 'failed', 'paused', 'stale'], true) ? $value : 'unknown';
        } elseif ($field === 'reason') {
            $safe = is_string($value) && (in_array($value, LOG_REASONS, true) || preg_match('/\Ahttp_[1-5][0-9]{2}\z/D', $value) === 1)
                ? $value : 'internal_error';
        } elseif ($field === 'backend') {
            if (is_string($value) && strlen($value) <= 128) {
                if (preg_match('/\Adecisions(?::[A-Za-z0-9~._\/-]{1,120})?\z/iD', $value) === 1) {
                    $safe = 'decisions';
                } elseif (preg_match('/\A(?:llm|llm-fallback)(?::[A-Za-z0-9~._\/-]{1,120})?\z/iD', $value) === 1) {
                    $safe = str_starts_with(strtolower($value), 'llm-fallback') ? 'llm-fallback' : 'llm';
                }
            }
            $safe ??= 'unknown';
        } elseif ($field === 'status') {
            $safe = is_string($value) && in_array($value, [
                'spawned', 'running', 'paused', 'spawn_failed', 'enabled', 'disabled',
                'key_storage_unavailable', 'restore_policy', 'jev_credentials_unavailable',
            ], true) ? $value : 'unknown';
        } elseif ($field === 'addresses') {
            if (is_bool($value)) {
                $safe = $value;
            }
        } elseif ($field === 'p') {
            if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1) {
                $safe = round((float) $value, 4);
            }
        } elseif ($field === 'turn') {
            if (is_int($value) && $value >= 0) {
                $safe = (string) $value;
            } elseif (is_string($value) && strlen($value) <= 20 && preg_match('/\A[0-9]{1,20}\z/D', $value) === 1) {
                $safe = $value;
            }
        } elseif (in_array($field, ['enabled', 'use_jev'], true) && is_bool($value)) {
            $safe = $value;
        }
        if ($safe !== null) {
            $record[$field] = $safe;
        }
    }
    return $record;
}

/** Write one safe JSON record through CHIM's logger, with PHP error_log as fallback. */
function sasLog(string $event, string $level, array $fields = []): void
{
    try {
        $record = sasLogRecord($event, $level, $fields);
        if ($record === []) {
            return;
        }
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $method = ['debug' => 'debug', 'info' => 'info', 'warning' => 'warn', 'error' => 'error'][$record['level']];
        if (class_exists('Logger', false) && is_callable(['\Logger', $method])) {
            try {
                \Logger::$method($json);
                return;
            } catch (\Throwable) {
            }
        }
        @error_log($json);
    } catch (\Throwable) {
    }
}

/** Read only bounded, structured plugin activity from CHIM's existing log. */
function recentPluginLogs(string $engineRoot, int $limit = 50): array
{
    $empty = ['status' => 'missing', 'entries' => []];
    try {
        if ($engineRoot === '' || str_contains($engineRoot, "\0")) {
            return ['status' => 'unreadable', 'entries' => []];
        }
        $logDirectory = rtrim($engineRoot, '/\\') . '/log';
        $path = $logDirectory . '/chim.log';
        if (!file_exists($logDirectory) && !is_link($logDirectory)) {
            return $empty;
        }
        if (is_link($logDirectory) || !is_dir($logDirectory) || !is_readable($logDirectory)) {
            return ['status' => 'unreadable', 'entries' => []];
        }
        if (!file_exists($path) && !is_link($path)) {
            return $empty;
        }
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            return ['status' => 'unreadable', 'entries' => []];
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ['status' => 'unreadable', 'entries' => []];
        }
        try {
            $stat = fstat($handle);
            if (!is_array($stat) || !isset($stat['size']) || !is_int($stat['size'])) {
                return ['status' => 'unreadable', 'entries' => []];
            }
            $maxBytes = 262144;
            $offset = max(0, $stat['size'] - $maxBytes);
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                return ['status' => 'unreadable', 'entries' => []];
            }
            $contents = fread($handle, $maxBytes);
            if (!is_string($contents)) {
                return ['status' => 'unreadable', 'entries' => []];
            }
        } finally {
            fclose($handle);
        }
        if ($offset > 0) {
            $firstLine = strpos($contents, "\n");
            $contents = $firstLine === false ? '' : substr($contents, $firstLine + 1);
        }
        $limit = max(0, min(50, $limit));
        $entries = [];
        foreach (array_reverse(explode("\n", $contents)) as $line) {
            if (count($entries) >= $limit) {
                break;
            }
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }
            if (preg_match('/\A\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\]\s+\[(?:debug|info|warn|error)\]\s+(\{.*\})\z/iD', $line, $matches) !== 1) {
                continue;
            }
            $json = $matches[1];
            $decoded = json_decode($json, true, 32);
            if (!is_array($decoded) || ($decoded['plugin'] ?? null) !== 'sworn_and_scorned' || ($decoded['schema_version'] ?? null) !== 1
                || !is_string($decoded['timestamp'] ?? null)) {
                continue;
            }
            $record = sasLogRecord(
                is_string($decoded['event'] ?? null) ? $decoded['event'] : '',
                is_string($decoded['level'] ?? null) ? $decoded['level'] : '',
                $decoded,
                $decoded['timestamp']
            );
            if ($record !== []) {
                $entries[] = $record;
            }
        }
        return ['status' => 'available', 'entries' => $entries];
    } catch (\Throwable) {
        return ['status' => 'unreadable', 'entries' => []];
    }
}
