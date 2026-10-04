# 0.1.5 candidate: Jev opt-in and re-audit corrections

Date: 2026-10-04. Local development candidate; this work did not commit, push, publish, or deploy to the gaming server.

## Behavior

- Default: CHIM's configured relationship LLM. Saving a dedicated key no longer implicitly selects Jev.
- **Use Jev — experimental** explicitly selects the Decisions API. Without an override, it reads the enabled CHIM relationship connector's OpenRouter credential through CHIM's resolver. It validates the driver and resolved HTTPS OpenRouter host, restores connector globals, and never copies that credential into plugin settings or the dashboard.
- A dedicated encrypted key remains an optional advanced override. An unreadable override pauses Jev; it does not block the default CHIM mode. Missing reusable credentials pause Jev before scanning or claiming work. Account provider restrictions still apply; genuine provider failures retain the existing retry/fallback policy.
- Upgrades retain dedicated keys, but users must explicitly enable the experimental toggle to continue using Jev. Casts already scoped to the current playthrough are retained; older global lists require reselection.

## Critique assessment

The updated critique contains three new findings. All are relevant and corrected:

- **N1:** global key save/removal and worker restart no longer require a resolvable playthrough. Session CSRF and key-entry HTTPS/local access gates remain enforced. Cast settings and issue mutations still use profile-bound tokens and reject stale or unavailable scopes.
- **N2:** a cast stored for another playthrough produces a reselection banner rather than silently appearing empty. One cast slot remains; the banner explains that saving replaces its previous selection.
- **N3:** CHIM's valid empty `relationships: []` is treated as an empty map for both Player and NPC affinity writes. Player writes use the canonical `Player` key. NPC SQL creates an object only after PHP accepts the map. Metadata, locks, transaction/history behavior and replay idempotency remain intact.

The author's baseline was preserved. N1's old action handler reproduced failed key removal with null scope; the current handler passed. N2's rendering and integration regressions verify the profile-switch explanation. N3's actual isolated clone regression reproduced the NPC SQL error before the fix and passed afterward.

## Verification

All PHP checks ran only on `DwemerAI4Skyrim3-test`: **34 syntax checks, 33 regular scripts and eight isolated database suites**, all passed. The suite includes default/experimental routing, cached/unsupported/wrong-host credentials, optional override selection, global restoration, encrypted-key recovery, stale forms, cast mismatch and both empty-map affinity paths.

The new backend integration runs the real Decisions adapter with a scripted transport: it verifies the endpoint, Jev model and selected authorization credential, zero default-mode Decisions calls, no Gemma call on the successful Jev path, and no credential persistence. A separate check used the installed CHIM badge resolver and confirmed a configured credential, unchanged model/globals and unchanged plugin settings. **No billed provider requests were made in this corrective task.**

The 240-batch scripted session and 240 crash replays again produced exactly 240 provider adapter calls, with 40 paused ticks consuming none. These are fixture/replay results, not live provider speed or accuracy measurements.

Two Python package tests passed. The builder verified the DWPkg, repository tar and MO2 wrapper for 0.1.5; the MO2 wrapper embeds the exact DWPkg. Earlier builds remain preserved. Checksums accompany the candidate assets.

The installed candidate passed eight HTTP checks: dashboard, invalid CSRF, experimental selection with real CHIM credential availability, rejection of scoped tokens on global actions, valid global restart while disabled, override removal preserving Jev selection, return to the CHIM LLM, and HTTP rejection of the private connector child. All **38 installed runtime files** matched source/package bytes. Owned public clone tables and the temporary installation were removed; NPC plugin state/history counts and 175 other extension files remained unchanged.

Independent source review found no blocking issue. Final artifact identities, scope and recognizable-secret scan results are retained with the private acceptance receipts.

## Remaining limits

Reused credentials retain CHIM's existing storage protection. This plugin's encryption applies only to its optional override. Account provider restrictions cannot be bypassed.

Non-empty malformed relationship arrays remain rejected and preserved, rather than overwritten. The existing `edge_invalid` job disposition can discard that evaluated transition; those corrupted rows need manual repair. This is distinct from CHIM's valid empty array representation, which is now supported.

History/failed-job/pacing retention, repeated earlier passes on provider retry, default-mode missing-credential handling and CHIM private-interface version drift remain maintenance concerns. Tests do not certify Skyrim gameplay, dialogue quality, real Jev semantic accuracy/latency, production concurrency, automatic MO2 transfer, or actual save migration.
