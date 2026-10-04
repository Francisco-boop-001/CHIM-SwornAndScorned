<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

final class KeyStorageError extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('API key storage is unavailable.');
    }
}

const DEFAULT_MASTER_KEY_PATH = '/var/lib/chim-secrets/sworn_and_scorned.key';
const API_KEY_MAX_BYTES = 400;

function masterKeyPath(): string
{
    $path = getenv('CHIM_SWORN_SCORNED_KEY_FILE');
    if ($path === false || $path === '') {
        return DEFAULT_MASTER_KEY_PATH;
    }
    if (!isCanonicalKeyPath($path)) {
        failKeyStorage();
    }
    return $path;
}

function readMasterKey(): string
{
    assertKeyCryptoCapabilities();

    $path = masterKeyPath();
    $handle = false;
    $key = null;
    $returnKey = false;
    try {
        $before = inspectKeyPath($path);
        assertKeyLocationAllowed($path, $before);
        assertKeyPathPermissions($before);

        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            failKeyStorage();
        }

        $opened = @fstat($handle);
        $after = inspectKeyPath($path);
        if (!is_array($opened)
            || !sameKeyFileIdentity($before[count($before) - 1]['stat'], $opened)
            || !sameKeyFileIdentity($before[count($before) - 1]['stat'], $after[count($after) - 1]['stat'])
            || !sameKeyPathSnapshot($before, $after)
            || !sameKeyFileMetadata($before[count($before) - 1]['stat'], $opened)
        ) {
            failKeyStorage();
        }

        $key = @fread($handle, SODIUM_CRYPTO_SECRETBOX_KEYBYTES + 1);
        $afterRead = @fstat($handle);
        $afterPathRead = inspectKeyPath($path);
        if (!is_string($key)
            || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            || !is_array($afterRead)
            || !sameKeyFileIdentity($opened, $afterRead)
            || !sameKeyFileMetadata($opened, $afterRead)
            || !sameKeyFileIdentity($opened, $afterPathRead[count($afterPathRead) - 1]['stat'])
            || !sameKeyPathSnapshot($before, $afterPathRead)
        ) {
            failKeyStorage();
        }

        $returnKey = true;
        return $key;
    } catch (KeyStorageError $error) {
        throw $error;
    } catch (\Throwable) {
        failKeyStorage();
    } finally {
        if (is_resource($handle)) {
            @fclose($handle);
        }
        if (!$returnKey && is_string($key)) {
            sodium_memzero($key);
        }
    }
}

