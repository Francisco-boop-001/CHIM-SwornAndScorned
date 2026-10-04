<?php
declare(strict_types=1);

require __DIR__ . '/../support/check.php';
require __DIR__ . '/../../server/tables.php';
require __DIR__ . '/../../server/page_actions.php';
require __DIR__ . '/../../server/queue.php';
require __DIR__ . '/../../server/page_model.php';
require __DIR__ . '/../../server/page_view.php';

use ChimSwornAndScorned\KeyStorageError;
use function ChimSwornAndScorned\{ensureSchema, loadSettings, openEngineConnection, performPageAction, saveSetting, workerTick};

if (getenv('SAS_IT') !== '1') {
    echo "key storage integration skipped (set SAS_IT=1 inside the test clone)\n";
    exit(0);
}
check(getenv('WSL_DISTRO_NAME') === 'DwemerAI4Skyrim3-test', 'only disposable clone');
putenv('SAS_TEST_SCHEMA=sas_it');
$pg = openEngineConnection('/var/www/html/HerikaServer');
check($pg->one("SELECT to_regnamespace('sas_it') AS s")['s'] === null, 'fixture schema must be absent');
$pg->exec('CREATE SCHEMA sas_it');
$directory = sys_get_temp_dir() . '/sas-key-it-' . bin2hex(random_bytes(8));
check(mkdir($directory, 0700), 'private fixture directory');
$path = $directory . '/master.key';
file_put_contents($path, random_bytes(32));
chmod($path, 0600);
putenv('CHIM_SWORN_SCORNED_KEY_FILE=' . $path);
$key = 'sk-or-fixture-only-' . bin2hex(random_bytes(16));

