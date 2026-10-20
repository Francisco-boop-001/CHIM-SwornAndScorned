<?php
declare(strict_types=1);
require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/jobs.php';
require __DIR__ . '/../server/lore.php';
require __DIR__ . '/support/lore_pause_fixture.php';

use ChimSwornAndScorned\{Pg, PerspectiveStore};
use function ChimSwornAndScorned\{seedLoreOnce, validateSeedFile};

// Load the actual queue bytes with fake DB/store boundaries, never a copied loop.
$case = $argv[1] ?? 'owners';
check(in_array($case, ['owners', 'in_owner'], true), 'known lore pause case');
$root = sys_get_temp_dir() . '/sas-lore-pause-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
try {
    copy(__DIR__ . '/../server/queue.php', $root . '/queue.php');
    foreach (['tables', 'rows', 'written_source', 'batches', 'jobs', 'store', 'decisions_backend', 'llm_backend', 'llm_connector', 'logging', 'lore', 'hook'] as $name) {
        file_put_contents($root . '/' . $name . '.php', "<?php\n");
    }
    require $root . '/queue.php';
    $builtIn = validateSeedFile(json_decode(file_get_contents(__DIR__ . '/../server/lore_grudges.json'), true, 512, JSON_THROW_ON_ERROR));
    $seeds = [$builtIn[0], $builtIn[$case === 'in_owner' ? 1 : 2]];
    $pg = new Pg($seeds);
    $store = new PerspectiveStore($pg);
    $GLOBALS['sas_lore_paused'] = false;
    if ($case === 'in_owner') {
        $pg->pauseOnResolve = true;
        $store->pauseAfterFirst = false;
        check(seedLoreOnce($pg, $store, ['id' => 'pt-lore'], $seeds) === 1, 'current atomic owner may commit the seed resolved before pause');
        check($pg->resolved === [$seeds[0]['counterpart']], 'pause inside owner prevents all remaining counterpart SQL resolutions');
        check($store->commits === [11] && $pg->ledgers[11]['seeded'] === [$seeds[0]['id']], 'unresolved paused seeds stay pending in existing seed ids');
        $GLOBALS['sas_lore_paused'] = false;
        $pg->pauseOnResolve = false;
        check(seedLoreOnce($pg, $store, ['id' => 'pt-lore'], $seeds) === 1, 'healthy call resumes remaining seeds of the same owner');
        check($pg->resolved === [$seeds[0]['counterpart'], $seeds[1]['counterpart']], 'same owner resumption resolves only pending counterpart');
        check($store->commits === [11, 11] && $pg->ledgers[11]['seeded'] === array_column($seeds, 'id'), 'same owner seeded ids retain both completed transactions');
        check(seedLoreOnce($pg, $store, ['id' => 'pt-lore'], $seeds) === 0 && $store->commits === [11, 11], 'resumed same owner remains idempotent');
        echo "lore in-owner pause checks passed (actual queue/lore, scripted DB/store)\n";
    } else {
    $first = seedLoreOnce($pg, $store, ['id' => 'pt-lore'], $seeds);
    check($first === 1, 'pause after owner one returns only its committed count');
    check($store->commits === [11], 'core pause prevents another owner transaction');
    check($pg->resolved === [$seeds[0]['counterpart']], 'core pause prevents the next owner counterpart resolver');
    check($pg->ledgers[11]['seeded'] === [$seeds[0]['id']] && $pg->ledgers[12]['seeded'] === [], 'only the atomic completed owner receives seeded bookkeeping');
    $GLOBALS['sas_lore_paused'] = false;
    $store->pauseAfterFirst = false;
    $second = seedLoreOnce($pg, $store, ['id' => 'pt-lore'], $seeds);
    check($second === 1 && $store->commits === [11, 12], 'healthy invocation resumes only the unseeded owner');
    check($pg->resolved === [$seeds[0]['counterpart'], $seeds[1]['counterpart']], 'resumption does not reevaluate already seeded owner');
    check($pg->ledgers[11]['seeded'] === [$seeds[0]['id']] && $pg->ledgers[12]['seeded'] === [$seeds[1]['id']], 'existing seeded ids finish remaining work without offsets');
    check(seedLoreOnce($pg, $store, ['id' => 'pt-lore'], $seeds) === 0 && $store->commits === [11, 12], 'completed lore remains idempotent');
    echo "lore pause checks passed (actual queue/lore, scripted DB/store)\n";
    }
} finally {
    unset($GLOBALS['sas_lore_paused']);
    foreach (glob($root . '/*') as $path) unlink($path);
    rmdir($root);
}
