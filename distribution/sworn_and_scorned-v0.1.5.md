# Sworn & Scorned 0.1.5 — PRE-ALPHA

The llama has stopped asking you to photocopy a key it can already find. It has also learned that an empty filing cabinet is not a database emergency. Character development, allegedly.

**Jev is now an explicit experimental toggle, off by default.** Leave it off to use CHIM's configured relationship LLM. Enable **Use Jev — experimental** to use the Decisions API with the enabled CHIM OpenRouter relationship connector's existing credential. Jev handles promise/grievance decisions; your dialogue model and other plugins keep their own jobs. Successful Jev evaluations reduce calls to the usual relationship LLM.

An encrypted dedicated key remains optional under **Advanced**, useful for separate spending limits. Saving one does not select Jev. Reused credentials retain CHIM's existing storage protection; they are not copied into this plugin's settings. Missing credentials and unreadable selected overrides pause Jev with recovery guidance. Account Typesafe restrictions still apply. Provider calls, retries and fallback can incur charges.

## Upgrade

1. Replace the older enabled installation with one 0.1.5 package. Use the MO2 ZIP, DWPkg upload, or repository tar route described in the [README](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/blob/sworn_and_scorned-v0.1.5/README.md#install-pick-one-route).
2. Verify **0.1.5 in the server Plugin Manager**, then open the Plugin Page.
3. Upgrading from 0.1.2: **reselect and save your cast**. Casts are scoped to the active CHIM playthrough; a profile switch now explains why reselection is needed.
4. If you want Jev, explicitly enable its toggle—even if you previously saved a dedicated key. Existing encrypted overrides are retained. Only a dedicated override requires the private master-key setup; normal CHIM credential reuse does not.

Back up the server database and, if used, the dedicated override's master key separately before upgrading. For rollback, restore the previous server package and compatible database state; toggling or uninstalling does not erase ledgers or reverse affinity.

## Corrected behavior

- Player affinity updates use CHIM's canonical `Player` edge, merge recognised legacy aliases, preserve metadata and apply each delta once.
- CHIM's valid empty `relationships: []` supports Player and NPC affinity-bearing transitions instead of dropping them or causing paid SQL retries.
- Restore-policy pauses happen before scanning/model work and are rechecked before commits. Queued work is retained.
- Unreadable Jev overrides no longer cause worker crash/respawn churn or block the default CHIM mode.
- Casts, stale cast/issue forms and reminder cooldowns respect playthrough scope. Global key/restart controls work without a resolvable playthrough while retaining access and CSRF protection.
- Case-insensitive presence checks and a real 20-second total deadline per CHIM fallback call close the remaining tested integration defects. The fallback requires Linux CLI, `proc_open` and `/usr/bin/timeout`.

## Evidence and limits

The accepted 0.1.5 candidate passed **34 PHP syntax checks, 33 regular scripts, eight isolated database suites, two package tests and eight installed-dashboard HTTP checks**. A scripted 240-batch session plus 240 crash replays produced exactly 240 provider adapter calls. Actual CHIM credential resolution was verified without billing a provider during the corrective work. Independent review accepted the code, exact archives and retained evidence. [Verification and remaining limits](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/blob/sworn_and_scorned-v0.1.5/docs/verification/jev-toggle-0.1.5.md).

This remains **PRE-ALPHA**. Skyrim gameplay, dialogue quality, live Jev interpretation/latency, production concurrency, automatic MO2 transfer and actual save migration remain unverified. Corrupted non-empty relationship arrays are preserved and rejected but can lose the evaluated transition; those rows need manual repair. History/job retention, repeated earlier passes on provider retries and CHIM private-interface drift remain maintenance concerns. The clipboard is improving. The disclaimer remains employed.
