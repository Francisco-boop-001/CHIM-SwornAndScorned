<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/page_view.php';

use function ChimSwornAndScorned\renderPage;

$issue = sampleIssue([
    'kind' => 'promise',
    'direction' => 'owed_to_me',
    'strength' => 'will_try',
    'status' => 'broken',
    'trajectory' => 'worsening',
    'wants' => 'explanation',
    'counterpart_name' => 'Lydia <b>',
    'evidence' => [array_replace(sampleIssue()['evidence'][0], ['ref' => 'e:10 <img>'])],
]);
$model = [
    'settings' => [
        'enabled' => true,
        'cast' => [3],
        'model' => 'typesafe/jev-1.13',
        'has_key' => true,
        'api_key' => 'sk-secret-must-never-render',
    ],
    'backend' => ['status' => 'Decisions API ready', 'model' => 'typesafe/jev-1.13'],
    'npcs' => [['id' => 3, 'name' => 'Farkas'], ['id' => 4, 'name' => '<script>x</script>']],
    'worker' => [
        'alive' => false,
        'jobs' => ['pending' => 2, 'running' => 0, 'failed' => 1],
        'last_failure' => 'http_429',
        'restart_result' => null,
    ],
    'journal' => [[
        'npc_id' => 3,
        'name' => 'Farkas',
        'evaluations' => ['pending' => 2, 'running' => 0, 'failed' => 1],
        'issues' => [$issue],
    ]],
    'director' => false,
    'art' => false,
    'key_submission_allowed' => true,
];
$csrf = str_repeat('a', 64);
$blocked = $model;
$blocked['key_submission_allowed'] = false;
$blocked['settings']['key_error'] = true;
$blockedHtml = renderPage($blocked, $csrf, null);
check(!str_contains($blockedHtml, 'name="api_key"') && !str_contains($blockedHtml, 'value="set_key"'), 'remote HTTP page has no key-entry form');
check(str_contains($blockedHtml, 'HTTPS') && str_contains($blockedHtml, 'master key') && str_contains($blockedHtml, 'value="clear_key"'), 'recovery guidance and removal remain available');
$html = renderPage($model, $csrf, 'Settings saved.');

check(str_contains($html, '&lt;script&gt;x&lt;/script&gt;') && !str_contains($html, '<script>x</script>'), 'NPC names are escaped');
check(str_contains($html, 'Lydia &lt;b&gt;'), 'counterpart names are escaped');
check(str_contains($html, 'name="csrf" value="' . $csrf . '"'), 'forms carry the CSRF token');
check(str_contains($html, 'Key saved') && !str_contains($html, 'sk-secret-must-never-render') && !preg_match('/name="api_key"[^>]*value=/i', $html), 'saved key status is shown without echoing or prefilling the key');
check(str_contains($html, 'Decisions API ready'), 'backend status is shown');
check(str_contains($html, 'Worker stopped') && str_contains($html, '2 waiting') && str_contains($html, '1 failed'), 'worker state and current-scope counts are shown');
check(str_contains($html, 'http_429'), 'last fixed failure is shown');
check(str_contains($html, '2 evaluations waiting'), 'cast member pending evaluations are shown');
check(str_contains($html, 'name="action" value="restart_worker"'), 'worker restart is a CSRF-protected POST form');
check(str_contains($html, '<details>'), 'spoiler-safe issue opens behind per-issue details');
$details = strpos($html, '<details>');
$beforeDetails = substr($html, 0, $details);
check(!str_contains($beforeDetails, 'broken') && !str_contains($beforeDetails, 'worsening') && !str_contains($beforeDetails, 'Wants:') && !str_contains($beforeDetails, 'Owed to you'), 'status, perspective, and wants stay hidden before reveal');
check(preg_match('~<details>.*broken.*worsening.*Wants: explanation.*Owed to you by Lydia &lt;b&gt;.*Commitment: Will try.*I&#039;ll stand with you before Aela\..*Source: e:10 &lt;img&gt;.*</details>~s', $html) === 1, 'issue details reveal promise perspective, strength, evidence, and escaped source');
check(str_contains($html, 'Dismiss (Out-of-character)'), 'dismiss is labelled out-of-character');

$director = renderPage(['director' => true] + $model, $csrf, null);
check(!str_contains($director, '<details>') && str_contains($director, 'broken') && str_contains($director, 'Owed to you by Lydia &lt;b&gt;') && str_contains($director, 'Commitment: Will try') && str_contains($director, 'Source: e:10'), 'director view shows issue details directly');

$corrupt = $model;
$corrupt['journal'][0]['ledger_invalid'] = true;
$corrupt['journal'][0]['issues'] = [];
$corruptHtml = renderPage($corrupt, $csrf, null);
check(str_contains($corruptHtml, 'Issue data unavailable') && !str_contains($corruptHtml, '0 tracked issues'), 'corrupt ledger is not presented as an empty journal');

echo "page view checks passed\n";
