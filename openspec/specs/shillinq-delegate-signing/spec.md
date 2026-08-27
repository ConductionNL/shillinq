---
status: done
---

# Spec: shillinq-delegate-signing

**Status:** done
**Scope:** shillinq
**Tier:** T2 (integration / governance)
**Depends on:**
- **docudesk** document e-signature event contract (`OCA\DocuDesk\Event\DocumentSigningRequestedEvent` / `SigningConcludedEvent`, dispatched synchronously via `OCP\EventDispatcher\IEventDispatcher`)
- **decidesk** decision event contract (`OCA\Decidesk\Event\DecisionRequestedEvent` / `DecisionConcludedEvent`, dispatched synchronously via `OCP\EventDispatcher\IEventDispatcher`)
- ADR-031 (schema-declarative-business-logic — consumer fields + outcome-driven lifecycle; PHP only for the integration exception path)
- ADR-022 (apps-consume-or-abstractions — no redundant per-app signing/approval CRUD)
- ADR-037 (modular-config-fragments — schema edits in `lib/Settings/register.d/*`; nav in `src/menu-layout.json`)
- ADR-012 (deduplication — this spec removes duplicated signing/approval ownership)
- `bookkeeping-market-government-separation` (`ACMReport` — accounting side stays)
- `bookkeeping-pension-ias19` (`ActuarialValuation` — accounting side stays)
- `bookkeeping-titel-9-jaarrekening` (`AnnualReport` — accounting side stays)
- `bookkeeping-general-ledger` / `bookkeeping-journal-entries` (GL posting consequence — stays in shillinq)

## Purpose

shillinq does not own document e-signature or governance sign-off/approval. Both
are delegated cross-app: document e-signature (jaarrekening PDF, ACM report,
management letter) to **docudesk**; governance sign-off / adoption decisions
(signature-as-a-method) to **decidesk**. shillinq implements only the ADR-031
integration exception path — a synchronous, in-process `IEventDispatcher`
request/consume-outcome pair per delegation target — and keeps the resulting
accounting consequence (GL posting / lifecycle flip) local. This spec
originated as `shillinq-delegate-signing` (archived 2026-06-14, describing a
now-superseded "integration registry `call()`" transport that was never
wired to anything real) and was reconstructed 2026-07-22 to reflect the
transport actually shipped: both delegation paths now use `IEventDispatcher`
event contracts, not a registry. See
Codeberg issue shillinq#491 (pre-migration, not migrated to GitHub)
for the reconstruction history.
## Requirements
### Requirement: REQ-SIGN-001 — Document e-signature on a shillinq finance document SHALL be delegated to docudesk via IEventDispatcher, request-then-consume-outcome; shillinq stores only the returned reference and mirrored status and NEVER signs on local authority

shillinq MUST NOT sign documents itself. Any e-signature on a finance document
(jaarrekening PDF, ACM report, management letter) MUST be raised as a **docudesk
`DocumentSigningRequestedEvent`** dispatched through a synchronous, in-process
**`OCP\EventDispatcher\IEventDispatcher`** — NOT a hard-coded `docudesk` hostname and NOT the
non-existent integration-registry `call()` method. shillinq implements only the ADR-031
integration exception path; it owns no signing engine, PKI material, or local trail.

- `SigningDelegationService::requestSignature()` MUST dispatch
  `OCA\DocuDesk\Event\DocumentSigningRequestedEvent` (guarded by `class_exists()` — if docudesk
  is not installed it MUST **fail closed** by throwing, never proceed or mark the document
  signed), then read the synchronous result `isHandled()` / `getSigningRequestId()`. If
  `isHandled()` is false OR `getSigningRequestId()` is null it MUST fail closed (throw). On
  success it MUST store the returned signing-request id as `signingRequestRef` and set
  `signingStatus = requested`.
