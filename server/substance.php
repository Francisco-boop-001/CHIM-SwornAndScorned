<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

use UnexpectedValueException;

require_once __DIR__ . '/written_evidence.php';

const SUBSTANCE_NATURES = ['obligation', 'insult', 'theft', 'betrayal', 'broken_agreement', 'harm', 'unknown'];
const SUBSTANCE_SETTLEMENT_MODES = ['explicit', 'complete_obligation', 'unknown'];

function substanceCandidates(array $lines): array
{
    if (!array_is_list($lines)) {
        throw new UnexpectedValueException('substance_lines_invalid');
    }
    $candidates = [];
    foreach ($lines as $position => $line) {
        if (!is_array($line) || !is_int($line['event_id'] ?? null) || $line['event_id'] <= 0
            || !is_string($line['speaker'] ?? null) || $line['speaker'] === '' || !mb_check_encoding($line['speaker'], 'UTF-8') || mb_strlen($line['speaker'], 'UTF-8') > 128
            || !is_string($line['text'] ?? null) || $line['text'] === '' || !mb_check_encoding($line['text'], 'UTF-8')) {
            continue;
        }
        $limit = substanceLineTextLimit($line);
        if ($limit === null || mb_strlen($line['text'], 'UTF-8') > $limit) continue;
        $candidates['L' . ($position + 1)] = capsuleFromLine($line);
    }
    return $candidates;
}

// shortcut: new records require a full origin exchange within eight bounded lines; use typed relevant-source selection if larger exchanges need tracking.
function substanceContext(array $lines): ?array
{
    if (!array_is_list($lines) || $lines === [] || count($lines) > MAX_ORIGIN_CAPSULES) {
        return null;
    }
    $context = [];
    $refs = [];
    $codepoints = 0;
    foreach ($lines as $line) {
        if (!is_array($line) || !is_int($line['event_id'] ?? null) || $line['event_id'] <= 0
            || !is_string($line['speaker'] ?? null) || $line['speaker'] === '' || !mb_check_encoding($line['speaker'], 'UTF-8') || mb_strlen($line['speaker'], 'UTF-8') > 128
            || !is_string($line['text'] ?? null) || $line['text'] === '' || !mb_check_encoding($line['text'], 'UTF-8')) {
            return null;
        }
        $limit = substanceLineTextLimit($line);
        if ($limit === null) return null;
        $lineCodepoints = mb_strlen($line['text'], 'UTF-8');
        if ($lineCodepoints > $limit || ($codepoints += $lineCodepoints) > MAX_ORIGIN_CODEPOINTS) return null;
        $ref = 'e:' . $line['event_id'];
        if (isset($refs[$ref])) {
            return null;
        }
        $refs[$ref] = true;
        $context[] = capsuleFromLine($line);
    }
    return $context;
}

