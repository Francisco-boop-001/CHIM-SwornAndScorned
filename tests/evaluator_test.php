<?php
declare(strict_types=1);

require __DIR__ . '/support/check.php';
require __DIR__ . '/support/fixtures.php';
require __DIR__ . '/../server/evaluator.php';
require __DIR__ . '/support/scripted_backend.php';

use function ChimSwornAndScorned\evaluatePerspective;
use const ChimSwornAndScorned\{POLICY_DECISION_MODEL, POLICY_LLM_FALLBACK};

$ask = sampleLine(4, 'Lydia', 'Will you defend me before Aela?');
$vow = sampleLine(5, 'Farkas', 'I will do that.');
$ctx = ['owner' => 'Lydia', 'counterpart' => 'Farkas', 'lines' => [$ask, $vow], 'open_issues' => []];
$grievanceLines = [
    sampleLine(4, 'Lydia', 'You left me alone when the bandits attacked.'),
    sampleLine(5, 'Farkas', 'I did not realize you were in danger.'),
];
$grievanceCtx = ['lines' => $grievanceLines] + $ctx;
$empty = ['new_issues' => [], 'updates' => []];

// No witnessed lines: no provider call.
$silent = new ScriptedBackend([]);
check(evaluatePerspective(['lines' => []] + $ctx, $silent, POLICY_DECISION_MODEL) === $empty, 'no lines, empty proposal');
check($silent->calls === [], 'no lines, no backend call');

// Nothing detected: one call only.
$quiet = new ScriptedBackend(['commitment' => ['value' => false, 'p' => 0.9], 'wronged' => ['value' => false, 'p' => 0.9]]);
check(evaluatePerspective($ctx, $quiet, POLICY_DECISION_MODEL) === $empty, 'nothing detected');
check(count($quiet->calls) === 1, 'pass 2 skipped when pass 1 is negative');

// Accepted firm promise from the counterpart.
$promiseAnswers = [
    'commitment' => ['value' => true, 'p' => 0.95],
    'wronged' => ['value' => false, 'p' => 0.9],
    'commitment.who' => ['value' => 'Farkas', 'p' => 0.9],
    'commitment.strength' => ['value' => 'will', 'p' => 0.88],
    'commitment.acceptance' => ['value' => 'accepted', 'p' => 0.85],
    'commitment.line' => ['value' => 'L2', 'p' => 0.97],
    'commitment.matter' => ['value' => 'L1', 'p' => 0.88],
    'commitment.settlement' => ['value' => 'unknown', 'p' => 0.9],
];
$proposal = evaluatePerspective($ctx, new ScriptedBackend($promiseAnswers), POLICY_DECISION_MODEL);
$proposedPromise = $proposal['new_issues'][0] ?? null;
check(
    $proposedPromise !== null
        && array_intersect_key($proposedPromise, array_flip(['kind', 'direction', 'strength', 'wants', 'line', 'label', 'p'])) === [
            'kind' => 'promise',
            'direction' => 'owed_to_me',
            'strength' => 'will',
            'wants' => 'keep_promise',
            'line' => $vow,
            'label' => 'commitment',
            'p' => 0.85,
        ]
        && $proposal['updates'] === [],
    'accepted promise from the counterpart becomes owed_to_me'
);
check(
    $proposedPromise['substance'] === [
        'version' => 1,
        'nature' => 'obligation',
        'matter' => ['ref' => 'e:4', 'speaker' => 'Lydia', 'text' => 'Will you defend me before Aela?'],
        'settlement' => ['mode' => 'complete_obligation', 'source' => null],
        'context' => [
            ['ref' => 'e:4', 'speaker' => 'Lydia', 'text' => 'Will you defend me before Aela?'],
            ['ref' => 'e:5', 'speaker' => 'Farkas', 'text' => 'I will do that.'],
        ],
    ]
        && $proposedPromise['related_issue_id'] === null
        && $proposedPromise['substance_lines'] === [$ask, $vow],
    'promise proposal preserves the exact request source and complete-obligation expectation'
);

