# Sworn & Scorned 0.1.1 verification scope

Acceptance completed on 2026-10-04 on an authorised disposable CHIM server with a simulated game client. These are server observations and synthetic checks, not Skyrim gameplay evidence. Private receipts, profile snapshots and screenshots are retained outside the public source set.

Tested DWPkg SHA-256: `38d1998e9b73d324ca4e717df5a510b909f33941621b9d5642fa61bebe995118`. All 35 runtime files matched source, archive and installed bytes. Installed HerikaServer `main.php` SHA-256: `ef2ff9a50203820ca5416f334504f352793ee5413053e3b7beee1a222c8e721b`; deployed Git revision unverified.

## Automated and database checks

31 runtime PHP files were syntax-clean; 28 regular scripts and both package integrity/path tests passed. All five isolated database suites passed; their disposable schema was confirmed absent afterward.

| Suite | Covered behaviour |
| --- | --- |
| Queue | Ordering, duplicate prevention, no-key fallback, retry delays, exclusive claims, failed-job visibility and unacknowledged-speech cursor handling |
| Store | Own-namespace preservation, history snapshots, replay no-op, affinity 10→7 for a broken promise, profile/busy locks, missing events, restore policy and stale scope/identity/enabled/cast rejection |
| Lore | Seed-once behaviour, deferred counterparts, ambiguity/corruption rejection, history and no affinity changes |
| Hook | Audience eligibility, pacing, included/excluded-player rules, new evidence, acknowledged Daedric mentions, no evidence writes and disabled behaviour |
| Page | Atomic settings rollback, scoped status, mute/dismiss isolation and history, unchanged affinity and corrupt-ledger handling |

Independent review found four Important issues: in-flight counterpart identity, original issue context, interleaved ordering and fresh enabled/cast checks. Reproductions preceded fixes; recheck found no remaining blocker in that scope. A one-shot worker error-exit defect was also corrected. Redundant fallback connector identity reads remain a nonblocking minor.

## Models and live server

Seven cases ran through both Decisions `typesafe/jev-1.13` and CHIM fallback `google/gemma-4-31b-it`: 14 case/backend combinations. Both identified firm/implicit and tentative promises; negative, ambiguous and declined cases created no issue. Gemma inferred a grievance in the apology case; Jev inferred none. This small corpus does not certify general semantic reliability.

The real package manager installed/uninstalled the candidate. A simulated exchange produced acknowledged replies, an evaluated ledger, one history snapshot and an empty queue; it inferred no issue or affinity change. Daemon startup and exit after disable were observed.

Browser checks verified enabled/cast persistence, backend/worker status, collapsed/Director views, restart and mute/unmute/dismiss. Each out-of-character mutation produced exactly one snapshot without changing affinity or other namespaces. Simulated exchanges verified surfacing, cooldown and mute suppression. Forwarded access returned HTTP403, invalid CSRF HTTP400, and no-store/CSP headers were present. No key was prefilled.

## Coexistence matrix

MP 0.1.17, PCV 0.1.14 and SHARMAT 3.1.9.3 (`aiagent_nsfw`) were tested in all combinations. `1` means present; `0` absent.

| MP / PCV / SHARMAT | Jobs remaining | Protected storage diff | New fatal/parse errors | Condition context | Excluded-player issue note |
| --- | ---: | --- | --- | --- | --- |
| 000 | 0 | empty | 0 / 0 | n/a | n/a |
| 100 | 0 | empty | 0 / 0 | n/a | n/a |
| 010 | 0 | empty | 0 / 0 | n/a | absent |
| 001 | 0 | empty | 0 / 0 | preserved | n/a |
| 110 | 0 | empty | 0 / 0 | n/a | absent |
| 101 | 0 | empty | 0 / 0 | preserved | n/a |
| 011 | 0 | empty | 0 / 0 | preserved | absent |
| 111 | 0 | empty | 0 / 0 | preserved | absent |

All rows passed. The additive note coexisted with an actual staged SHARMAT alcohol-state condition. The core relationship worker was isolated for storage comparisons; simultaneous production scheduling and in-game intoxication behaviour are not certified. Some cases needed four ticks; bounded drain asserted actual progress. Fresh presence reports were needed before private-scene arms. Initial fixture failures and corrections were retained locally.

Other-plugin files/state were restored, owned fixtures removed, services stopped and the test server shut down. The gaming server was not started.

## Distribution and limits

MO2 layout is exactly `CHIM/server-plugins/sworn_and_scorned/0.1.1.dwpkg`; embedded bytes equal the tested DWPkg. CRCs, source membership and checksums were verified. The tar uses one `sworn_and_scorned/` wrapper. Automatic MO2 transfer and catalog-style installation were not exercised; the DWPkg manager route was exercised.

Gameplay, dialogue quality, long sessions, save migration, production concurrency/latency, physical secrecy and general model reliability remain unverified. Consecutive counterpart segments can separate commitment from acceptance. Plugin logs omit raw dialogue/keys; CHIM/provider logs are outside that boundary. Saved keys are unencrypted in the server database. [Player guide](../../README.md)
