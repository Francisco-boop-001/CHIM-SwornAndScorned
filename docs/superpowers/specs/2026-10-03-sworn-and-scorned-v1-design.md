# Sworn & Scorned v1 (CHIM plugin) — Unfinished Business & Promises — design

Date: 2026-10-03. Status: design approved in brainstorming; not implemented.
Source notebook: `K:\ActorwrightExchange\projects\CHIM-Social-Drama-Ideas.md`.

## 1. Purpose

Give NPCs (and the player) concrete, persistent **grievances** and **promises** with each
other, so a later encounter has something specific to be about: Lydia wants an
explanation from Farkas; Farkas still owes Aela a favour. Issues are tracked per
perspective, evaluated asynchronously by a decision model, surfaced quietly in
conversation, and roll back with the save.

## 2. Boundaries

| Plugin | Owns |
| --- | --- |
| Mind Poisoning (MP) | Opinion change from what is said *about* a third party (per line). |
| Private Conversation (PCV) | Scene staging: who speaks, who hears. Scope closed at 0.1.14. |
| **Sworn & Scorned (this)** | Grievances and promises *between* two parties; lifecycle affinity only. |

- Works alone. Reads only CHIM data (`eventlog`, `core_npc_master`, the `_speech`
  acknowledgement). Never reads MP's ledger or PCV's state files.
- With PCV present, its exact witness lists (`eventlog.people` = scene members) make
  perspective filtering more precise; no PCV code is called.
- Never changes affinity per line (that is MP's and CHIM's). Changes affinity only on an
  issue lifecycle transition (§6). No overlap with MP by construction.
- Ext directory: `sworn_and_scorned` (sorts after `mind_poisoning` and `private_conversation`,
  so its hooks run last under `scandir` order). Prefixes: PHP namespace `ChimSwornAndScorned`,
  constants `SWORN_SCORNED_*`. Display name: "Sworn & Scorned" (chosen 2026-10-03).
- Never writes `HERIKA_PERS`; never overrides SHARMAT or other condition prompts.

## 3. Parties and cast

- Parties: NPC↔NPC and NPC↔player. The player's own perspective is not modelled; a
  player issue lives on the NPC's row.
- Lore Daedra may be counterparts (`daedra:<prince>`), from seeds only (§8).
- **Cast:** up to 8 NPCs chosen on the plugin page. An exchange is evaluated when its
  speaker or addressed listener is in the cast (the player always counts). Non-cast NPCs
  can still be subjects and can still surface lore seeds (§8), but get no evaluated changes.

## 4. Storage (CHIM PostgreSQL; no vector index in v1)

### 4.1 Perspective ledger (authoritative)

