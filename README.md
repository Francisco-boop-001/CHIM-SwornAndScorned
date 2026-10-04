# Sworn & Scorned

**Part of the World of Drama-Llama.** Skyrim already has dragons, a civil war, and people who remember that you stole a cheese wheel. Now your companions can also remember that you promised to help and then wandered off to collect seventeen more cheese wheels. An ambitious expansion of the consequences industry.

**Sworn & Scorned tracks unfinished business: grievances, promises, and favours between NPCs and the player.** Each NPC keeps its own perspective. A later conversation can receive a small reminder that Farkas still owes an explanation, Lydia wants an apology, or somebody actually kept their word. The emo llama has acquired a ledger. This was probably inevitable.

![A broken oath ring lies between two estranged Nords on snowy mead-hall steps; an emo llama watches from the roof.](server/dashboard-art.webp)

**Release: [0.1.1 — PRE-ALPHA](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/releases/tag/sworn_and_scorned-v0.1.1).** Tested on a disposable CHIM server with synthetic fixtures, real model calls, and a simulated game client. **In-game dialogue quality and long-session behaviour remain unverified.** The llama can hold a clipboard; it cannot certify your save.

## What it actually does

- Records supported grievances and promises from eligible conversation evidence, distinguishing a firm promise from “I will try.”
- Tracks what the NPC wants: an explanation, apology, restitution, a kept promise, thanks, or something still unclear.
- Revisits existing issues when later speech supports fulfilment, dispute, release, resolution, or letting go.
- Adds at most one short, paced reminder to an eligible speaker's prompt. The dialogue model decides what to do with it.
- Applies small CHIM affinity changes once per qualifying lifecycle transition.
- Provides a journal, Director view, mute/dismiss controls and worker status.
- Includes ten source-backed starting-state lore grudges. Whiterun was already doing quite well without our help.

It does not set Skyrim relationship ranks, complete quests, transfer items, or guarantee a confrontation. It judges conversation evidence; it is not secretly watching your inventory with a little accountant's visor.

## Install: pick one route

**0.1.2 adds encrypted storage for this plugin's saved OpenRouter key.** Install the new version and provision its private master key as described below. Older **0.1.1 packages store saved keys as plaintext**; downloading an update does not change the installed server copy.

This is a **CHIM server extension** using CHIM's existing client. It adds no ESP/ESL, Papyrus companion, or Skyrim plugin slot. You need CHIM support for server-plugin packages, the conversation evidence/hooks this plugin uses, and PHP CLI worker execution on the server. The [verification record](docs/verification/release-0.1.1.md) identifies the tested server; this is not a claim that every historical CHIM build supports it.

| Route | Download | What to do |
| --- | --- | --- |
| **MO2** | [MO2 ZIP](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/releases/download/sworn_and_scorned-v0.1.2/sworn_and_scorned-0.1.2-mo2.zip) | Import as a mod, enable it, and let a compatible CHIM client sync the embedded server package. |
| **Plugin Manager upload** | [DWPkg](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/releases/download/sworn_and_scorned-v0.1.2/sworn_and_scorned-0.1.2.dwpkg) | Upload through CHIM's Plugin Manager. The installation route was exercised for 0.1.1; 0.1.2 has package and isolated runtime checks. |
| **Repository installer** | [Tar archive](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/releases/download/sworn_and_scorned-v0.1.2/sworn_and_scorned.tar.gz) | Separate installer format: one `sworn_and_scorned/` wrapper directory is stripped during installation. Catalog-style installation was not exercised. |

[SHA-256 checksums](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/releases/download/sworn_and_scorned-v0.1.2/SHA256SUMS.txt) · [Release notes](distribution/sworn_and_scorned-v0.1.2.md)

The MO2 archive puts `CHIM/server-plugins/sworn_and_scorned/0.1.2.dwpkg` directly under the virtual Data root. **Do not unpack the inner DWPkg into Data.** MO2 may warn that this server-only mod has no recognised game content; it genuinely contains no ESP. No ceremonial dummy plugin has been sacrificed to appease it.

**Use one installation source and one enabled version.** Replace an older enabled package rather than stacking versions or letting an old sync package compete with a Plugin Manager update. After installation, verify **0.1.2 in the server's Plugin Manager**, then open **Plugin Page**. A download filename is not running-code evidence. MO2 layout and embedded bytes were verified; automatic MO2-to-server transfer remains untested. No official CHIM catalog listing has been submitted.

## Setup: nominate your disasters

1. Open Sworn & Scorned's **Plugin Page**.
2. Enable it; the feature starts **off**.
3. Select up to **eight NPCs** as the conversation cast and save. Without a cast, speech is not queued. Issues belong to NPC owners; the player or another NPC can be the counterpart.
4. Configure a backend below.
5. Check backend availability and worker status. Use **Restart worker** if needed.

### Jev or your CHIM relationship model?

| Configuration | Evaluator used |
| --- | --- |
| Save an OpenRouter key on this plugin's page | OpenRouter Decisions API, default model **`typesafe/jev-1.13`**. The decision model ID is editable. |
| Leave the plugin key empty | CHIM's configured relationship LLM connector, when enabled and using **`openrouterjson`** or **`openrouterjsoncached`**. Other drivers are unsupported by this fallback. |

**The package ships no API key.** Installation does not automatically activate Jev. Without a plugin key, the supported CHIM connector selects the model. The test server used `google/gemma-4-31b-it`; Gemma is not a bundled requirement.

