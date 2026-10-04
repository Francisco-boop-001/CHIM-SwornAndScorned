<?php
declare(strict_types=1);
require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/launcher.php';

use function ChimSwornAndScorned\{ensureWorkerRunning, workerPauseReason, castForPlaythrough};

$settings = ['enabled' => true, '_key_error' => null];
check(workerPauseReason($settings) === null, 'healthy enabled worker is eligible');
check(workerPauseReason(['enabled' => false]) === 'disabled', 'disabled worker is paused');
check(workerPauseReason($settings + []) === null, 'absence of a key permits ordinary fallback');
$settings['_key_error'] = 'key_storage_unavailable';
$settings['use_jev'] = true;
check(workerPauseReason($settings) === 'key_storage_unavailable', 'unreadable saved key blocks fallback and launch');
check(ensureWorkerRunning('/nonexistent', '/nonexistent', $settings) === 'paused', 'unreadable key cannot spawn a process');
$settings['_key_error'] = null;
$settings['use_jev'] = false;
$GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = 'true';
check(workerPauseReason($settings) === 'restore_policy', 'restore policy pauses evaluation');
check(ensureWorkerRunning('/nonexistent', '/nonexistent', $settings) === 'paused', 'restore policy cannot spawn a process');
unset($GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA']);
check(workerPauseReason($settings) === null, 'recovery is immediately eligible without clearing work');

$cast = ['playthrough_id' => 'a', 'ids' => [7, 8]];
check(castForPlaythrough($cast, 'a') === [7, 8], 'cast belongs to its saved playthrough');
check(castForPlaythrough($cast, 'b') === [], 'reused row IDs in another playthrough do not select a cast');
check(castForPlaythrough([7, 8], 'a') === [], 'unscoped legacy cast requires deliberate reselection');
check(castForPlaythrough($cast, null) === [], 'ambiguous current playthrough cannot select a cast');
echo "eligibility checks passed\n";
