# Sworn & Scorned — implementation roadmap

Spec: `docs/superpowers/specs/2026-10-03-sworn-and-scorned-v1-design.md` (approved 2026-10-03).

The spec spans several subsystems. It is delivered as four plans; each produces
working, tested software on its own.

| Plan | Scope | Status |
| --- | --- | --- |
| **1. Domain core** | Pure PHP, no CHIM: ledger, transitions, witness filter, decision-backend contract and policies, questions, evaluator, apply rules (idempotent lifecycle affinity, links, caps), surfacing selection and note rendering. Spec §4.1, §6.2–6.4, §7.1 (selection/render), parts of §2a. | `2026-10-03-sworn-and-scorned-plan-1-domain-core.md` |
| **0. Clone verification** | Spikes on `DwemerAI4Skyrim3-test` only. Answers spec §11 plus items found while writing Plan 1 (below). Produces a findings doc, no product code. | To write after Plan 1 |
| **2. CHIM integration** | Manifest and packaging; `_speech` ACK capture and the `sworn_scorned_jobs` table; worker daemon (claim, lease, settle window, retries); store (locks, `jsonb_set`, relative affinity, history snapshot, revalidation); OpenRouter Decisions adapter and CHIM LLM fallback adapter; settings. | After Plan 0 |
| **3. Surfacing, page, lore** | Context hook wiring and `sworn_scorned_pacing`; director page (settings, cast, worker panel, journal, dismiss/mute); lore seed format, validator and lazy application; UESP-sourced seed list (research task); 8-combination coexistence matrix on the clone. | After Plan 2 |

## Plan 0 questions (collected so far)

1. Does CHIM delete or keep "future" `eventlog` rows when a save is loaded? (stale-job check)
2. `pcntl`/`posix` in the distro PHP; can a **plugin** daemon be started and stopped cleanly?
   CHIM's `start.sh` trap kills only its own relationship worker, so a plugin daemon needs its
   own shutdown and duplicate-instance guard. Does CHIM's package manager accept a plugin that
   starts a daemon? (Mind Poisoning's contributor rules forbid daemons *for MP*; check whether
   that reflects a CHIM packaging rule.)
3. OpenRouter Decisions API: request and response shape, limits, and errors for the Jev model.
4. Context injection hook and its order relative to SHARMAT and PCV; does our entry survive
   PCV's nearby-actor replacement?
5. Acceptable `_chim_history_source` value for our snapshots.
6. Exact `eventlog.type` values for NPC speech and player speech, and how the player's
   speaker name appears (Plan 1 assumes `chat`, `inputtext`, `inputtext_s`, `ginputtext`,
   `ginputtext_s`; see `server/witness.php`).
7. How MP handles a playthrough change in its ledger (Plan 1 resets to an empty ledger on a
   different `playthrough_id`, matching MP's single-playthrough ledger).
8. `mbstring` present in the distro PHP (Plan 1 Task 0 checks this).