- `requestSignature()` MUST have a real production caller — a transport that is correctly
  rewired but never invoked is the same defect as no transport at all. The `ACMReport.sign`
  lifecycle transition (`draft` -> `ready-for-submission`,
  `lib/Settings/register.d/bookkeeping-market-government-separation.json`) is purely
  declarative (from/to/label only, no handler); `ACMReportSignTransitionListener` consumes the
  `OCA\OpenRegister\Event\ObjectTransitionedEvent` OR fires once that transition commits,
  filters to schema `ACMReport` + action `sign`, calls `requestSignature()`, and persists the
  returned `signingRequestRef` + `signingStatus` back onto the object via OR `ObjectService`
  (registered in `lib/AppInfo/Application.php`, mirroring the established
  `*TransitionListener` pattern used elsewhere in shillinq). Fail-soft: the transition has
  already committed by the time the event fires, so a docudesk outage on the request side logs
  and leaves the object without a `signingRequestRef` rather than corrupting the transition.
- A registered listener on `OCA\DocuDesk\Event\SigningConcludedEvent` MUST consume the terminal
  outcome, filtering to `getSourceApp() === 'shillinq'`, and MUST project the
  `signed` / `declined` / `expired` / `cancelled` status onto the originating finance object
  via `SigningDelegationService::onSigningCallback()`. The callback MUST stay idempotent (a
  repeated conclusion is a no-op) and MUST fire the local accounting consequence exactly once
  on `signed` (REQ-SIGN-006).
- The originating finance schema stores only the document-signing **consumer field set**
  (`signingRequestRef`, `signingStatus`, `signingProvider`, `signingLevel`,
  `signedDocumentRef`) — never a signing engine, PKI material, or a local trail. These mirror
  fields are written **only** by the docudesk `SigningConcludedEvent` listener (or by
  `requestSignature` setting `requested`) — never by an app-local transition.
- No raw fleet hostname, hard-coded HTTP client, or phantom `$registry->call('docudesk', ...)`
  MUST appear in `lib/Service/Signing/`.

@e2e exclude backend integration: the IEventDispatcher dispatch + SigningConcludedEvent consumption are server-side, asserted via unit, not UI

#### Scenario: A finance document signature is dispatched to docudesk via IEventDispatcher and fails closed when docudesk is absent

- **GIVEN** a finance document (`ACMReport` / jaarrekening / management letter) in shillinq that requires a signature
- **WHEN** an operator requests the signature
- **THEN** shillinq MUST dispatch `OCA\DocuDesk\Event\DocumentSigningRequestedEvent` via `IEventDispatcher`, and only on `isHandled() === true` with a non-null `getSigningRequestId()` set `signingRequestRef` and `signingStatus = requested`; if docudesk is not installed or did not handle the event it MUST throw and MUST NOT advance, mark signed, or produce any local signature/PKI fingerprint

#### Scenario: The ACMReport `sign` transition is the production trigger that reaches requestSignature()

- **GIVEN** an `ACMReport` in state `draft`
- **WHEN** it transitions via the declarative `sign` action to `ready-for-submission`
- **THEN** OpenRegister fires `ObjectTransitionedEvent`, `ACMReportSignTransitionListener` filters to schema `ACMReport` + action `sign`, calls `SigningDelegationService::requestSignature()`, and persists the returned `signingRequestRef` + `signingStatus = requested` back onto the object; a repeated transition delivery, or an object whose `signingStatus` is already `requested` / `in-progress` / `signed`, MUST NOT raise a duplicate docudesk request

#### Scenario: The concluded signing outcome is consumed by a listener and drives the local GL consequence

- **GIVEN** a finance object with `signingStatus = requested` and a stored `signingRequestRef`
- **WHEN** docudesk dispatches `OCA\DocuDesk\Event\SigningConcludedEvent` with `getSourceApp() === 'shillinq'` and `getStatus() === 'signed'`
- **THEN** the registered shillinq listener MUST project `signingStatus = signed` (with `signedDocumentRef` from the event) onto the matching finance object and fire the existing submission/GL consequence exactly once; a repeated conclusion MUST be a no-op

