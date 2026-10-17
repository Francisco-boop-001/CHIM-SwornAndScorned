# Sworn & Scorned 0.1.9 — PRE-ALPHA

The llama now keeps the receipt. “Someone said a thing” was a magnificent filing system, right up until we needed to know what the bloody thing was.

**[MO2 users: download this ZIP only](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/releases/download/sworn_and_scorned-v0.1.9/sworn_and_scorned-0.1.9-mo2.zip).** The DWPkg is already inside. Replace the older enabled package, launch Skyrim/SKSE through MO2 and load a save with CHIM connected, then verify 0.1.9 in the server's Plugin Manager. Downloading it alone does not update the running server.

## What changed

- New records preserve the particular wrong or accepted obligation, who owes whom, and evidence for established settlement conditions. Items, amounts and deadlines come from exact witnessed conversation; unspecified terms stay unknown.
- The bounded originating exchange preserves terms split across lines. The subject stays pinned through later evaluation, ledger/history, journal and conversation reminders; unrelated later evidence cannot redefine it.
- Partial repayment does not settle the whole obligation. A matching completed repayment can; an unrelated gift cannot. This is conversation continuity, not a new banking simulator.
- Accepted replacement terms create a distinct linked promise and release the original with zero affinity. Its original terms remain in history. Missing or skipped replacements do not silently erase the debt.
- Includes the tone correction developed for the unpublished 0.1.8 candidate: affectionate insults and combat banter are evaluated in context, while genuine hurt still counts. Calling somebody a bastard after they save your life is not automatically grounds for a tribunal.
- A favour creates an obligation only when the conversation establishes an accepted commitment. Existing records remain readable; historical grievances and affinity are not automatically cleaned up.

## Jev and the usual relationship LLM

The default remains CHIM's configured relationship LLM. **Use Jev — experimental** selects the existing typed OpenRouter Decisions API adapter, reusing CHIM credentials unless an optional encrypted dedicated key overrides them. Both routes receive the same grounded issue substance. No extra model service or model round trip was added. API calls still cost money; the llama has not negotiated free electricity.

## What was verified

On the disposable server, real typesafe/jev-1.13 and google/gemma-4-31b-it each passed 38 synthetic cases: 11 development, 9 untouched held-out, 4 boundary and14 tone cases. Final observed false creations, missed genuine issues, wrong terms/sources, wrong-issue/lifecycle updates and unsupported settlements were zero. The 48 richer-record evaluations additionally applied actual decisions and asserted lifecycle, preserved substance, applicable affinity and replay; the 28 tone evaluations checked their original creation/lifecycle expectations.

Acknowledged-event database fixtures checked exact persisted matter/parties/source references/history, simultaneous obligations, partial/full repayment, specific restitution, changed terms, missing/tampered evidence and replay. Also passed 36 regular PHP scripts, 10 database suites, 89 syntax checks and 3 package checks. Earlier failures were investigated and retained separately; expectations and confidence thresholds were not lowered.

## Limits, because PRE-ALPHA is not decorative

New records require the complete originating exchange to fit eight utterances of at most 640 characters each; otherwise creation abstains rather than lose terms. **This is an evidence budget, not an NPC cast cap.** Existing issue updates still run. Cross-batch acceptance remains unsupported. The 240-character reminder can elide details that remain in the full journal/evaluator context.

Real-model cases cover two simultaneous obligations, not full 24-issue fanout. Actual gameplay, audible delivery, inventory actions, native MO2 installation/sync and long-session behaviour are not established by these tests. Provider failures use existing retry/fallback handling. The live server was not changed.

[Verification and limits](../docs/verification/richer-records-0.1.9.md) · [Installation and setup](../README.md)

The attached standalone DWPkg and tar are alternative formats for supported admin/installer tooling; they are not additional dependencies for MO2 users. SHA256SUMS.txt is optional integrity evidence. Earlier release assets are unchanged.
