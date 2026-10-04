<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/hook.php';

use function ChimSwornAndScorned\{audienceNames, displayName, mentionedKeys, pgTextArray, playerInputText, presentKeys};

check(audienceNames('|Lidia Sobieska|Farkas|Hawke|', 'Lidia Sobieska') === ['Farkas', 'Hawke'], 'audience minus the speaker');
check(audienceNames(null, 'Lidia') === [], 'no audience');
check(presentKeys(['Farkas', 'Hawke', 'Stranger'], 'Hawke', ['farkas' => 3]) === ['npc:3', 'player'], 'present keys; unknown names ignored');
check(
    mentionedKeys("Hawke: Did Molag Bal's priest find you, Farkas?", ['daedra:molag_bal' => 'Molag Bal', 'npc:3' => 'Farkas', 'npc:4' => 'Aela'])
        === ['daedra:molag_bal', 'npc:3'],
    'whole-name, case-insensitive mentions'
);
check(mentionedKeys('Farkasson is here', ['npc:3' => 'Farkas']) === [], 'no partial-word matches');
check(displayName('player', 'Hawke', []) === 'Hawke' && displayName('daedra:molag_bal', 'Hawke', []) === 'Molag Bal'
    && displayName('npc:3', 'Hawke', [3 => 'Farkas']) === 'Farkas' && displayName('npc:9', 'Hawke', []) === null, 'display names');
check(playerInputText(['inputtext', '1', '2', 'Hawke: Lidia, are we good?'], 'Hawke') === 'Lidia, are we good?', 'player input text');
check(playerInputText(['rechat', '1', '2', '{}'], 'Hawke') === '', 'no player text on rechat');
check(pgTextArray(['a', 'b"c', 'd\\e']) === '{"a","b\\"c","d\\\\e"}', 'text array escaping');

echo "hook checks passed\n";