#### Scenario: No phantom integration-registry call remains and shillinq never signs on local authority

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned in `lib/` for `$registry->call('docudesk'`, for `createSigningRequest`, and for hard-coded docudesk transport in `lib/Service/Signing/`
- **THEN** the docudesk `$registry->call(...)` / `createSigningRequest` path MUST be gone (replaced by the IEventDispatcher dispatch), and no code path MUST mark a document signed without a docudesk-delivered `SigningConcludedEvent`

### Requirement: REQ-SIGN-002 — Governance sign-off SHALL be modelled as a decidesk Decision (signature-as-method); shillinq consumes only the outcome

A governance **sign-off** — the concerncontroller approving an `ACMReport`, the actuaris approving an `ActuarialValuation`, the AV/board **adopting** an `AnnualReport` (jaarrekening) — MUST be raised as a **decidesk `Decision`** (signature-as-a-method) via the delegation transport described in REQ-SIGN-005. shillinq does **not** own the approval state machine.
Each finance schema gains a governance-decision **consumer field set**:

| Property | Type | Purpose |
|---|---|---|
| `decisionRef` | string | The decidesk `Decision` id (the authority) |
| `decisionOutcome` | enum | Mirror of the decision outcome: `pending` / `approved` / `rejected` |

On `ActuarialValuation`, the existing `approvalStatus` / `approvedBy` / `approvedAt` become a
**mirror** of the decidesk decision (`x-mirror-of: decidesk-decision`), not an app-owned
authority. The mirror fields are written **only** by the decidesk `DecisionConcludedEvent`
listener (REQ-SIGN-005).

#### Scenario: Board adoption of the annual accounts is a decidesk Decision

- **GIVEN** an `AnnualReport` in `in-review` (or `opgemaakt`) awaiting adoption by the AV
- **WHEN** adoption is requested
- **THEN** shillinq MUST raise a decidesk **adoption** `Decision` via `SignoffDecisionService::requestSignoff()`, set `decisionOutcome = pending`, store `decisionRef`, and MUST NOT advance to `vastgesteld` on any app-local approval transition

#### Scenario: shillinq owns no approval state machine

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned for an app-local approval/sign-off state machine, approval service, or decision-authority logic on `ACMReport` / `ActuarialValuation` / `AnnualReport`
- **THEN** none MUST exist; the approval authority is the decidesk `Decision`, and shillinq holds only the `decisionRef` + mirrored `decisionOutcome`

### Requirement: REQ-SIGN-003 — Finance lifecycle transitions SHALL be outcome-driven, gated on the consumed signing/decision status

The transitions that previously fired on a local signing/approval write MUST instead be
**gated on the consumed outcome**, while the transition *definitions* are retained (so
deep-links, existing reports, and the accounting consequence keep working):

- `ACMReport.sign` (`draft → ready-for-submission`): guarded by `signingStatus == signed`
  (document) and/or the concerncontroller `decisionOutcome == approved` (governance) —
  **not** by a local `signatureFingerprint` write.
- `AnnualReport.vaststellen` (`in-review → vastgesteld`) and `vaststellenZonderReview`
  (`opgemaakt → vastgesteld`): guarded by the adoption `decisionOutcome == approved`.
- `ActuarialValuation` approval: guarded by `decisionOutcome == approved`.

A finance object MUST NOT self-advance these transitions while the consumed outcome is
`pending` / `requested` / `in-progress`.

#### Scenario: An ACM report cannot advance to ready-for-submission without a completed signature

- **GIVEN** an `ACMReport` in `draft` with `signingStatus = requested` and `decisionOutcome = pending`
- **WHEN** a transition to `ready-for-submission` is attempted
- **THEN** it MUST be rejected; only a `signed` signing status and/or an `approved` decision outcome permits the transition

#### Scenario: Adoption is gated on the decidesk outcome

- **GIVEN** an `AnnualReport` in `in-review` with `decisionOutcome = pending`
- **WHEN** `vaststellen` is attempted
- **THEN** it MUST be rejected; the transition to `vastgesteld` is permitted only when the adoption `decisionOutcome == approved`

