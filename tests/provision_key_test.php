<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
check(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'only disposable clone');
check(posix_geteuid() === 0, 'provisioning fixture needs root');
$script = __DIR__ . '/../server/provision_key.php';
$directory = '/var/lib/sas-provision-fixture-' . bin2hex(random_bytes(8));
$path = $directory . '/master.key';
putenv('CHIM_SWORN_SCORNED_KEY_FILE=' . $path);
function provisionRun(string $script): array
{
    $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'fixture process starts');
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $out];
}
try {
    [$code, $out] = provisionRun($script);
    check($code === 0 && is_file($path), 'root provisions a persistent private master key');
    $owner = posix_getpwnam('www-data')['uid'];
    check(strlen(file_get_contents($path)) === 32, 'master is 256 bits');
    check((fileperms($path) & 0777) === 0600 && fileowner($path) === $owner, 'service-owned private master');
    check((fileperms($directory) & 0777) === 0700 && fileowner($directory) === $owner, 'service-owned private directory');
    $hash = hash_file('sha256', $path);
    [$code, $out] = provisionRun($script);
    check($code !== 0 && hash_file('sha256', $path) === $hash, 'repeat provisioning never overwrites a master');
    putenv('CHIM_SWORN_SCORNED_KEY_FILE=' . $directory . '/service-owned-directory.key');
    [$code, $out] = provisionRun($script);
    check($code !== 0 && !is_file($directory . '/service-owned-directory.key'), 'root never provisions inside a service-owned existing directory');
    putenv('CHIM_SWORN_SCORNED_KEY_FILE=' . $path);
    require __DIR__ . '/../server/key_crypto.php';
    check(strlen(ChimSwornAndScorned\readMasterKey()) === 32, 'root validates provisioned key');
    $reader = 'require ' . var_export(__DIR__ . '/../server/key_crypto.php', true) . '; exit(strlen(ChimSwornAndScorned\\readMasterKey()) === 32 ? 0 : 1);';
    $process = proc_open(['runuser', '-u', 'www-data', '--', PHP_BINARY, '-r', $reader], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'service reader starts');
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0 && $output === '', 'web/worker service can read without exposing key');

    putenv('CHIM_SWORN_SCORNED_KEY_FILE=/var/www/html/HerikaServer/sas-forbidden-master-fixture/key');
    [$code, $out] = provisionRun($script);
    check($code !== 0 && !is_dir('/var/www/html/HerikaServer/sas-forbidden-master-fixture'), 'provisioning refuses the served tree before creating files');
    $unsafeDirectory = '/tmp/sas-provision-forbidden-' . bin2hex(random_bytes(8));
    putenv('CHIM_SWORN_SCORNED_KEY_FILE=' . $unsafeDirectory . '/master.key');
    [$code, $out] = provisionRun($script);
    check($code !== 0 && !is_dir($unsafeDirectory), 'root refuses provisioning under a writable ancestor');
    putenv('CHIM_SWORN_SCORNED_KEY_FILE=' . $directory . '/never-created.key');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    check(is_resource($socket), 'reserve fixture HTTP port');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', dirname($script)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($server), 'fixture HTTP server starts');
    try {
        $body = false;
        for ($i = 0; $i < 30; ++$i) {
            $body = @file_get_contents('http://' . $address . '/provision_key.php', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 1]]));
            if ($body !== false) { break; }
            usleep(50000);
        }
        check($body === '' && str_contains($http_response_header[0] ?? '', '403'), 'HTTP provisioning returns an empty 403');
        check(!is_file($directory . '/never-created.key'), 'HTTP cannot create a master key');
    } finally {
        proc_terminate($server);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($server);
    }
} finally {
    if (is_file($path)) { unlink($path); }
    if (is_file($directory . '/service-owned-directory.key')) { unlink($directory . '/service-owned-directory.key'); }
    if (is_dir($directory)) { rmdir($directory); }
    putenv('CHIM_SWORN_SCORNED_KEY_FILE');
}
echo "master-key provisioning checks passed\n";
