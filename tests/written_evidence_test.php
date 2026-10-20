<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/../server/rows.php';
require __DIR__ . '/../server/written_evidence.php';

use function ChimSwornAndScorned\{
    lineFromEventRow,
    validateWrittenEnvelope,
    writtenActorKey,
    writtenEvidenceCapability,
    writtenLineFromEventRow,
};

$playthrough = ['id' => 'unprofiled', 'player_name' => 'Hawke'];
$body = "  Camilla,\r\nI enclose 50 septims. Please hear Faendal fairly.\nSigned, Jarl Balgruuf — café 🌙\n";
$envelope = [
    'schema_version' => 1,
    'document_id' => '6c4dd05e-909c-4e93-8a11-7f72181a2df4',
    'playthrough_id' => 'unprofiled',
    'recipient' => ['kind' => 'npc', 'id' => 900001, 'name' => 'Camilla'],
    'claimed_sender' => ['kind' => 'npc', 'id' => 900002, 'name' => 'Jarl Balgruuf'],
    'body' => $body,
    'receipt' => [
        'receipt_id' => '914c2b35-7147-44f8-8c91-ae8745b11755',
        'operation_id' => 'b98aec36-775d-4f41-aa71-90a753cff653',
        'status' => 'confirmed',
        'exposure' => 'context',
    ],
    'attachments' => [['kind' => 'gold', 'form_id' => 'Skyrim.esm|0000000F', 'quantity' => 50]],
];

$rowFor = static fn (array $payload, array $overrides = []): array => array_replace([
    'rowid' => '41',
    'type' => 'info',
    'sess' => 'written_evidence_v1',
    'people' => '|Camilla|',
    'gamets' => '292500100',
    'localts' => '1790000000',
    'data' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
], $overrides);

$parse = static fn (array $payload, array $overrides = []): ?array =>
    writtenLineFromEventRow($rowFor($payload, $overrides), $playthrough);

$rejectEnvelope = static function (array $candidate, string $message) use ($playthrough, $rowFor): void {
    check(validateWrittenEnvelope($candidate, $playthrough) === null, $message . ' validator');
    check(
        writtenLineFromEventRow($rowFor($candidate), $playthrough) === null,
        $message . ' event row'
    );
};

check(validateWrittenEnvelope($envelope, $playthrough) === $envelope, 'valid envelope passes through unchanged');
$reorderedEnvelope = array_reverse($envelope, true);
$reorderedEnvelope['recipient'] = array_reverse($envelope['recipient'], true);
$reorderedEnvelope['claimed_sender'] = array_reverse($envelope['claimed_sender'], true);
$reorderedEnvelope['receipt'] = array_reverse($envelope['receipt'], true);
$reorderedEnvelope['attachments'][0] = array_reverse($envelope['attachments'][0], true);
check(
    validateWrittenEnvelope($reorderedEnvelope, $playthrough) === $envelope,
    'valid envelope with reordered object keys returns canonical key order'
);
$reorderedLine = $parse($reorderedEnvelope);
check(
    $reorderedLine !== null && $reorderedLine['written'] === $envelope,
    'event row parser canonicalizes nested JSON object key order'
);
$line = $parse($envelope);
check($line === [
    'event_id' => 41,
    'gamets' => 292500100.0,
    'type' => 'written_evidence',
    'speaker' => 'Jarl Balgruuf',
    'listener' => 'Camilla',
    'people' => ['Camilla'],
    'text' => $body,
    'localts' => 1790000000,
    'written' => $envelope,
], 'written line preserves exact Unicode, newlines, attribution and envelope');
check(writtenActorKey($envelope['recipient']) === 'npc:900001', 'NPC actor key');
check(writtenActorKey($envelope['claimed_sender']) === 'npc:900002', 'claimed sender actor key');
check(
    lineFromEventRow($rowFor($envelope), 'Hawke') === null,
    'ordinary speech parser still rejects written evidence'
);