### Requirement: REQ-SIGN-004 — The local PKI signing handler SHALL be removed and replaced by the docudesk delegation

The local PKI signing handler MUST NOT exist: the `ACMReport` `signReport` lifecycle-action
previously called `OCA\Shillinq\Service\AcmReportGenerator::sign`, which wrote a PKI
`signatureFingerprint`; `AcmReportGenerator` MUST NOT define a `sign()` method. The
`signReport` action MUST instead be re-pointed at the docudesk delegation path (REQ-SIGN-001),
which performs **no** local signing. The `signatureFingerprint` property MUST be treated as
deprecated / `x-delegated-to: docudesk` and retained only for legacy provenance
(REQ-SIGN-009).

#### Scenario: No local PKI signature is produced

- **GIVEN** the shillinq codebase
- **WHEN** searched for `AcmReportGenerator::sign` and any PKI/certificate signing code path
- **THEN** no `sign()` handler and no code path producing a local cryptographic signature or fingerprint MUST exist; the `signReport` action instead raises a docudesk signing request (REQ-SIGN-001)

### Requirement: REQ-SIGN-005 — Cross-app sign-off DECISIONS SHALL be delegated to decidesk via IEventDispatcher, request-then-consume-outcome; DOCUMENT e-signature is governed by REQ-SIGN-001

The governance **sign-off DECISION** delegation to decidesk MUST use a synchronous,
in-process **`OCP\EventDispatcher\IEventDispatcher`** dispatch — NOT a hard-coded
`decidesk` hostname and NOT the non-existent integration-registry `call()` method. shillinq
implements only the ADR-031 integration exception path; it owns no approval logic. The
DOCUMENT e-signature delegation to docudesk is a separate transport, specified in full by
REQ-SIGN-001 (also `IEventDispatcher`-based); it is not restated here.

- `SignoffDecisionService::requestSignoff()` MUST dispatch
  `OCA\Decidesk\Event\DecisionRequestedEvent` (guarded by `class_exists()` — if decidesk is
  not installed it MUST **fail closed** by throwing, never proceed or auto-approve), then read
  the synchronous result `isHandled()` / `getDecisionId()`. If `isHandled()` is false OR
  `getDecisionId()` is null it MUST fail closed (throw). On success it MUST store the returned
  decision id as `decisionRef` and set `decisionOutcome = pending`.
- The request MUST be raised from a REAL production trigger, not merely be callable —
  `AnnualReportSignoffRequestListener` reacts to `OCA\OpenRegister\Event\ObjectTransitionedEvent`
  on the AnnualReport schema transitioning to `opgemaakt` (the state shared by both the
  `vaststellen` and `vaststellenZonderReview` AV-adoption transitions) and is the sole
  production caller of `requestSignoff()`. It is idempotent — skipped once the object already
  carries a non-empty `decisionOutcome` — and fail-soft at the listener boundary (the
  `opgemaakt` transition has already committed by the time the event fires; a request failure
  leaves `decisionOutcome` unset rather than corrupting the record or auto-approving).
- A registered listener (`SignoffDecisionConcludedListener`) on
  `OCA\Decidesk\Event\DecisionConcludedEvent` MUST consume the terminal outcome, filtering to
  `getSourceApp() === 'shillinq'`, and MUST project the `approved` / `rejected` outcome onto
  the originating finance object via `SignoffDecisionService::onDecisionCallback()`. The
  callback MUST stay idempotent (a repeated conclusion is a no-op) and MUST fire the local
  accounting consequence exactly once (REQ-SIGN-006).
- No raw fleet hostname or hard-coded HTTP client to decidesk MUST appear in
  `lib/Service/Signing/`.

@e2e exclude backend integration: the IEventDispatcher dispatch + DecisionConcludedEvent consumption are server-side, asserted via unit, not UI

#### Scenario: Sign-off decisions are dispatched to decidesk via IEventDispatcher and fail closed when decidesk is absent

