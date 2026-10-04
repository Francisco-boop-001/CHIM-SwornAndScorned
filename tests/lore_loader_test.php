<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/queue.php';

use function ChimSwornAndScorned\loadSeeds;

$root = sys_get_temp_dir() . '/sas-seeds-' . bin2hex(random_bytes(6));
mkdir($root . '/data', 0700, true);
$path = $root . '/data/sworn_and_scorned_lore.json';
try {
    $builtIn = loadSeeds(__DIR__ . '/../server', $root);
    check(count($builtIn) === 10, 'built-in seeds load without override');
    file_put_contents($path, '{bad json');
    check(loadSeeds(__DIR__ . '/../server', $root) === $builtIn, 'invalid JSON override cannot disable built-in seeds');
    file_put_contents($path, str_repeat('x', 262145));
    check(loadSeeds(__DIR__ . '/../server', $root) === $builtIn, 'oversize override ignored');
    $replacement = $builtIn[0];
    $replacement['wants'] = 'apology';
    file_put_contents($path, json_encode(['version' => 1, 'seeds' => [$replacement]]));
    $merged = loadSeeds(__DIR__ . '/../server', $root);
    check(count($merged) === 10 && $merged[0]['wants'] === 'apology', 'valid override replaces by id');
    rename($path, $path . '.target');
    symlink($path . '.target', $path);
    check(loadSeeds(__DIR__ . '/../server', $root) === $builtIn, 'symlink override ignored');
} finally {
    if (is_link($path) || is_file($path)) {
        unlink($path);
    }
    if (is_file($path . '.target')) {
        unlink($path . '.target');
    }
    rmdir($root . '/data');
    rmdir($root);
}
echo "lore loader checks passed\n";
