<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';

$cryptoModule = __DIR__ . '/../server/key_crypto.php';
if (!is_file($cryptoModule)) {
    fwrite(STDERR, "FAIL: server/key_crypto.php is missing\n");
    exit(1);
}
require $cryptoModule;

use ChimSwornAndScorned\KeyStorageError;
use function ChimSwornAndScorned\{masterKeyPath, openApiKey, readMasterKey, sealApiKey};

const KEY_STORAGE_ERROR_MESSAGE = 'API key storage is unavailable.';

$keyPathVariable = 'CHIM_SWORN_SCORNED_KEY_FILE';
$originalKeyPath = getenv($keyPathVariable);
$hadDocumentRoot = array_key_exists('DOCUMENT_ROOT', $_SERVER);
$originalDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
$originalExceptionIgnoreArgs = ini_get('zend.exception_ignore_args');
$fixturePaths = [];
ini_set('zend.exception_ignore_args', '0');

function rememberKeyFixture(string $path): void
{
    $GLOBALS['fixturePaths'][] = $path;
}

function makeKeyFixtureDirectory(string $path, int $mode = 0700): string
{
    if (!mkdir($path, $mode) || !chmod($path, $mode)) {
        throw new RuntimeException('could not create private key fixture directory');
    }
    rememberKeyFixture($path);
    return $path;
}

function makeKeyFixtureFile(string $path, string $contents, int $mode = 0600): string
{
    if (file_put_contents($path, $contents) !== strlen($contents) || !chmod($path, $mode)) {
        throw new RuntimeException('could not create key fixture file');
    }
    rememberKeyFixture($path);
    return $path;
}

function useKeyFixturePath(string $path): void
{
    putenv('CHIM_SWORN_SCORNED_KEY_FILE=' . $path);
}

function expectKeyStorageError(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (KeyStorageError $error) {
        check($error->getMessage() === KEY_STORAGE_ERROR_MESSAGE, $message . ': fixed safe error');
        return;
    }
    throw new RuntimeException($message);
}

function expectSecretRedactedFromTrace(callable $operation, #[\SensitiveParameter] string $secret, string $message): void
{
    try {
        $operation();
    } catch (KeyStorageError $error) {
        check($error->getMessage() === KEY_STORAGE_ERROR_MESSAGE, $message . ': fixed safe error');
        $validatorArgument = null;
        $leakingFrames = [];
        foreach ($error->getTrace() as $frame) {
            foreach ($frame['args'] ?? [] as $index => $argument) {
                if (is_string($argument) && str_contains($argument, $secret)) {
                    $leakingFrames[] = ($frame['function'] ?? 'unknown') . '[' . $index . ']';
                }
            }
            if (is_string($frame['function'] ?? null) && str_ends_with($frame['function'], 'assertApiKey')) {
                $validatorArgument = $frame['args'][0] ?? null;
            }
        }
        check($leakingFrames === [], $message . ': plaintext absent from trace (' . implode(', ', $leakingFrames) . ')');
        check($validatorArgument instanceof SensitiveParameterValue, $message . ': validator argument is redacted');
        return;
    }
    throw new RuntimeException($message);
}

function cleanupKeyCryptoFixtures(): void
{
    $paths = array_reverse($GLOBALS['fixturePaths']);
    foreach ($paths as $path) {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            @rmdir($path);
        }
    }

    if ($GLOBALS['originalKeyPath'] === false) {
        putenv('CHIM_SWORN_SCORNED_KEY_FILE');
    } else {
        putenv('CHIM_SWORN_SCORNED_KEY_FILE=' . $GLOBALS['originalKeyPath']);
    }
    if ($GLOBALS['hadDocumentRoot']) {
        $_SERVER['DOCUMENT_ROOT'] = $GLOBALS['originalDocumentRoot'];
    } else {
        unset($_SERVER['DOCUMENT_ROOT']);
    }
    if (is_string($GLOBALS['originalExceptionIgnoreArgs'])) {
        @ini_set('zend.exception_ignore_args', $GLOBALS['originalExceptionIgnoreArgs']);
    }
}

register_shutdown_function('cleanupKeyCryptoFixtures');

check(PHP_OS_FAMILY === 'Linux' && function_exists('posix_geteuid') && extension_loaded('sodium'), 'POSIX and Sodium runtime available');
check(masterKeyPath() === '/var/lib/chim-secrets/sworn_and_scorned.key', 'default master-key path');

$fixtureRoot = makeKeyFixtureDirectory(sys_get_temp_dir() . '/chim-sworn-and-scorned-key-' . bin2hex(random_bytes(12)));
$keyDirectory = makeKeyFixtureDirectory($fixtureRoot . '/private');
$keyBytes = random_bytes(32);
$keyFile = makeKeyFixtureFile($keyDirectory . '/master.key', $keyBytes);
useKeyFixturePath($keyFile);

