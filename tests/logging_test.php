<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/logging.php';
require __DIR__ . '/../server/backend_error.php';

use ChimSwornAndScorned\DecisionBackendError;
use function ChimSwornAndScorned\sasLog;

$file = tempnam(sys_get_temp_dir(), 'saslog');
check(is_string($file), 'temporary log created');
try {
    ini_set('error_log', $file);
    sasLog('job_finished', 'info', [
        'job_id' => 7,
        'outcome' => 'done',
        'reason' => 'http_429',
        'text' => 'secret dialogue',
        'prompt' => 'secret prompt',
        'api_key' => 'secret key',
        'token' => 'secret token',
    ]);
    $logged = (string) file_get_contents($file);
} finally {
    @unlink($file);
}
check(str_contains($logged, '"plugin":"sworn_and_scorned"') && str_contains($logged, '"job_id":7'), 'record written');
check(str_contains($logged, '"reason":"http_429"'), 'fixed reason code retained');
foreach (['secret dialogue', 'secret prompt', 'secret key', 'secret token', '"text"', '"api_key"'] as $secret) {
    check(!str_contains($logged, $secret), 'unapproved log data dropped');
}

$previous = new RuntimeException('transport failed');
$error = new DecisionBackendError('http_429', true, $previous);
check(
    $error->reasonCode === 'http_429'
        && $error->retryable
        && $error->getMessage() === 'http_429'
        && $error->getPrevious() === $previous,
    'error carries a code, retryable flag, and previous error'
);

echo "logging checks passed\n";