// Lydia's own promise is owed_by_me with unknown wants.
$ownCtx = ['lines' => [$ask, sampleLine(5, 'Lydia', 'I will stand with you before Aela.')]] + $ctx;
$own = evaluatePerspective($ownCtx, new ScriptedBackend([
    'commitment.who' => ['value' => 'Lydia', 'p' => 0.9],
    'commitment.matter' => ['value' => 'L2', 'p' => 0.9],
] + $promiseAnswers), POLICY_DECISION_MODEL);
check($own['new_issues'][0]['direction'] === 'owed_by_me' && $own['new_issues'][0]['wants'] === 'unknown', 'own promise is owed_by_me');

// Unclear acceptance creates nothing; a favour is not an agreed debt.
$unclear = evaluatePerspective($ctx, new ScriptedBackend(['commitment.acceptance' => ['value' => 'unclear', 'p' => 0.9]] + $promiseAnswers), POLICY_DECISION_MODEL);
check($unclear === $empty, 'unclear acceptance creates no promise');

// Low pass 1 confidence stops before pass 2.
$weak = new ScriptedBackend(['commitment' => ['value' => true, 'p' => 0.6]] + $promiseAnswers);
check(evaluatePerspective($ctx, $weak, POLICY_DECISION_MODEL) === $empty && count($weak->calls) === 1, 'low confidence detection ignored');

// The LLM fallback policy is stricter: 0.85 detection is not enough.
$llm = evaluatePerspective($ctx, new ScriptedBackend(['commitment' => ['value' => true, 'p' => 0.85]] + $promiseAnswers), POLICY_LLM_FALLBACK);
check($llm === $empty, 'LLM fallback needs high confidence');

// New grievance.
$grievanceAnswers = [
    'commitment' => ['value' => false, 'p' => 0.9],
    'wronged' => ['value' => true, 'p' => 0.9],
    'grievance.line' => ['value' => 'L1', 'p' => 0.9],
    'grievance.wants' => ['value' => 'explanation', 'p' => 0.6],
    'grievance.matter' => ['value' => 'L1', 'p' => 0.9],
    'grievance.nature' => ['value' => 'unknown', 'p' => 0.9],
    'grievance.settlement' => ['value' => 'unknown', 'p' => 0.9],
];
$grievance = evaluatePerspective($grievanceCtx, new ScriptedBackend($grievanceAnswers), POLICY_DECISION_MODEL);
$proposedGrievance = $grievance['new_issues'][0] ?? null;
check(
    $proposedGrievance !== null
        && array_intersect_key($proposedGrievance, array_flip(['kind', 'direction', 'strength', 'wants', 'line', 'label', 'p'])) === [
            'kind' => 'grievance', 'direction' => null, 'strength' => null, 'wants' => 'unknown',
            'line' => $grievanceLines[0], 'label' => 'wronged', 'p' => 0.9,
        ],
    'grievance with low-confidence wants falls back to unknown'
);
check(
    $proposedGrievance['substance'] === [
        'version' => 1,
        'nature' => 'unknown',
        'matter' => ['ref' => 'e:4', 'speaker' => 'Lydia', 'text' => 'You left me alone when the bandits attacked.'],
        'settlement' => ['mode' => 'unknown', 'source' => null],
        'context' => [
            ['ref' => 'e:4', 'speaker' => 'Lydia', 'text' => 'You left me alone when the bandits attacked.'],
            ['ref' => 'e:5', 'speaker' => 'Farkas', 'text' => 'I did not realize you were in danger.'],
        ],
    ]
        && $proposedGrievance['related_issue_id'] === null
        && $proposedGrievance['substance_lines'] === $grievanceLines,
    'grievance proposal carries only its selected evidence and does not infer settlement'
);

