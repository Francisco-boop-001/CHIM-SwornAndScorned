<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/tables.php';

use function ChimSwornAndScorned\{normalizeSettings, parsePgIntArray, pgIntArray, sasTable};
use const ChimSwornAndScorned\DEFAULT_SETTINGS;

check(normalizeSettings(DEFAULT_SETTINGS) === ['enabled' => false, 'cast' => [], 'model' => 'typesafe/jev-1.13', 'api_key' => ''], 'defaults: disabled');
check(normalizeSettings(['enabled' => 'yes'] + DEFAULT_SETTINGS)['enabled'] === false, 'only true enables');
check(normalizeSettings(['cast' => ['5124', 5124, -1, 'x', 2892, 1, 2, 3, 4, 5, 6, 7]] + DEFAULT_SETTINGS)['cast'] === [5124, 2892, 1, 2, 3, 4, 5, 6], 'cast: positive unique ids, at most 8');
check(normalizeSettings(['model' => 'bad model; drop'] + DEFAULT_SETTINGS)['model'] === 'typesafe/jev-1.13', 'invalid model falls back');
check(normalizeSettings(['model' => '~typesafe/jev-latest'] + DEFAULT_SETTINGS)['model'] === '~typesafe/jev-latest', 'alias model accepted');
check(normalizeSettings(['api_key' => ' k '] + DEFAULT_SETTINGS)['api_key'] === 'k', 'key trimmed');

check(sasTable('jobs') === 'plugins.sworn_scorned_jobs', 'default schema');
putenv('SAS_TEST_SCHEMA=sas_it');
check(sasTable('jobs') === 'sas_it.sworn_scorned_jobs', 'test schema override');
putenv('SAS_TEST_SCHEMA=bad;name');
rejected(fn () => sasTable('jobs'), 'invalid schema rejected');
putenv('SAS_TEST_SCHEMA');

check(pgIntArray([3, '4', 5]) === '{3,4,5}' && parsePgIntArray('{3,4,5}') === [3, 4, 5] && parsePgIntArray('{}') === [], 'array helpers');

echo "settings checks passed\n";
