# Written-evidence interface v1 (0.1.10)

S&S consumes neutral CHIM eventlog rows, never another plugin's private state. This interface is server-only; it does not accept browser/client prose or establish physical delivery. The producer must reject normalized client `info` with either reserved fifth-field marker (`written_evidence_v1` or `correspondence_v1`) before core logging and publish only after confirmed receipt and actual recipient-context/read exposure. A constant session marker is not cryptographic authentication against another privileged eventlog writer.

## Contract

`type=info`, `sess=written_evidence_v1` (logical input/source kind remains `written_evidence`), exact `people=|recipient canonical name|`. JSON has exactly `schema_version`, `document_id`, `playthrough_id`, `recipient`, `claimed_sender`, `body`, `receipt`, `attachments`. Version is integer1; scope is exact current CHIM profile id or `unprofiled`. NPC identities require unique canonical names and stable positive DB ids; Player requires current canonical name and null id. Only NPC recipients are supported perspectives. The claimed sender may be physically absent; neither that actor nor anyone else acquires knowledge by appearing in the metadata.

Document, receipt and operation ids are lowercase canonical UUIDs. Receipt is confirmed and exposure is `context` or `read`. Context exposure does not establish a reading animation or audible speech. Preserve nonempty original UTF-8 body (up to2000 codepoints/8000bytes). New issue substance still requires complete originating evidence within eight lines of640 characters each; oversized origins abstain from creation rather than truncate terms. Up to8 confirmed received attachments have kind `gold|item`, unique stable `Plugin|8HEX` references and positive signed32-bit quantities. The gold reference is `Skyrim.esm|0000000F`. No actual hidden author/payer or operator provenance enters this record, model or logs.

## Public server API

Entry: `server/written_source.php`, namespace `ChimSwornAndScorned`. Use the plugin's `Pg` handle (the same checked CHIM connection used by its existing worker).

- `writtenEvidenceCapability(): array`: version1, input_type=`written_evidence`, source_event_type=`info`, source_marker, max_body_codepoints2000, max_substance_codepoints640, recipient_only=true. Capability is not proof the plugin is enabled or a source processed.
- `enqueueWrittenEvidence(Pg $pg, int $eventId): array`: accepts only a persisted event id; fetches and validates scope, identity, uniqueness and settings itself; requests the normal chronological scanner. It performs no model call or direct ledger write.
- `writtenEvidenceStatus(Pg $pg, int $eventId): array`: revalidates the source and distinguishes `invalid`, `paused`, `pending`, `queued`, `failed`, `abstained`, `processed`. Queued/pending are not social success; processed means evaluation was committed, not necessarily that an issue was created or changed.

Queue/status read only enabled/use_jev/cast/model; they never load, decrypt or migrate saved keys. Pending/queued/paused results report `credential_readiness=unchecked`; the normal worker alone checks actual credentials.

The [sanitized verification record](verification/written-evidence-0.1.10.md) distinguishes deterministic interface checks from coordinator-run real-provider evidence. No HTTP endpoint or service is added. The normal worker remains responsible for transport retry/fallback and evaluation.

## Interpretation, persistence and restore

The body is what the letter claims; received attachments establish quantities reaching the recipient, not the signature's authorship, who paid, acceptance of a new obligation or satisfaction of a particular debt. Both typed evaluators see modality and claimed attribution, never synthetic spoken dialogue. Written attribution must be supported before blaming the claimed sender. One-sided letters cannot manufacture recipient acceptance. A letter's claim alone cannot fulfil money/item debts or resolve/forgive the recipient's grievance. Ordinary actual recipient acknowledgement can support later settlement through existing speech rules.

Exact written provenance follows source capsules and evidence through normalization, history, later questions, journal and bounded reminder. Existing records remain readable and ordinary acknowledged speech filtering stays unchanged. Only `PerspectiveStore::commitLedger` writes ledger/history/affinity. It locks/revalidates current scope, cast/identities, exact source and envelope, checks alternate document/recipient or receipt/operation rows, and refuses missing/tampered sources. Source kinds retain separate replay identity.

Written source identities live in the restored owner ledger/history. Never evict them: at1024 retained sources, abstain with `written_capacity` until a separately designed storage extension exists. This bounds restored snapshots without silently permitting replay. The interface adds no automatic historical cleanup, independent affinity policy or provider service.

