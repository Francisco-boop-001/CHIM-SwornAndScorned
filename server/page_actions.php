<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

use UnexpectedValueException;

require_once __DIR__ . '/tables.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/launcher.php';
require_once __DIR__ . '/logging.php';

/** User-facing validation text: expose only recognised fixed messages. */
function pageActionErrorNotice(UnexpectedValueException $error): string
{
    return match ($error->getMessage()) {
        'cast_invalid' => 'The cast selection was not valid. Refresh this page and select known NPCs. Settings were not saved.',
        'model_invalid' => 'The Jev model name was not valid. Settings were not saved.',
        default => 'That request was not valid.',
    };
}

/** @param list<int> $knownNpcIds */
function parsePageAction(#[\SensitiveParameter] array $post, array $knownNpcIds): array
{
    $action = $post['action'] ?? null;
    if ($action === 'save_settings') {
        $rawCast = $post['cast'] ?? [];
        if (isset($post['cast_form']) && ($post['cast_complete'] ?? null) !== '1') {
            throw new UnexpectedValueException('cast_invalid');
        }
        // One form field avoids PHP max_input_vars truncating a large selection.
        if (array_key_exists('cast_json', $post)) {
            try {
                if (!is_string($post['cast_json'])) throw new \JsonException();
                $rawCast = json_decode($post['cast_json'], false, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new UnexpectedValueException('cast_invalid');
            }
        }
        if (!is_array($rawCast) || !array_is_list($rawCast)) {
            throw new UnexpectedValueException('cast_invalid');
        }
        $cast = [];
        $known = array_fill_keys($knownNpcIds, true);
        foreach ($rawCast as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if (!is_int($id) || $id < 1 || !isset($known[$id])) {
                throw new UnexpectedValueException('cast_invalid');
            }
            $cast[$id] = $id;
        }
        $model = is_string($post['model'] ?? null) ? trim($post['model']) : '';
        if (preg_match('#\A[A-Za-z0-9~._/-]{3,100}\z#D', $model) !== 1) {
            throw new UnexpectedValueException('model_invalid');
        }
        return ['action' => 'save_settings', 'enabled' => ($post['enabled'] ?? null) === '1', 'use_jev' => ($post['use_jev'] ?? null) === '1', 'cast' => array_values($cast), 'model' => $model];
    }
    if ($action === 'set_key') {
        $key = is_string($post['api_key'] ?? null) ? trim($post['api_key']) : '';
        if ($key === '' || strlen($key) > 400 || preg_match('/\s/', $key) === 1) {
            throw new UnexpectedValueException('key_invalid');
        }
        return ['action' => 'set_key', 'api_key' => $key];
    }
    if ($action === 'clear_key') {
        return ['action' => 'clear_key'];
    }
    if ($action === 'restart_worker') {
        return ['action' => 'restart_worker'];
    }
    if ($action === 'issue_flag') {
        $npcId = filter_var($post['npc_id'] ?? null, FILTER_VALIDATE_INT);
        $issueId = $post['issue_id'] ?? null;
        $flag = $post['flag'] ?? null;
        if (!is_int($npcId) || $npcId < 1 || !in_array($npcId, $knownNpcIds, true)
            || !is_string($issueId) || preg_match('/\A[0-9a-f]{16}\z/D', $issueId) !== 1
            || !in_array($flag, ['dismiss', 'mute', 'unmute'], true)) {
            throw new UnexpectedValueException('issue_flag_invalid');
        }
        return ['action' => 'issue_flag', 'npc_id' => $npcId, 'issue_id' => $issueId, 'flag' => $flag];
    }
    throw new UnexpectedValueException('action_unknown');
}

/** Out-of-character override: never an in-world event and never affinity. */
function issueFlagMutation(string $issueId, string $flag): callable
{
    if (!in_array($flag, ['dismiss', 'mute', 'unmute'], true)) {
        throw new UnexpectedValueException('issue_flag_invalid');
    }
    return static function (array $ledger) use ($issueId, $flag): array {
        foreach ($ledger['issues'] as $index => $issue) {
            if ($issue['id'] !== $issueId) {
                continue;
            }
            [$field, $value] = match ($flag) {
                'dismiss' => ['dismissed', true],
                'mute' => ['muted', true],
                'unmute' => ['muted', false],
            };
            if ($issue[$field] === $value) {
                break;
            }
            $ledger['issues'][$index][$field] = $value;
            $ledger['issues'] = enforceClosedCap($ledger['issues']);
            return ['status' => 'applied', 'ledger' => $ledger, 'affinity_delta' => 0];
        }
        return ['status' => 'unchanged', 'ledger' => $ledger, 'affinity_delta' => 0];
    };
}

/** Perform a validated action and return only a fixed, safe notice. */
function performPageAction(Pg $pg, #[\SensitiveParameter] array $action, string $engineRoot, string $pluginDir): string
{
    if (in_array($action['action'], ['save_settings', 'issue_flag'], true) && array_key_exists('playthrough_id', $action) && ($action['playthrough_id'] === null || (currentPlaythrough($pg)['id'] ?? null) !== $action['playthrough_id'])) {
        return 'The playthrough changed; refresh this page before saving.';
    }
    switch ($action['action']) {
        case 'save_settings':
            $pg->exec('BEGIN');
            $committed = false;
            try {
                saveSetting($pg, 'enabled', $action['enabled']);
                saveSetting($pg, 'use_jev', $action['use_jev'] ?? false);
                saveSetting($pg, 'cast', $action['cast'], $action['playthrough_id'] ?? null);
                saveSetting($pg, 'model', $action['model']);
                $pg->exec('COMMIT');
                $committed = true;
            } finally {
                if (!$committed) {
                    try {
                        $pg->exec('ROLLBACK');
                    } catch (\Throwable) {
                    }
                }
            }
            sasLog('settings_saved', 'info', ['cast_count' => count($action['cast']), 'enabled' => $action['enabled'], 'use_jev' => $action['use_jev'] ?? false]);
            $settings = loadSettings($pg);
            $pause = workerPauseReason($settings);
            if ($action['enabled'] && $pause !== null) return 'Settings saved. ' . workerPauseNotice($pause);
            if ($action['enabled'] && ensureWorkerRunning($engineRoot, $pluginDir, $settings) === 'spawn_failed') {
                return 'Settings saved, but the worker could not be started.';
            }
            return 'Settings saved.';
        case 'set_key':
            saveSetting($pg, 'api_key', $action['api_key']);
            sasLog('key_updated', 'info');
            return 'Decision model key saved.';
        case 'clear_key':
            saveSetting($pg, 'api_key', '');
            sasLog('key_removed', 'info');
            return 'Dedicated Jev key removed. Jev uses CHIM OpenRouter credentials when enabled; otherwise the CHIM relationship LLM is used.';
        case 'restart_worker':
            sasLog('worker_restart_requested', 'info');
            $settings = loadSettings($pg);
            $pause = workerPauseReason($settings);
            if ($pause !== null) return workerPauseNotice($pause);
            return match (ensureWorkerRunning($engineRoot, $pluginDir, $settings)) {
                'running' => 'Worker is already running.',
                'spawned' => 'Worker restart was attempted.',
                default => 'Worker restart could not be started.',
            };
        case 'issue_flag':
            $playthrough = currentPlaythrough($pg);
            $owner = $pg->one('SELECT npc_name FROM core_npc_master WHERE id = $1', [$action['npc_id']]);
            if ($playthrough === null || $owner === null) {
                return 'That character is not available right now.';
            }
            $status = (new PerspectiveStore($pg))->commitLedger(
                ['owner_npc_id' => $action['npc_id'], 'owner_name' => $owner['npc_name'], 'playthrough_id' => $action['playthrough_id'] ?? $playthrough['id'], 'event_ids' => [], 'counterpart_name' => null],
                issueFlagMutation($action['issue_id'], $action['flag'])
            );
            return match ($status) {
                'committed' => 'Updated.',
                'unchanged' => 'Nothing to change.',
                'busy' => 'Busy right now; try again in a moment.',
                'restore_policy' => 'Updates are disabled by the restore policy.',
                default => 'Could not update that issue.',
            };
    }
    throw new UnexpectedValueException('action_unknown');
}
