<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

const LOG_FIELDS = ['job_id', 'owner_npc_id', 'npc_id', 'issue_id', 'outcome', 'reason', 'backend', 'jobs', 'failures', 'pid', 'status', 'addresses', 'p', 'turn'];

/**
 * One JSON line to CHIM's Logger (log/chim.log) or PHP's error_log.
 * Only whitelisted fields are kept: never pass dialogue text or credentials.
 */
function sasLog(string $event, string $level, array $fields = []): void
{
    try {
        $record = ['plugin' => 'sworn_and_scorned', 'event' => $event, 'level' => $level]
            + array_intersect_key($fields, array_flip(LOG_FIELDS));
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            return;
        }
        $method = ['debug' => 'debug', 'info' => 'info', 'warning' => 'warn', 'error' => 'error'][$level] ?? 'info';
        if (class_exists('Logger', false) && is_callable(['\\Logger', $method])) {
            @\Logger::$method($json);
        } else {
            @error_log($json);
        }
    } catch (\Throwable) {
    }
}
