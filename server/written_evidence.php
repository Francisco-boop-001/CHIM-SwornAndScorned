<?php
declare(strict_types=1);

namespace ChimSwornAndScorned;

require_once __DIR__ . '/rows.php';

const MAX_SPOKEN_SUBSTANCE_CODEPOINTS = 640;
const MAX_WRITTEN_SUBSTANCE_CODEPOINTS = 2000;
const MAX_ORIGIN_CODEPOINTS = 5120;
const MAX_ORIGIN_CAPSULES = 8;

function writtenEvidenceCapability(): array
{
    return [
        'version' => 1,
        'input_type' => 'written_evidence',
        'source_event_type' => 'info',
        'source_marker' => 'written_evidence_v1',
        'max_body_codepoints' => MAX_WRITTEN_SUBSTANCE_CODEPOINTS,
        'max_substance_codepoints' => MAX_SPOKEN_SUBSTANCE_CODEPOINTS,
        'max_written_substance_codepoints' => MAX_WRITTEN_SUBSTANCE_CODEPOINTS,
        'max_origin_codepoints' => MAX_ORIGIN_CODEPOINTS,
        'recipient_only' => true,
    ];
}

function writtenEvidenceHasExactKeys(array $value, array $keys): bool
{
    if (count($value) !== count($keys)) {
        return false;
    }
    $actual = array_keys($value);
    sort($actual);
    sort($keys);
    return $actual === $keys;
}

function writtenEvidenceValidUuid(mixed $value): bool
{
    return is_string($value)
        && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $value) === 1;
}

function writtenEvidenceValidScope(mixed $value): bool
{
    return is_string($value)
        && ($value === 'unprofiled' || (strlen($value) <= 19 && preg_match('/\A[1-9][0-9]*\z/', $value) === 1));
}

function writtenEvidenceValidName(mixed $value): bool
{
    if (!is_string($value) || $value === '' || !mb_check_encoding($value, 'UTF-8')
        || mb_strlen($value, 'UTF-8') > 128 || str_contains($value, '|')) {
        return false;
    }
    return preg_match('/\p{Cc}/u', $value) !== 1
        && preg_match('/\A[\p{Z}\p{Cc}]|[\p{Z}\p{Cc}]\z/u', $value) !== 1;
}

function writtenEvidenceActorShape(mixed $actor): bool
{
    if (!is_array($actor) || !writtenEvidenceHasExactKeys($actor, ['kind', 'id', 'name'])
        || !writtenEvidenceValidName($actor['name'])) {
        return false;
    }
    if ($actor['kind'] === 'player') {
        return $actor['id'] === null;
    }
    return $actor['kind'] === 'npc' && is_int($actor['id']) && $actor['id'] > 0;
}

function writtenActorKey(array $actor): string
{
    if (!writtenEvidenceActorShape($actor)) {
        throw new \InvalidArgumentException('invalid_written_actor');
    }
    return $actor['kind'] === 'player' ? 'player' : 'npc:' . $actor['id'];
}

function writtenEvidenceValidFormId(mixed $value): bool
{
    return is_string($value) && strlen($value) <= 264
        && preg_match('/\A[A-Za-z0-9][A-Za-z0-9 _().-]*\.(?i:esm|esp|esl)\|[0-9A-F]{8}\z/', $value) === 1;
}