// Addressed open promise.
$open = sampleIssue(['id' => 'p000000000000001', 'kind' => 'promise', 'direction' => 'owed_to_me', 'strength' => 'will', 'status' => 'pending', 'wants' => 'keep_promise']);
$addressedCtx = ['open_issues' => [$open]] + $ctx;
$addressedAnswers = [
    'addresses:p000000000000001' => ['value' => true, 'p' => 0.9],
    'commitment' => ['value' => false, 'p' => 0.9],
    'wronged' => ['value' => false, 'p' => 0.9],
    'issue:p000000000000001.status' => ['value' => 'broken', 'p' => 0.92],
    'issue:p000000000000001.trajectory' => ['value' => 'worsening', 'p' => 0.85],
    'issue:p000000000000001.line' => ['value' => 'L1', 'p' => 0.9],
];
$broken = evaluatePerspective($addressedCtx, new ScriptedBackend($addressedAnswers), POLICY_DECISION_MODEL);
check($broken['updates'] === [[
    'issue_id' => 'p000000000000001', 'status' => 'broken', 'status_p' => 0.9,
    'trajectory' => 'worsening', 'wants' => null, 'line' => $ask,
]] && $broken['new_issues'] === [], 'addressed promise update');

$doubtful = evaluatePerspective($addressedCtx, new ScriptedBackend(['issue:p000000000000001.status' => ['value' => 'broken', 'p' => 0.7]] + $addressedAnswers), POLICY_DECISION_MODEL);
check($doubtful['updates'][0]['status'] === null && $doubtful['updates'][0]['status_p'] === 0.7, 'doubtful status is not proposed');

$unknownMatterAnswers = $promiseAnswers;
$unknownMatterAnswers['commitment.matter'] = ['value' => 'unknown', 'p' => 0.9];
check(
    evaluatePerspective($ctx, new ScriptedBackend($unknownMatterAnswers), POLICY_DECISION_MODEL) === $empty,
    'unknown commitment matter abstains'
);
$missingMatterAnswers = $promiseAnswers;
unset($missingMatterAnswers['commitment.matter']);
check(
    evaluatePerspective($ctx, new ScriptedBackend($missingMatterAnswers), POLICY_DECISION_MODEL) === $empty,
    'missing commitment matter abstains'
);
$weakMatterAnswers = $promiseAnswers;
$weakMatterAnswers['commitment.matter'] = ['value' => 'L1', 'p' => 0.79];
check(
    evaluatePerspective($ctx, new ScriptedBackend($weakMatterAnswers), POLICY_DECISION_MODEL) === $empty,
    'low-confidence commitment matter abstains'
);
$longContextLines = [$ask, $vow];
for ($number = 6; $number <= 12; $number++) {
    $longContextLines[] = sampleLine($number, 'Lydia', 'A separate witnessed exchange line.');
}
$longContextCtx = ['lines' => $longContextLines] + $ctx;
check(
    evaluatePerspective($longContextCtx, new ScriptedBackend($promiseAnswers), POLICY_DECISION_MODEL) === $empty,
    'new issue abstains when the complete witnessed context exceeds its supported bound'
);
$longContextPromise = sampleIssue([
    'id' => 'p000000000000003',
    'kind' => 'promise',
    'direction' => 'owed_to_me',
    'strength' => 'will',
    'status' => 'pending',
    'wants' => 'keep_promise',
]);
$longContextWithOpen = ['lines' => $longContextLines, 'open_issues' => [$longContextPromise]] + $ctx;
$longContextAnswers = $promiseAnswers + [
    'addresses:p000000000000003' => ['value' => true, 'p' => 0.9],
    'issue:p000000000000003.status' => ['value' => 'broken', 'p' => 0.9],
    'issue:p000000000000003.trajectory' => ['value' => 'worsening', 'p' => 0.85],
    'issue:p000000000000003.line' => ['value' => 'L1', 'p' => 0.9],
];
$longContextUpdate = evaluatePerspective($longContextWithOpen, new ScriptedBackend($longContextAnswers), POLICY_DECISION_MODEL);
check(
    $longContextUpdate['new_issues'] === []
        && count($longContextUpdate['updates']) === 1
        && $longContextUpdate['updates'][0]['issue_id'] === 'p000000000000003'
        && $longContextUpdate['updates'][0]['status'] === 'broken',
    'over-bound new context suppresses creation without suppressing existing issue updates'
);
$unknownGrievanceAnswers = $grievanceAnswers;
$unknownGrievanceAnswers['grievance.matter'] = ['value' => 'unknown', 'p' => 0.9];
check(
    evaluatePerspective($grievanceCtx, new ScriptedBackend($unknownGrievanceAnswers), POLICY_DECISION_MODEL) === $empty,
    'unknown grievance matter abstains'
);

