# Sworn & Scorned

**Part of the World of Drama-Llama.** Skyrim already has dragons, a civil war, and people who remember that you stole a cheese wheel. Now your companions can also remember that you promised to help and then wandered off to collect seventeen more cheese wheels. An ambitious expansion of the consequences industry.

**Sworn & Scorned tracks unfinished business: grievances, promises, and favours between NPCs and the player.** Each NPC keeps its own perspective. A later conversation can receive a small reminder that Farkas still owes an explanation, Lydia wants an apology, or somebody actually kept their word. The emo llama has acquired a ledger. This was probably inevitable.

![A broken oath ring lies between two estranged Nords on snowy mead-hall steps; an emo llama watches from the roof.](server/dashboard-art.webp)

**Release: [0.1.10 — PRE-ALPHA](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/releases/tag/sworn_and_scorned-v0.1.10).** The llama can now keep written evidence from compatible server plugins. Current promises and endorsements are assessed separately from enclosed old quotations; forwarding an IOU is not debt necromancy. Unsupported written settlement claims cannot quietly mark an issue as improving or refresh it.

**New in 0.1.10:** a recipient-only written-evidence interface, exact provenance/history and replay safeguards, clearer current-letter attribution, and the complete-update settlement guard. The tested logic passed 19 native suites and 15 real relationship-LLM quote cases on the disposable server. Earlier model mistakes remain documented; these samples do not establish an error rate. [Release notes](distribution/sworn_and_scorned-v0.1.10.md) · [Verification and limits](docs/verification/written-evidence-0.1.10.md) · [Server API](docs/written-evidence-v1.md).

This interface needs a compatible producer and does not itself send letters, create spontaneous offscreen NPC correspondence, transfer gifts/items, or implement forgery discovery. Existing spoken-conversation features remain available without a written-evidence producer. **Gameplay, physical delivery, audible dialogue and long-session behaviour remain unverified.** The llama still cannot audit your inventory.

**Since 0.1.9:** richer records preserve the particular wrong or accepted obligation, who owes whom, and established settlement conditions. Unknown terms stay unknown; partial repayment does not settle the entire obligation, and a favour does not magically become debt. The contextual tone correction distinguishes affectionate insults and combat banter from genuine hurt. Existing records and historical affinity are not automatically rewritten. The [0.1.9 verification](docs/verification/richer-records-0.1.9.md) covered 38 synthetic cases per real backend, including nine untouched cases each.

**Since 0.1.7:** the MO2 ZIP includes version/project metadata and one clear installation route. [Packaging verification](docs/verification/mo2-0.1.7.md).

**Since 0.1.6:** removes the eight-NPC cast cap, adds **Select all**, **Clear selection** and a selected count, and shows **Recent activity**. Install the update to change your running server; downloading it alone changes precisely nothing. The llama appreciates the thought.

[0.1.6 changes](distribution/sworn_and_scorned-v0.1.6.md) · [Verification and limits](docs/verification/dashboard-0.1.6.md)

[Earlier tone verification](docs/verification/tone-0.1.8.md). Existing mistaken grievances are not automatically removed.

## What it actually does

- Records supported grievances and promises from eligible conversation evidence, distinguishing a firm promise from “I will try.”
- Preserves the specific matter and originating evidence, including stated quantities, deadlines and settlement conditions. Later evidence cannot rewrite the original terms.
- Tracks what the NPC wants: an explanation, apology, restitution, a kept promise, thanks, or something still unclear.
- Revisits existing issues when later speech supports fulfilment, dispute, release, resolution, or letting go.
- Adds at most one short, paced reminder to an eligible speaker's prompt. The dialogue model decides what to do with it.
- Applies small CHIM affinity changes once per qualifying lifecycle transition.
- Provides a journal, Director view, mute/dismiss controls and worker status.
- Includes ten source-backed starting-state lore grudges. Whiterun was already doing quite well without our help.

It does not set Skyrim relationship ranks, complete quests, transfer items, or guarantee a confrontation. It judges conversation evidence; it is not secretly watching your inventory with a little accountant's visor.

## Install with MO2: download one file