`core_npc_master.plugin_extended_data -> 'sworn_and_scorned'`, one per NPC row. Written with
`jsonb_set` on this key only, and snapshotted into `core_npc_master_history` on every
commit (MP's pattern), so CHIM restore rolls it back with the save.

```
{ playthrough_id, floor_event_id, processed: [event_id … ≤128],
  issues: [ {
    id,                 // hash(origin event or seed id, counterpart, kind)
    origin: event | lore,
    kind: grievance | promise,
    counterpart: "player" | "npc:<id>" | "daedra:<prince>",
    direction: promise → owed_by_me | owed_to_me
    strength:  promise → will | will_try
    status: grievance → open | resolved | let_go
            promise   → pending | fulfilled | disputed | broken | released
    trajectory: improving | worsening | unchanged
    wants: explanation | apology | restitution | keep_promise | thanks | unknown
    evidence: [ {event_id | source_ref, gamets, speaker, quote≤160, label, p, backend} ≤8 ],
    links: [issue id …],   // e.g. disputed promise → grievance
    affinity_applied: [transition key …],
    muted, dismissed       // out-of-character flags (§7)
  } … ≤24 open + ≤16 recently closed ]
}
```

- Perspectives may disagree: Lydia `owed_to_me / broken`, Farkas `owed_by_me / disputed`.
- Full dialogue stays in `eventlog`; the ledger keeps short quotes plus `event_id`.
- Over the cap, the oldest closed issues are dropped first.

### 4.2 Job queue (transient)

Table `sworn_scorned_jobs`, created lazily: `event_id` (unique), speaker, addressed
listener, people, playthrough_id, gamets, status, attempts, lease_until, last_error.
Append-only; one row per acknowledged line. **Never coalesced per NPC** (CHIM's
relationship queue upserts per NPC and would lose earlier commitments).

### 4.3 Pacing (transient)

Table `sworn_scorned_pacing`: (npc, issue id) → last surfaced time, surfaced event id.
Not snapshotted: pacing is not story state and must not be on the ledger write path.

## 5. Async pipeline

1. **Capture** (in request, ~1 ms): on the `_speech` acknowledgement, if the speaker or
   addressed listener is in the cast, insert a job. Only spoken rows; `instruction`
   rows (e.g. PCV "The Narrator: <direction>") are never evidence.
2. **Worker** (daemon, own process): modelled on CHIM's
   `ext/relationship_system/worker.php` — `pcntl_fork` daemon, PID file under `log/`,
   auto-start attempt from a hook when not running, ~2 s poll. It:
   - claims jobs with `FOR UPDATE SKIP LOCKED` and a lease;
   - groups by pair and waits for a **settle window** (pair quiet ~20 s, or 6 new lines);
   - evaluates each participant's perspective separately (§6), holding no DB lock during
     the provider call;
   - commits in a short transaction: per-NPC advisory lock then row lock (MP's order);
     revalidate (playthrough matches, source event still in `eventlog`, not already
     processed); write ledger key; apply lifecycle affinity as a relative change; insert
     history snapshot; delete jobs.
3. **Read** (context hook, ~1 ms): exact lookup of committed ledger; never waits.

If the daemon is not running, jobs wait; the page shows "worker stopped, N waiting" and
the next request retries the start. No inline processing on the chat path.

## 6. Evaluator

### 6.1 Backends

Interface: `ask(state, questions) → typed answers + confidence`. Adapters:

- **OpenRouter Decisions API** (`POST` System One endpoint); model id is a setting
  (default `typesafe/jev-1.13`). Any model on that API works without code changes.
- **CHIM LLM fallback** via CHIM's connector: questions rendered as a JSON prompt; each
  answer must be an allowed label plus `high|medium|low` self-confidence.
- Other decision providers: new adapter behind the same interface (not in v1).

Each adapter has its own thresholds. Every transition records the backend and model id.
No key → fallback adapter. Key and model id stored server-side; key never logged or echoed.

### 6.2 Questions (per perspective owner X about counterpart Y)

State: X's open issues with Y, and the settled exchange lines **X witnessed**
(X in `eventlog.people`), labelled L1…Ln.

Pass 1 — independent Nouls: for each open issue k, "does this exchange address k?";
"does someone commit to doing something?"; "does X express being wronged by Y?"

Pass 2 — Choices, only for Pass 1 positives:

| Finding | Questions |
| --- | --- |
| New promise | who committed (X/Y) · will / will_try · accepted / declined / unclear · which line |
| New grievance | which line · wants |
| Addressed promise | fulfilled / broken / renegotiated / released / disputed / no_change · trajectory |
| Addressed grievance | resolved / let_go / no_change · trajectory · wants |

The quote stored as evidence is the chosen line verbatim; nothing is generated.

### 6.3 Commit rules

- Status change: Jev p ≥ 0.80; LLM fallback only `high`. Otherwise no change.
- Silence is not evidence: an unaddressed promise stays `pending`.
- A promisee acknowledging delivery makes the promise fulfilled **in that perspective**;
  it is a belief, not a world fact.
- Renegotiation may create a new linked promise; a disputed/broken promise may create a
  linked grievance.

### 6.4 Lifecycle affinity

Once per transition key (`issueId:from→to`), only X's affinity toward Y, p ≥ 0.90
(fallback: `high`), clamped ±100. Starting values:

| Transition | Δ |
| --- | --- |
| promise → fulfilled (owed_to_me) | +2 |
| promise → broken (owed_to_me) | −3 |
| grievance → resolved | +2 |
| grievance → let_go | +1 |
| disputed / renegotiated | 0 |

Lore seeding is not a transition and never changes affinity. Dismissal never changes
affinity. Under `NEVER_CLEAR_RELATIONSHIP_DATA=true`, no writes (as MP).

## 7. Surfacing and director page

### 7.1 Context note

- On speaker X's turn, if X has an open, non-muted issue whose counterpart is present
  (addressed listener or witness; PCV scene members when staged; player issues only when
  the player is in the scene). For `daedra:` counterparts: when the prince is named in the
  current exchange.
- At most one issue per reply: most recently changed eligible issue.
- Cooldown ~10 real minutes per issue unless new evidence since last surfacing; skipped
  when the player's input names the counterpart.
- ≤240 characters, X's point of view, quoting evidence. Example: *On your mind:
  unresolved with Farkas. He said: "I'll stand with you before Aela." You want an
  explanation. Raise it only if it fits the moment.*
- Added beside condition prompts; never written to `eventlog`.
- Compliance logged: surfaced issue k on turn t vs. Pass 1 "addresses k" afterwards.
- Exact CHIM injection hook to be confirmed against `lib/prompt_injections.php` and
  PCV's context hooks.

### 7.2 Page

- Settings: decision API key, model id, backend status, enable/pause, cast picker (≤8).
- Worker: running/stopped, waiting jobs, failed jobs, last error, restart attempt.
- Journal: per cast member, issues with perspective, status, trajectory, wants, evidence,
  backend, pending evaluation. Spoiler-conscious by default (counts and counterparts;
  reveal per issue); explicit director view.
- Out-of-character controls: **dismiss** (labelled OOC override, no affinity) and
  **mute** (stops surfacing, keeps tracking). No generic undo in v1.

## 8. Lore grudges

- Shipped `lore_grudges.json` plus a user override file of the same format.
- Entry: two **named** parties (NPC↔NPC or NPC↔`daedra:<prince>`), direction
  (one-sided/mutual), kind (grievance), wants, one-line paraphrase, **required** source
  reference (UESP page). No group or faction membership expansion; "Gray-Mane vs
  Battle-Born" is listed as explicit named pairs.
- Applied lazily when the NPC's `core_npc_master` row first exists, as `origin: lore`
  issues; NPC↔NPC seeds then evolve like any issue (only cast members get evaluated
  changes). `daedra:` issues are surface-only in v1: a prince is never an exchange
  participant, so the evaluator does not change them; dismissal still applies.
- Represents vanilla starting state. Dead NPCs are never present; other divergence is
  corrected by play or dismissal.
- Curating the list is a separate research task; entries without a verifiable source do
  not ship.

## 9. Failure handling

| Situation | Behaviour |
| --- | --- |
| Provider down / timeout / error | Retries at 30 s, 2 min, 10 min; then fallback adapter; then `failed`, shown on page. |
| Answer outside allowed labels | No answer → no change. |
| Reload with job in flight | Commit-time revalidation discards the result. |
| Worker crash | Lease expiry; re-claim; dedupe makes it harmless. |
| Duplicate acknowledgement | Unique `event_id`; ledger processed list and floor. |
| Shared NPC row with MP / CHIM | Same lock order; `jsonb_set` own key; relative affinity update in transaction. |

## 10. Testing

1. Unit tests with a scripted fake backend: all transitions; thresholds per adapter;
   idempotent affinity; perspective isolation; Narrator rows excluded; stale rejection;
   lore seeding; pacing and override.
2. Replay corpus through real adapters: positive, negative, implicit, ambiguous;
   "I'll try"; promise then apology order; declined favour.
3. DB integration on the **test clone only** (never the live distro): concurrent writes
   with MP; reload in flight; snapshot/restore; worker start/stop/restart.
4. Coexistence: alone, +MP, +PCV, +both, with SHARMAT conditions.
5. In-game: surfacing compliance, chat overhead (~0), queue lag, repetition.

## 11. To verify before or during implementation

- CHIM's handling of `eventlog` rows on save load (stale-job check may need game time).
- `pcntl` / `posix` availability in the DwemerDistro PHP build.
- OpenRouter Decisions API request/response shape and limits for the configured model.
- Context injection hook and its order relative to SHARMAT and PCV.
- Acceptable `_chim_history_source` value for our snapshots.

## 12. Out of scope for v1 (deferred)

Vector index for long-term recall (v2: derived, rebuildable, pgvector if available,
filtered by pair and playthrough first); "Who Told You That?" (needs MP provenance);
Taking Sides, Confidences, Jealousy, Public Face; faction-level stances; NPC-initiated
openers; latecomers; generic undo; additional decision-provider adapters.
