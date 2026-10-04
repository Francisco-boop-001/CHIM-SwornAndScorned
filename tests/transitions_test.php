<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/transitions.php';

use function ChimSwornAndScorned\{lifecycleAffinityDelta, transitionAllowed, transitionKey};

check(transitionAllowed('promise', 'pending', 'broken'), 'pending promise can break');
check(transitionAllowed('promise', 'pending', 'released'), 'pending promise can be released');
check(transitionAllowed('promise', 'disputed', 'fulfilled'), 'disputed promise can still be fulfilled');
check(!transitionAllowed('promise', 'disputed', 'pending'), 'disputed does not return to pending');
check(!transitionAllowed('promise', 'fulfilled', 'broken'), 'fulfilled is terminal');
check(!transitionAllowed('promise', 'disputed', 'disputed'), 'no self transition');
check(transitionAllowed('grievance', 'open', 'let_go'), 'open grievance can be let go');
check(!transitionAllowed('grievance', 'resolved', 'open'), 'resolved grievance is terminal');
check(!transitionAllowed('grievance', 'open', 'fulfilled'), 'grievance cannot use promise vocabulary');
check(!transitionAllowed('unknown', 'open', 'resolved'), 'unknown kind cannot use grievance transitions');

check(transitionKey('abc', 'pending', 'broken') === 'abc:pending>broken', 'transition key format');

$owedToMe = sampleIssue(['kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'status' => 'pending']);
check(lifecycleAffinityDelta($owedToMe, 'fulfilled') === 2, 'kept promise to me: +2');
check(lifecycleAffinityDelta($owedToMe, 'broken') === -3, 'broken promise to me: -3');
check(lifecycleAffinityDelta($owedToMe, 'disputed') === 0, 'disputed: 0');
check(lifecycleAffinityDelta($owedToMe, 'released') === 0, 'released: 0');

$owedByMe = sampleIssue(['kind' => 'promise', 'direction' => 'owed_by_me', 'strength' => 'will', 'status' => 'pending']);
check(lifecycleAffinityDelta($owedByMe, 'fulfilled') === 0, 'my own kept promise does not change my view');
check(lifecycleAffinityDelta($owedByMe, 'broken') === 0, 'my own broken promise does not change my view');

check(lifecycleAffinityDelta(sampleIssue(), 'resolved') === 2, 'resolved grievance: +2');
check(lifecycleAffinityDelta(sampleIssue(), 'let_go') === 1, 'let go grievance: +1');

echo "transitions checks passed\n";