## Verification status

The coordinator ran parser/flow checks, 11 ordinary regressions (including worker eligibility), 15 runtime PHP syntax checks and the guarded native PostgreSQL fixture on `DwemerAI4Skyrim3-test`; all passed. The DB fixture uses only owned `sas_it` tables, an explicit password-free peer socket connection and absent-schema/cleanup guards. It checks exact provenance/history, absent-sender isolation, replay/pruning/restore, source and identity races, scanner serialization, metadata-only attachment races, and capacity-safe ordinary speech settlement with exact affinity. Status/enqueue also preserve a synthetic legacy saved key unchanged with key storage deliberately unavailable. Independent review inspected source, hashes and completed receipts.

Root separately verified real correspondence source emission followed by an exact neutral-row transfer into guarded `sas_it`, public S&S queue, deterministic evaluation, locked history and processed readback. This is a source-transfer seam, not a claim both products operated on the same physical table or that the game executed delivery.

Interface/storage checks used deterministic model answers. The final current-code real relationship-LLM run passed 12 original quote cases and three fresh contrasts through normal persistence/history/replay; earlier misses remain recorded. These small samples do not establish general interpretation reliability. Jev received the same question/state in captured adapter tests but was not called as a real provider in this round. Native game delivery/read animation and long-session behaviour remain unverified. Duplicate JSON object keys follow PHP last-value-wins decoding; the trusted producer emits unique fields. Collision predicates scan neutral JSON without a new core index; large-history throughput is unbenchmarked. Malformed privileged neutral JSON fails closed; the producer must preserve schema-valid neutral rows. Current-row locks do not lock absent duplicate rows; producer uniqueness is required against a duplicate inserted after validation. Rollback to older S&S code requires the matching earlier ledger/history snapshot because old readers do not understand written capsule metadata.

The disposable verification fixture was restored and stopped after the cross-product checks. No live installation, gameplay or physical delivery is certified; publication evidence is recorded separately from runtime evidence.

## Carrier privacy amendment

The custom physical type `written_evidence` is rejected: inspected generic/Narrator/BGL history readers can accept unknown types, and compaction can discard the original type before late hooks. Only core-excluded physical `info` plus exact `written_evidence_v1` is accepted; there is no legacy fallback. The producer must block reserved client markers before the core logger copies request field4 into sess. S&S itself does not patch core. A compatible producer/platform must actually exclude this carrier from general history and reject reserved client markers before logging; installing S&S does not establish those producer/core safeguards.

S&S scanners and bootstrap queries select speech or exact-marker info, so unrelated plaintext info and private correspondence_v1 anchors neither enter the evaluator nor consume its scan budget. Collision SQL uses an explicit MATERIALIZED marker-restricted CTE before JSON casts. Current source rows remain locked/revalidated by the existing store; no general info-history scan or new ledger format is introduced. Completed disposable checks cover scanner-budget exclusion with more than 500 unrelated ordinary/private-anchor info rows, pre-cast marker isolation, carrier/marker mutation during evaluation, rejection of the unsafe raw type, locked source validation and the existing substance/affinity/replay invariants. The sanitized verification record describes the completed cross-product and installer scope; no native-game result is inferred.

## Labelled forwarded copies

A forwarded copy remains ordinary exact v1 body text. The shared evaluator state distinguishes current cover words from quoted prior claims; copy labels do not authenticate segment authorship, create a new receipt, or make an old promise, insult, refusal, release or payment a current declaration by the forwarding sender. An explicit present endorsement must be judged separately. Unknown attribution abstains. No quote-stripping, hidden lineage fields, new backend service, acceptance rule or affinity policy is introduced.

The focused quote regression checks exact body/identity preservation, the same question/state reaching both typed adapters, genuine current promise/acceptance, false or missing attribution gates, and unsupported written-settlement no-effects. A rejected terminal claim skips its complete update, preserving issue trajectory, evidence and freshness as well as status/affinity. Genuine no-change discussion can still retain addressed evidence. Actual real-provider cases are reported separately in the [verification record](verification/written-evidence-0.1.10.md).