$statedWantOnly = $grievanceAnswers;
$statedWantOnly['grievance.wants'] = ['value' => 'apology', 'p' => 0.95];
$wantOnlyIssue = evaluatePerspective($grievanceCtx, new ScriptedBackend($statedWantOnly), POLICY_DECISION_MODEL)['new_issues'][0];
check(
    $wantOnlyIssue['wants'] === 'apology'
        && $wantOnlyIssue['substance']['settlement'] === ['mode' => 'unknown', 'source' => null],
    'a model-selected want cannot invent an explicit settlement source'
);

$theftLines = [
    sampleLine(4, 'Lydia', 'You took the 50 septims I left by the fire.'),
    sampleLine(5, 'Lydia', 'Return exactly 50 septims by sundown.'),
];
$theftCtx = ['lines' => $theftLines] + $ctx;
$theftIssue = evaluatePerspective($theftCtx, new ScriptedBackend([
    'commitment' => ['value' => false, 'p' => 0.9],
    'wronged' => ['value' => true, 'p' => 0.92],
    'grievance.line' => ['value' => 'L1', 'p' => 0.9],
    'grievance.wants' => ['value' => 'restitution', 'p' => 0.9],
    'grievance.matter' => ['value' => 'L1', 'p' => 0.9],
    'grievance.nature' => ['value' => 'theft', 'p' => 0.9],
    'grievance.settlement' => ['value' => 'L2', 'p' => 0.9],
]), POLICY_DECISION_MODEL)['new_issues'][0] ?? null;
check(
    $theftIssue !== null
        && $theftIssue['substance'] === [
            'version' => 1,
            'nature' => 'theft',
            'matter' => ['ref' => 'e:4', 'speaker' => 'Lydia', 'text' => 'You took the 50 septims I left by the fire.'],
            'settlement' => [
                'mode' => 'explicit',
                'source' => ['ref' => 'e:5', 'speaker' => 'Lydia', 'text' => 'Return exactly 50 septims by sundown.'],
            ],
            'context' => [
                ['ref' => 'e:4', 'speaker' => 'Lydia', 'text' => 'You took the 50 septims I left by the fire.'],
                ['ref' => 'e:5', 'speaker' => 'Lydia', 'text' => 'Return exactly 50 septims by sundown.'],
            ],
        ]
        && $theftIssue['substance_lines'] === $theftLines,
    'theft proposal records distinct exact matter and explicitly stated settlement sources'
);

$priorPromise = sampleIssue([
    'id' => 'p000000000000001',
    'kind' => 'promise',
    'direction' => 'owed_to_me',
    'strength' => 'will',
    'status' => 'pending',
    'wants' => 'keep_promise',
]);
$linkedCtx = ['open_issues' => [$priorPromise]] + $ctx;
$linkedAnswers = $promiseAnswers + [
    'addresses:p000000000000001' => ['value' => false, 'p' => 0.9],
    'commitment.related' => ['value' => 'p000000000000001', 'p' => 0.9],
];
$linkedBackend = new ScriptedBackend($linkedAnswers);
$linked = evaluatePerspective($linkedCtx, $linkedBackend, POLICY_DECISION_MODEL);
check(
    in_array('commitment.related', $linkedBackend->calls[1], true)
        && $linked['new_issues'][0]['related_issue_id'] === null,
    'a selected prior promise is not linked when the new promise merely continues it'
);
$noneAnswers = $linkedAnswers;
$noneAnswers['commitment.related'] = ['value' => 'none', 'p' => 0.9];
$none = evaluatePerspective($linkedCtx, new ScriptedBackend($noneAnswers), POLICY_DECISION_MODEL);
check($none['new_issues'][0]['related_issue_id'] === null, 'none leaves the new issue unlinked');
$weakLinkAnswers = $linkedAnswers;
$weakLinkAnswers['commitment.related'] = ['value' => 'p000000000000001', 'p' => 0.79];
$weakLink = evaluatePerspective($linkedCtx, new ScriptedBackend($weakLinkAnswers), POLICY_DECISION_MODEL);
check($weakLink['new_issues'][0]['related_issue_id'] === null, 'low-confidence relation selection is ignored');