try {
    ensureSchema($pg);
    $pg->exec('CREATE TABLE sas_it.core_npc_master (LIKE public.core_npc_master INCLUDING ALL)');
    $pg->exec('SET search_path=sas_it,public');
    saveSetting($pg, 'api_key', $key);
    $raw = $pg->one("SELECT value::text AS value FROM sas_it.sworn_scorned_settings WHERE key='api_key'")['value'];
    check(!str_contains($raw, $key) && is_array(json_decode($raw, true)), 'saving a key stores ciphertext, never plaintext');
    check(loadSettings($pg)['api_key'] === $key, 'worker can recover saved key');
    $first = $raw;
    saveSetting($pg, 'api_key', $key);
    $raw = $pg->one("SELECT value::text AS value FROM sas_it.sworn_scorned_settings WHERE key='api_key'")['value'];
    check($raw !== $first, 'saving twice uses a fresh nonce');

    // Insert a legacy value only in the disposable fixture, then exercise migration.
    $pg->exec("UPDATE sas_it.sworn_scorned_settings SET value=$1::jsonb WHERE key='api_key'", [json_encode($key)]);
    $pg->exec('CREATE FUNCTION sas_it.reject_key_update() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NULL; END $$');
    $pg->exec('CREATE TRIGGER reject_key BEFORE UPDATE ON sas_it.sworn_scorned_settings FOR EACH ROW EXECUTE FUNCTION sas_it.reject_key_update()');
    check(loadSettings($pg)['_key_error'] !== null && loadSettings($pg)['api_key'] === '', 'migration must persist successfully before plaintext is usable');
    $pg->exec('DROP TRIGGER reject_key ON sas_it.sworn_scorned_settings');
    $pg->exec('CREATE FUNCTION sas_it.fail_key_update() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'fixture write denied\'; END $$');
    $pg->exec('CREATE TRIGGER fail_key BEFORE UPDATE ON sas_it.sworn_scorned_settings FOR EACH ROW EXECUTE FUNCTION sas_it.fail_key_update()');
    $denied = loadSettings($pg);
    check($denied['_key_error'] !== null && $denied['api_key'] === '' && $denied['_key_present'], 'actual migration SQL error preserves safe dashboard recovery');
    $pg->exec('DROP TRIGGER fail_key ON sas_it.sworn_scorned_settings');
    check(loadSettings($pg)['api_key'] === $key, 'legacy plaintext migrates before use');
    $migrated = $pg->one("SELECT value::text AS value FROM sas_it.sworn_scorned_settings WHERE key='api_key'")['value'];
    check(!str_contains($migrated, $key) && is_array(json_decode($migrated, true)), 'legacy value replaced with ciphertext');
    check(loadSettings($pg)['api_key'] === $key, 'encrypted load is idempotent');
    check($migrated === $pg->one("SELECT value::text AS value FROM sas_it.sworn_scorned_settings WHERE key='api_key'")['value'], 'reads do not rotate ciphertext');

    rename($path, $path . '.backup');
    $failed = loadSettings($pg);
    check($failed['api_key'] === '' && $failed['_key_present'] && $failed['_key_error'] === 'key_storage_unavailable', 'missing master preserves recovery metadata and hides key');
    $model = ChimSwornAndScorned\buildPageModel($pg, '/var/www/html/HerikaServer', false);
    check($model['settings']['has_key'] && $model['settings']['key_error'], 'dashboard retains removal and recovery state');
    check(str_contains($model['backend']['status'], 'blocked'), 'dashboard does not announce fallback for an unreadable saved key');
    check(!str_contains(json_encode($model), $key) && !str_contains(json_encode($model), json_decode($migrated, true)['box']), 'page model excludes plaintext and encrypted credential payload');
    $model['key_submission_allowed'] = true;
    check(str_contains(ChimSwornAndScorned\renderPage($model, str_repeat('a', 64), null), 'value="clear_key"'), 'dashboard can render recovery without decrypting key');
    try {
        workerTick($pg, $failed, time(), static function (): never { throw new RuntimeException('provider must not be called'); });
        check(false, 'missing master must block evaluation');
    } catch (KeyStorageError) {
    }
    try {
        saveSetting($pg, 'api_key', $key);
        check(false, 'missing master must reject save');
    } catch (KeyStorageError) {
    }
    check($migrated === $pg->one("SELECT value::text AS value FROM sas_it.sworn_scorned_settings WHERE key='api_key'")['value'], 'failed save preserves ciphertext');
    check(performPageAction($pg, ['action' => 'clear_key'], '/nonexistent', '/nonexistent') !== '', 'clear works without a master key');
    check(loadSettings($pg)['_key_error'] === null && !loadSettings($pg)['_key_present'], 'clear restores ordinary fallback eligibility');
    $pg->exec("UPDATE sas_it.sworn_scorned_settings SET value=$1::jsonb WHERE key='api_key'", [json_encode($key)]);
    check(loadSettings($pg)['_key_error'] !== null, 'legacy key cannot be used without encrypting it first');
    check(str_contains($pg->one("SELECT value::text AS value FROM sas_it.sworn_scorned_settings WHERE key='api_key'")['value'], $key), 'failed legacy migration preserves recoverable value');
    rename($path . '.backup', $path);
    check(loadSettings($pg)['api_key'] === $key, 'restoring master allows migration');
    $raw = $pg->one("SELECT value::text AS value FROM sas_it.sworn_scorned_settings WHERE key='api_key'")['value'];
    $envelope = json_decode($raw, true);
    $box = base64_decode($envelope['box'], true);
    $box[0] = chr(ord($box[0]) ^ 1);
    $envelope['box'] = base64_encode($box);
    $pg->exec("UPDATE sas_it.sworn_scorned_settings SET value=$1::jsonb WHERE key='api_key'", [json_encode($envelope)]);
    check(loadSettings($pg)['_key_error'] !== null && loadSettings($pg)['api_key'] === '', 'tampered ciphertext is never used');
    saveSetting($pg, 'api_key', $key);
    check(loadSettings($pg)['api_key'] === $key, 'fresh save recovers corrupted storage');
} finally {
    $pg->exec('DROP SCHEMA sas_it CASCADE');
    if (is_file($path)) { unlink($path); }
    if (is_file($path . '.backup')) { unlink($path . '.backup'); }
    rmdir($directory);
    putenv('SAS_TEST_SCHEMA');
    putenv('CHIM_SWORN_SCORNED_KEY_FILE');
}
echo "key storage integration checks passed\n";
