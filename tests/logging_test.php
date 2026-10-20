<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/logging.php';
require __DIR__ . '/../server/backend_error.php';

use ChimSwornAndScorned\DecisionBackendError;
use function ChimSwornAndScorned\recentPluginLogs;
use function ChimSwornAndScorned\sasLog;
use function ChimSwornAndScorned\sasLogRecord;

$file = tempnam(sys_get_temp_dir(), 'saslog');
check(is_string($file), 'temporary log created');
try {
    ini_set('error_log', $file);
    sasLog('job_finished', 'info', [
        'job_id' => '3000000000',
        'outcome' => 'done',
        'reason' => 'http_429',
        'issue_id' => '0123456789abcdef',
        'addresses' => true,
        'p' => 0.91,
        'turn' => '12345',
        'backend' => 'decisions:openrouter/model',
        'text' => 'secret dialogue',
        'prompt' => 'secret prompt',
        'api_key' => 'secret key',
        'token' => 'secret token',
    ]);
    sasLog("raw_dialogue\nforged", 'critical', [
        'reason' => "secret-key-123456\nforged=true",
        'backend' => 'sk-secret-token-value',
        'outcome' => 'The NPC said the secret phrase',
    ]);
    foreach (['committed', 'replay', 'unchanged'] as $reason) {
        sasLog('issue_evaluated', 'info', ['reason' => $reason]);
    }
    sasLog('settings_saved', 'info', ['cast_count' => 8, 'enabled' => true, 'use_jev' => false, 'duration_ms' => 123]);
    sasLog('written_source_finished', 'info', [
        'job_id' => 3000000000,
        'owner_npc_id' => 900001,
        'playthrough_id' => 'unprofiled',
        'source_event_id' => 47,
        'source_result' => 'evaluated',
        'reason' => 'committed',
        'origin_event_count' => 8,
        'origin_codepoints' => 5120,
        'owner_name' => 'private name',
        'source_body' => 'private letter body',
    ]);
    sasLog('written_source_finished', 'info', [
        'job_id' => 3000000000,
        'owner_npc_id' => 900001,
        'playthrough_id' => '42',
        'source_event_id' => 48,
        'source_result' => 'abstained',
        'reason' => 'substance_budget',
        'origin_event_count' => 9,
        'origin_codepoints' => 5121,
        'source_body' => 'private letter body',
    ]);
    foreach (['written_source_invalid', 'written_source_changed', 'written_capacity'] as $reason) {
        sasLog('job_finished', 'warning', ['reason' => $reason]);
    }
    foreach (['settings_rejected', 'worker_restart_requested', 'key_updated', 'key_removed', 'access_rejected', 'form_rejected'] as $event) {
        sasLog($event, 'warning');
    }
    $logged = (string) file_get_contents($file);
} finally {
    @unlink($file);
}
check(str_contains($logged, '"plugin":"sworn_and_scorned"') && str_contains($logged, '"job_id":3000000000'), 'record written with a valid bigserial ID');
check(str_contains($logged, '"reason":"http_429"'), 'fixed reason code retained');
foreach (['committed', 'replay', 'unchanged'] as $reason) {
    check(str_contains($logged, '"reason":"' . $reason . '"'), 'fixed issue evaluation reason retained');
}
foreach (['settings_saved', 'settings_rejected', 'worker_restart_requested', 'key_updated', 'key_removed', 'access_rejected', 'form_rejected'] as $event) {
    check(str_contains($logged, '"event":"' . $event . '"'), 'fixed settings event retained');
}
check(str_contains($logged, '"cast_count":8') && str_contains($logged, '"enabled":true') && str_contains($logged, '"use_jev":false') && str_contains($logged, '"duration_ms":123'), 'settings diagnostics retain bounded typed fields');
check(str_contains($logged, '"event":"written_source_finished"') && str_contains($logged, '"source_event_id":47')
    && str_contains($logged, '"source_result":"evaluated"') && str_contains($logged, '"playthrough_id":"unprofiled"')
    && str_contains($logged, '"origin_event_count":8') && str_contains($logged, '"origin_codepoints":5120'), 'written-source completion retains only bounded typed provenance');
