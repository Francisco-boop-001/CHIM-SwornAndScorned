<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';

check(PHP_VERSION_ID >= 80200, 'PHP 8.2 or newer required');
check(extension_loaded('mbstring'), 'mbstring extension required');
check(extension_loaded('json'), 'json extension required');
rejected(static function (): void {
    throw new UnexpectedValueException('x');
}, 'rejected() must accept UnexpectedValueException');

echo "smoke checks passed\n";