**Before saving a plugin key on 0.1.2**, an administrator must provision its private master key once. On the Linux CHIM server, with PHP sodium and POSIX available:

```sh
sudo php /var/www/html/HerikaServer/ext/sworn_and_scorned/provision_key.php
```

Adjust the plugin location if your server lives elsewhere. This creates **`/var/lib/chim-secrets/sworn_and_scorned.key`**, outside the web root and database: 32 random bytes, owned by `www-data`, file mode `0600`, directory mode `0700`. The helper targets CHIM's `www-data` service account and refuses to overwrite an existing key. An installation with a different PHP/worker account needs its administrator to create the same private layout owned by that account. The web process and CLI worker must use the same master key; optional `CHIM_SWORN_SCORNED_KEY_FILE` must be an absolute private path configured consistently for both.

Existing plaintext plugin keys migrate on the first settings load after provisioning, before they can be used. If migration cannot persist, or the master key is missing, insecure or wrong, evaluation is blocked rather than quietly using another backend. The dashboard remains available to restore the master key or remove/replace the saved key. No need to interrogate the llama with a soldering iron.

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

Only the NPC owner's CHIM opinion of the counterpart changes. Lifecycle decisions require confidence of at least **0.90**; the CHIM fallback uses its validated `high` threshold. Relative changes are clamped to **−100 through +100**. Locked profiles receive no affinity update.

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

**Plugin logs omit raw dialogue, prompts, provider responses and credentials.** CHIM core can log full prompts; providers receive the conversation evidence needed for evaluation. Their retention is outside this plugin. This is not a private-conversation vault wearing a llama sticker.

On **0.1.2**, saved plugin keys use authenticated **libsodium secretbox** encryption with a fresh random nonce on every save. The database contains a versioned ciphertext envelope, while the master key stays in the separate private file above. Keys are never prefilled on the page. Provider requests use HTTPS. Page access requires exact localhost without forwarded headers or a server-authenticated user; mutations use CSRF protection. Remote key entry additionally requires HTTPS recognised by the web server. Direct localhost HTTP remains available for local setup; client-supplied forwarded-protocol headers cannot authorise key entry. TLS-terminating proxies must configure trustworthy server HTTPS state.

**This protects a database-only leak**, not a compromised server. Root, the PHP service account and compromised code running as that account can access a key while it is used. A backup containing both the database and master key can decrypt it. CHIM's own fallback connector credentials retain CHIM's existing storage; this change covers the key saved by this plugin. Use a dedicated spending-limited key.

**Back up the master key separately and securely from the database.** Losing it means replacing the saved API key; creating a new master does not recover old ciphertext. Do not rotate the master file blindly. **Clear key** removes the current plugin setting and returns to an available CHIM fallback; it neither revokes the key at OpenRouter nor erases old backups, WAL or historical plaintext copies. Rotate a previously exposed provider key yourself. Encryption is useful; retroactive amnesia is still outside the budget.

[0.1.2 security verification](docs/verification/key-encryption-0.1.2.md): real crypto/filesystem tests, encrypted-save and migration integration, recovery controls, HTTPS gates and independent review. Administrators choosing a custom master path must keep it outside **every** served directory, including nonstandard web roots.

## Tested, and not magically tested

Acceptance included **31 PHP syntax checks, 28 unit/regression scripts, five isolated database suites, two package checks, 14 real-adapter synthetic replays, browser controls, simulated-client exchanges and eight coexistence combinations**. Independent review findings were fixed before final acceptance. [Detailed record](docs/verification/release-0.1.1.md)

Both Jev and the tested Gemma fallback recognised firm/implicit promises and created no issues for negative, ambiguous or declined cases. Both identified tentative promises with different confidence. In the apology case, **Gemma inferred a grievance; Jev inferred none**. Useful observations, not a scientific certificate that either model understands your marriage.

**Still unverified:** gameplay, dialogue quality, long sessions, save migration, production concurrency/latency, automatic MO2 transfer and general interpretation reliability. Disabling/removing the plugin stops future evaluation, without erasing ledgers or reversing affinity. Disabling the MO2 carrier does not uninstall the server copy. State uses CHIM's playthrough/restore policy; changing Skyrim saves alone does not guarantee a fresh database scope. Back up the server before experiments you want to reverse.

## For people who open the source

Runtime code lives in [`server/`](server/), checks in [`tests/`](tests/), and packaging in [`scripts/build-package.py`](scripts/build-package.py). Namespaced NPC ledgers hold issues; owned tables hold jobs, scan cursors, settings and pacing. Ledgers are bounded to 24 open and 16 closed issues, with bounded evidence and replay tracking. History snapshots and qualifying affinity effects commit together.

Build release formats with `python scripts/build-package.py --format release`; check packaging with `python tests/package_check.py`. Run PHP checks with `bash scripts/run-php-tests.sh` on the designated disposable server. Database fixtures require explicit `SAS_IT=1` and refuse to run outside that clone. Do not point them at your gaming database and call it courage.

Report installed plugin/CHIM/client versions, backend/model, cast/settings, reproduction steps, expected/observed results and filtered plugin logs. Omit keys and private conversation dumps. “They are being weird” is excellent Skyrim commentary and an incomplete bug report.

[Issues and support](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/issues) · [World of Drama-Llama](https://github.com/Francisco-boop-001/CHIM-Plugins)

Maintained by [Francisco](https://github.com/Francisco-boop-001), built for [CHIM by Dwemer Dynamics](https://github.com/Dwemer-Dynamics/CHIM). They built the platform. I gave the llama a grudge book.
