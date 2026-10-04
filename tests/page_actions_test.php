<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/page_actions.php';

use function ChimSwornAndScorned\{emptyLedger, issueFlagMutation, normalizeLedger, parsePageAction};

$known = [5124, 2892, 3];
check(parsePageAction(['action' => 'save_settings', 'enabled' => '1', 'cast' => ['5124', '2892'], 'model' => ' typesafe/jev~1.13 '], $known)
    === ['action' => 'save_settings', 'enabled' => true, 'cast' => [5124, 2892], 'model' => 'typesafe/jev~1.13'], 'settings normalized');
check(parsePageAction(['action' => 'save_settings', 'model' => 'typesafe/jev-1.13'], $known)['enabled'] === false, 'unchecked box disables');
check(parsePageAction(['action' => 'save_settings', 'cast' => ['3', '3'], 'model' => 'x/y'], $known)['cast'] === [3], 'duplicate known ids are stored once');
rejected(fn () => parsePageAction(['action' => 'save_settings', 'cast' => ['999'], 'model' => 'x/y'], $known), 'unknown cast id refused');
rejected(fn () => parsePageAction(['action' => 'save_settings', 'cast' => array_map('strval', range(1, 9)), 'model' => 'x/y'], range(1, 9)), 'cast over 8 refused');
rejected(fn () => parsePageAction(['action' => 'save_settings', 'cast' => ['3'], 'model' => 'bad model'], $known), 'invalid model refused');

check(parsePageAction(['action' => 'set_key', 'api_key' => ' sk-or-abc '], $known) === ['action' => 'set_key', 'api_key' => 'sk-or-abc'], 'key trimmed before storage');
rejected(fn () => parsePageAction(['action' => 'set_key', 'api_key' => 'has space'], $known), 'key with whitespace refused');
rejected(fn () => parsePageAction(['action' => 'set_key', 'api_key' => str_repeat('k', 401)], $known), 'oversized key refused');
check(parsePageAction(['action' => 'clear_key'], $known) === ['action' => 'clear_key'], 'clear key');
check(parsePageAction(['action' => 'restart_worker'], $known) === ['action' => 'restart_worker'], 'worker restart is an explicit POST action');

check(parsePageAction(['action' => 'issue_flag', 'npc_id' => '3', 'issue_id' => 'abc123abc123abc1', 'flag' => 'mute'], $known)
    === ['action' => 'issue_flag', 'npc_id' => 3, 'issue_id' => 'abc123abc123abc1', 'flag' => 'mute'], 'issue flag');
rejected(fn () => parsePageAction(['action' => 'issue_flag', 'npc_id' => '3', 'issue_id' => 'ZZZ', 'flag' => 'mute'], $known), 'bad issue id refused');
rejected(fn () => parsePageAction(['action' => 'issue_flag', 'npc_id' => '999', 'issue_id' => 'abc123abc123abc1', 'flag' => 'mute'], $known), 'unknown owner refused');
rejected(fn () => parsePageAction(['action' => 'issue_flag', 'npc_id' => '3', 'issue_id' => 'abc123abc123abc1', 'flag' => 'erase'], $known), 'unknown flag refused');
rejected(fn () => parsePageAction(['action' => 'drop_tables'], $known), 'unknown action refused');

$ledger = emptyLedger('pt-1');
$ledger['issues'][] = sampleIssue();
$muted = issueFlagMutation('abc123abc123abc1', 'mute')($ledger);
check($muted['status'] === 'applied' && $muted['ledger']['issues'][0]['muted'] === true && $muted['affinity_delta'] === 0, 'mute applies without affinity');
check(issueFlagMutation('abc123abc123abc1', 'mute')($muted['ledger'])['status'] === 'unchanged', 'mute is idempotent');
check(issueFlagMutation('abc123abc123abc1', 'unmute')($muted['ledger'])['ledger']['issues'][0]['muted'] === false, 'unmute');
rejected(fn () => issueFlagMutation('abc123abc123abc1', 'erase'), 'mutation only accepts OOC flags');
$dismissed = issueFlagMutation('abc123abc123abc1', 'dismiss')($ledger);
check($dismissed['ledger']['issues'][0]['dismissed'] === true && $dismissed['affinity_delta'] === 0, 'dismiss is an OOC override with no affinity');
check(issueFlagMutation('ffffffffffffffff', 'mute')($ledger)['status'] === 'unchanged', 'unknown issue unchanged');

$closedCapLedger = emptyLedger('pt-cap');
for ($i = 1; $i <= 16; $i++) {
    $closedCapLedger['issues'][] = sampleIssue([
        'id' => sprintf('%016x', $i),
        'status' => 'resolved',
        'changed_event_id' => $i,
    ]);
}
$closedCapLedger['issues'][] = sampleIssue(['changed_event_id' => 999]);
$capMutation = issueFlagMutation('abc123abc123abc1', 'dismiss')($closedCapLedger);
$capNormalized = normalizeLedger($capMutation['ledger'], 'pt-cap');
check(count($capNormalized['issues']) === 16 && issueById($capNormalized, 'abc123abc123abc1')['dismissed'], 'dismiss keeps the ledger valid and retains the newest closed issue at cap');

echo "page actions checks passed\n";
