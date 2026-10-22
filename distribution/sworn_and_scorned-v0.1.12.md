# Sworn & Scorned 0.1.12 — PRE-ALPHA

The relationship LLM now gets one retry budget. I had accidentally enrolled it in a loyalty programme where four failures earned another four failures. Terrible rewards scheme. Impressively on-brand.

## What is fixed

If you turned Jev off while Jev jobs were still queued, those jobs could immediately fall back to your CHIM relationship LLM while the queue continued calling them Jev attempts. Repeated request failures could then spend one retry budget under the wrong name and another under the correct name: **eight failing LLM requests instead of four**.

The worker now carries the actual fallback backend into retry scheduling and diagnostics. Its failure counter starts with that backend's own budget, keeping the existing **30 / 120 / 600-second retry delays**. Successful fallback still runs immediately in the same worker tick, including the bounded written-evidence path. No extra model service, schema migration, issue rewrite or affinity change.

This corrects request bookkeeping. It does not make Jev faster or turn a model's interpretation into divine revelation. Jev remains optional and experimental; normal evaluation uses CHIM's relationship LLM. Calls, retries and fallback may incur provider charges.

## Your existing interface still works

**The new Skyrim interface is optional. The existing web Plugin Page still works.** Settings, journal, Director view and recent activity remain available at **CHIM web → Plugin Manager → Server Plugins → sworn_and_scorned → Plugin Page**. Ordinary conversation tracking does not require a new in-game menu.

Apparently I can fix a retry counter without making you install another interface. Personal growth. Please do not expect consistency.

## Installation: one download, please

**[MO2 users: download sworn_and_scorned-0.1.12-mo2.zip](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/releases/download/sworn_and_scorned-v0.1.12/sworn_and_scorned-0.1.12-mo2.zip).** Install it like a regular mod and replace the old enabled S&S package. The DWPkg and meta.ini are already inside; no second package download or ESP is needed. Launch Skyrim/SKSE through MO2, load a connected save and verify **0.1.12** in CHIM's server Plugin Manager. A ZIP sitting in Downloads still has no telepathic deployment powers.

The standalone DWPkg and repository tar are alternative installation routes. Keep only one installation route/version enabled, and retain your backups. Compatible CHIM client/server synchronization is still required for the MO2 route.

## Verification and limits

The disposable-clone bughunt passed **52 synthetic behavioral invocations and 109 local PHP syntax checks**. The new regression exercises the real worker, job processing and queue completion with scripted SQL and model failures: four failed requests, the three expected retry delays and correct backend labels. Existing tests retain successful same-tick fallback and six isolated playthrough-switch cases. No real model was billed eight times to demonstrate my arithmetic problem.

Release packages are checked against the exact tagged source and verified by downloaded checksums. [Verification details](../docs/verification/fixes-0.1.12.md).

**Still PRE-ALPHA.** This fix has no fresh real-provider, PostgreSQL-database, MO2 synchronization or Skyrim gameplay verification. The switch regressions use frozen core lock code with scripted state, not actual save restoration. Existing issue-cap and truncated-model-reply abstention limits remain. Tone correction and richer issue records are retained. No historical grievances were cleaned up, and the live server was not accessed or updated.

[Installation and setup](../README.md) · [Written-evidence interface](../docs/written-evidence-v1.md)
