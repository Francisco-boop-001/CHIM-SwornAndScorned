# Sworn & Scorned 0.1.10 — PRE-ALPHA

The llama reads the cover letter. Forwarding an ancient IOU should not summon a fresh debt from Oblivion. A letter saying “paid” is also not a bank statement, however confidently someone signs it.

**[MO2 users: download this ZIP only](https://github.com/Francisco-boop-001/CHIM-SwornAndScorned/releases/download/sworn_and_scorned-v0.1.10/sworn_and_scorned-0.1.10-mo2.zip).** The DWPkg is already inside. Replace the old enabled package, launch Skyrim/SKSE through MO2, load a save with CHIM connected, then verify **0.1.10** in the server's Plugin Manager. Downloading a ZIP does not update your running server by telepathy.

## What changed

- Adds a recipient-only written-evidence v1 interface for compatible server producers. Exact claimed sender, recipient, body, receipt and source identity survive the ledger, history, later evaluation, journal and bounded reminder. This interface does not itself send letters.
- Evaluates current unquoted words and explicit present endorsements separately from old enclosed claims. An earlier author's name does not automatically invalidate a genuine current promise or authenticate the old text. Unclear/disputed attribution still blocks unsupported writes.
- Rejects the entire unsupported written settlement update. An unconfirmed copied payment cannot mark the debt “improving,” add addressed evidence or refresh it while leaving the debt technically pending. The llama has stopped congratulating imaginary repayments.
- Keeps genuine discussion, supported creditor release and actual recipient acknowledgement working. Sources are revalidated under the normal locked store, and replay cannot repeat history or affinity. No extra model service, schema expansion, model round trip or new affinity policy was added for the attribution/guard corrections.
- Preserves 0.1.9's richer records and contextual tone correction. No automatic cleanup of old grievances or historical affinity.

## Jev and testing

Jev remains an optional experimental evaluator; the default remains your configured CHIM relationship LLM. Both receive the same typed question/state. This round's real-provider quote tests used google/gemma-4-31b-it: **12 original cases plus three fresh contrasts passed**, asserting actual stored terms, parties, history, lifecycle, affinity and replay. All 19 relevant native suites passed, and the tested candidate passed official installer/payload checks.

Earlier failures remain documented. The model still misread a copied payment internally; the hard guard blocked every issue effect. An earlier genuine-promise miss remains evidence of limited reliability. These small samples establish neither a general error rate nor a causal accuracy improvement. Jev was checked through captured adapter requests, not real-provider calls in this round. [Verification and limits](../docs/verification/written-evidence-0.1.10.md).

## Before installing

This is **PRE-ALPHA**. The written interface needs a compatible producer and its documented core safeguards. It does not add spontaneous fully offscreen correspondence, item/gift transfers, automatic loan repayment or a forgery-discovery system. Existing spoken-conversation features do not require that producer. Generated NPC letters have no attachments in the current correspondence implementation.

Back up your server. Written-source metadata requires a matching earlier ledger/history snapshot if you later return to older code. Creation still needs a complete bounded origin; written identity tracking abstains at capacity instead of evicting replay protection. Gameplay, native MO2/game sync, physical delivery, audible dialogue and long sessions remain unverified. The live server was not changed during this work.

The standalone DWPkg and tar are alternative formats for supported admin tooling, not extra MO2 dependencies. SHA256SUMS.txt is optional download verification. Earlier release assets remain unchanged.

[Installation and setup](../README.md) · [Server interface](../docs/written-evidence-v1.md)