$capability = writtenEvidenceCapability();
check(
    ($capability['version'] ?? null) === 1
    && ($capability['input_type'] ?? null) === 'written_evidence'
    && ($capability['source_event_type'] ?? null) === 'info'
    && ($capability['source_marker'] ?? null) === 'written_evidence_v1'
    && ($capability['max_body_codepoints'] ?? null) === 2000
    && ($capability['max_substance_codepoints'] ?? null) === 640
    && ($capability['max_written_substance_codepoints'] ?? null) === 2000
    && ($capability['max_origin_codepoints'] ?? null) === 5120
    && ($capability['recipient_only'] ?? null) === true,
    'written evidence capability descriptor'
);

$playerSender = $envelope;
$playerSender['claimed_sender'] = ['kind' => 'player', 'id' => null, 'name' => 'Hawke'];
$playerLine = $parse($playerSender);
check(
    $playerLine !== null
    && $playerLine['speaker'] === 'Hawke'
    && $playerLine['text'] === $body
    && writtenActorKey($playerSender['claimed_sender']) === 'player',
    'player may be the explicitly claimed sender'
);

$playerRecipient = $envelope;
$playerRecipient['recipient'] = ['kind' => 'player', 'id' => null, 'name' => 'Hawke'];
$rejectEnvelope($playerRecipient, 'player recipient abstains');
$selfSender = $envelope;
$selfSender['claimed_sender'] = $selfSender['recipient'];
$rejectEnvelope($selfSender, 'same NPC cannot send to itself');
$sameActorDifferentLabel = $envelope;
$sameActorDifferentLabel['claimed_sender'] = ['kind' => 'npc', 'id' => 900001, 'name' => 'Another Name'];
$rejectEnvelope($sameActorDifferentLabel, 'same actor ID cannot be relabeled to evade self-sender rejection');

$validZeroVersionUuid = $envelope;
$validZeroVersionUuid['document_id'] = '00000000-0000-0000-0000-000000000001';
check(
    validateWrittenEnvelope($validZeroVersionUuid, $playthrough) === $validZeroVersionUuid,
    'canonical UUID syntax does not require a particular version or variant'
);

$unknownTopLevel = $envelope;
$unknownTopLevel['actual_author_id'] = 900003;
$rejectEnvelope($unknownTopLevel, 'unknown private top-level field');
foreach (array_keys($envelope) as $key) {
    $bad = $envelope;
    unset($bad[$key]);
    $rejectEnvelope($bad, 'missing top-level field ' . $key);
}
$unknownActor = $envelope;
$unknownActor['claimed_sender']['private_author'] = 900003;
$rejectEnvelope($unknownActor, 'unknown actor field');
foreach (['kind', 'id', 'name'] as $key) {
    $bad = $envelope;
    unset($bad['recipient'][$key]);
    $rejectEnvelope($bad, 'missing recipient field ' . $key);
    $bad = $envelope;
    unset($bad['claimed_sender'][$key]);
    $rejectEnvelope($bad, 'missing claimed sender field ' . $key);
}
$unknownReceipt = $envelope;
$unknownReceipt['receipt']['payer_id'] = 900004;
$rejectEnvelope($unknownReceipt, 'unknown receipt field');
foreach (['receipt_id', 'operation_id', 'status', 'exposure'] as $key) {
    $bad = $envelope;
    unset($bad['receipt'][$key]);
    $rejectEnvelope($bad, 'missing receipt field ' . $key);
}
$unknownAttachment = $envelope;
$unknownAttachment['attachments'][0]['private_path'] = 'secret';
$rejectEnvelope($unknownAttachment, 'unknown attachment field');
foreach (['kind', 'form_id', 'quantity'] as $key) {
    $bad = $envelope;
    unset($bad['attachments'][0][$key]);
    $rejectEnvelope($bad, 'missing attachment field ' . $key);
}

foreach ([
    null,
    'not an envelope',
    1,
    true,
    new stdClass(),
    [],
] as $notAnEnvelope) {
    check(validateWrittenEnvelope($notAnEnvelope, $playthrough) === null, 'non-envelope value rejected');
}

