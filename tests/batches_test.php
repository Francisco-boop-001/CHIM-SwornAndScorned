<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/batches.php';

use function ChimSwornAndScorned\{perspectivesOf, scanWindow, settledBatches};

function bline(int $id, string $speaker, string $listener, array $people, int $localts): array
{
    return ['event_id' => $id, 'gamets' => (float) $id, 'type' => 'chat', 'speaker' => $speaker,
        'listener' => $listener, 'people' => $people, 'text' => "line {$id}", 'localts' => $localts];
}

$cast = ['Lidia Sobieska' => 5124, 'Aela the Huntress' => 2892];
$lines = [
    bline(41, 'Farkas', 'Lidia Sobieska', ['Farkas', 'Lidia Sobieska', 'Hawke'], 1000),
    ['type' => 'inputtext', 'listener' => ''] + bline(42, 'Hawke', '', ['Lidia Sobieska', 'Hawke'], 1005),
    bline(43, 'Lidia Sobieska', 'Hawke', ['Lidia Sobieska', 'Hawke'], 1006),
    bline(44, 'Aela the Huntress', 'Farkas', ['Aela the Huntress', 'Farkas', 'Lidia Sobieska'], 1100),
];

check(perspectivesOf($lines[0], $cast) === [['Lidia Sobieska', 'Farkas']], 'addressed cast listener views the speaker');
check(perspectivesOf($lines[1], $cast) === [['Lidia Sobieska', 'Hawke']], 'unaddressed line: cast witnesses view the speaker');
check(perspectivesOf($lines[2], $cast) === [['Lidia Sobieska', 'Hawke']], 'cast speaker views the listener');
check(perspectivesOf($lines[3], $cast) === [['Aela the Huntress', 'Farkas']], 'third-party witnesses get no perspective');
check(perspectivesOf(bline(45, 'Lidia Sobieska', 'explicit_disable_rechat', ['Lidia Sobieska'], 1), $cast) === [], 'solo reflection is not an exchange');

$batch = settledBatches($lines, $cast, [], 1030, 44);
check($batch['jobs'] === [
    ['owner_npc_id' => 5124, 'owner_name' => 'Lidia Sobieska', 'counterpart_name' => 'Farkas', 'event_ids' => [41]],
    ['owner_npc_id' => 5124, 'owner_name' => 'Lidia Sobieska', 'counterpart_name' => 'Hawke', 'event_ids' => [42, 43]],
], 'quiet groups settle, the active perspective waits');
check($batch['cursor'] === 43, 'cursor stops before the first unsettled line');

$marked = settledBatches($lines, $cast, ['5124|Farkas' => 41], 1030, 44);
check(array_column($marked['jobs'], 'counterpart_name') === ['Hawke'], 'marks prevent re-emitting lines');

$busy = [];
for ($n = 1; $n <= 6; $n++) {
    $busy[] = bline(50 + $n, 'Farkas', 'Lidia Sobieska', ['Farkas', 'Lidia Sobieska'], 2000);
}
check(count(settledBatches($busy, $cast, [], 2000, 56)['jobs']) === 1, 'six lines settle immediately');

$rows = [
    ['rowid' => '60', 'type' => 'chat', 'delivery_state' => 'spoken', 'localts' => '1000'],
    ['rowid' => '61', 'type' => 'chat', 'delivery_state' => 'emitted', 'localts' => '1990'],
    ['rowid' => '62', 'type' => 'chat', 'delivery_state' => 'spoken', 'localts' => '1995'],
];
$window = scanWindow($rows, 2000);
check(array_column($window['rows'], 'rowid') === ['60'] && $window['read_up_to'] === 60, 'young unacknowledged line holds the window');
check(scanWindow($rows, 2110)['read_up_to'] === 62, 'line at the grace limit is old');
check(scanWindow([], 2000) === ['rows' => [], 'read_up_to' => 0], 'empty window');

echo "batches checks passed\n";

$interleaved = [bline(1, 'Farkas', 'Lidia Sobieska', ['Farkas', 'Lidia Sobieska'], 1000)];
for ($id = 2; $id < 500; $id++) {
    $interleaved[] = bline($id, 'Other', 'Lidia Sobieska', ['Other', 'Lidia Sobieska'], 1000);
}
$interleaved[] = bline(500, 'Farkas', 'Lidia Sobieska', ['Farkas', 'Lidia Sobieska'], 2000);
check(settledBatches($interleaved, $cast, [], 2000, 500)['jobs'] === [], 'later large group waits behind earliest unsettled owner group');
$settled = settledBatches($interleaved, $cast, [], 2021, 500);
check(array_column($settled['jobs'], 'counterpart_name') === ['Farkas', 'Other', 'Farkas'], 'settled owner segments emit in source order');
$bothLarge = [];
for ($id = 1; $id <= 300; $id++) {
    $name = $id % 2 ? 'Farkas' : 'Other';
    $bothLarge[] = bline($id, $name, 'Lidia Sobieska', [$name, 'Lidia Sobieska'], 1000);
}
$previous = 0;
foreach (settledBatches($bothLarge, $cast, [], 2021, 300)['jobs'] as $job) {
    check(min($job['event_ids']) > $previous, 'large interleaved groups have strictly ordered disjoint ranges');
    $previous = max($job['event_ids']);
}
check($previous === 300, 'every interleaved source event is emitted');
$splitExchange = [bline(1, 'Farkas', 'Lidia Sobieska', ['Farkas', 'Lidia Sobieska'], 1000),
    bline(2, 'Other', 'Lidia Sobieska', ['Other', 'Lidia Sobieska'], 1000),
    bline(3, 'Lidia Sobieska', 'Farkas', ['Farkas', 'Lidia Sobieska'], 1000)];
$splitExchange[0]['text'] = 'I will bring bread tomorrow.';
$splitExchange[2]['text'] = 'I accept.';
check(array_column(settledBatches($splitExchange, $cast, [], 2021, 3)['jobs'], 'event_ids') === [[1], [2], [3]], 'intervening counterpart separates commitment and acceptance evaluation context');