check(masterKeyPath() === $keyFile, 'absolute environment key path accepted');
check(readMasterKey() === $keyBytes, 'reads exactly the 32-byte master key');

$plaintext = 'opaque-api-key-without-whitespace';
$sealed = sealApiKey($plaintext);
check(array_keys($sealed) === ['v', 'nonce', 'box'] && $sealed['v'] === 1, 'sealed envelope has exactly the versioned fields');
check(is_string($sealed['nonce']) && is_string($sealed['box']), 'envelope encodes nonce and ciphertext as strings');
check(openApiKey($sealed) === $plaintext, 'secretbox round trip');
$sealedAgain = sealApiKey($plaintext);
check($sealedAgain['nonce'] !== $sealed['nonce'], 'each save uses a fresh nonce');
check(openApiKey($sealedAgain) === $plaintext, 'second envelope also opens');
check(openApiKey(sealApiKey(str_repeat('x', 400))) === str_repeat('x', 400), '400-byte API key accepted');

$decodedNonce = base64_decode($sealed['nonce'], true);
$decodedBox = base64_decode($sealed['box'], true);
check(is_string($decodedNonce) && strlen($decodedNonce) === SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, 'nonce has the required length');
check(is_string($decodedBox) && strlen($decodedBox) <= 400 + SODIUM_CRYPTO_SECRETBOX_MACBYTES, 'ciphertext stays within the API-key bound');

$tampered = $sealed;
$tamperedBox = base64_decode($tampered['box'], true);
$tamperedBox[0] = chr(ord($tamperedBox[0]) ^ 1);
$tampered['box'] = base64_encode($tamperedBox);
expectKeyStorageError(fn () => openApiKey($tampered), 'tampered ciphertext rejected');

$randomCiphertext = [
    'v' => 1,
    'nonce' => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)),
    'box' => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_MACBYTES + 1)),
];
expectKeyStorageError(fn () => openApiKey($randomCiphertext), 'random ciphertext rejected');

$traceSecret = 'trace-redaction-sentinel ';
$traceNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$traceEnvelope = [
    'v' => 1,
    'nonce' => base64_encode($traceNonce),
    'box' => base64_encode(sodium_crypto_secretbox($traceSecret, $traceNonce, $keyBytes)),
];
check(ini_get('zend.exception_ignore_args') === '0', 'exception trace arguments enabled for redaction regression');
expectSecretRedactedFromTrace(fn () => openApiKey($traceEnvelope), $traceSecret, 'decrypted plaintext exception trace redacted');

$wrongKeyBytes = random_bytes(32);
makeKeyFixtureFile($fixtureRoot . '/wrong.key', $wrongKeyBytes);
useKeyFixturePath($fixtureRoot . '/wrong.key');
expectKeyStorageError(fn () => openApiKey($sealed), 'wrong master key rejected');
useKeyFixturePath($keyFile);

foreach ([
    ['v' => '1', 'nonce' => $sealed['nonce'], 'box' => $sealed['box']],
    ['v' => 1, 'nonce' => 'not base64!', 'box' => $sealed['box']],
    ['v' => 1, 'nonce' => base64_encode('short'), 'box' => $sealed['box']],
    ['v' => 1, 'nonce' => $sealed['nonce'], 'box' => base64_encode('too short')],
    ['v' => 1, 'nonce' => $sealed['nonce'], 'box' => $sealed['box'], 'extra' => true],
    ['v' => 1, 'nonce' => $sealed['nonce']],
    'not an envelope',
] as $malformedEnvelope) {
    expectKeyStorageError(fn () => openApiKey($malformedEnvelope), 'malformed envelope rejected');
}

expectKeyStorageError(fn () => sealApiKey(''), 'empty API key rejected');
expectKeyStorageError(fn () => sealApiKey("key with whitespace"), 'API key whitespace rejected');
expectKeyStorageError(fn () => sealApiKey("key\x01with-control"), 'API key control byte rejected');
expectKeyStorageError(fn () => sealApiKey(str_repeat('x', 401)), 'oversized API key rejected');

$missingKey = $fixtureRoot . '/missing.key';
useKeyFixturePath($missingKey);
expectKeyStorageError(fn () => readMasterKey(), 'missing key rejected');

useKeyFixturePath($keyDirectory);
expectKeyStorageError(fn () => readMasterKey(), 'directory used as key rejected');

