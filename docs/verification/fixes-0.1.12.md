# 0.1.12 verification and limits

## Finding and scope

A queued Decisions job could use the CHIM relationship LLM immediately when Decisions credentials were unavailable, including after turning Jev off. Retry scheduling still received the queued Decisions backend and its failure count. The reproduced failure sequence made eight scripted LLM requests: four under the Decisions retry budget, then four after an explicit LLM switch.

After validating the selected implicit fallback, job processing notifies the worker before any model or persistence work. The worker records that backend and resets the previous backend's failure count, retaining the identity even when a later storage exception is caught. Same-tick successful fallback is retained, including bounded written-work operation. No thresholds, issue substance, affinity policy, schema or provider service change.

## Behavioral evidence

Checks ran in the registered DwemerAI4Skyrim3-test clone on 22 October 2026 using PHP 8.2.29. All provider and SQL behavior below was scripted; no real provider credentials, quota or database records were used.

- Before edits: 45 existing pure regression scripts passed.
- Expected failure: the original workerTick/processJob/finishJob path exceeded the four-attempt assertion. The separate original retry trace observed eight failed model calls. An earlier fixture setup error is excluded from defect proof.
- Final source: **46 pure regression scripts plus six isolated runtime-switch modes, 52 invocations total, all passed**. Switch modes: idle, before_work, pass1, pass2, pass1_error and pass2_error. These use frozen core runtime-lock code and scripted database/model state.
- **109 local PHP files passed syntax checks**, including two ignored private integration runners that were linted only. The source release includes 107 PHP files; the runtime payload contains 38 PHP files.
- The new worker regression checks both request failures and post-evaluation storage exceptions: each reaches terminal failure after four relationship-LLM requests, with retry delays of 30/120/600 seconds and logs identifying the LLM backend. Existing job tests verify that successful implicit fallback still evaluates and commits within the same tick, with its model identity retained.
- Tone, grounded substance, replay, repeated disputes, party/evidence validation, affinity and pause guards remain covered by the existing synthetic suite.
- Independent release review also reproduced a storage-exception path that lost result-only backend metadata. The final notification occurs before evaluation/storage, and the expanded regression verifies the exception path.
- An initially green deferred-fallback implementation was rejected in source review because a bounded written worker could exit before handling the deferred fallback. It is not the released implementation.

The release is bound to the tested runtime source hashes. Packaging and public artifact verification are separate gates; no installation is implied by those checks.

## Limits and preserved state

No fresh actual PostgreSQL integration, provider accuracy/latency, audible dialogue, long-session gameplay or native MO2/game synchronization was performed. The 24-open-issue policy, skipped replacement creation at capacity and possible missed issues after truncated/malformed model replies remain existing limitations. Earlier version-specific real-provider and database evidence is not presented as fresh proof for this fix.

Tests use private temporary fixture directories and clean their owned files/processes in finally blocks. Existing distro services were not started or stopped. Installed server files, core/live data, gaming distro and provider credentials were untouched. Release metadata and packages do not deploy themselves to the live server.

## Release assets

| Asset | SHA-256 |
| --- | --- |
| `SHA256SUMS.txt` | `c3bacd78579f4a5864c2f57dd92f0fa688d00926c1601f0b16f78bde7fef643c` |
| `sworn_and_scorned-0.1.12-mo2.zip` | `40b48bb77f82460d6421d7c6ac73901f7cf3dc7f11930d74a7c2cb6ea2adf830` |
| `sworn_and_scorned-0.1.12.dwpkg` | `8ae2da4e2f49f26f79c375352678fd1d32595db5bb3df68ffb57ef80d785dc8e` |
| `sworn_and_scorned.tar.gz` | `7894a145edd146aec606d494e8e70f510c0523a36e1ba33b7589ac0c5608fd23` |

Three package checks passed. All three installer formats and SHA256SUMS.txt are rebuilt from the clean tag and downloaded again before publication; public asset checksums must match this table.
