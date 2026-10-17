<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/substance.php';
require __DIR__ . '/../server/ledger.php';

use function ChimSwornAndScorned\{substanceCandidates, substanceContext, validateIssue, validateSubstance, validateSubstanceForLines};

$longText = str_repeat('The agreement names 50 septims, due tomorrow. ', 4);
$lines = [
    sampleLine(41, 'Farkas', $longText),
    sampleLine(42, 'Lydia', str_repeat('x', 641)),
    sampleLine(43, 'Lydia', 'Please repay the 50 septims tomorrow.'),
    sampleLine(44, str_repeat('S', 128), str_repeat('é', 640)),
];

$candidates = substanceCandidates($lines);
check(array_keys($candidates) === ['L1', 'L3', 'L4'], 'oversized utterance is omitted without renumbering later line labels');
check($candidates['L1'] === ['ref' => 'e:41', 'speaker' => 'Farkas', 'text' => $longText], 'candidate keeps the exact full utterance beyond the old 160-character quote cap');
check($candidates['L4']['text'] === str_repeat('é', 640) && mb_strlen($candidates['L4']['text']) === 640, 'candidate accepts the 640-character boundary and counts characters');
check($candidates['L4']['speaker'] === str_repeat('S', 128), 'candidate retains the 128-character speaker boundary');

$complete = [
    'version' => 1,
    'nature' => 'obligation',
    'matter' => ['ref' => 'e:41', 'speaker' => 'Farkas', 'text' => $longText],
    'settlement' => ['mode' => 'complete_obligation', 'source' => null],
];
check(validateSubstance($complete) === $complete, 'valid supported obligation substance round-trips');
check(validateSubstanceForLines($complete, $lines) === $complete, 'exact full source utterance validates against witnessed lines');

$explicit = [
    'version' => 1,
    'nature' => 'theft',
    'matter' => ['ref' => 'e:43', 'speaker' => 'Lydia', 'text' => 'Please repay the 50 septims tomorrow.'],
    'settlement' => ['mode' => 'explicit', 'source' => ['ref' => 'e:41', 'speaker' => 'Farkas', 'text' => $longText]],
];
check(validateSubstanceForLines($explicit, $lines) === $explicit, 'explicit settlement and matter sources validate independently');

$splitTermsLines = [
    sampleLine(51, 'Farkas', 'I owe you the iron sword'),
    sampleLine(52, 'Lydia', 'and will return it by sundown.'),
];
$splitContext = [
    ['ref' => 'e:51', 'speaker' => 'Farkas', 'text' => 'I owe you the iron sword'],
    ['ref' => 'e:52', 'speaker' => 'Lydia', 'text' => 'and will return it by sundown.'],
];
check(substanceContext($splitTermsLines) === $splitContext, 'context retains every witnessed utterance in order when terms span lines');
$splitSubstance = [
    'version' => 1,
    'nature' => 'obligation',
    'matter' => $splitContext[0],
    'settlement' => ['mode' => 'complete_obligation', 'source' => null],
    'context' => $splitContext,
];
check(validateSubstance($splitSubstance) === $splitSubstance, 'v1 accepts an optional exact context list');
check(validateSubstanceForLines($splitSubstance, $splitTermsLines) === $splitSubstance, 'context validates only when it retains the full witnessed batch');
$truncatedContext = $splitSubstance;
$truncatedContext['context'] = [$splitContext[0]];
rejected(fn () => validateSubstanceForLines($truncatedContext, $splitTermsLines), 'truncated context cannot discard terms from another witnessed line');
$forgedContext = $splitSubstance;
$forgedContext['context'][1]['text'] = 'and return the iron sword whenever.';
rejected(fn () => validateSubstanceForLines($forgedContext, $splitTermsLines), 'forged context utterance must match its exact witnessed line');
$sourceOutsideContext = $explicit;
$sourceOutsideContext['context'] = [$explicit['matter']];
rejected(fn () => validateSubstance($sourceOutsideContext), 'matter and settlement sources must be represented in context');
$eightLines = array_merge(array_slice($splitTermsLines, 0, 1), [sampleLine(53, 'Aela', 'third line'), sampleLine(54, 'Aela', 'fourth line'), sampleLine(55, 'Aela', 'fifth line'), sampleLine(56, 'Aela', 'sixth line'), sampleLine(57, 'Aela', 'seventh line'), sampleLine(58, 'Aela', 'eighth line'), sampleLine(59, 'Aela', 'ninth line')]);
check(count(substanceContext($eightLines) ?? []) === 8, 'context accepts the eight-line boundary');
check(substanceContext(array_merge($eightLines, [sampleLine(60, 'Aela', 'tenth line')])) === null, 'context exceeding eight witnessed lines abstains');
check(substanceContext([sampleLine(60, 'Aela', str_repeat('x', 641))]) === null, 'context abstains rather than clipping an oversized witnessed line');
rejected(fn () => validateSubstance(array_replace($splitSubstance, ['context' => []])), 'context must be a nonempty list');
rejected(fn () => validateSubstance(array_replace($splitSubstance, ['context' => [$splitContext[0], $splitContext[0]]])), 'context references must be unique');