**MO2 users only need [sworn_and_scorned-0.1.10-mo2.zip](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/releases/download/sworn_and_scorned-v0.1.10/sworn_and_scorned-0.1.10-mo2.zip).** Install it like a regular mod. The DWPkg is already inside; you do not download another package, put the DWPkg inside the ZIP, or extract PHP files into Skyrim's Data folder. The llama brought three containers, not three dependencies.

1. In MO2, choose **Install a new mod from an archive** and select the **`-mo2.zip`** file.
2. Keep the ZIP's root as the Data directory: `CHIM` must be directly below it. Do not select the inner `sworn_and_scorned` folder as Data. If MO2 says the content does not look valid, keep this layout and continue; this server-only mod contains no ESP, meshes or textures.
3. Enable the mod in MO2's **left pane**. When updating, replace the previous mod and keep only one version enabled. It has no ESP/ESL entry to tick in the right-hand Plugins pane.
4. Start your CHIM server, then **launch Skyrim/SKSE through MO2 and load a save**. A compatible CHIM client sends the bundled server package to the connected server. Installing the ZIP in MO2 alone does not perform the server installation.
5. Open CHIM's web **Plugin Manager → Server Plugins**, refresh **Automatic Game Plugin Sync**, and verify `sworn_and_scorned` shows the version you installed. Then open **Plugin Page** and follow Setup below. The server version is the completion check; MO2's checkbox is not.

For 0.1.10, MO2 should expose `Data/CHIM/server-plugins/sworn_and_scorned/0.1.10.dwpkg`. This is a **CHIM server extension**, with no ESP/ESL or Papyrus companion. Both your client and server must support game-bundled server-plugin synchronization, and the server needs PHP CLI worker execution. The older [verification record](docs/verification/release-0.1.1.md) identifies the tested platform; support across every historical CHIM build is not guaranteed. Actual MO2/game synchronization remains unverified in our test record.

The **0.1.10 MO2 ZIP** includes a minimal `meta.ini` for its version, project URL and installed-mod missing-content flag. It does not guarantee that MO2's initial installation-dialog warning disappears, add GitHub update polling, or repair CHIM connection errors. [Verification and limits](docs/verification/mo2-0.1.7.md).

### What are the other files?

These are alternative formats for supported server/admin tooling, **not extra steps for MO2 users**:

| File | Purpose |
| --- | --- |
| [Standalone DWPkg](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/releases/download/sworn_and_scorned-v0.1.10/sworn_and_scorned-0.1.10.dwpkg) | The same server package already inside the MO2 ZIP, for tools that explicitly accept CHIM packages. The inspected Plugin Manager has no manual-upload button; do not look for one. |
| [sworn_and_scorned.tar.gz](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/releases/download/sworn_and_scorned-v0.1.10/sworn_and_scorned.tar.gz) | Repository/channel installer format with one wrapper directory. It is not an MO2 download or a DWPkg upload. No official catalog listing has been submitted, and catalog installation remains untested. |
| [SHA256SUMS.txt](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/releases/download/sworn_and_scorned-v0.1.10/SHA256SUMS.txt) | Optional download-integrity checks. Nothing to install. |

Use **one authoritative installation route**. An old enabled MO2 package can compete with an admin-installed update. Do not stack versions or use multiple routes to repair an unexplained error.

### If installation reports an error

An MO2 **no game data/content** warning is about its Skyrim-content detector. For this package, first verify the Data/CHIM layout above; it is not a CHIM upload error. Older 0.1.6 packages do not include the new MO2 metadata.

If CHIM says **“Could not read automatic package status”**, that message concerns its status request, not necessarily the archive. Verify the game is connected to the intended server, use **Refresh Status**, and retain the exact error and the status/response from `ui/api/plugin_packages.php?action=packages` for troubleshooting. Do not post API keys or full conversation logs. A missing row means sync has not been confirmed. A red error is not a successful installation; do not ignore it or repeatedly install all three formats. The quoted user report did not include enough detail to establish its cause.

**This release includes encrypted storage for an optional dedicated OpenRouter key.** Reusing CHIM credentials needs no extra key setup. Only dedicated overrides need the private master key described below. Older **0.1.1 packages store saved keys as plaintext**; downloading an update does not change the installed server copy.