function validateWrittenEnvelope(mixed $envelope, array $playthrough): ?array
{
    if (!is_array($envelope) || !writtenEvidenceHasExactKeys($envelope, [
        'schema_version', 'document_id', 'playthrough_id', 'recipient', 'claimed_sender', 'body', 'receipt', 'attachments',
    ])) {
        return null;
    }

    $playthroughId = $playthrough['id'] ?? null;
    $playerName = $playthrough['player_name'] ?? null;
    if (!writtenEvidenceValidScope($playthroughId) || !writtenEvidenceValidName($playerName)
        || $envelope['schema_version'] !== 1
        || !writtenEvidenceValidUuid($envelope['document_id'])
        || !writtenEvidenceValidScope($envelope['playthrough_id'])
        || $envelope['playthrough_id'] !== $playthroughId) {
        return null;
    }

    $recipient = $envelope['recipient'];
    $sender = $envelope['claimed_sender'];
    if (!writtenEvidenceActorShape($recipient) || $recipient['kind'] !== 'npc'
        || !writtenEvidenceActorShape($sender)
        || ($sender['kind'] === 'player' && $sender['name'] !== $playerName)
        || writtenActorKey($recipient) === writtenActorKey($sender)) {
        return null;
    }

    $body = $envelope['body'];
    if (!is_string($body) || $body === '' || !mb_check_encoding($body, 'UTF-8')
        || strlen($body) > 8000 || mb_strlen($body, 'UTF-8') > 2000) {
        return null;
    }

    $receipt = $envelope['receipt'];
    if (!is_array($receipt) || !writtenEvidenceHasExactKeys($receipt, ['receipt_id', 'operation_id', 'status', 'exposure'])
        || !writtenEvidenceValidUuid($receipt['receipt_id'])
        || !writtenEvidenceValidUuid($receipt['operation_id'])
        || $receipt['status'] !== 'confirmed'
        || !in_array($receipt['exposure'], ['context', 'read'], true)) {
        return null;
    }

    $attachments = $envelope['attachments'];
    if (!is_array($attachments) || !array_is_list($attachments) || count($attachments) > 8) {
        return null;
    }
    $seenForms = [];
    foreach ($attachments as $attachment) {
        if (!is_array($attachment) || !writtenEvidenceHasExactKeys($attachment, ['kind', 'form_id', 'quantity'])
            || !in_array($attachment['kind'], ['gold', 'item'], true)
            || !writtenEvidenceValidFormId($attachment['form_id'])
            || !is_int($attachment['quantity']) || $attachment['quantity'] < 1 || $attachment['quantity'] > 2147483647
            || ($attachment['kind'] === 'gold' && $attachment['form_id'] !== 'Skyrim.esm|0000000F')
            || ($attachment['kind'] === 'item' && $attachment['form_id'] === 'Skyrim.esm|0000000F')) {
            return null;
        }
        $plugin = strtolower(substr($attachment['form_id'], 0, strpos($attachment['form_id'], '|')));
        $formKey = $plugin . substr($attachment['form_id'], strpos($attachment['form_id'], '|'));
        if (isset($seenForms[$formKey])) {
            return null;
        }
        $seenForms[$formKey] = true;
    }

    $normalizedAttachments = [];
    foreach ($attachments as $attachment) {
        $normalizedAttachments[] = [
            'kind' => $attachment['kind'],
            'form_id' => $attachment['form_id'],
            'quantity' => $attachment['quantity'],
        ];
    }
    return [
        'schema_version' => $envelope['schema_version'],
        'document_id' => $envelope['document_id'],
        'playthrough_id' => $envelope['playthrough_id'],
        'recipient' => [
            'kind' => $recipient['kind'],
            'id' => $recipient['id'],
            'name' => $recipient['name'],
        ],
        'claimed_sender' => [
            'kind' => $sender['kind'],
            'id' => $sender['id'],
            'name' => $sender['name'],
        ],
        'body' => $body,
        'receipt' => [
            'receipt_id' => $receipt['receipt_id'],
            'operation_id' => $receipt['operation_id'],
            'status' => $receipt['status'],
            'exposure' => $receipt['exposure'],
        ],
        'attachments' => $normalizedAttachments,
    ];
}

function writtenEvidenceEnvelopeFromJson(string $data): ?array
{
    try {
        $decoded = json_decode($data, false, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        return null;
    }
    if (!$decoded instanceof \stdClass) {
        return null;
    }

    $envelope = get_object_vars($decoded);
    foreach (['recipient', 'claimed_sender', 'receipt'] as $key) {
        if (!(($envelope[$key] ?? null) instanceof \stdClass)) {
            return null;
        }
        $envelope[$key] = get_object_vars($envelope[$key]);
    }
    if (!isset($envelope['attachments']) || !is_array($envelope['attachments'])) {
        return null;
    }
    foreach ($envelope['attachments'] as $index => $attachment) {
        if (!$attachment instanceof \stdClass) {
            return null;
        }
        $envelope['attachments'][$index] = get_object_vars($attachment);
    }
    return $envelope;
}

/**
 * Parse only confirmed written evidence for the active playthrough.
 * The returned speaker is the document's claimed sender, not verified authorship.
 */
function writtenLineFromEventRow(array $row, array $playthrough): ?array
{
    $type = $row['type'] ?? null;
    $source = $row['sess'] ?? null;
    $people = $row['people'] ?? null;
    $data = $row['data'] ?? null;
    $eventId = filter_var($row['rowid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $gameTime = $row['gamets'] ?? null;
    $localTime = filter_var($row['localts'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($type !== 'info' || $source !== 'written_evidence_v1'
        || !is_string($people) || !is_string($data) || $eventId === false
        || !is_numeric($gameTime) || !is_finite((float) $gameTime) || $localTime === false) {
        return null;
    }

    $envelope = writtenEvidenceEnvelopeFromJson($data);
    if ($envelope === null || ($envelope = validateWrittenEnvelope($envelope, $playthrough)) === null) {
        return null;
    }
    $recipientName = $envelope['recipient']['name'];
    if ($people !== '|' . $recipientName . '|') {
        return null;
    }

    return [
        'event_id' => $eventId,
        'gamets' => (float) $gameTime,
        'type' => 'written_evidence',
        'speaker' => $envelope['claimed_sender']['name'],
        'listener' => $recipientName,
        'people' => [$recipientName],
        'text' => $envelope['body'],
        'localts' => $localTime,
        'written' => $envelope,
    ];
}
