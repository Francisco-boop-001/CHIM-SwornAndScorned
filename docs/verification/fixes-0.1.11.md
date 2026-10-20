# 0.1.11 verification and limits

Fresh final-source debug completed on 20 October 2026 in the registered disposable test clone. No live server was accessed or updated, and no real provider credentials or quota were used.

## Fresh checks

- **52 native regression invocations passed**, including idle, before-work, pass-one/pass-two and corresponding error-path playthrough pauses, ordinary pause/resume, lore yielding, repeated disputes, tone/substance, written-source processing, interaction commit guards, logging, keys and bounded worker execution.
- **106 release PHP files passed syntax checks.** Two preserved private integration fixtures and the two fresh debug fixtures were also linted; they are not additional published runtime files.
- **Two actual PostgreSQL fixtures passed** using a fresh socket-only cluster, separate blank synthetic databases and owned `sas_it` schemas. Model transport was deterministic; queue, typed adapter, ledger application, history and source readback were real.
- The written-source fixture preserved the exact 2,000-codepoint Unicode body, terms, parties, provenance and replay identity through the public queue and committed ledger/history. Replay made no model or ledger/history writes. Pauses after either evaluation pass retained the pending job's backend/failure count, cleared its lease and produced no stale ledger/history/source-finished diagnostic. A 2,001-codepoint body was rejected without truncation or effects.
- The repeated-dispute fixture processed an accepted 50-septim obligation, its first dispute and a further genuine grievance: three completed jobs, six deterministic model calls and three history snapshots. Original terms and reciprocal links stayed intact; the later grievance did not attach an invalid second dispute link. Replay, affinity, unrelated plugin state and original source rows were unchanged.
- **Three package checks passed**, covering package contents, MO2 metadata/layout and deterministic rebuilding. The MO2 ZIP contains its DWPkg; it needs no second package download.

Both fresh database fixtures removed their owned schemas and temporary state. Independent readback found zero fixture user relations and other sessions. Guarded identity checks stopped only the owned private cluster and holder; data/logs were preserved. The disposable distro returned to its initially stopped state. No other owner's artifacts were removed.

Source, fixture and wrapper reviews preceded execution. Final staged-source/privacy, clean-tag rebuild and public-download verification gate publication. Runtime and package hashes were checked before and after the debug fixtures.

## Earlier controlled evidence

The worker/switch correction also has actual CHIM Home-controller and PostgreSQL evidence on pinned HerikaServer `0ec590b42c8087860138bb4e9aab470049ff9a2b`: idle switching and switches held during either evaluation pass, recovery/replay and owned restoration passed. These earlier timings were 2.530 seconds idle, 1.261 seconds at pass one and 1.378 seconds at pass two. They are controlled fixture timings, not a general performance promise.

The fresh six-mode switch regressions use the pinned core runtime helper with controlled boundaries. The fresh PostgreSQL pause cases inject the pause predicate; they do not independently reproduce the Home UI. Earlier bounded-worker process-group/timeout evidence remains scoped to the supported foreground relationship helper. A deliberately escaping descendant remains outside that guarantee; quiet waiting plus two worst-case relationship requests can exhaust the outer 60-second budget. A successful child exit alone does not prove a particular source was processed.

## Asset identity

| Asset | SHA-256 |
| --- | --- |
| `sworn_and_scorned-0.1.11.dwpkg` | `ce2bf0a7ccdbb5dff7b48178952277afc135ea44945de7d19dac669198c35cb5` |
| `sworn_and_scorned-0.1.11-mo2.zip` | `afec490d48eb1b486d4c3670cb4a4865aed03fc05fd2fe35e85fa464285c3801` |
| `sworn_and_scorned.tar.gz` | `7b9dc5378ef3017ecff8ebd834c92863b59428b87ef021b10d0e58bc751f3716` |
| `SHA256SUMS.txt` | `145f1a7b466898743a0fea8b9ae28806dfabb04e172a41596ba3bd38b52731ae` |

## Remaining limits

Tone and richer-record rules remain intact; there is no new backend, service, schema or affinity policy. This debug run does not measure real Jev/relationship-LLM latency or general interpretation accuracy. Earlier real-provider checks support only their named cases/versions.

Skyrim gameplay, long sessions, physical delivery, audible dialogue, native MO2/game synchronization and the installed live-server state remain unverified by this work. Written evidence requires a compatible producer. A letter alone cannot establish recipient acceptance or a qualifying positive settlement. No historical grievance cleanup or live deployment was performed.
