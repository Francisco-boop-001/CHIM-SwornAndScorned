<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/support/written_fixture.php';
require __DIR__ . '/../server/substance.php';
require __DIR__ . '/../server/ledger.php';

use function ChimSwornAndScorned\{substanceCandidates, substanceContext, validateIssue, validateSubstance, validateSubstanceForLines, writtenLineFromEventRow};

$playthrough = ['id' => 'unprofiled', 'player_name' => 'Hawke'];
$writtenLineFor = static function (int $eventId, string $body) use ($playthrough): ?array {
    $row = writtenFixture($eventId, $body);
    $envelope = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
    $prefix = sprintf('%08x', $eventId);
    $envelope['document_id'] = $prefix . '-1111-4111-8111-111111111111';
    $envelope['receipt']['receipt_id'] = $prefix . '-2222-4222-8222-222222222222';
    $envelope['receipt']['operation_id'] = $prefix . '-3333-4333-8333-333333333333';
    $row['data'] = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return writtenLineFromEventRow($row, $playthrough);
};

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

$written2000 = $writtenLineFor(61, str_repeat('🙂', 2000));
check($written2000 !== null && mb_strlen($written2000['text'], 'UTF-8') === 2000, 'validated written source reaches the 2000-codepoint boundary');
check(array_keys(substanceCandidates([$written2000])) === ['L1'], 'validated written body above the speech limit is a substance candidate');
check(substanceContext([$written2000]) !== null, 'validated written body above the speech limit enters a new context');
$written2001Row = writtenFixture(62, str_repeat('🙂', 2001));
check(writtenLineFromEventRow($written2001Row, $playthrough) === null, 'written body above 2000 codepoints fails envelope validation');
$tamperedWritten = $written2000;
$tamperedWritten['text'] = str_repeat('🙂', 728);
$tamperedWritten['written']['body'] = 'different body';
check(substanceCandidates([$tamperedWritten]) === [], 'invalid written source cannot borrow the extended text allowance');
check(substanceContext([$tamperedWritten]) === null, 'changed written body cannot enter a new context');
$wrongSenderWritten = $written2000;
$wrongSenderWritten['speaker'] = 'Other sender';
check(substanceCandidates([$wrongSenderWritten]) === [], 'written allowance requires the validated claimed sender');
$unvalidatedWritten = $written2000;
$unvalidatedWritten['written']['receipt']['status'] = 'pending';
check(substanceCandidates([$unvalidatedWritten]) === [], 'unvalidated written envelope cannot borrow the extended text allowance');

$complete = [
    'version' => 1,
    'nature' => 'obligation',
    'matter' => ['ref' => 'e:41', 'speaker' => 'Farkas', 'text' => $longText],
    'settlement' => ['mode' => 'complete_obligation', 'source' => null],
];
check(validateSubstance($complete) === $complete, 'valid supported obligation substance round-trips');
check(validateSubstanceForLines($complete, $lines) === $complete, 'exact full source utterance validates against witnessed lines');
$conflictingDuplicate = sampleLine(41, 'Farkas', 'Different text for the same event ID.');
rejected(
    fn () => validateSubstanceForLines($complete, [$lines[0], $conflictingDuplicate]),
    'conflicting duplicate witnessed event IDs cannot validate a source'
);
$oversizedSource = $complete;
$oversizedSource['matter'] = ['ref' => 'e:42', 'speaker' => 'Lydia', 'text' => str_repeat('x', 640)];
rejected(
    fn () => validateSubstanceForLines($oversizedSource, $lines),
    'an oversized witnessed line cannot validate a referenced substance source'
);

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
$eightOrigin = array_slice($eightLines, 0, 8);
$eightContext = substanceContext($eightOrigin);
$nineContext = substanceContext($eightLines);
$eightPersisted = array_replace($splitSubstance, ['matter' => $eightContext[0], 'context' => $eightContext]);
check(validateSubstance($eightPersisted) === $eightPersisted, 'persisted context accepts eight capsules');
$ninePersisted = array_replace($splitSubstance, ['matter' => $nineContext[0] ?? $eightContext[0], 'context' => array_merge($eightContext, [
    ['ref' => 'e:60', 'speaker' => 'Aela', 'text' => 'tenth line'],
])]);
rejected(fn () => validateSubstance($ninePersisted), 'persisted context rejects a ninth capsule');

$written2000Context = substanceContext([$written2000]);
$writtenPersisted = [
    'version' => 1,
    'nature' => 'obligation',
    'matter' => $written2000Context[0],
    'settlement' => ['mode' => 'complete_obligation', 'source' => null],
    'context' => $written2000Context,
];
check(validateSubstance($writtenPersisted) === $writtenPersisted, 'persisted context accepts exact validated 2000-codepoint written body');
$wrongTypeWritten = $written2000;
$wrongTypeWritten['type'] = 'chat';
rejected(fn () => validateSubstanceForLines($writtenPersisted, [$wrongTypeWritten]), 'non-written source type cannot borrow the written allowance');
$forgedLongCapsule = $writtenPersisted;
$forgedLongCapsule['context'][0]['text'] = str_repeat('🙂', 728);
rejected(fn () => validateSubstance($forgedLongCapsule), 'persisted context rejects text changed from its validated written envelope');

$mixedExact = [
    $writtenLineFor(70, str_repeat('🙂', 2000)),
    $writtenLineFor(71, str_repeat('🧭', 2000)),
    sampleLine(72, 'Farkas', str_repeat('s', 640)),
    sampleLine(73, 'Lydia', str_repeat('t', 480)),
];
$mixedOverflow = $mixedExact;
$mixedOverflow[3] = sampleLine(73, 'Lydia', str_repeat('t', 481));
$mixedExactContext = substanceContext($mixedExact);
check($mixedExactContext !== null && array_sum(array_map(static fn (array $capsule): int => mb_strlen($capsule['text'], 'UTF-8'), $mixedExactContext)) === 5120, 'mixed written and spoken context accepts exact 5120-codepoint aggregate');
check(substanceContext($mixedOverflow) === null, 'mixed written and spoken context rejects aggregate 5121 without truncation');
$mixedPersisted = array_replace($splitSubstance, ['matter' => $mixedExactContext[0], 'context' => $mixedExactContext]);
check(validateSubstance($mixedPersisted) === $mixedPersisted, 'persisted mixed context accepts exact 5120-codepoint aggregate');
$mixedPersisted['context'][3]['text'] .= 'x';
rejected(fn () => validateSubstance($mixedPersisted), 'persisted mixed context rejects aggregate 5121');
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
