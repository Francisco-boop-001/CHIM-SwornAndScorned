<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/witness.php';

use function ChimSwornAndScorned\pairLinesWitnessedBy;

$lines = [
    sampleLine(5, 'Farkas', 'I will stand with you before Aela.'),
    sampleLine(6, 'Aela', 'Farkas is a coward.'),                                   // third party: MP's business
    sampleLine(7, 'Farkas', 'Lydia is late again.', ['Farkas', 'Aela']),            // Lydia absent
    sampleLine(8, 'The Narrator', 'The Narrator: Aela claims Farkas stole.', ['Lydia', 'Farkas'], '', 'instruction'),
    sampleLine(9, 'Lydia', 'Why did he stay silent?', ['Lydia'], 'explicit_disable_rechat'),
    sampleLine(4, 'Lydia', 'You promised.'),
    sampleLine(10, 'Lydia', 'I saw the drinks.', ['Lydia', 'Farkas'], '', 'Consume'),
];

$lydia = pairLinesWitnessedBy($lines, 'Lydia', 'Farkas');
check(array_column($lydia, 'event_id') === [4, 5], 'Lydia sees only pair speech she witnessed, in order');

$farkas = pairLinesWitnessedBy($lines, 'Farkas', 'Lydia');
check(array_column($farkas, 'event_id') === [4, 5, 7], 'a speaker always witnesses their own line');

$player = pairLinesWitnessedBy([sampleLine(11, 'Dovah', 'I will find your sword.', ['Lydia'], 'Lydia', 'inputtext')], 'Lydia', 'Dovah');
check(array_column($player, 'event_id') === [11], 'player input counts as speech');

check(pairLinesWitnessedBy([], 'Lydia', 'Farkas') === [], 'no lines, no result');

echo "witness checks passed\n";