function sealApiKey(#[\SensitiveParameter] string $plaintext): array
{
    $key = null;
    try {
        assertKeyCryptoCapabilities();
        assertApiKey($plaintext);
        $key = readMasterKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return [
            'v' => 1,
            'nonce' => base64_encode($nonce),
            'box' => base64_encode($box),
        ];
    } catch (KeyStorageError $error) {
        throw $error;
    } catch (\Throwable) {
        failKeyStorage();
    } finally {
        if (is_string($key)) {
            sodium_memzero($key);
        }
    }
}

function openApiKey(#[\SensitiveParameter] mixed $stored): string
{
    $key = null;
    try {
        assertKeyCryptoCapabilities();
        [$nonce, $box] = decodeApiKeyEnvelope($stored);
        $key = readMasterKey();
        $plaintext = sodium_crypto_secretbox_open($box, $nonce, $key);
        if (!is_string($plaintext)) {
            failKeyStorage();
        }
        try {
            assertApiKey($plaintext);
        } catch (KeyStorageError $error) {
            sodium_memzero($plaintext);
            throw $error;
        }
        return $plaintext;
    } catch (KeyStorageError $error) {
        throw $error;
    } catch (\Throwable) {
        failKeyStorage();
    } finally {
        if (is_string($key)) {
            sodium_memzero($key);
        }
    }
}

function assertApiKey(#[\SensitiveParameter] string $plaintext): void
{
    if ($plaintext === '' || strlen($plaintext) > API_KEY_MAX_BYTES
        || preg_match('/[\x00-\x20\x7f]/', $plaintext) === 1
    ) {
        failKeyStorage();
    }
}

function decodeApiKeyEnvelope(mixed $stored): array
{
    if (!is_array($stored) || count($stored) !== 3
        || !array_key_exists('v', $stored)
        || !array_key_exists('nonce', $stored)
        || !array_key_exists('box', $stored)
        || $stored['v'] !== 1
        || !is_string($stored['nonce'])
        || strlen($stored['nonce']) !== 4 * (int) ceil(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES / 3)
        || !is_string($stored['box'])
        || strlen($stored['box']) > 4 * (int) ceil((API_KEY_MAX_BYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) / 3)
    ) {
        failKeyStorage();
    }

    $nonce = base64_decode($stored['nonce'], true);
    $box = base64_decode($stored['box'], true);
    if (!is_string($nonce)
        || base64_encode($nonce) !== $stored['nonce']
        || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
        || !is_string($box)
        || base64_encode($box) !== $stored['box']
        || strlen($box) <= SODIUM_CRYPTO_SECRETBOX_MACBYTES
        || strlen($box) > API_KEY_MAX_BYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES
    ) {
        failKeyStorage();
    }

    return [$nonce, $box];
}

function assertKeyCryptoCapabilities(): void
{
    if (DIRECTORY_SEPARATOR !== '/' || !function_exists('posix_geteuid')
        || !function_exists('sodium_crypto_secretbox')
        || !function_exists('sodium_crypto_secretbox_open')
        || !function_exists('sodium_memzero')
        || !defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES')
        || !defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')
        || !defined('SODIUM_CRYPTO_SECRETBOX_MACBYTES')
        || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== 32
    ) {
        failKeyStorage();
    }
}

function inspectKeyPath(string $path): array
{
    clearstatcache(true);
    if (!isCanonicalKeyPath($path)) {
        failKeyStorage();
    }

    $parts = explode('/', substr($path, 1));

    $root = @lstat('/');
    if (!is_array($root) || keyFileType($root) !== 0040000) {
        failKeyStorage();
    }
    $components = [['path' => '/', 'stat' => $root]];
    $current = '';
    foreach ($parts as $index => $part) {
        $current .= '/' . $part;
        $stat = @lstat($current);
        if (!is_array($stat)) {
            failKeyStorage();
        }
        $final = $index === count($parts) - 1;
        if (keyFileType($stat) === 0120000 || (!$final && keyFileType($stat) !== 0040000)) {
            failKeyStorage();
        }
        $components[] = ['path' => $current, 'stat' => $stat];
    }

    $resolved = @realpath($path);
    if (!is_string($resolved) || $resolved !== $path) {
        failKeyStorage();
    }
    return $components;
}

function assertKeyLocationAllowed(string $path, array $components): void
{
    $resolved = $components[count($components) - 1]['path'];
    $roots = [__DIR__, dirname(__DIR__), dirname(__DIR__, 2)];
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
    if ($documentRoot !== null && $documentRoot !== '') {
        if (!is_string($documentRoot) || !isAbsoluteKeyPath($documentRoot)) {
            failKeyStorage();
        }
        $roots[] = $documentRoot;
    }

    foreach ($roots as $root) {
        $realRoot = @realpath($root);
        if (!is_string($realRoot) || !is_dir($realRoot)) {
            failKeyStorage();
        }
        if (pathIsWithin($resolved, $realRoot)) {
            failKeyStorage();
        }
    }

    if ($path !== $resolved) {
        failKeyStorage();
    }
}

function assertKeyPathPermissions(array $components): void
{
    $count = count($components);
    if ($count < 3) {
        failKeyStorage();
    }

    $key = $components[$count - 1]['stat'];
    $directoryIndex = $count - 2;
    $directory = $components[$directoryIndex]['stat'];
    $effectiveUid = posix_geteuid();
    $keyUid = (int) ($key['uid'] ?? -1);
    $directoryUid = (int) ($directory['uid'] ?? -1);
    $keyMode = (int) ($key['mode'] ?? 0);
    $directoryMode = (int) ($directory['mode'] ?? 0);

    if (keyFileType($key) !== 0100000
        || ($keyMode & 07777) !== 0600
        || (int) ($key['nlink'] ?? 0) !== 1
        || (int) ($key['size'] ?? -1) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        || keyFileType($directory) !== 0040000
        || ($directoryMode & 0077) !== 0
        || ($directoryMode & 07000) !== 0
        || $directoryUid !== $keyUid
        || ($effectiveUid !== 0 && $keyUid !== $effectiveUid)
    ) {
        failKeyStorage();
    }

    $trustedOwners = array_unique([0, $effectiveUid, $keyUid]);
    for ($index = 0; $index < $directoryIndex; $index++) {
        $component = $components[$index];
        $stat = $component['stat'];
        $uid = (int) ($stat['uid'] ?? -1);
        $mode = (int) ($stat['mode'] ?? 0) & 07777;
        if (keyFileType($stat) !== 0040000 || !in_array($uid, $trustedOwners, true)) {
            failKeyStorage();
        }
        if (($mode & 0022) !== 0) {
            $safeTemporaryAncestor = $component['path'] === '/tmp'
                && $uid === 0
                && ($mode & 01000) !== 0;
            if (!$safeTemporaryAncestor) {
                failKeyStorage();
            }
        }
    }
}

function sameKeyPathSnapshot(array $left, array $right): bool
{
    if (count($left) !== count($right)) {
        return false;
    }
    foreach ($left as $index => $component) {
        if ($component['path'] !== $right[$index]['path']
            || !sameKeyFileIdentity($component['stat'], $right[$index]['stat'])
            || !sameKeyFileMetadata($component['stat'], $right[$index]['stat'])
        ) {
            return false;
        }
    }
    return true;
}

function sameKeyFileIdentity(array $left, array $right): bool
{
    return isset($left['dev'], $left['ino'], $right['dev'], $right['ino'])
        && (int) $left['dev'] === (int) $right['dev']
        && (int) $left['ino'] === (int) $right['ino'];
}

function sameKeyFileMetadata(array $left, array $right): bool
{
    foreach (['mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
        if (!isset($left[$field], $right[$field]) || (int) $left[$field] !== (int) $right[$field]) {
            return false;
        }
    }
    return true;
}

function keyFileType(array $stat): int
{
    return (int) ($stat['mode'] ?? 0) & 0170000;
}

function isAbsoluteKeyPath(string $path): bool
{
    return $path !== '' && str_starts_with($path, '/');
}

function isCanonicalKeyPath(string $path): bool
{
    if (!isAbsoluteKeyPath($path) || str_contains($path, "\0")) {
        return false;
    }
    $parts = explode('/', substr($path, 1));
    return $parts !== []
        && !in_array('', $parts, true)
        && !in_array('.', $parts, true)
        && !in_array('..', $parts, true);
}

function pathIsWithin(string $path, string $root): bool
{
    $root = rtrim($root, '/');
    if ($root === '') {
        return str_starts_with($path, '/');
    }
    return $path === $root || str_starts_with($path, $root . '/');
}

function failKeyStorage(): never
{
    throw new KeyStorageError();
}