check(str_contains($logged, '"source_event_id":48') && str_contains($logged, '"source_result":"abstained"')
    && str_contains($logged, '"reason":"substance_budget"'), 'terminal substance-budget replay is distinguishable from evaluation');
foreach (['written_source_invalid', 'written_source_changed', 'written_capacity', 'substance_budget'] as $reason) {
    check(str_contains($logged, '"reason":"' . $reason . '"'), 'written evidence reason retained: ' . $reason);
}
check(str_contains($logged, '"timestamp":"') && preg_match('/"timestamp":"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ"/', $logged) === 1, 'record has an ISO8601 UTC timestamp');
check(str_contains($logged, '"schema_version":1'), 'record has a stable schema version');
check(str_contains($logged, '"issue_id":"0123456789abcdef"') && str_contains($logged, '"addresses":true') && str_contains($logged, '"p":0.91') && str_contains($logged, '"turn":"12345"'), 'safe diagnostic identifiers and typed values retained');
check(str_contains($logged, '"event":"unknown_event"') && str_contains($logged, '"level":"warning"') && str_contains($logged, '"reason":"internal_error"') && str_contains($logged, '"backend":"unknown"'), 'unknown and hostile values normalize safely');
foreach (['secret dialogue', 'secret prompt', 'secret key', 'secret token', 'secret-key-123456', 'sk-secret-token-value', 'raw_dialogue', 'forged=true', 'The NPC said the secret phrase', '"text"', '"api_key"', 'private name', 'private letter body', '"owner_name"', '"source_body"'] as $secret) {
    check(!str_contains($logged, $secret), 'unapproved log data dropped');
}
check(substr_count($logged, "\n") === 17, 'each caller produces one physical log line');

$engineRoot = sys_get_temp_dir() . '/saslog-engine-' . bin2hex(random_bytes(4));
mkdir($engineRoot . '/log', 0700, true);
$stamp = '2026-10-05T12:34:56Z';
$prefix = '[2026-10-05T12:34:56+00:00] [info] ';
$makeRecord = static fn (int $id): string => $prefix . json_encode([
    'plugin' => 'sworn_and_scorned', 'schema_version' => 1, 'timestamp' => $stamp,
    'event' => 'job_finished', 'level' => 'info', 'job_id' => $id,
    'outcome' => 'done', 'reason' => 'http_429', 'backend' => 'decisions:model',
], JSON_THROW_ON_ERROR);
$lines = [
    'not json',
    $prefix . '{malformed}',
    $prefix . json_encode(['plugin' => 'chim', 'event' => 'core_event']),
    $prefix . json_encode(['context' => ['plugin' => 'sworn_and_scorned'], 'event' => 'nested_plugin']),
    json_encode(['plugin' => 'sworn_and_scorned', 'schema_version' => 1, 'timestamp' => $stamp, 'event' => 'unprefixed']),
];
for ($i = 1; $i <= 55; $i++) $lines[] = $makeRecord($i);
$lines[] = $prefix . json_encode([
    'plugin' => 'sworn_and_scorned', 'schema_version' => 1, 'timestamp' => $stamp,
    'event' => 'new_event_from_bad_input', 'level' => 'trace', 'reason' => "dialogue\napi_key=secret",
], JSON_THROW_ON_ERROR);
$lines[] = $prefix . json_encode(sasLogRecord('written_source_finished', 'info', [
    'job_id' => 56,
    'owner_npc_id' => 900001,
    'playthrough_id' => '13',
    'source_event_id' => 88,
    'source_result' => 'abstained',
    'reason' => 'written_capacity',
    'origin_event_count' => 8,
    'origin_codepoints' => 5120,
], $stamp), JSON_THROW_ON_ERROR);
file_put_contents($engineRoot . '/log/chim.log', implode("\n", $lines) . "\n");
$recent = recentPluginLogs($engineRoot, 100);
check($recent['status'] === 'available' && count($recent['entries']) === 50, 'reader caps records at 50');
check($recent['entries'][0]['event'] === 'written_source_finished' && $recent['entries'][0]['source_event_id'] === 88
    && $recent['entries'][0]['source_result'] === 'abstained' && $recent['entries'][1]['event'] === 'unknown_event'
    && $recent['entries'][2]['job_id'] === 55 && $recent['entries'][49]['job_id'] === 8, 'reader retains and resanitizes bounded written-source records');
