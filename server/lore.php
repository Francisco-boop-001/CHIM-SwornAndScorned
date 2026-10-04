<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

use UnexpectedValueException;

require_once __DIR__ . '/ledger.php';

const DAEDRA_NAMES = [
    'azura' => 'Azura', 'boethiah' => 'Boethiah', 'clavicus_vile' => 'Clavicus Vile',
    'hermaeus_mora' => 'Hermaeus Mora', 'hircine' => 'Hircine', 'jyggalag' => 'Jyggalag',
    'malacath' => 'Malacath', 'mehrunes_dagon' => 'Mehrunes Dagon', 'mephala' => 'Mephala',
    'meridia' => 'Meridia', 'molag_bal' => 'Molag Bal', 'namira' => 'Namira',
    'nocturnal' => 'Nocturnal', 'peryite' => 'Peryite', 'sanguine' => 'Sanguine',
    'sheogorath' => 'Sheogorath', 'vaermina' => 'Vaermina',
];
const LORE_SOURCE_PREFIX = 'https://en.uesp.net/wiki/Skyrim:';
const MAX_SEED_FILE_ENTRIES = 256;

/** @return list<array{id: string, owner: string, counterpart: string, wants: string, paraphrase: string, source: string}> */
function validateSeedFile(mixed $data): array
{
    if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['seeds'] ?? null)
        || !array_is_list($data['seeds']) || count($data) !== 2 || array_diff(array_keys($data), ['version', 'seeds']) !== []) {
        throw new UnexpectedValueException('seed_file_invalid');
    }
    if (count($data['seeds']) > MAX_SEED_FILE_ENTRIES) {
        throw new UnexpectedValueException('seed_file_too_large');
    }
    $seen = [];
    $seeds = [];
    foreach ($data['seeds'] as $seed) {
        $keys = ['id', 'owner', 'counterpart', 'wants', 'paraphrase', 'source'];
        if (!is_array($seed) || array_is_list($seed) || count($seed) !== count($keys) || array_diff(array_keys($seed), $keys) !== []) {
            throw new UnexpectedValueException('seed_invalid');
        }
        $id = $seed['id'] ?? null;
        if (!is_string($id) || preg_match('/\A[a-z][a-z0-9-]{2,79}\z/D', $id) !== 1 || isset($seen[$id])) {
            throw new UnexpectedValueException('seed_id_invalid');
        }
        $seen[$id] = true;
        foreach (['owner', 'counterpart', 'paraphrase', 'source'] as $key) {
            $value = $seed[$key] ?? null;
            if (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
                throw new UnexpectedValueException("seed_{$key}_invalid");
            }
        }
        $owner = trim($seed['owner']);
        $counterpart = trim($seed['counterpart']);
        if (mb_strlen($owner) > 128 || preg_match('/\A(?:npc:|player\z|daedra:)/i', $owner) === 1) {
            throw new UnexpectedValueException('seed_owner_invalid');
        }
        if (preg_match('/\Adaedra:/i', $counterpart) === 1) {
            if (!str_starts_with($counterpart, 'daedra:')) {
                throw new UnexpectedValueException('seed_counterpart_invalid');
            }
            $validCounterpart = array_key_exists(substr($counterpart, 7), DAEDRA_NAMES);
        } else {
            $validCounterpart = mb_strlen($counterpart) <= 128
                && !preg_match('/\A(?:npc:|player\z)/i', $counterpart)
                && mb_strtolower($counterpart) !== mb_strtolower($owner);
        }
        if (!$validCounterpart) {
            throw new UnexpectedValueException('seed_counterpart_invalid');
        }
        if (!is_string($seed['wants'] ?? null) || !in_array($seed['wants'], WANTS, true)) {
            throw new UnexpectedValueException('seed_wants_invalid');
        }
        $paraphrase = trim(preg_replace('/\s+/u', ' ', $seed['paraphrase']) ?? $seed['paraphrase']);
        if ($paraphrase === '' || mb_strlen($paraphrase) > MAX_QUOTE_CHARS) {
            throw new UnexpectedValueException('seed_paraphrase_invalid');
        }
        $source = trim($seed['source']);
        if (!validLoreSource($source)) {
            throw new UnexpectedValueException('seed_source_invalid');
        }
        $seeds[] = ['id' => $id, 'owner' => $owner, 'counterpart' => $counterpart, 'wants' => $seed['wants'], 'paraphrase' => $paraphrase, 'source' => $source];
    }
    return $seeds;
}

function validLoreSource(string $source): bool
{
    if (mb_strlen($source) > 2048 || !str_starts_with($source, LORE_SOURCE_PREFIX)) {
        return false;
    }
    $parts = parse_url($source);
    if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'en.uesp.net'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
        return false;
    }
    $path = $parts['path'] ?? '';
    return str_starts_with($path, '/wiki/Skyrim:') && strlen($path) > strlen('/wiki/Skyrim:')
        && preg_match('/\s/u', $source) !== 1;
}

/** User overrides replace built-in seeds with the same id. */
function mergeSeeds(array $builtIn, array $override): array
{
    $byId = [];
    foreach ([...$builtIn, ...$override] as $seed) {
        $byId[$seed['id']] = $seed;
    }
    return array_values($byId);
}

/**
 * Add this owner's not-yet-applied seeds as open lore grievances. Never changes affinity.
 * @param callable(string $counterpart): ?string $resolve counterpart text -> key, or null while unknown
 */
function applyLoreSeeds(array $ledger, array $seeds, callable $resolve): array
{
    $changed = false;
    foreach ($seeds as $seed) {
        if (in_array($seed['id'], $ledger['seeded'], true)) {
            continue;
        }
        if (count($ledger['seeded']) >= MAX_SEEDED) {
            break;
        }
        $key = $resolve($seed['counterpart']);
        if ($key === null) {
            continue;
        }
        if (!is_string($key) || preg_match('/\A(?:npc:[1-9][0-9]*|daedra:[a-z][a-z_]*)\z/D', $key) !== 1
            || (str_starts_with($key, 'daedra:') && !array_key_exists(substr($key, 7), DAEDRA_NAMES))) {
            throw new UnexpectedValueException('seed_counterpart_resolution_invalid');
        }
        $daedraSeed = str_starts_with($seed['counterpart'], 'daedra:');
        if (($daedraSeed && $key !== $seed['counterpart']) || (!$daedraSeed && !str_starts_with($key, 'npc:'))) {
            throw new UnexpectedValueException('seed_counterpart_resolution_invalid');
        }
        if (count(array_filter($ledger['issues'], isOpen(...))) >= MAX_OPEN_ISSUES) {
            break;
        }
        $ledger['issues'][] = [
            'id' => issueId('lore:' . $seed['id'], $key, 'grievance'),
            'origin' => 'lore',
            'kind' => 'grievance',
            'counterpart' => $key,
            'direction' => null,
            'strength' => null,
            'status' => 'open',
            'trajectory' => 'unchanged',
            'wants' => $seed['wants'],
            'evidence' => [[
                'ref' => $seed['source'], 'gamets' => null, 'speaker' => 'lore', 'quote' => $seed['paraphrase'],
                'label' => 'lore', 'p' => null, 'backend' => 'lore',
            ]],
            'links' => [],
            'affinity_applied' => [],
            'muted' => false,
            'dismissed' => false,
            'changed_event_id' => 0,
        ];
        $ledger['seeded'][] = $seed['id'];
        $changed = true;
    }
    return ['status' => $changed ? 'applied' : 'unchanged', 'ledger' => $ledger, 'affinity_delta' => 0];
}