This release is **0.1.10 — PRE-ALPHA**. On upgrading from 0.1.2, **reselect and save your cast**: old global NPC IDs are intentionally not carried into another playthrough. Player affinity uses CHIM's canonical `Player` relationship; journal names still use your character's name. **Jev now requires the explicit experimental toggle**, even if you previously saved a plugin key. Existing dedicated keys are retained.

[Published release notes](distribution/sworn_and_scorned-v0.1.10.md)

## Setup: nominate your disasters

1. Open Sworn & Scorned's **Plugin Page**.
2. Enable it; the feature starts **off**.
3. Select the NPCs to track and **Save settings**. There is no plugin-imposed cast cap: select any number of known NPCs. **Select all** selects the current list; it does not save automatically or enrol future NPCs. More eligible conversations mean more queued work and potential API charges. Without a cast, speech is not queued. Issues belong to NPC owners; the player or another NPC can be the counterpart.
4. Leave **Use Jev — experimental** off for your CHIM relationship LLM, or enable it to reuse CHIM's OpenRouter credentials.
5. Check backend availability and worker status. Use **Restart worker** if needed.

### Jev or your CHIM relationship model?

| Configuration | Evaluator used |
| --- | --- |
| **Use Jev — experimental** off (default) | CHIM's configured relationship LLM connector, when enabled and using **`openrouterjson`** or **`openrouterjsoncached`**. |
| **Use Jev — experimental** on | OpenRouter Decisions API, default model **`typesafe/jev-1.13`**, using the enabled CHIM relationship connector's OpenRouter key. The decision model ID is editable. |
| Jev on, with an optional dedicated key under **Advanced** | The dedicated encrypted key overrides CHIM's credential. Useful for separate spending limits; saving a key does not enable Jev. |

**The package ships no API key.** Jev can handle small structured decisions faster and reduce calls to your usual relationship LLM. It stays experimental because Skyrim-conversation interpretation has limited validation and the Decisions API is alpha. It is not your dialogue model and does not take over other plugins. The test server used `google/gemma-4-31b-it`; Gemma is not a bundled requirement.

Credential reuse reads the selected **`RELLLM_CONNECTOR`** through CHIM's own resolver. The connector must be enabled, use an OpenRouter JSON driver, and resolve to an HTTPS `openrouter.ai` endpoint with a key. No arbitrary connector or unrelated provider key is borrowed, and no reused credential is copied into this plugin's settings or exposed on the page. If none is available, the page explains the pause. The plugin cannot override your account's Typesafe allowlist.

**Only if saving an optional dedicated Jev key**, an administrator must provision this plugin's private master key once. Reusing CHIM credentials needs no additional master key. On the Linux CHIM server, with PHP sodium and POSIX available:

```sh
sudo php /var/www/html/HerikaServer/ext/sworn_and_scorned/provision_key.php
```

Adjust the plugin location if your server lives elsewhere. This creates **`/var/lib/chim-secrets/sworn_and_scorned.key`**, outside the web root and database: 32 random bytes, owned by `www-data`, file mode `0600`, directory mode `0700`. The helper targets CHIM's `www-data` service account and refuses to overwrite an existing key. An installation with a different PHP/worker account needs its administrator to create the same private layout owned by that account. The web process and CLI worker must use the same master key; optional `CHIM_SWORN_SCORNED_KEY_FILE` must be an absolute private path configured consistently for both.

Existing plaintext plugin keys migrate on the first settings load after provisioning, before they can be used. If migration cannot persist, or the master key is missing, insecure or wrong, **Jev evaluation is blocked while that override is selected**. The default CHIM relationship mode remains available. The dashboard's key and restart controls work even without a resolvable playthrough; cast and issue changes still require one. No need to interrogate the llama with a soldering iron.

Transient failures retry after **30 seconds, 2 minutes and 10 minutes**. After the Decisions retry budget, an available supported CHIM fallback is attempted. Exhausted/unrecoverable jobs stay visible as failed. The page shows pending/running/failed counts and fixed failure codes. If Jev has no permitted provider, check whether your OpenRouter account allows Typesafe; the plugin cannot override that policy. Provider calls, retries and fallback can incur charges.

## How the drama moves

**Speech → NPC perspective → model judgment → guarded ledger → later prompt reminder.**