$bad = $envelope;
$bad['schema_version'] = '1';
$rejectEnvelope($bad, 'schema version must be an integer');
$bad = $envelope;
$bad['schema_version'] = 1.0;
$rejectEnvelope($bad, 'floating schema version rejected');
$bad = $envelope;
$bad['document_id'] = strtoupper($bad['document_id']);
$rejectEnvelope($bad, 'uppercase UUID rejected');
$bad = $envelope;
$bad['receipt']['receipt_id'] = '{914c2b35-7147-44f8-8c91-ae8745b11755}';
$rejectEnvelope($bad, 'noncanonical receipt UUID rejected');
$bad = $envelope;
$bad['receipt']['operation_id'] = '914c2b35-7147-44f8-8c91-ae8745b1175z';
$rejectEnvelope($bad, 'malformed operation UUID rejected');

foreach (['delivered', 'failed', 'unknown', 'pending'] as $status) {
    $bad = $envelope;
    $bad['receipt']['status'] = $status;
    $rejectEnvelope($bad, 'receipt status ' . $status . ' rejected');
}
foreach (['possession', 'spoken', 'none', ''] as $exposure) {
    $bad = $envelope;
    $bad['receipt']['exposure'] = $exposure;
    $rejectEnvelope($bad, 'receipt exposure ' . $exposure . ' rejected');
}
foreach (['context', 'read'] as $exposure) {
    $receiptEnvelope = $envelope;
    $receiptEnvelope['receipt']['exposure'] = $exposure;
    check(validateWrittenEnvelope($receiptEnvelope, $playthrough) !== null, 'valid receipt exposure ' . $exposure);
}

foreach (['', '0', '00', '01', '+1', '-1', '1.0', 'Unprofiled', 1] as $scope) {
    $badScope = $envelope;
    $badScope['playthrough_id'] = $scope;
    $active = ['id' => $scope, 'player_name' => 'Hawke'];
    check(validateWrittenEnvelope($badScope, $active) === null, 'invalid playthrough scope rejected');
}
foreach (['unprofiled', '1', '42'] as $scope) {
    $scoped = $envelope;
    $scoped['playthrough_id'] = $scope;
    $active = ['id' => $scope, 'player_name' => 'Hawke'];
    check(validateWrittenEnvelope($scoped, $active) === $scoped, 'canonical playthrough scope accepted');
}
$largestScope = $envelope;
$largestScope['playthrough_id'] = '9223372036854775807';
check(
    validateWrittenEnvelope($largestScope, ['id' => $largestScope['playthrough_id'], 'player_name' => 'Hawke']) === $largestScope,
    '19-digit playthrough scope remains accepted'
);
$oversizedScope = $envelope;
$oversizedScope['playthrough_id'] = '19223372036854775807';
check(
    validateWrittenEnvelope($oversizedScope, ['id' => $oversizedScope['playthrough_id'], 'player_name' => 'Hawke']) === null,
    'playthrough scope above the signed database ID bound is rejected'
);
$wrongScope = $envelope;
$wrongScope['playthrough_id'] = '12';
check(
    validateWrittenEnvelope($wrongScope, ['id' => '13', 'player_name' => 'Hawke']) === null
    && writtenLineFromEventRow($rowFor($wrongScope), ['id' => '13', 'player_name' => 'Hawke']) === null,
    'envelope from another active playthrough rejected'
);