$unknown = [
    'version' => 1,
    'nature' => 'unknown',
    'matter' => null,
    'settlement' => ['mode' => 'unknown', 'source' => null],
];
check(validateSubstance($unknown) === $unknown, 'unknown substance is representable for legacy data');

rejected(fn () => validateSubstance(array_replace($complete, ['version' => 2])), 'unsupported substance version is rejected');
rejected(fn () => validateSubstance(array_replace($complete, ['nature' => 'threat'])), 'nature enum is enforced');
rejected(fn () => validateSubstance(array_replace($complete, ['extra' => true])), 'substance shape rejects extra fields');
rejected(fn () => validateSubstance(array_replace($complete, ['matter' => array_replace($complete['matter'], ['extra' => true])])), 'capsule shape rejects extra fields');
rejected(fn () => validateSubstance(array_replace($complete, ['settlement' => ['mode' => 'complete_obligation', 'source' => null, 'extra' => true]])), 'settlement shape rejects extra fields');
rejected(fn () => validateSubstance(array_replace($complete, ['matter' => array_replace($complete['matter'], ['ref' => 'e:0'])])), 'capsule event references must be positive');
rejected(fn () => validateSubstance(array_replace($complete, ['matter' => array_replace($complete['matter'], ['text' => ''])])), 'capsule text cannot be empty');
rejected(fn () => validateSubstance(array_replace($complete, ['matter' => array_replace($complete['matter'], ['text' => str_repeat('x', 641)])])), 'capsule text cannot exceed 640 characters');
rejected(fn () => validateSubstance(array_replace($complete, ['matter' => array_replace($complete['matter'], ['speaker' => str_repeat('S', 129)])])), 'capsule speaker cannot exceed 128 characters');
rejected(fn () => validateSubstance(array_replace($complete, ['settlement' => ['mode' => 'invented', 'source' => null]])), 'settlement mode enum is enforced');
rejected(fn () => validateSubstance(array_replace($complete, ['settlement' => ['mode' => 'explicit', 'source' => null]])), 'explicit settlement requires a source capsule');
rejected(fn () => validateSubstance(['version' => 1, 'nature' => 'obligation', 'matter' => null, 'settlement' => ['mode' => 'complete_obligation', 'source' => null]]), 'complete obligation requires known matter');
rejected(fn () => validateSubstance(array_replace($unknown, ['settlement' => ['mode' => 'unknown', 'source' => ['ref' => 'e:41', 'speaker' => 'Farkas', 'text' => $longText]]])), 'unknown settlement cannot carry a source');
rejected(fn () => validateSubstance(array_replace($complete, ['settlement' => ['mode' => 'complete_obligation', 'source' => ['ref' => 'e:41', 'speaker' => 'Farkas', 'text' => $longText]]])), 'complete obligation cannot carry a settlement source');

$forgedText = $complete;
$forgedText['matter']['text'] = mb_substr($longText, 160);
rejected(fn () => validateSubstanceForLines($forgedText, $lines), 'a source substring cannot substitute for the exact utterance');
$forgedSpeaker = $complete;
$forgedSpeaker['matter']['speaker'] = 'Lydia';
rejected(fn () => validateSubstanceForLines($forgedSpeaker, $lines), 'capsule speaker must match the witnessed line');
$forgedRef = $complete;
$forgedRef['matter']['ref'] = 'e:999';
rejected(fn () => validateSubstanceForLines($forgedRef, $lines), 'capsule reference must identify a witnessed line');
$forgedSettlement = $explicit;
$forgedSettlement['settlement']['source']['text'] = 'A fabricated settlement condition.';
rejected(fn () => validateSubstanceForLines($forgedSettlement, $lines), 'settlement source must match its exact witnessed utterance');

$legacy = validateIssue(sampleIssue());
check(!array_key_exists('substance', $legacy), 'legacy issue keeps substance absent');
$legacyNull = validateIssue(sampleIssue(['substance' => null]));
check(array_key_exists('substance', $legacyNull) && $legacyNull['substance'] === null, 'explicit legacy null remains null');
$promise = sampleIssue([
    'kind' => 'promise',
    'direction' => 'owed_to_me',
    'strength' => 'will',
    'status' => 'pending',
    'substance' => $complete,
]);
check(validateIssue($promise)['substance'] === $complete, 'valid rich promise retains its substance');
rejected(fn () => validateIssue(sampleIssue(['substance' => $complete])), 'complete obligation settlement is restricted to promises');
rejected(fn () => validateIssue(sampleIssue(['substance' => ['version' => 1]])), 'malformed stored substance is rejected');

echo "substance checks passed\n";