check($recent['entries'][1]['reason'] === 'internal_error' && !str_contains(json_encode($recent), 'api_key=secret'), 'reader resanitizes fields before returning them');
check(!str_contains(json_encode($recent), 'core_event') && !str_contains(json_encode($recent), 'nested_plugin') && !str_contains(json_encode($recent), 'unprefixed'), 'reader skips malformed, core, nested, and unprefixed records');

$tailRoot = sys_get_temp_dir() . '/saslog-tail-' . bin2hex(random_bytes(4));
mkdir($tailRoot . '/log', 0700, true);
file_put_contents($tailRoot . '/log/chim.log', $makeRecord(1) . "\n" . str_repeat("noise\n", 50000) . $makeRecord(2) . "\n");
$tail = recentPluginLogs($tailRoot, 50);
check($tail['status'] === 'available' && count($tail['entries']) === 1 && $tail['entries'][0]['job_id'] === 2, 'reader reads only the newest 256 KiB');

$missingRoot = sys_get_temp_dir() . '/saslog-missing-' . bin2hex(random_bytes(4));
mkdir($missingRoot, 0700, true);
check(recentPluginLogs($missingRoot) === ['status' => 'missing', 'entries' => []], 'reader distinguishes a missing log');
$unreadableRoot = sys_get_temp_dir() . '/saslog-unreadable-' . bin2hex(random_bytes(4));
mkdir($unreadableRoot . '/log/chim.log', 0700, true);
check(recentPluginLogs($unreadableRoot) === ['status' => 'unreadable', 'entries' => []], 'reader distinguishes an unreadable log path');

$oldErrorLog = ini_get('error_log');
ini_set('error_log', sys_get_temp_dir() . '/missing-saslog-directory/error.log');
try {
    sasLog('job_finished', 'info', ['reason' => 'http_429']);
    check(true, 'writer failure remains harmless');
} catch (Throwable) {
    check(false, 'writer failure remains harmless');
} finally {
    if (is_string($oldErrorLog)) ini_set('error_log', $oldErrorLog);
    foreach ([$engineRoot, $tailRoot, $missingRoot, $unreadableRoot] as $root) {
        if (is_dir($root . '/log/chim.log')) rmdir($root . '/log/chim.log');
        if (is_file($root . '/log/chim.log')) unlink($root . '/log/chim.log');
        if (is_dir($root . '/log')) rmdir($root . '/log');
        if (is_dir($root)) rmdir($root);
    }
}

$previous = new RuntimeException('transport failed');
$error = new DecisionBackendError('http_429', true, $previous);
check(
    $error->reasonCode === 'http_429'
        && $error->retryable
        && $error->getMessage() === 'http_429'
        && $error->getPrevious() === $previous,
    'error carries a code, retryable flag, and previous error'
);

$switchRecord = ChimSwornAndScorned\sasLogRecord('job_finished', 'info', ['outcome'=>'paused', 'reason'=>'playthrough_switch']);
check($switchRecord['outcome'] === 'paused' && $switchRecord['reason'] === 'playthrough_switch', 'core switch pause keeps its exact safe diagnostic');

echo "logging checks passed\n";