foreach ([
    ['kind' => 'npc', 'id' => '900002', 'name' => 'Jarl Balgruuf'],
    ['kind' => 'npc', 'id' => 0, 'name' => 'Jarl Balgruuf'],
    ['kind' => 'npc', 'id' => -1, 'name' => 'Jarl Balgruuf'],
    ['kind' => 'npc', 'id' => 900002.0, 'name' => 'Jarl Balgruuf'],
    ['kind' => 'other', 'id' => 900002, 'name' => 'Jarl Balgruuf'],
    ['kind' => 'player', 'id' => 900002, 'name' => 'Hawke'],
    ['kind' => 'player', 'id' => null, 'name' => 'hawke'],
    ['kind' => 'player', 'id' => null, 'name' => ' Hawke'],
    ['kind' => 'player', 'id' => null, 'name' => 'Hawke|Other'],
    ['kind' => 'npc', 'id' => 900002, 'name' => 10],
] as $sender) {
    $bad = $envelope;
    $bad['claimed_sender'] = $sender;
    $rejectEnvelope($bad, 'malformed claimed sender rejected');
}
foreach ([
    ['kind' => 'npc', 'id' => 0, 'name' => 'Camilla'],
    ['kind' => 'npc', 'id' => 900001, 'name' => ' Camilla'],
    ['kind' => 'npc', 'id' => 900001, 'name' => 'Camilla '],
    ['kind' => 'npc', 'id' => 900001, 'name' => "Camilla\nOther"],
    ['kind' => 'npc', 'id' => 900001, 'name' => 'Camilla|Other'],
    ['kind' => 'npc', 'id' => 900001, 'name' => str_repeat('N', 129)],
    ['kind' => 'npc', 'id' => 900001, 'name' => 10],
    ['kind' => 'other', 'id' => 900001, 'name' => 'Camilla'],
    ['kind' => 'player', 'id' => null, 'name' => 'Hawke'],
] as $recipient) {
    $bad = $envelope;
    $bad['recipient'] = $recipient;
    $rejectEnvelope($bad, 'malformed recipient rejected');
}
$badPlayerSender = $playerSender;
$badPlayerSender['claimed_sender']['name'] = 'Other Player';
$rejectEnvelope($badPlayerSender, 'claimed player must match the current canonical Player name');
$badPlayerId = $playerSender;
$badPlayerId['claimed_sender']['id'] = 1;
$rejectEnvelope($badPlayerId, 'player actor ID must be null');

$emptyAttachments = $envelope;
$emptyAttachments['attachments'] = [];
check(validateWrittenEnvelope($emptyAttachments, $playthrough) !== null, 'empty attachment list accepted');
$eightAttachments = $envelope;
$eightAttachments['attachments'] = [];
for ($i = 1; $i <= 8; $i++) {
    $eightAttachments['attachments'][] = [
        'kind' => 'item',
        'form_id' => sprintf('Example.esp|%08X', $i),
        'quantity' => $i,
    ];
}
check(validateWrittenEnvelope($eightAttachments, $playthrough) !== null, 'eight distinct attachments accepted');
$maxMetadataEnvelope = $eightAttachments;
$maxMetadataEnvelope['body'] = str_repeat('🙂', 2000);
$maxMetadataEnvelope['recipient']['name'] = str_repeat('R', 128);
$maxMetadataEnvelope['claimed_sender']['name'] = str_repeat('S', 128);
for ($i = 1; $i <= 8; $i++) {
    $maxMetadataEnvelope['attachments'][$i - 1]['form_id'] =
        chr(ord('A') + $i - 1) . str_repeat('A', 250) . '.esp|' . sprintf('%08X', $i);
}
$maxMetadataJson = json_encode(
    $maxMetadataEnvelope,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
);
check(
    strlen($maxMetadataEnvelope['attachments'][0]['form_id']) === 264
    && strlen($maxMetadataJson) > 8000
    && validateWrittenEnvelope($maxMetadataEnvelope, $playthrough) === $maxMetadataEnvelope,
    '2000-codepoint body remains valid with bounded maximum-name and eight maximum-length attachments'
);
$tooManyAttachments = $eightAttachments;
$tooManyAttachments['attachments'][] = ['kind' => 'item', 'form_id' => 'Other.esp|00000001', 'quantity' => 1];
$rejectEnvelope($tooManyAttachments, 'attachment count above eight rejected');

