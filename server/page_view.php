<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfField(string $csrf): string
{
    return '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
}

function renderIssue(array $issue, int $npcId, string $csrf, bool $director): string
{
    $counterpart = (string) ($issue['counterpart_name'] ?? $issue['counterpart'] ?? 'Unknown');
    $details = '<p><strong>' . h((string) ($issue['kind'] ?? 'Issue')) . '</strong> · '
        . h((string) ($issue['status'] ?? 'unknown')) . ' · ' . h((string) ($issue['trajectory'] ?? 'unknown')) . '</p>'
        . '<p>Wants: ' . h((string) ($issue['wants'] ?? 'unknown')) . '</p>';
    if (($issue['kind'] ?? null) === 'promise') {
        $details .= '<p>' . h(match ($issue['direction'] ?? null) {
            'owed_by_me' => 'You owe ' . $counterpart,
            'owed_to_me' => 'Owed to you by ' . $counterpart,
            default => 'Perspective unknown',
        }) . '</p><p>Commitment: ' . h(match ($issue['strength'] ?? null) {
            'will' => 'Will',
            'will_try' => 'Will try',
            default => 'Unknown',
        }) . '</p>';
    }
    $details .= '<ul class="evidence">';
    foreach ($issue['evidence'] ?? [] as $entry) {
        $details .= '<li><q>' . h((string) ($entry['quote'] ?? '')) . '</q> <span class="meta">'
            . h(implode(' · ', array_map('strval', [$entry['speaker'] ?? '', $entry['label'] ?? '', $entry['backend'] ?? ''])))
            . ' · Source: ' . h((string) ($entry['ref'] ?? 'unknown'));
        if (isset($entry['p']) && (is_int($entry['p']) || is_float($entry['p']))) {
            $details .= ' · ' . h((string) $entry['p']);
        }
        $details .= '</span></li>';
    }
    $details .= '</ul>';
    $body = $director ? $details : '<details><summary>Issue details</summary>' . $details;

    $buttons = '';
    if (empty($issue['dismissed'])) {
        $forms = empty($issue['muted']) ? [['mute', 'Mute']] : [['unmute', 'Unmute']];
        $forms[] = ['dismiss', 'Dismiss (Out-of-character)'];
        foreach ($forms as [$flag, $label]) {
            $buttons .= '<form method="post">' . csrfField($csrf)
                . '<input type="hidden" name="action" value="issue_flag">'
                . '<input type="hidden" name="npc_id" value="' . $npcId . '">'
                . '<input type="hidden" name="issue_id" value="' . h((string) $issue['id']) . '">'
                . '<input type="hidden" name="flag" value="' . $flag . '">'
                . '<button type="submit">' . h($label) . '</button></form>';
        }
    }
    $actions = '<div class="actions">' . $buttons . '</div>';
    if ($director) {
        $body .= $actions;
    } else {
        $body .= $actions . '</details>';
    }
    return '<article class="issue"><h4>Regarding ' . h($counterpart) . '</h4>' . $body . '</article>';
}