The worker reads eligible player speech and acknowledged NPC speech from CHIM's event log. Scene directions, narrator instructions, background events and injected reminders are not treated as something an NPC already said. Audience evidence limits whose perspective is evaluated; it does not prove physical hearing or secrecy in Skyrim.

After an exchange settles, evaluation runs asynchronously. The model proposes changes; validation and transaction checks decide what can be stored. Provider calls happen outside database locks. Before a conversation-based write commits, the plugin rechecks events, identities, current scope, enabled state and cast membership. Replaying evidence cannot keep manufacturing history or affinity.

On a later eligible turn, an open, unmuted issue may add a prompt note of **at most 240 characters** in additive `character_bottom` context. It does not replace character condition prompts. Cooldowns limit repetition; new evidence or an eligible player mention can make an issue relevant again. The dialogue model can ignore or misunderstand the note.

These are illustrative possible outcomes, not gameplay recordings:

| Conversation | Possible tracked result |
| --- | --- |
| Farkas promises Lydia help; Lydia accepts | Lydia records a promise owed to her by Farkas. |
| Farkas later says he will not honour it | Lydia's evidence may support a broken or disputed promise. |
| Lydia acknowledges that he kept his word | Her existing promise may be fulfilled. |
| Somebody offers a vague “sorry” | The issue can remain open. Apologies are not universal solvent. |

**Recognition is not guaranteed.** Processing follows source order in consecutive segments addressed to the same counterpart. An intervening exchange with someone else splits context; a promise and its acceptance on opposite sides may go unrecognised. Silence does not mean forgiveness, forgetting, or agreement.

## Affinity: small consequences, once

Only the NPC owner's CHIM opinion of the counterpart changes. Decisions API lifecycle status changes require confidence of at least **0.80**; an affinity change additionally requires **0.90**. The CHIM fallback requires its validated `high` threshold (**0.95**) for both. Relative changes are clamped to **−100 through +100**. Locked profiles receive no affinity update.

| Qualifying transition | Affinity change |
| --- | ---: |
| Promise owed to the owner is fulfilled | +2 |
| Promise owed to the owner is broken | −3 |
| Grievance is resolved | +2 |
| Grievance is let go | +1 |
| Promise is disputed or renegotiated | 0 |

These are lifecycle effects, not points per line. Lore seeding and out-of-character controls **never change affinity**. Dismissal does not undo a change already written. Nobody gets infinite friendship points for repeatedly mentioning that one favour from last Tuesday.

## The journal, or the llama's paperwork

The default journal keeps evidence collapsed. **Director view** reveals the details and what the owner wants. **Mute / Unmute** stops or restores prompt surfacing while tracking continues. **Dismiss (Out-of-character)** closes an issue without inventing an in-world reconciliation or changing affinity.

Opening evidence or Director view reveals the ledger. The default view avoids immediately spilling the details; it is not a secrecy mechanism.

Director reveals **recorded** issues and their evidence; it does not generate drama on demand. With an empty ledger, there is nothing to reveal. The 0.1.6 page says that explicitly, because clicking a mysterious button and getting the same nothing is not an interface feature.

## Lore grudges: locally sourced resentment

Ten directional starting-state seeds ship for eight NPC owners:

**Olfrid → Eorlund; Olfrid → Vignar; Vignar → Olfrid; Fralia → Olfrid; Fralia → Idolaf; Elisif → Ulfric; Erandur → Vaermina; Logrolf → Molag Bal; Carlotta Valentia → Mikael; Lars → Braith.**

[Source notes](docs/lore-sources.md) explain each dispute and link its UESP reference. No future quest outcome is assumed. Mutual feuds use two explicit directions; factions are not expanded into grudges against every member.

When the worker runs, a matching owner can be seeded even outside the cast. Missing or ambiguous NPC counterparts defer that seed. Daedric entries are **surface-only**, eligible when the prince is named in the current exchange; Vaermina has not joined your evaluation queue.

An optional override goes at `<HerikaServer>/data/sworn_and_scorned_lore.json`. Follow [the built-in version-1 structure](server/lore_grudges.json): `version`, then `seeds` with `id`, `owner`, `counterpart`, `wants`, `paraphrase` and a UESP Skyrim-page `source`. Matching IDs replace built-in entries. Invalid files or entries are not applied.