$duplicateAttachments = $envelope;
$duplicateAttachments['attachments'][] = ['kind' => 'item', 'form_id' => 'Skyrim.esm|0000000F', 'quantity' => 1];
$rejectEnvelope($duplicateAttachments, 'duplicate attachment form ID rejected');
foreach ([
    ['kind' => 'gold', 'form_id' => 'Skyrim.esm|0000000F', 'quantity' => 0],
    ['kind' => 'gold', 'form_id' => 'Skyrim.esm|0000000F', 'quantity' => -1],
    ['kind' => 'gold', 'form_id' => 'Skyrim.esm|0000000F', 'quantity' => 2147483648],
    ['kind' => 'gold', 'form_id' => 'Skyrim.esm|0000000F', 'quantity' => '50'],
    ['kind' => 'gold', 'form_id' => 'Skyrim.esm|0000000F', 'quantity' => 50.0],
    ['kind' => 'gold', 'form_id' => 'Skyrim.esm|0000000F', 'quantity' => true],
    ['kind' => 'gold', 'form_id' => 'Example.esp|00000001', 'quantity' => 50],
    ['kind' => 'unknown', 'form_id' => 'Example.esp|00000001', 'quantity' => 1],
    ['kind' => 'item', 'form_id' => str_repeat('A', 252) . '.esp|00000001', 'quantity' => 1],
    ['kind' => 'item', 'form_id' => 'Skyrim.esm|0000000f', 'quantity' => 1],
    ['kind' => 'item', 'form_id' => 'Example.esp|0000000', 'quantity' => 1],
    ['kind' => 'item', 'form_id' => '|00000001', 'quantity' => 1],
    ['kind' => 'item', 'form_id' => '../Example.esp|00000001', 'quantity' => 1],
] as $attachment) {
    $bad = $envelope;
    $bad['attachments'] = [$attachment];
    $rejectEnvelope($bad, 'malformed attachment rejected');
}
$notAList = $envelope;
$notAList['attachments'] = ['first' => ['kind' => 'item', 'form_id' => 'Example.esp|00000001', 'quantity' => 1]];
$rejectEnvelope($notAList, 'attachments must be a JSON list');

$atBodyLimit = $envelope;
$atBodyLimit['body'] = str_repeat('🙂', 2000);
$atBodyLimitLine = $parse($atBodyLimit);
check(
    strlen($atBodyLimit['body']) === 8000
    && validateWrittenEnvelope($atBodyLimit, $playthrough) === $atBodyLimit
    && $atBodyLimitLine !== null
    && $atBodyLimitLine['text'] === $atBodyLimit['body'],
    '2000 four-byte codepoints and 8000 bytes are preserved'
);
$overBodyLimit = $envelope;
$overBodyLimit['body'] = str_repeat('x', 2001);
$rejectEnvelope($overBodyLimit, 'body above 2000 codepoints rejected without truncation');
$emptyBody = $envelope;
$emptyBody['body'] = '';
$rejectEnvelope($emptyBody, 'empty body rejected');
$invalidUtf8Body = $envelope;
$invalidUtf8Body['body'] = "\xFF";
check(validateWrittenEnvelope($invalidUtf8Body, $playthrough) === null, 'invalid UTF-8 body rejected');
foreach ([null, 1, true, ['letter']] as $invalidBody) {
    $bad = $envelope;
    $bad['body'] = $invalidBody;
    $rejectEnvelope($bad, 'non-string body rejected');
}

foreach ([
    ['type' => 'inputtext'],
    ['type' => 'written_evidence'],
    ['type' => 'written_evidence '],
    ['sess' => 'correspondence_v1'],
    ['sess' => ''],
    ['sess' => 'other'],
    ['sess' => null],
    ['people' => '|Camilla|Hawke|'],
    ['people' => '|Jarl Balgruuf|'],
    ['people' => '||Camilla|'],
    ['people' => '|Camilla| '],
    ['rowid' => '0'],
    ['rowid' => '-1'],
    ['rowid' => 'bad'],
    ['gamets' => 'NaN'],
    ['gamets' => 'INF'],
    ['localts' => '-1'],
    ['localts' => 'bad'],
    ['data' => 'not JSON'],
    ['data' => "\xFF"],
] as $rowOverrides) {
    check($parse($envelope, $rowOverrides) === null, 'malformed or wrongly sourced row rejected');
}

check(writtenLineFromEventRow($rowFor($envelope), ['player_name' => 'Hawke']) === null, 'missing active scope rejected');
echo "written evidence checks passed\n";
