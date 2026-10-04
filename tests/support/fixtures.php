<?php
declare(strict_types=1);

function sampleIssue(array $overrides = []): array
{
    return array_replace([
        'id' => 'abc123abc123abc1',
        'origin' => 'event',
        'kind' => 'grievance',
        'counterpart' => 'npc:3',
        'direction' => null,
        'strength' => null,
        'status' => 'open',
        'trajectory' => 'unchanged',
        'wants' => 'explanation',
        'evidence' => [[
            'ref' => 'e:10',
            'gamets' => 100.0,
            'speaker' => 'Farkas',
            'quote' => "I'll stand with you before Aela.",
            'label' => 'wronged',
            'p' => 0.91,
            'backend' => 'fake',
        ]],
        'links' => [],
        'affinity_applied' => [],
        'muted' => false,
        'dismissed' => false,
        'changed_event_id' => 10,
    ], $overrides);
}

function sampleLine(
    int $eventId,
    string $speaker,
    string $text,
    array $people = ['Lydia', 'Farkas'],
    string $listener = '',
    string $type = 'chat'
): array {
    return [
        'event_id' => $eventId,
        'gamets' => (float) $eventId,
        'type' => $type,
        'speaker' => $speaker,
        'listener' => $listener,
        'people' => $people,
        'text' => $text,
    ];
}

function issueById(array $ledger, string $id): array
{
    foreach ($ledger['issues'] as $issue) {
        if ($issue['id'] === $id) {
            return $issue;
        }
    }
    throw new RuntimeException("issue {$id} not found");
}
