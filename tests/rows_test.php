<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/rows.php';

use function ChimSwornAndScorned\{lineFromEventRow, parsePeople};

$player = 'Hawke';
$spoken = [
    'rowid' => '41', 'type' => 'chat',
    'data' => 'Farkas: I will stand with you before Aela. (talking to Lidia Sobieska)',
    'people' => '|Farkas|Lidia Sobieska|Hawke|', 'gamets' => '292500100', 'localts' => '1790000000',
    'delivery_state' => 'spoken',
];
check(lineFromEventRow($spoken, $player) === [
    'event_id' => 41, 'gamets' => 292500100.0, 'type' => 'chat', 'speaker' => 'Farkas',
    'listener' => 'Lidia Sobieska', 'people' => ['Farkas', 'Lidia Sobieska', 'Hawke'],
    'text' => 'I will stand with you before Aela.', 'localts' => 1790000000,
], 'spoken chat line');
check(lineFromEventRow(['delivery_state' => 'aborted'] + $spoken, $player) === null, 'aborted lines ignored');
check(lineFromEventRow(['delivery_state' => 'emitted'] + $spoken, $player) === null, 'unacknowledged lines ignored');
check(lineFromEventRow(['delivery_state' => null] + $spoken, $player) === null, 'unacknowledged lines ignored');
check(lineFromEventRow(['type' => 'chat_background'] + $spoken, $player) === null, 'background chat ignored');
check(lineFromEventRow(['data' => 'no separator here'] + $spoken, $player) === null, 'malformed data ignored');
check(lineFromEventRow(['data' => ' : no speaker'] + $spoken, $player) === null, 'empty speaker ignored');
check(lineFromEventRow(['rowid' => 'not-an-id'] + $spoken, $player) === null, 'invalid event id ignored');
check(lineFromEventRow(['gamets' => 'not-a-time'] + $spoken, $player) === null, 'invalid game time ignored');
check(
    lineFromEventRow(['data' => 'Lidia Sobieska: Why did he stay silent? (talking to )'] + $spoken, $player) === null,
    'malformed listener suffix ignored'
);

$solo = lineFromEventRow(['data' => 'Lidia Sobieska: Why did he stay silent? (talking to explicit_disable_rechat)'] + $spoken, $player);
check($solo['listener'] === 'explicit_disable_rechat', 'sentinel listener kept for the witness filter');

$input = [
    'rowid' => '42', 'type' => 'inputtext', 'data' => 'Hawke: Lidia, did Aela keep her word?',
    'people' => '|Lidia Sobieska|Hawke|', 'gamets' => '292500200', 'localts' => '1790000005', 'delivery_state' => null,
];
$line = lineFromEventRow($input, $player);
check($line['speaker'] === 'Hawke' && $line['listener'] === '' && $line['text'] === 'Lidia, did Aela keep her word?', 'player input line');
check(lineFromEventRow(['data' => 'Bruce: hi there'] + $input, $player) === null, 'input under another name ignored');
check(lineFromEventRow(['type' => 'instruction', 'data' => 'The Narrator: Aela claims Farkas stole.'] + $input, $player) === null, 'instruction rows ignored');

check(parsePeople(null) === [] && parsePeople('||') === [] && parsePeople('|A| B |') === ['A', 'B'], 'people parsing');

echo "rows checks passed\n";