- **GIVEN** a finance object (`ACMReport` / `ActuarialValuation` / `AnnualReport`) awaiting governance sign-off
- **WHEN** an operator requests the sign-off
- **THEN** shillinq MUST dispatch `OCA\Decidesk\Event\DecisionRequestedEvent` via `IEventDispatcher`, and only on `isHandled() === true` with a non-null `getDecisionId()` set `decisionRef` and `decisionOutcome = pending`; if decidesk is not installed or did not handle the event it MUST throw and MUST NOT advance or auto-approve

#### Scenario: The decision request is wired to a real AnnualReport lifecycle trigger, not left orphaned

- **GIVEN** an AnnualReport with no `decisionOutcome` set yet
- **WHEN** the AnnualReport transitions to `opgemaakt` (via `opmaken` or `reviewAnnuleren`)
- **THEN** `AnnualReportSignoffRequestListener` MUST call `SignoffDecisionService::requestSignoff()` exactly once and persist the returned `decisionRef` / `decisionOutcome = pending`; a subsequent re-entry into `opgemaakt` while `decisionOutcome` is already set MUST NOT raise a duplicate decision

#### Scenario: The concluded decision outcome is consumed by a listener and drives the local GL consequence

- **GIVEN** a finance object with `decisionOutcome = pending` and a stored `decisionRef`
- **WHEN** decidesk dispatches `OCA\Decidesk\Event\DecisionConcludedEvent` with `getSourceApp() === 'shillinq'` and `getStatus() === 'approved'`
- **THEN** the registered shillinq listener MUST project `decisionOutcome = approved` onto the matching finance object and fire the existing GL posting / lifecycle consequence exactly once; a repeated conclusion MUST be a no-op

#### Scenario: No phantom integration-registry call remains for either delegation path

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `$registry->call('decidesk'`, `$registry->call('docudesk'`, and for hard-coded decidesk/docudesk transport in `lib/Service/Signing/`
- **THEN** neither `$registry->call(...)` path MUST exist; both delegation paths MUST be `IEventDispatcher`-based (decidesk per this requirement, docudesk per REQ-SIGN-001)

### Requirement: REQ-SIGN-006 — The accounting consequence of a completed signature/decision SHALL stay in shillinq and fire on the consumed outcome

Delegating the signing/approval **flow** MUST NOT remove the **accounting consequence**.
When the consumed outcome completes, shillinq MUST perform the resulting GL posting /
lifecycle flip through its **existing** journal/GL surface (ADR-022) — no new GL logic, and
the consequence MUST still fire:

- `ACMReport` `signingStatus == signed` (and/or sign-off `approved`) → the ACM submission
  gate opens (the report becomes submittable).
- `AnnualReport` adoption `decisionOutcome == approved` → the year-end / retained-earnings
  posting runs.
- `ActuarialValuation` `decisionOutcome == approved` → the IAS19 actuarial posting runs.

#### Scenario: The GL posting still fires when adoption completes

- **GIVEN** an `AnnualReport` whose adoption `decisionOutcome` transitions to `approved` via the decidesk callback
- **WHEN** shillinq consumes the outcome
- **THEN** the existing year-end / retained-earnings posting MUST run exactly once through the existing GL surface, and the report MUST transition to `vastgesteld`

#### Scenario: Removing flow ownership does not orphan the consequence

- **GIVEN** the change is applied
- **WHEN** the `signed` / `approved` outcome is consumed for `ACMReport`, `AnnualReport`, and `ActuarialValuation`
- **THEN** each app-specific accounting consequence (submission gate, year-end posting, IAS19 posting) MUST still execute; no consequence MUST be left unreachable (hydra orphan-auth boundary)

### Requirement: REQ-SIGN-007 — `BookkeepingSigningTrail` SHALL be retired as an owned capability; the page MAY remain as a read-only federated view

