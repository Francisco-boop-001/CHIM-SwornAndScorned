<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/launcher.php';

use function ChimSwornAndScorned\{workerCommand, workerPidAlive};

check(
    workerCommand('/usr/bin/php', '/var/www/html/HerikaServer/ext/sworn_and_scorned/worker.php')
        === "'/usr/bin/php' '/var/www/html/HerikaServer/ext/sworn_and_scorned/worker.php' --sas-daemon",
    'command arguments are shell-escaped'
);
check(
    workerCommand('/usr/bin/php; touch /tmp/pwned', "/tmp/plugin's path/worker.php")
        === "'/usr/bin/php; touch /tmp/pwned' '/tmp/plugin'\\''s path/worker.php' --sas-daemon",
    'shell metacharacters and quotes stay within arguments'
);
$command = workerCommand('/usr/bin/php', '/ext/sworn_and_scorned/worker.php');
check(preg_match('/worker\.php.*--daemon/', $command) !== 1, 'launcher flag does not match CHIM relationship worker detection');

$root = sys_get_temp_dir() . '/sas-launcher-' . bin2hex(random_bytes(4));
mkdir("{$root}/proc/123", 0777, true);
mkdir("{$root}/proc/124", 0777, true);
mkdir("{$root}/proc/125", 0777, true);
mkdir("{$root}/proc/126", 0777, true);
mkdir("{$root}/proc/127", 0777, true);
file_put_contents("{$root}/proc/123/cmdline", "/usr/bin/php\0/x/ext/sworn_and_scorned/worker.php\0--sas-daemon\0");
file_put_contents("{$root}/proc/124/cmdline", "/usr/bin/php\0/x/ext/relationship_system/worker.php\0");
file_put_contents("{$root}/proc/125/cmdline", "/usr/bin/php\0/x/ext/sworn_and_scorned/worker.php.old\0");
file_put_contents("{$root}/proc/126/cmdline", "/usr/bin/php\0/x/ext/not_sworn_and_scorned/worker.php\0");
file_put_contents("{$root}/proc/127/cmdline", "/usr/bin/php\0/x/ext/sworn_and_scorned/worker.php\0--daemon\0");
file_put_contents("{$root}/pid", "123\n");
check(workerPidAlive("{$root}/pid", "{$root}/proc"), 'live worker detected');
file_put_contents("{$root}/pid", '124');
check(!workerPidAlive("{$root}/pid", "{$root}/proc"), 'another plugin worker is not ours');
file_put_contents("{$root}/pid", '125');
check(!workerPidAlive("{$root}/pid", "{$root}/proc"), 'a path with a similar worker name is not ours');
file_put_contents("{$root}/pid", '126');
check(!workerPidAlive("{$root}/pid", "{$root}/proc"), 'a similar plugin name is not ours');
file_put_contents("{$root}/pid", '127');
check(!workerPidAlive("{$root}/pid", "{$root}/proc"), 'shared --daemon flag is not accepted as our worker flag');
file_put_contents("{$root}/pid", '999');
check(!workerPidAlive("{$root}/pid", "{$root}/proc"), 'dead pid');
file_put_contents("{$root}/pid", '123junk');
check(!workerPidAlive("{$root}/pid", "{$root}/proc"), 'malformed pid is rejected');
check(!workerPidAlive("{$root}/missing", "{$root}/proc"), 'missing pid file');

unlink("{$root}/proc/123/cmdline");
unlink("{$root}/proc/124/cmdline");
unlink("{$root}/proc/125/cmdline");
unlink("{$root}/proc/126/cmdline");
unlink("{$root}/proc/127/cmdline");
unlink("{$root}/pid");
rmdir("{$root}/proc/123");
rmdir("{$root}/proc/124");
rmdir("{$root}/proc/125");
rmdir("{$root}/proc/126");
rmdir("{$root}/proc/127");
rmdir("{$root}/proc");
rmdir($root);

echo "launcher checks passed\n";