function validateSubstance(mixed $substance): ?array
{
    if ($substance === null) {
        return null;
    }
    $hasContext = is_array($substance) && array_key_exists('context', $substance);
    if (!is_array($substance)
        || (!$hasContext && !hasExactKeys($substance, ['version', 'nature', 'matter', 'settlement']))
        || ($hasContext && !hasExactKeys($substance, ['version', 'nature', 'matter', 'settlement', 'context']))
        || $substance['version'] !== 1
        || !is_string($substance['nature']) || !in_array($substance['nature'], SUBSTANCE_NATURES, true)) {
        throw new UnexpectedValueException('issue_substance_invalid');
    }
    $matter = $substance['matter'] === null ? null : validateSubstanceCapsule($substance['matter']);
    $settlement = $substance['settlement'];
    if (!is_array($settlement) || !hasExactKeys($settlement, ['mode', 'source']) || !is_string($settlement['mode'])
        || !in_array($settlement['mode'], SUBSTANCE_SETTLEMENT_MODES, true)) {
        throw new UnexpectedValueException('issue_substance_invalid');
    }
    $source = $settlement['source'] === null ? null : validateSubstanceCapsule($settlement['source']);
    if (($settlement['mode'] === 'explicit' && $source === null)
        || ($settlement['mode'] === 'complete_obligation' && ($matter === null || $source !== null))
        || ($settlement['mode'] === 'unknown' && $source !== null)) {
        throw new UnexpectedValueException('issue_substance_invalid');
    }
    $normalized = [
        'version' => 1,
        'nature' => $substance['nature'],
        'matter' => $matter,
        'settlement' => ['mode' => $settlement['mode'], 'source' => $source],
    ];
    if ($hasContext) {
        $context = $substance['context'];
        if (!is_array($context) || !array_is_list($context) || $context === [] || count($context) > MAX_ORIGIN_CAPSULES) {
            throw new UnexpectedValueException('issue_substance_invalid');
        }
        $normalizedContext = [];
        $refs = [];
        $originCodepoints = 0;
        foreach ($context as $capsule) {
            $capsule = validateSubstanceCapsule($capsule);
            if (isset($refs[$capsule['ref']])) {
                throw new UnexpectedValueException('issue_substance_invalid');
            }
            $originCodepoints += mb_strlen($capsule['text'], 'UTF-8');
            if ($originCodepoints > MAX_ORIGIN_CODEPOINTS) throw new UnexpectedValueException('issue_substance_invalid');
            $refs[$capsule['ref']] = true;
            $normalizedContext[] = $capsule;
        }
        foreach ([$matter, $source] as $capsule) {
            if ($capsule !== null && !in_array($capsule, $normalizedContext, true)) {
                throw new UnexpectedValueException('issue_substance_invalid');
            }
        }
        $normalized['context'] = $normalizedContext;
    }
    return $normalized;
}

function validateSubstanceForLines(mixed $substance, array $lines): ?array
{
    $substance = validateSubstance($substance);
    if ($substance === null) {
        return null;
    }
    if (!array_is_list($lines)) {
        throw new UnexpectedValueException('substance_lines_invalid');
    }
    $byRef = [];
    $sourceRefs = [];
    foreach ([$substance['matter'], $substance['settlement']['source']] as $capsule) {
        if ($capsule !== null) $sourceRefs[] = $capsule['ref'];
    }
    foreach ($lines as $line) {
        if (!is_array($line) || !is_int($line['event_id'] ?? null) || $line['event_id'] <= 0
            || !is_string($line['speaker'] ?? null) || !is_string($line['text'] ?? null)) {
            throw new UnexpectedValueException('substance_lines_invalid');
        }
        $ref = 'e:' . $line['event_id'];
        try {
            $entry = capsuleFromLine($line);
        } catch (UnexpectedValueException $exception) {
            if (in_array($ref, $sourceRefs, true) || array_key_exists('context', $substance)) throw $exception;
            continue;
        }
        if (isset($byRef[$ref]) && $byRef[$ref] !== $entry) {
            throw new UnexpectedValueException('substance_lines_invalid');
        }
        $byRef[$ref] = $entry;
    }
    foreach ([$substance['matter'], $substance['settlement']['source']] as $capsule) {
        if ($capsule === null) {
            continue;
        }
        $line = $byRef[$capsule['ref']] ?? null;
        if ($line === null || $line !== $capsule) {
            throw new UnexpectedValueException('substance_source_invalid');
        }
    }
    if (array_key_exists('context', $substance)) {
        $expectedContext = substanceContext($lines);
        if ($expectedContext === null || $expectedContext !== $substance['context']) {
            throw new UnexpectedValueException('substance_source_invalid');
        }
    }
    return $substance;
}

