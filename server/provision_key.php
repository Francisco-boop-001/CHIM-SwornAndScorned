<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

// Administration only. Installing this file never creates or rotates a key.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/key_crypto.php';

$createdFile = false;
$createdDirectory = false;
$path = null;
$directory = null;
$previousMask = umask(0077);
try {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !function_exists('sodium_crypto_secretbox')) {
        throw new KeyStorageError();
    }
    $owner = posix_getpwnam('www-data');
    if (!is_array($owner)) {
        throw new KeyStorageError();
    }
    $path = masterKeyPath();
    $directory = dirname($path);
    // Provision only beneath root-owned, non-writable real ancestors. No
    // symlink following or master keys in the served tree, even as root.
    $webRoot = realpath('/var/www');
    $pluginRoot = realpath(__DIR__);
    $parent = $directory;
    while (true) {
        if ($parent === $webRoot || $parent === $pluginRoot) {
            throw new KeyStorageError();
        }
        if ($parent !== $directory) {
            $stat = @lstat($parent);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== 0
                || ($stat['mode'] & 0022) !== 0) {
                throw new KeyStorageError();
            }
        }
        if ($parent === '/') { break; }
        $parent = dirname($parent);
    }
    if (@lstat($path) !== false) {
        throw new KeyStorageError();
    }
    $dirStat = @lstat($directory);
    if ($dirStat === false) {
        if (!@mkdir($directory, 0700)) { throw new KeyStorageError(); }
        $createdDirectory = true;
    } elseif (($dirStat['mode'] & 0170000) !== 0040000 || ($dirStat['mode'] & 07777) !== 0700
        || $dirStat['uid'] !== 0) {
        throw new KeyStorageError();
    }
    // Keep the directory root-owned until every path-based mutation finishes.
    // A service-owned directory could replace the child with a symlink.
    $stream = @fopen($path, 'xb');
    if ($stream === false) { throw new KeyStorageError(); }
    $createdFile = true;
    $master = random_bytes(32);
    try {
        if (fwrite($stream, $master) !== 32 || !fflush($stream) || !fsync($stream)) {
            throw new KeyStorageError();
        }
    } finally {
        sodium_memzero($master);
        fclose($stream);
    }
    if (!@chown($path, $owner['uid']) || !@chgrp($path, $owner['gid']) || !@chmod($path, 0600)
        || !@chgrp($directory, $owner['gid']) || !@chown($directory, $owner['uid'])) {
        throw new KeyStorageError();
    }
    $validated = readMasterKey();
    sodium_memzero($validated);
    echo "Private master key provisioned for www-data. Back it up separately from the database.\n";
} catch (\Throwable) {
    if ($createdFile && is_string($path)) { @unlink($path); }
    if ($createdDirectory && is_string($directory)) { @rmdir($directory); }
    fwrite(STDERR, "Master-key provisioning refused. Requires root, sodium, a safe private path and no existing key.\n");
    exit(1);
} finally {
    umask($previousMask);
}
