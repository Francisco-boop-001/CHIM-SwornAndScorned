# 0.1.4 critique corrections — candidate verification

This candidate fixes the confirmed correctness defects reported against 0.1.2. It has not been published or deployed to the gaming server.

- Player affinity writes use CHIM's canonical `Player` target and its own alias normalizer. Tests cover strong/weak existing edges, alias-only and competing aliases, metadata, locked profiles/relationships and replay. Other NPC relationship edges are preserved.
- Restore policy and unreadable saved keys pause before scanning, advancing cursors, claiming jobs or calling providers. A policy change during evaluation is reloaded at commit and retains the job without spending its retry budget. The dashboard explains recovery; a running worker pauses once and resumes automatically.
- Cast IDs are bound to the selected CHIM playthrough. Legacy global cast lists require reselection. Stale dashboard forms expire after a profile switch, and saves revalidate their expected scope. Surfacing cooldowns are namespaced by playthrough; player presence is case-insensitive.
- The fallback uses CHIM's existing connector in a fresh PHP process supervised by GNU `timeout`, with a 20-second total deadline per call. Credentials are resolved inside the child. Native trickling HTTP response testing stopped at approximately 1.003 seconds for a one-second test deadline. The initial PHP signal approach failed that real stream test and was replaced.
- Documentation distinguishes Decisions status confidence 0.80 from affinity confidence 0.90; the fallback requires validated high confidence (0.95).

## Executed checks

All PHP execution used the registered disposable WSL test clone. Synthetic SQL fixtures used only the isolated `sas_it` schema; the installed-page check used newly owned clone plugin tables and restored them afterward.

| Check | Result |
|---|---|
| Runtime PHP syntax | 34 / 34 |
| Regular unit and regression scripts | 32 / 32 |
| Synthetic database suites | 7 / 7 |
| Package tests | 2 / 2 |
| Installed candidate dashboard GET | HTTP 200 |
| Invalid/stale form rejection | HTTP 400 |
| Valid disabled-settings POST | HTTP 200 |
| Private CLI entrypoint through HTTP | HTTP 404 |

The extended synthetic session committed 240 negative batches, then simulated a crash after each commit and replayed all 240 jobs. Exactly 240 provider-adapter passes and 240 history snapshots occurred: retries made neither duplicate calls nor snapshots. Forty paused ticks made no provider calls and did not move the cursor. A separate database connection holding the owner's row lock prevented a concurrent commit; after release, retry succeeded.

Those 240 full-row snapshots occupied approximately 5 MB. Median commit time was approximately 33 ms and the 95th percentile approximately 41 ms on this clone with scripted provider responses. These are fixture measurements, not production latency or provider accuracy claims. Retaining processed-event bookkeeping and history preserves replay and restore behavior; skipping a no-issue commit would break the tested crash case.

The 38 installed runtime files matched source and archive bytes. The HTTP test restored its owned files and tables. NPC plugin-data identity and history count were unchanged, and all 175 other extension files matched their before hashes. No actual provider credential was used or provider request charged during these corrective checks. Recognizable-secret scanning found no matches in the reviewed source/package set.

## Remaining limits

No blocking defect remains in the exercised paths after independent review. History growth, failed-job/pacing retention and repeated earlier evaluation passes during provider retries remain maintenance concerns. CHIM private database/connector interfaces remain version-sensitive. Tests do not certify Skyrim gameplay, model interpretation, live provider latency, actual save migration or simultaneous production scheduling of all plugins.

On upgrading, reselect and save the cast. Linux CLI, `proc_open` and `/usr/bin/timeout` are required for the bounded CHIM fallback; unavailable execution fails closed. The existing encrypted-key provisioning and backup requirements still apply.