/** @param array $model page-safe settings/backend/worker/journal data without the API key */
function renderPage(array $model, string $csrf, ?string $notice, ?string $globalCsrf = null): string
{
    $globalCsrf ??= $csrf;
    $settings = $model['settings'];
    $backend = $model['backend'];
    $worker = $model['worker'];
    $jobs = $worker['jobs'];
    $director = ($model['director'] ?? false) === true;
    $out = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Sworn &amp; Scorned</title><link rel="stylesheet" href="assets/style.css"><script src="assets/dashboard.js" defer></script></head><body><main>'
        . '<header><h1>Sworn &amp; Scorned</h1><p class="subtitle">Part of the World of Drama-Llama</p></header>';
    if (!empty($model['art'])) {
        $out .= '<img class="art" src="dashboard-art.webp" alt="A Nord offers an open hand on snowy mead-hall steps; a woman above him turns away; a snapped oath ring lies between them.">';
    }
    if ($notice !== null) {
        $out .= '<p class="notice" role="status">' . h($notice) . '</p>';
    }

    if (!empty($worker['pause'])) $out .= '<p class="notice" role="status">' . h($worker['pause']) . '</p>';
    if (!empty($settings['cast_reselect'])) $out .= '<p class="notice">Reselect and save the cast for this playthrough after upgrading. Legacy row IDs are no longer shared between profiles.</p>';
    if (!empty($settings['cast_other_playthrough'])) $out .= '<p class="notice">Cast was selected in another playthrough; reselect and save it for this one to resume new tracking. Saving replaces the previous cast selection.</p>';
    $out .= '<section><h2>Settings</h2><p>Backend: ' . h((string) ($backend['status'] ?? 'Unavailable'))
        . (empty($backend['model']) ? '' : ' · ' . h((string) $backend['model'])) . '</p>'
        . '<form method="post">' . csrfField($csrf) . '<input type="hidden" name="action" value="save_settings">'
        . '<label><input type="checkbox" name="enabled" value="1"' . (!empty($settings['enabled']) ? ' checked' : '') . '> Enabled</label>'
        . '<label><input type="checkbox" name="use_jev" value="1"' . (!empty($settings['use_jev']) ? ' checked' : '') . '> Use Jev — experimental</label>'
        . '<p>Jev handles promise and grievance decisions faster, reducing calls to your usual relationship LLM. It uses your existing CHIM OpenRouter credentials and incurs API charges. Experimental: its interpretation of Skyrim conversations has limited validation, and it uses OpenRouter\'s alpha Decisions API. Failed requests can fall back to your configured relationship LLM.</p>'
        . '<label>Jev decision model <input name="model" value="' . h((string) $settings['model']) . '"></label>'
        . '<input type="hidden" name="cast_form" value="1"><fieldset id="cast-picker" aria-describedby="cast-help"><legend>Cast</legend><p id="cast-help" class="notice">Choose any number of known NPCs. More tracked conversations mean more background work and possible API charges. Select all chooses the NPCs listed now; newly discovered NPCs need selecting later. Save settings applies your cast, Enabled and Jev choices.</p>'
        . '<div class="actions"><button type="button" id="cast-all" hidden>Select all</button><button type="button" id="cast-clear" hidden>Clear selection</button></div>'
        . '<p id="cast-count" aria-live="polite">' . count(array_intersect($settings['cast'], array_column($model['npcs'], 'id'))) . ' of ' . count($model['npcs']) . ' selected</p><div class="cast">';
    foreach ($model['npcs'] as $npc) {
        $checked = in_array($npc['id'], $settings['cast'], true) ? ' checked' : '';
        $out .= '<label><input type="checkbox" name="cast[]" value="' . (int) $npc['id'] . '"' . $checked . '> ' . h((string) $npc['name']) . '</label>';
    }
    $out .= '</div><input type="hidden" name="cast_complete" value="1"></fieldset><button type="submit">Save settings</button></form>';
    $out .= '<details><summary>Advanced: optional dedicated Jev key</summary><p>Normally Jev reuses the enabled CHIM OpenRouter relationship connector\'s key. A dedicated key overrides it for separate spending limits. Saving a key does not enable Jev.</p>';
    if (!empty($settings['key_error'])) {
        $out .= '<p class="notice">Saved Jev override unavailable. Jev evaluations are blocked while it is selected; the default CHIM mode remains available. Restore the private master key, or remove the saved key before replacing it.</p>';
    } else {
        $out .= '<p>' . (!empty($settings['has_key']) ? 'Dedicated key saved (encrypted, hidden).' : 'No dedicated key saved; Jev uses CHIM credentials when selected.') . '</p>';
    }
    if (($model['key_submission_allowed'] ?? false) === true) {
        $out .= '<form method="post" class="key">' . csrfField($globalCsrf) . '<input type="hidden" name="action" value="set_key">'
            . '<label>Optional OpenRouter key <input type="password" name="api_key" autocomplete="new-password"></label><button type="submit">Save key</button></form>';
    } else {
        $out .= '<p>Use HTTPS or direct localhost to save a key.</p>';
    }
    if (!empty($settings['has_key'])) {
        $out .= '<form method="post">' . csrfField($globalCsrf) . '<input type="hidden" name="action" value="clear_key"><button type="submit">Remove key</button></form>';
    }
    $out .= '</details></section>';

    $out .= '<section><h2>Worker</h2><p>' . (!empty($worker['alive']) ? 'Worker running' : 'Worker stopped')
        . ' · ' . (int) $jobs['pending'] . ' waiting · ' . (int) $jobs['running'] . ' running · ' . (int) $jobs['failed'] . ' failed</p>';
    if (is_string($worker['last_failure'] ?? null) && $worker['last_failure'] !== '') {
        $out .= '<p>Last failure: ' . h($worker['last_failure']) . '</p>';
    }
    $out .= '<form method="post">' . csrfField($globalCsrf) . '<input type="hidden" name="action" value="restart_worker"><button type="submit">Attempt worker restart</button></form></section>';

    $out .= '<section><h2>' . ($director ? 'Journal — Director view' : 'Journal') . '</h2><p class="meta">' . ($director
        ? 'Director view reveals recorded issue details and evidence; it does not generate issues. <a href="?">Spoiler-safe view</a>'
        : 'Spoiler-safe view: issue details stay closed until opened. <a href="?view=director">Director view</a>') . '</p>';
    if (!array_filter($model['journal'], static fn (array $entry): bool => !empty($entry['issues']) || !empty($entry['ledger_invalid']))) {
        $out .= '<p>No recorded issues to reveal yet. Enable tracking, select a cast and save settings; issues appear after relevant conversations are evaluated.</p>';
    }
    foreach ($model['journal'] as $entry) {
        $out .= '<h3>' . h((string) $entry['name']) . '</h3>';
        $evaluations = $entry['evaluations'] ?? ['pending' => 0, 'running' => 0, 'failed' => 0];
        if ($evaluations['pending'] > 0) {
            $n = (int) $evaluations['pending'];
            $out .= '<p class="meta">' . $n . ' evaluation' . ($n === 1 ? '' : 's') . ' waiting</p>';
        }
        if (!empty($entry['ledger_invalid'])) {
            $out .= '<p class="notice" role="status">Issue data unavailable because the saved ledger is invalid.</p>';
            continue;
        }
        $count = count($entry['issues']);
        $out .= '<p class="meta">' . $count . ' tracked issue' . ($count === 1 ? '' : 's') . '</p>';
        foreach ($entry['issues'] as $issue) {
            $out .= renderIssue($issue, (int) $entry['npc_id'], $csrf, $director);
        }
    }
    $out .= '</section><section><h2>Recent activity</h2><p class="meta">Latest 50 plugin events from CHIM’s log. Times are UTC. Reload to refresh; dialogue, prompts and keys are excluded. This view covers all playthroughs.</p>';
    $activity = $model['activity'] ?? ['status' => 'available', 'entries' => []];
    if (($activity['status'] ?? '') !== 'available') {
        $out .= '<p>' . (($activity['status'] ?? '') === 'missing' ? 'No plugin events yet; CHIM’s log file has not been created.' : 'CHIM’s log is unavailable; check server permissions.') . '</p>';
    } elseif (empty($activity['entries'])) {
        $out .= '<p>No plugin events in the recent log window. Save settings or run evaluations to produce activity.</p>';
    } else {
        $out .= '<ul>';
        foreach ($activity['entries'] as $entry) {
            $details = [];
            foreach (['job_id', 'owner_npc_id', 'npc_id', 'issue_id', 'outcome', 'reason', 'backend', 'cast_count', 'enabled', 'use_jev', 'duration_ms', 'jobs', 'failures', 'status'] as $field) {
                if (isset($entry[$field]) && is_scalar($entry[$field])) $details[] = $field . '=' . (is_bool($entry[$field]) ? ($entry[$field] ? 'true' : 'false') : (string) $entry[$field]);
            }
            $out .= '<li><time>' . h((string) ($entry['timestamp'] ?? '')) . '</time> · ' . h((string) ($entry['level'] ?? 'info')) . ' · ' . h((string) ($entry['event'] ?? 'unknown')) . ' <span class="meta">' . h(implode(' · ', $details)) . '</span></li>';
        }
        $out .= '</ul>';
    }
    return $out . '</section></main></body></html>';
}
