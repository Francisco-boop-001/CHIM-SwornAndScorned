<?php
declare(strict_types=1);
require_once __DIR__ . '/fixtures.php';

/** Synthetic speech only; never copy private game conversations into this corpus. */
function toneCases(): array
{
    $grievance = sampleIssue(['wants' => 'restitution']);
    $grievance['evidence'][0] = array_replace($grievance['evidence'][0], ['speaker' => 'Lydia', 'quote' => 'You stole my purse. Return it with all my coins.']);
    $promise = sampleIssue(['kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'status' => 'pending', 'wants' => 'keep_promise']);
    $promise['evidence'][0] = array_replace($promise['evidence'][0], ['speaker' => 'Farkas', 'quote' => 'I promise I will bring you mead tonight.']);
    return [
        'affectionate_insult' => ['speech' => [['Lydia', 'You absolute bastard, that was brilliant! I love you.'], ['Farkas', 'I love you too.']], 'grievance' => false],
        'affectionate_intensity' => ['speech' => [['Lydia', 'You completely ruined me, love. That was wonderful. Please do not stop.']], 'grievance' => false],
        'combat_score' => ['speech' => [['Lydia', 'Down! Sixty-something and counting, love. Try to keep up; I am not writing the entries twice.'], ['Farkas', 'Ha! Race you to the next bandit.']], 'grievance' => false],
        'combat_rivalry' => ['speech' => [['Lydia', 'Eighty-two, love. You are upgrading my own doctrine behind my back.'], ['Farkas', 'Just trying to beat your kill count.']], 'grievance' => false],
        'ambiguous_insult' => ['speech' => [['Lydia', 'You bastard.']], 'grievance' => false],
        'genuine_theft' => ['speech' => [['Lydia', 'You bastard. You stole my purse and left me hungry. Return it with all my coins.']], 'grievance' => true],
        'genuine_hurt_with_love' => ['speech' => [['Lydia', 'I love you, but sharing my secret with Aela really hurt me. I need an apology.']], 'grievance' => true],
        'genuine_hurt_despite_smile' => ['speech' => [['Lydia', 'I am smiling, but I am not joking: you humiliated me in front of Aela. That hurt, and I want an apology.']], 'grievance' => true],
        'genuine_abandonment' => ['speech' => [['Lydia', 'You abandoned me at the gate when I needed you. I feel betrayed. Explain why you left.']], 'grievance' => true],
        'banter_does_not_rewrite_theft' => ['speech' => [['Lydia', 'You absolute bastard, that was brilliant! I love you.'], ['Farkas', 'That bandit never stood a chance.']], 'open' => [$grievance], 'grievance' => false, 'updates' => []],
        'specific_grievance_resolved' => ['speech' => [['Farkas', 'I returned your purse with every coin. I am sorry I stole it.'], ['Lydia', 'I have my purse and all my coins back. I accept your apology; that theft is settled.']], 'open' => [$grievance], 'grievance' => false, 'updates' => ['resolved']],
        'unrelated_gift_not_mead' => ['speech' => [['Farkas', 'Here is some bread.'], ['Lydia', 'Thank you for the bread.']], 'open' => [$promise], 'grievance' => false, 'updates' => []],
        'specific_promise_fulfilled' => ['speech' => [['Farkas', 'Here is the mead I promised you tonight.'], ['Lydia', 'You brought the promised mead. Thank you; your promise is kept.']], 'open' => [$promise], 'grievance' => false, 'updates' => ['fulfilled']],
        'specific_promise_broken' => ['speech' => [['Farkas', 'I will not bring you the mead I promised tonight. I am breaking that promise.'], ['Lydia', 'You broke your promise about the mead. That hurt me.']], 'open' => [$promise], 'grievance' => true, 'updates' => ['broken']],
    ];
}

function toneCaseContext(array $case): array
{
    $lines = [];
    foreach ($case['speech'] as $i => [$speaker, $text]) $lines[] = sampleLine(20 + $i, $speaker, $text);
    return ['owner' => 'Lydia', 'counterpart' => 'Farkas', 'lines' => $lines, 'open_issues' => $case['open'] ?? []];
}

function toneCaseFailures(array $case, array $proposal): array
{
    $failures = [];
    $grievances = array_filter($proposal['new_issues'], static fn (array $issue): bool => $issue['kind'] === 'grievance');
    if (($grievances !== []) !== $case['grievance']) $failures[] = $case['grievance'] ? 'genuine_grievance_missing' : 'false_grievance';
    if (array_key_exists('updates', $case)) {
        $statuses = array_column($proposal['updates'], 'status');
        if ($statuses !== $case['updates']) $failures[] = 'wrong_matter_update';
    }
    return $failures;
}