$renegotiatedAnswers = $linkedAnswers;
$renegotiatedAnswers['addresses:p000000000000001'] = ['value' => true, 'p' => 0.9];
$renegotiatedAnswers['issue:p000000000000001.status'] = ['value' => 'renegotiated', 'p' => 0.92];
$renegotiatedAnswers['issue:p000000000000001.line'] = ['value' => 'L1', 'p' => 0.9];
$renegotiated = evaluatePerspective($linkedCtx, new ScriptedBackend($renegotiatedAnswers), POLICY_DECISION_MODEL);
check(
    $renegotiated['updates'][0]['status'] === 'renegotiated'
        && $renegotiated['new_issues'][0]['direction'] === 'owed_to_me'
        && $renegotiated['new_issues'][0]['related_issue_id'] === 'p000000000000001',
    'a matching renegotiated promise links only when its same-batch direction is preserved'
);

$oppositePromise = sampleIssue([
    'id' => 'p000000000000002',
    'kind' => 'promise',
    'direction' => 'owed_by_me',
    'strength' => 'will',
    'status' => 'pending',
    'wants' => 'keep_promise',
]);
$oppositeCtx = ['open_issues' => [$oppositePromise]] + $ctx;
$oppositeAnswers = $promiseAnswers + [
    'addresses:p000000000000002' => ['value' => true, 'p' => 0.9],
    'commitment.related' => ['value' => 'p000000000000002', 'p' => 0.9],
    'issue:p000000000000002.status' => ['value' => 'renegotiated', 'p' => 0.92],
    'issue:p000000000000002.line' => ['value' => 'L1', 'p' => 0.9],
];
$opposite = evaluatePerspective($oppositeCtx, new ScriptedBackend($oppositeAnswers), POLICY_DECISION_MODEL);
check(
    $opposite['updates'][0]['status'] === 'renegotiated'
        && $opposite['new_issues'][0]['direction'] === 'owed_to_me'
        && $opposite['new_issues'][0]['related_issue_id'] === null,
    'a relation selection is discarded when renegotiation reverses the prior promise direction'
);

$replacementTerms = sampleLine(40, 'Farkas', 'I will return 37 septims by Friday.');
$replacementAcceptance = sampleLine(41, 'Lydia', 'I accept those replacement terms.');
$replacementLines = [$replacementTerms, $replacementAcceptance];
$replacementId = 'p000000000000004';
$replacementPromise = sampleIssue([
    'id' => $replacementId,
    'kind' => 'promise',
    'direction' => 'owed_to_me',
    'strength' => 'will',
    'status' => 'pending',
    'wants' => 'keep_promise',
]);
$replacementCtx = [
    'owner' => 'Lydia',
    'counterpart' => 'Farkas',
    'lines' => $replacementLines,
    'open_issues' => [$replacementPromise],
];
$replacementAnswers = [
    'commitment' => ['value' => true, 'p' => 0.98],
    'wronged' => ['value' => false, 'p' => 0.9],
    "addresses:{$replacementId}" => ['value' => true, 'p' => 0.95],
    'commitment.who' => ['value' => 'Farkas', 'p' => 0.97],
    'commitment.strength' => ['value' => 'will', 'p' => 0.96],
    'commitment.acceptance' => ['value' => 'accepted', 'p' => 0.95],
    'commitment.line' => ['value' => 'L1', 'p' => 0.94],
    'commitment.matter' => ['value' => 'L1', 'p' => 0.94],
    'commitment.settlement' => ['value' => 'unknown', 'p' => 0.9],
    'commitment.related' => ['value' => $replacementId, 'p' => 0.95],
    "issue:{$replacementId}.status" => ['value' => 'renegotiated', 'p' => 0.96],
    "issue:{$replacementId}.trajectory" => ['value' => 'improving', 'p' => 0.9],
    "issue:{$replacementId}.line" => ['value' => 'L2', 'p' => 0.79],
];
$replacement = evaluatePerspective($replacementCtx, new ScriptedBackend($replacementAnswers), POLICY_DECISION_MODEL);
check(
    $replacement['updates'][0]['status'] === 'renegotiated'
        && $replacement['updates'][0]['status_p'] === 0.94
        && $replacement['updates'][0]['line'] === $replacementTerms
        && $replacement['new_issues'][0]['related_issue_id'] === $replacementId,
    'a related accepted replacement supplies concrete update evidence when generic issue-line confidence is low'
);
$missingReplacementLineAnswers = $replacementAnswers;
unset($missingReplacementLineAnswers["issue:{$replacementId}.line"]);
$missingReplacementLine = evaluatePerspective($replacementCtx, new ScriptedBackend($missingReplacementLineAnswers), POLICY_DECISION_MODEL);
check(
    $missingReplacementLine['updates'][0]['status'] === 'renegotiated'
        && $missingReplacementLine['updates'][0]['line'] === $replacementTerms,
    'a related accepted replacement supplies evidence when the generic issue line is absent'
);

