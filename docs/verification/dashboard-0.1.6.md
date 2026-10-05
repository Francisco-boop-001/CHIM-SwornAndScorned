# Dashboard and logging verification — 0.1.6

This PRE-ALPHA release removes the eight-NPC cast cap and adds Select all, Clear selection, an accessible selection count, explicit Director guidance and recent plugin activity. Publication does not deploy the update to the gaming server.

## Executed checks

- 34 PHP runtime syntax checks and 33 regular PHP scripts passed on the registered disposable CHIM clone. Focused checks were rerun after review corrections.
- All eight synthetic database suites passed, including atomic settings rollback, playthrough binding, persisted unrestricted cast, guarded ledger writes and stubbed Jev/default routing. The session suite exercised 240 negative batches, 240 post-commit crash replays and 40 paused ticks. Its provider-call count refers to the scripted transport; this task made no billed API requests.
- Native browser checks selected all 1,400 fixture NPCs, cleared the selection, verified selection did not autosave, saved all 1,400 IDs, and confirmed the count survived reload. The browser displayed the resulting settings-save record from CHIM's real logger.
- Director displayed recorded fixture evidence directly, while the ordinary journal kept it collapsed. Empty Director guidance and HTML escaping have regressions.
- Seven HTTP checks passed: dashboard/CSP, served external JavaScript, CSRF rejection, unknown NPC rejection, incomplete native submission rejection, Director evidence and forwarded-request denial.
- Logging regressions cover fixed codes, UTC/schema metadata, bigint IDs, secret/newline rejection, CHIM's actual prefix, newest-first ordering, the 50-record/256-KiB bounds, missing/unreadable files and harmless writer failure.
- Package checks verify the explicit 39-file payload, hashes and ZIP CRCs, including the external dashboard script. Existing 0.1.5 release assets are retained unchanged.

The test database used its own Unix socket with TCP disabled. This task changed no gaming-server source, settings or services. Temporary fixtures, installed test files and clone configuration were restored after acceptance.

## Practical limits

Select all covers currently known NPCs; newly discovered NPCs require a later selection/save. Larger casts can produce more serial background work and API charges. Ordinary PHP request-size limits still apply; JavaScript sends the cast in one field to avoid input-variable truncation, and incomplete native submissions fail before saving.

Recent activity shows newly structured plugin records from the recent tail of CHIM's log, across playthroughs. CHIM still owns the underlying log's retention, permissions and configured log level. PHP error-log fallback records are not included in this dashboard reader. Core logs and provider retention are outside the plugin's sanitization boundary.

Synthetic/server/browser evidence does not establish Skyrim dialogue quality, automatic MO2 sync, or performance in a long gameplay session with every NPC selected.