## Fits beside the other bad ideas

- **[Mind Poisoning](https://github.com/Francisco-boop-001/CHIM-MindPoisoning):** opinions influenced by what A says to B about C. This plugin tracks unfinished business between the parties themselves.
- **[Private Conversation](https://github.com/Francisco-boop-001/CHIM-PrivateConversation):** scene staging and participant scope. This plugin observes eligible evidence from those scenes.
- **SHARMAT (`aiagent_nsfw`):** its condition context remains beside the additive issue note.

All **eight on/off combinations** of Mind Poisoning **0.1.17**, Private Conversation **0.1.14** and SHARMAT **3.1.9.3** passed bounded prompt/storage checks on the disposable server. Queues drained, protected data stayed unchanged, no new fatal/parse errors appeared, and excluded-player scenes received no player-issue note. The core relationship worker was isolated for storage comparisons: **simultaneous production scheduling and condition-driven game behaviour remain unverified**. [Verification scope](docs/verification/release-0.1.1.md)

## Logs, keys, and things you should not post in a bug report

Structured JSON entries use CHIM's logger, normally **`<HerikaServer>/log/chim.log`**, tagged `"plugin":"sworn_and_scorned"`. Events cover worker starts/stops/errors, evaluations and issue surfacing. PHP's error log is the fallback. Entries contain IDs, backend identity, outcomes and fixed reason codes. There is no dedicated downloadable-log viewer in this release.

The candidate deliberately pauses evaluation while **`NEVER_CLEAR_RELATIONSHIP_DATA` is enabled**, because restoring an older issue ledger beside newer affinity would make the llama's accounts disagree. The page explains the pause; scans, cursors and queued jobs are retained. An unreadable encrypted override also pauses Jev without falling back or repeatedly launching workers. An already running worker logs a pause once and resumes when the condition is repaired. Cast selection and surfacing cooldowns are scoped to the active CHIM playthrough. Changing profiles shows why the previous cast is inactive; saving replaces the previous cast selection. Stale cast/issue forms cannot rebind old row IDs silently, while global key and restart forms remain usable.

The CHIM fallback runs in a fresh PHP CLI process with a **20-second total deadline per model call**, supervised by Linux's **`/usr/bin/timeout`**. It reloads the connector key inside that child; credentials do not travel through command arguments or its input pipe. Linux CLI, `proc_open` and that executable are required; unavailable execution fails closed. This was tested on the disposable WSL server. It also means a provider cannot keep the one worker hostage by dribbling out bytes forever.

**Plugin logs omit raw dialogue, prompts, provider responses and credentials.** CHIM core can log full prompts; providers receive the conversation evidence needed for evaluation. Their retention is outside this plugin. This is not a private-conversation vault wearing a llama sticker.

In 0.1.6, plugin records use UTC timestamps, a schema version, fixed event/reason codes and validated IDs/counts. Settings saves, rejected forms, key changes (never the key), worker events and job duration are visible in CHIM's `log/chim.log`; worker stdout/stderr still use `log/sworn_scorned_worker.log`. **Recent activity** shows the newest 50 safe plugin records within the last 256 KiB, across playthroughs; reload to refresh. CHIM owns log retention and access permissions. Restricted log levels or an unavailable logger can hide ordinary events; PHP's error log is the fallback. Logging failures never prevent an evaluation or settings save.

On **0.1.2**, saved plugin keys use authenticated **libsodium secretbox** encryption with a fresh random nonce on every save. The database contains a versioned ciphertext envelope, while the master key stays in the separate private file above. Keys are never prefilled on the page. Provider requests use HTTPS. Page access requires exact localhost without forwarded headers or a server-authenticated user; mutations use CSRF protection. Remote key entry additionally requires HTTPS recognised by the web server. Direct localhost HTTP remains available for local setup; client-supplied forwarded-protocol headers cannot authorise key entry. TLS-terminating proxies must configure trustworthy server HTTPS state.

**This protects a database-only leak**, not a compromised server. Root, the PHP service account and compromised code running as that account can access a key while it is used. A backup containing both the database and master key can decrypt it. Reused CHIM credentials retain CHIM's existing storage; this encryption covers only the optional key saved by this plugin. An optional dedicated spending-limited key can isolate this plugin's provider budget.

**Back up the master key separately and securely from the database.** Losing it means replacing the saved API key; creating a new master does not recover old ciphertext. Do not rotate the master file blindly. **Clear key** removes the current plugin override. With Jev enabled, it returns to CHIM's OpenRouter credentials; with Jev disabled, the CHIM relationship LLM is used. It neither revokes the key at OpenRouter nor erases old backups, WAL or historical plaintext copies. Rotate a previously exposed provider key yourself. Encryption is useful; retroactive amnesia is still outside the budget.

[0.1.2 security verification](docs/verification/key-encryption-0.1.2.md): real crypto/filesystem tests, encrypted-save and migration integration, recovery controls, HTTPS gates and independent review. Administrators choosing a custom master path must keep it outside **every** served directory, including nonstandard web roots.

## Tested, and not magically tested

The **0.1.5 candidate** passed 34 syntax checks, 33 regular scripts, eight synthetic database suites, two package tests and eight installed-dashboard HTTP checks. Jev credential reuse was checked against the real CHIM resolver without billing a provider. Experimental/default routing, global key/restart controls, cast-switch guidance and empty Player/NPC relationship maps have regression coverage. [Jev toggle verification and remaining limits](docs/verification/jev-toggle-0.1.5.md).

The **0.1.4 candidate** passed 34 syntax checks, 32 regular scripts, seven synthetic database suites and two package checks. A 240-batch session with 240 simulated crash replays made exactly one provider pass per batch; pause, profile-scope, canonical player affinity and native HTTP deadline regressions passed. The installed dashboard passed its HTTP checks and the clone fixture was restored. [Correction verification and remaining limits](docs/verification/critique-fixes-0.1.4.md).

Acceptance included **31 PHP syntax checks, 28 unit/regression scripts, five isolated database suites, two package checks, 14 real-adapter synthetic replays, browser controls, simulated-client exchanges and eight coexistence combinations**. Independent review findings were fixed before final acceptance. [Detailed record](docs/verification/release-0.1.1.md)

Both Jev and the tested Gemma fallback recognised firm/implicit promises and created no issues for negative, ambiguous or declined cases. Both identified tentative promises with different confidence. In the apology case, **Gemma inferred a grievance; Jev inferred none**. Useful observations, not a scientific certificate that either model understands your marriage.

**Still unverified:** gameplay, dialogue quality, long sessions, save migration, production concurrency/latency, automatic MO2 transfer and general interpretation reliability. Disabling/removing the plugin stops future evaluation, without erasing ledgers or reversing affinity. Disabling the MO2 carrier does not uninstall the server copy. State uses CHIM's playthrough/restore policy; changing Skyrim saves alone does not guarantee a fresh database scope. Back up the server before experiments you want to reverse.

## For people who open the source

Runtime code lives in [`server/`](server/), checks in [`tests/`](tests/), and packaging in [`scripts/build-package.py`](scripts/build-package.py). Namespaced NPC ledgers hold issues; owned tables hold jobs, scan cursors, settings and pacing. Ledgers are bounded to 24 open and 16 closed issues, with bounded evidence and replay tracking. History snapshots and qualifying affinity effects commit together.

Build release formats with `python scripts/build-package.py --format release`; check packaging with `python tests/package_check.py`. Run PHP checks with `bash scripts/run-php-tests.sh` on the designated disposable server. Database fixtures require explicit `SAS_IT=1` and refuse to run outside that clone. Do not point them at your gaming database and call it courage.

Report installed plugin/CHIM/client versions, backend/model, cast/settings, reproduction steps, expected/observed results and filtered plugin logs. Omit keys and private conversation dumps. “They are being weird” is excellent Skyrim commentary and an incomplete bug report.

[Issues and support](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/issues) · [World of Drama-Llama](https://github.com/Francisco-boop-001/CHIM-Plugins)

Maintained by [Francisco](https://github.com/Francisco-boop-001), built for [CHIM by Dwemer Dynamics](https://github.com/Dwemer-Dynamics/CHIM). They built the platform. I gave the llama a grudge book.