$weakReplacementRelationAnswers = $replacementAnswers;
$weakReplacementRelationAnswers['commitment.related'] = ['value' => $replacementId, 'p' => 0.79];
$weakReplacementRelation = evaluatePerspective($replacementCtx, new ScriptedBackend($weakReplacementRelationAnswers), POLICY_DECISION_MODEL);
check($weakReplacementRelation['updates'] === [], 'weak relation confidence cannot provide predecessor update evidence');
$missingReplacementRelationAnswers = $replacementAnswers;
unset($missingReplacementRelationAnswers['commitment.related']);
$missingReplacementRelation = evaluatePerspective($replacementCtx, new ScriptedBackend($missingReplacementRelationAnswers), POLICY_DECISION_MODEL);
check($missingReplacementRelation['updates'] === [], 'missing relation selection cannot provide predecessor update evidence');
$weakReplacementStatusAnswers = $replacementAnswers;
$weakReplacementStatusAnswers["issue:{$replacementId}.status"] = ['value' => 'renegotiated', 'p' => 0.79];
$weakReplacementStatus = evaluatePerspective($replacementCtx, new ScriptedBackend($weakReplacementStatusAnswers), POLICY_DECISION_MODEL);
check($weakReplacementStatus['updates'] === [], 'weak renegotiated status cannot use replacement evidence');
$unclearReplacementAcceptanceAnswers = $replacementAnswers;
$unclearReplacementAcceptanceAnswers['commitment.acceptance'] = ['value' => 'unclear', 'p' => 0.95];
$unclearReplacementAcceptance = evaluatePerspective($replacementCtx, new ScriptedBackend($unclearReplacementAcceptanceAnswers), POLICY_DECISION_MODEL);
check($unclearReplacementAcceptance['updates'] === [], 'unclear acceptance cannot use replacement evidence');
$oppositeReplacementPromise = $replacementPromise;
$oppositeReplacementPromise['direction'] = 'owed_by_me';
$oppositeReplacementCtx = ['open_issues' => [$oppositeReplacementPromise]] + $replacementCtx;
$oppositeReplacement = evaluatePerspective($oppositeReplacementCtx, new ScriptedBackend($replacementAnswers), POLICY_DECISION_MODEL);
check($oppositeReplacement['updates'] === [], 'opposite promise direction cannot use replacement evidence');
$unboundedReplacementLines = $replacementLines;
for ($number = 42; $number <= 48; $number++) {
    $unboundedReplacementLines[] = sampleLine($number, 'Lydia', 'A separate witnessed exchange line.');
}
$unboundedReplacementCtx = ['lines' => $unboundedReplacementLines] + $replacementCtx;
$unboundedReplacement = evaluatePerspective($unboundedReplacementCtx, new ScriptedBackend($replacementAnswers), POLICY_DECISION_MODEL);
check($unboundedReplacement['updates'] === [], 'incomplete new context cannot provide predecessor update evidence');

echo "evaluator checks passed\n";