shillinq MUST NOT own a signing trail. `BookkeepingSigningTrail` MUST be removed from the
Bookkeeping navigation by adding `"BookkeepingSigningTrail"` to `removals` in
`src/menu-layout.json`. Per the established removal pattern, the **page stays routable** for
deep-links. No local signing-trail schema MUST be created. The page MAY be re-implemented as
a **read-only federated consumer view** that lists docudesk `signingRequest`/
`signingAuditEntry` records and decidesk `Decision` (sign-off/adoption) records for finance
documents — with no write actions and no local event store.

@e2e exclude unbuilt UI: the read-only federated signing view is not yet implemented

#### Scenario: The signing-trail nav leaf is removed but the page stays routable

- **GIVEN** `src/menu-layout.json`
- **WHEN** the navigation is rendered
- **THEN** `BookkeepingSigningTrail` MUST NOT appear as a Bookkeeping nav leaf, **and** its page MUST remain reachable by direct route (deep-link), consistent with the existing `removals` pattern

#### Scenario: The surviving page owns no local trail

- **GIVEN** the (if implemented) `BookkeepingSigningTrail` page
- **WHEN** inspected
- **THEN** it MUST be a read-only view federating docudesk + decidesk records, MUST have no write actions, and MUST NOT define or persist a local signing-trail schema

### Requirement: REQ-SIGN-008 — Notifications SHALL fire on the consumed signing/decision status, not on a local signing engine

Notification rules MUST be declared in the canonical `x-openregister-notifications` dialect
(ADR-031) on the **mirror** fields: signature `signed`/`declined`, decision
`approved`/`rejected`. They MUST use `updated` triggers with field-change conditions on
`signingStatus` / `decisionOutcome`, recipients via `{"kind":"field","field":"owner"}` plus
the object-acl `manage` permission, and subjects in `nl` + `en` (metadata-only). No
imperative notification dispatch (gate-18); no app-local signing engine emits them.

#### Scenario: Finance users are notified when the outcome arrives

- **GIVEN** an `ACMReport` whose `signingStatus` transitions to `signed` (or an `AnnualReport` whose `decisionOutcome` transitions to `approved`)
- **WHEN** the relevant `SigningConcludedEvent` / `DecisionConcludedEvent` listener writes the mirror
- **THEN** an `x-openregister-notifications` rule MUST notify the owner / `manage`-acl users, driven by the field-change on the mirror status, with no imperative dispatch

### Requirement: REQ-SIGN-009 — Existing sign-off status SHALL migrate to a consumed decision/signature reference without losing accounting data

A `lib/Repair/DelegateSigningMigrationRepair.php` step MUST map every existing sign-off status
to a **consumed decision/signature reference** carrying `kind: legacy-local`, preserving
provenance:

- a signed `ACMReport` → `signingRequestRef = legacy-local:<signatureFingerprint>`,
  `signingStatus = signed`;
- an approved `ActuarialValuation` → `decisionRef = legacy-local:<approvedBy>@<approvedAt>`,
  `decisionOutcome = approved`;
- an adopted `AnnualReport` (`vastgesteld` / `gedeponeerd`) → `decisionRef =
  legacy-local:vaststellen`, `decisionOutcome = approved`.

The migration MUST be idempotent and MUST NOT rewrite or drop any legitimate **accounting
status** (`ACMReport.status`, the `AnnualReport` states, the `ActuarialValuation` valuation
fields). Data MUST never be silently dropped.

@e2e exclude backend migration: the repair step is server-side, asserted via unit/Newman, not UI

#### Scenario: An already-signed report keeps its provenance and stays valid

- **GIVEN** an `ACMReport` in `ready-for-submission` with a pre-existing `signatureFingerprint`
- **WHEN** the migration runs
- **THEN** it MUST receive `signingRequestRef = legacy-local:<that fingerprint>` and `signingStatus = signed`, while `ACMReport.status` is left unchanged

#### Scenario: Accounting status is never dropped by the migration

- **GIVEN** the set of `ACMReport`, `ActuarialValuation`, and `AnnualReport` objects before migration
- **WHEN** the migration runs to completion and again (idempotency)
- **THEN** every finance status enum value MUST be byte-for-byte unchanged, only the new consumer reference fields MUST be added, and a second run MUST make no further changes