useKeyFixturePath('relative/key');
expectKeyStorageError(fn () => masterKeyPath(), 'relative environment path rejected');
foreach (['/tmp/./key', '/tmp/../key', '/tmp//key', '/tmp/key/'] as $noncanonicalPath) {
    useKeyFixturePath($noncanonicalPath);
    expectKeyStorageError(fn () => masterKeyPath(), 'noncanonical absolute environment path rejected');
}

useKeyFixturePath($keyFile);
check(chmod($keyFile, 0640), 'make key mode insecure fixture');
expectKeyStorageError(fn () => readMasterKey(), 'group-readable key rejected');
check(chmod($keyFile, 0600), 'restore key mode after fixture');
check(chmod($keyDirectory, 0755), 'make containing directory insecure fixture');
expectKeyStorageError(fn () => readMasterKey(), 'group/other-accessible key directory rejected');
check(chmod($keyDirectory, 0700), 'restore directory mode after fixture');

$hardLink = $fixtureRoot . '/hard-link.key';
if (!link($keyFile, $hardLink)) {
    throw new RuntimeException('could not create hard-link fixture');
}
rememberKeyFixture($hardLink);
useKeyFixturePath($keyFile);
expectKeyStorageError(fn () => readMasterKey(), 'hard-linked key rejected');
if (!unlink($hardLink)) {
    throw new RuntimeException('could not remove hard-link fixture');
}

$fileSymlink = $fixtureRoot . '/file-link.key';
if (!symlink($keyFile, $fileSymlink)) {
    throw new RuntimeException('could not create file symlink fixture');
}
rememberKeyFixture($fileSymlink);
useKeyFixturePath($fileSymlink);
expectKeyStorageError(fn () => readMasterKey(), 'symlink key rejected');

$directorySymlink = $fixtureRoot . '/directory-link';
if (!symlink($keyDirectory, $directorySymlink)) {
    throw new RuntimeException('could not create ancestor symlink fixture');
}
rememberKeyFixture($directorySymlink);
useKeyFixturePath($directorySymlink . '/master.key');
expectKeyStorageError(fn () => readMasterKey(), 'symlink ancestor rejected');

$writableAncestor = makeKeyFixtureDirectory($fixtureRoot . '/writable-ancestor', 0777);
$privateChild = makeKeyFixtureDirectory($writableAncestor . '/private');
$nestedKey = makeKeyFixtureFile($privateChild . '/master.key', random_bytes(32));
useKeyFixturePath($nestedKey);
expectKeyStorageError(fn () => readMasterKey(), 'writable ancestor rejected');

$webRoot = makeKeyFixtureDirectory($fixtureRoot . '/web-root');
$webKeyDirectory = makeKeyFixtureDirectory($webRoot . '/private');
$webKey = makeKeyFixtureFile($webKeyDirectory . '/master.key', random_bytes(32));
$_SERVER['DOCUMENT_ROOT'] = $webRoot;
useKeyFixturePath($webKey);
expectKeyStorageError(fn () => readMasterKey(), 'document-root key rejected');
unset($_SERVER['DOCUMENT_ROOT']);

useKeyFixturePath(__FILE__);
expectKeyStorageError(fn () => readMasterKey(), 'plugin/engine-tree key rejected');

if (posix_geteuid() === 0 && function_exists('posix_getpwnam')) {
    $nobody = posix_getpwnam('nobody');
    if (is_array($nobody) && isset($nobody['uid'], $nobody['gid'])) {
        $ownerMismatchDirectory = makeKeyFixtureDirectory($fixtureRoot . '/owner-mismatch');
        $ownerMismatchKey = makeKeyFixtureFile($ownerMismatchDirectory . '/master.key', random_bytes(32));
        check(chown($ownerMismatchKey, (int) $nobody['uid']) && chgrp($ownerMismatchKey, (int) $nobody['gid']), 'make key owner mismatch fixture');
        useKeyFixturePath($ownerMismatchKey);
        expectKeyStorageError(fn () => readMasterKey(), 'key owner differing from private directory rejected');

        $serviceDirectory = makeKeyFixtureDirectory($fixtureRoot . '/trusted-service');
        $serviceKey = makeKeyFixtureFile($serviceDirectory . '/master.key', random_bytes(32));
        check(chown($serviceDirectory, (int) $nobody['uid']) && chgrp($serviceDirectory, (int) $nobody['gid']), 'make trusted service directory fixture');
        check(chown($serviceKey, (int) $nobody['uid']) && chgrp($serviceKey, (int) $nobody['gid']), 'make trusted service key fixture');
        useKeyFixturePath($serviceKey);
        check(strlen(readMasterKey()) === 32, 'root can read a key owned by its private trusted directory');
    }
}

echo "key crypto checks passed\n";