function validateSubstanceCapsule(mixed $capsule): array
{
    $hasWritten = is_array($capsule) && array_key_exists('written', $capsule);
    if (!is_array($capsule) || !hasExactKeys($capsule, $hasWritten ? ['ref', 'speaker', 'text', 'written'] : ['ref', 'speaker', 'text'])
        || !is_string($capsule['ref']) || strlen($capsule['ref']) > 21 || preg_match('/\Ae:[1-9][0-9]*\z/D', $capsule['ref']) !== 1
        || !is_string($capsule['speaker']) || $capsule['speaker'] === '' || !mb_check_encoding($capsule['speaker'], 'UTF-8') || mb_strlen($capsule['speaker'], 'UTF-8') > 128
        || !is_string($capsule['text']) || $capsule['text'] === '' || !mb_check_encoding($capsule['text'], 'UTF-8')) {
        throw new UnexpectedValueException('issue_substance_capsule_invalid');
    }
    $normalized = ['ref' => $capsule['ref'], 'speaker' => $capsule['speaker'], 'text' => $capsule['text']];
    if ($hasWritten) {
        $w = persistedWrittenSource($capsule['written']);
        if ($w['body'] !== $capsule['text'] || $w['claimed_sender']['name'] !== $capsule['speaker']
            || mb_strlen($capsule['text'], 'UTF-8') > MAX_WRITTEN_SUBSTANCE_CODEPOINTS) {
            throw new UnexpectedValueException('issue_substance_capsule_invalid');
        }
        $normalized['written'] = $w;
    } elseif (mb_strlen($capsule['text'], 'UTF-8') > MAX_SPOKEN_SUBSTANCE_CODEPOINTS) {
        throw new UnexpectedValueException('issue_substance_capsule_invalid');
    }
    return $normalized;
}

/** Return a written allowance only after its marker, envelope, body, and claimed sender agree. */
function substanceLineTextLimit(array $line): ?int
{
    if (!array_key_exists('written', $line)) return MAX_SPOKEN_SUBSTANCE_CODEPOINTS;
    if (($line['type'] ?? null) !== 'written_evidence') return null;
    try {
        $written = persistedWrittenSource($line['written']);
    } catch (\Throwable) {
        return null;
    }
    if ($written['body'] !== ($line['text'] ?? null) || $written['claimed_sender']['name'] !== ($line['speaker'] ?? null)) return null;
    return MAX_WRITTEN_SUBSTANCE_CODEPOINTS;
}

function hasExactKeys(array $value, array $keys): bool
{
    return count($value) === count($keys) && array_diff($keys, array_keys($value)) === [];
}

function capsuleFromLine(array $line): array
{
    if (!is_int($line['event_id'] ?? null) || $line['event_id'] <= 0
        || !is_string($line['speaker'] ?? null) || $line['speaker'] === '' || !mb_check_encoding($line['speaker'], 'UTF-8') || mb_strlen($line['speaker'], 'UTF-8') > 128
        || !is_string($line['text'] ?? null) || $line['text'] === '' || !mb_check_encoding($line['text'], 'UTF-8')) {
        throw new UnexpectedValueException('substance_lines_invalid');
    }
    $limit = substanceLineTextLimit($line);
    if ($limit === null || mb_strlen($line['text'], 'UTF-8') > $limit) throw new UnexpectedValueException('substance_lines_invalid');
    $capsule = ['ref'=>'e:'.$line['event_id'],'speaker'=>$line['speaker'],'text'=>$line['text']];
    if (isset($line['written'])) $capsule['written'] = persistedWrittenSource($line['written']);
    return $capsule;
}

function persistedWrittenSource(mixed $w): array
{
    if (!is_array($w)) throw new UnexpectedValueException('written_source_invalid');
    $pt = ['id'=>$w['playthrough_id'] ?? null,'player_name'=>($w['claimed_sender']['kind'] ?? null) === 'player' ? ($w['claimed_sender']['name'] ?? '') : 'Player'];
    $valid = validateWrittenEnvelope($w, $pt);
    if ($valid === null) throw new UnexpectedValueException('written_source_invalid');
    return $valid;
}
