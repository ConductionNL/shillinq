# Spec: shillinq-delegate-signing

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (integration / governance)
**Depends on:**
- **docudesk** document e-signature API (`signingRequest`/`signingSession`/`signerRecord`/`signingAuditEntry`; eIDAS modes/levels/providers) — consumed via the ADR-019 integration registry
- **decidesk** decision API (`Decision` supertype + `decisionType`, signature/eIDAS-as-a-method, adopt/approve lifecycle, decision outcome) — consumed via the ADR-019 integration registry
- ADR-019 (integration registry — cross-app calls go through the registry, not hard-coded HTTP)
- ADR-022 (apps-consume-or-abstractions — no redundant per-app signing/approval CRUD)
- ADR-031 (schema-declarative-business-logic — consumer fields + outcome-driven lifecycle; PHP only for the integration exception path)
- ADR-037 (modular-config-fragments — schema edits in `lib/Settings/register.d/*`; nav in `src/menu-layout.json`)
- ADR-012 (deduplication — this change removes duplicated signing/approval ownership)
- `bookkeeping-market-government-separation` (`ACMReport` — accounting side stays)
- `bookkeeping-pension-ias19` (`ActuarialValuation` — accounting side stays)
- `bookkeeping-titel-9-jaarrekening` (`AnnualReport` — accounting side stays)
- `bookkeeping-general-ledger` / `bookkeeping-journal-entries` (GL posting consequence — stays in shillinq)

## ADDED Requirements

### Requirement: REQ-SIGN-001 — Document e-signature on a shillinq finance document SHALL be requested from docudesk via the integration registry; shillinq stores only the returned reference and mirrored status

shillinq MUST NOT sign documents itself. Any e-signature on a finance document
(jaarrekening PDF, ACM report, management letter) MUST be raised as a **docudesk
`signingRequest`** through the **ADR-019 integration registry** (no hard-coded HTTP). The
originating finance schema gains a document-signing **consumer field set** (in its existing
ADR-037 fragment), and stores only these — never a signing engine, PKI material, or a local
trail:

| Property | Type | Purpose |
|---|---|---|
| `signingRequestRef` | string | The docudesk `signingRequest` id (the authority) |
| `signingStatus` | enum | Mirror of docudesk status: `requested` / `in-progress` / `signed` / `declined` / `expired` |
| `signingProvider` | string | eIDAS provider, read back for the dossier |
| `signingLevel` | enum | eIDAS level (simple / advanced / qualified), read back |
| `signedDocumentRef` | string | NC Files reference to the signed artifact docudesk produced (link, don't re-store) |

For `ACMReport` this consumer set **replaces** the local `signatureFingerprint`
(REQ-SIGN-004). The mirror fields are written **only** by the docudesk registry callback
(REQ-SIGN-005) — never by an app-local transition.

#### Scenario: A finance document signature is raised as a docudesk request via the registry

- **GIVEN** an `ACMReport` (or a jaarrekening / management letter) in shillinq that requires a signature
- **WHEN** an operator requests the signature
- **THEN** shillinq MUST open a docudesk `signingRequest` for that document **through the ADR-019 integration registry**, set `signingStatus = requested`, store `signingRequestRef`, and MUST NOT produce any local signature, PKI fingerprint, or signing-trail entry

#### Scenario: shillinq stores only the reference and mirrored status, not a signing engine

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned for a signing engine, PKI/certificate signing code, or an app-local signing-trail schema/store
- **THEN** none MUST exist; the only signing-related data on a finance object is the consumer field set above, written by the docudesk callback

### Requirement: REQ-SIGN-002 — Governance sign-off SHALL be modelled as a decidesk Decision (signature-as-method); shillinq consumes only the outcome

A governance **sign-off** — the concerncontroller approving an `ACMReport`, the actuaris approving an `ActuarialValuation`, the AV/board **adopting** an `AnnualReport` (jaarrekening) — MUST be raised as a **decidesk `Decision`** (signature-as-a-method) through the **ADR-019 integration registry**. shillinq does **not** own the approval state machine.
Each finance schema gains a governance-decision **consumer field set**:

| Property | Type | Purpose |
|---|---|---|
| `decisionRef` | string | The decidesk `Decision` id (the authority) |
| `decisionOutcome` | enum | Mirror of the decision outcome: `pending` / `approved` / `rejected` |

On `ActuarialValuation`, the existing `approvalStatus` / `approvedBy` / `approvedAt` become a
**mirror** of the decidesk decision (`x-mirror-of: decidesk-decision`), not an app-owned
authority. The mirror fields are written **only** by the decidesk registry callback
(REQ-SIGN-005).

#### Scenario: Board adoption of the annual accounts is a decidesk Decision

- **GIVEN** an `AnnualReport` in `in-review` (or `opgemaakt`) awaiting adoption by the AV
- **WHEN** adoption is requested
- **THEN** shillinq MUST open a decidesk **adoption** `Decision` through the registry, set `decisionOutcome = pending`, store `decisionRef`, and MUST NOT advance to `vastgesteld` on any app-local approval transition

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

The `ACMReport` `signReport` lifecycle-action currently calls `OCA\Shillinq\Service\AcmReportGenerator::sign`, which writes a PKI `signatureFingerprint`, and this handler MUST be **removed**. The `signReport` action MUST be re-pointed at the
docudesk-delegation exception path (REQ-SIGN-005), which performs **no** local signing. The
`signatureFingerprint` property MUST be marked deprecated / `x-delegated-to: docudesk` and
retained only for legacy provenance (REQ-SIGN-009).

#### Scenario: No local PKI signature is produced after the change

- **GIVEN** the shillinq codebase after this change
- **WHEN** searched for `AcmReportGenerator::sign` and any PKI/certificate signing code path
- **THEN** the `sign()` handler MUST be gone and no code path MUST produce a local cryptographic signature or fingerprint; the `signReport` action MUST instead raise a docudesk `signingRequest`

### Requirement: REQ-SIGN-005 — Cross-app signing/sign-off SHALL go through the ADR-019 integration registry, request-then-consume-outcome

Both delegation paths MUST use the **ADR-019 integration registry** for transport — no
hard-coded `docudesk` / `decidesk` hostnames in `lib/Service/Signing/`. The PHP shipped is
the ADR-031 **integration exception path** only:

- `SigningDelegationService` — `requestSignature()` opens a docudesk `signingRequest` via
  the registry; `onSigningCallback()` consumes the `signed`/`declined`/`expired` outcome and
  writes the document-signing mirror.
- `SignoffDecisionService` — `requestSignoff()` opens a decidesk `Decision`
  (signature-as-method) via the registry; `onDecisionCallback()` consumes the
  `approved`/`rejected` outcome and writes the decision mirror.

Neither service implements any signing or approval logic; each only *requests* and
*consumes an outcome*, then triggers the accounting consequence (REQ-SIGN-006). Callbacks
MUST be idempotent (a repeated callback is a no-op).

@e2e exclude backend integration: the registry request + docudesk/decidesk callback consumption are server-side, asserted via Newman/unit, not UI

#### Scenario: The registry is the only cross-app path

- **GIVEN** `lib/Service/Signing/SigningDelegationService.php` and `SignoffDecisionService.php`
- **WHEN** scanned for transport
- **THEN** all docudesk/decidesk calls MUST go through the ADR-019 integration registry, and no raw fleet hostname or hard-coded HTTP client to docudesk/decidesk MUST appear

#### Scenario: A repeated callback is idempotent

- **GIVEN** a finance object whose `signingStatus` is already `signed` (or `decisionOutcome` already `approved`) from a prior callback
- **WHEN** the same docudesk/decidesk callback is delivered again
- **THEN** the mirror MUST be unchanged and the accounting consequence MUST NOT fire a second time

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
a **read-only federated consumer view** that, through the ADR-019 registry, lists docudesk
`signingRequest`/`signingAuditEntry` records and decidesk `Decision` (sign-off/adoption)
records for finance documents — with no write actions and no local event store.

@e2e exclude unbuilt UI: the read-only federated signing view is not yet implemented

#### Scenario: The signing-trail nav leaf is removed but the page stays routable

- **GIVEN** `src/menu-layout.json` after this change
- **WHEN** the navigation is rendered
- **THEN** `BookkeepingSigningTrail` MUST NOT appear as a Bookkeeping nav leaf, **and** its page MUST remain reachable by direct route (deep-link), consistent with the existing `removals` pattern

#### Scenario: The surviving page owns no local trail

- **GIVEN** the re-implemented `BookkeepingSigningTrail` page
- **WHEN** inspected
- **THEN** it MUST be a read-only view federating docudesk + decidesk records via the registry, MUST have no write actions, and MUST NOT define or persist a local signing-trail schema

### Requirement: REQ-SIGN-008 — Notifications SHALL fire on the consumed signing/decision status, not on a local signing engine

Notification rules MUST be declared in the canonical `x-openregister-notifications` dialect
(ADR-031) on the **mirror** fields: signature `signed`/`declined`, decision
`approved`/`rejected`. They MUST use `updated` triggers with field-change conditions on
`signingStatus` / `decisionOutcome`, recipients via `{"kind":"field","field":"owner"}` plus
the object-acl `manage` permission, and subjects in `nl` + `en` (metadata-only). No
imperative notification dispatch (gate-18); no app-local signing engine emits them.

#### Scenario: Finance users are notified when the outcome arrives

- **GIVEN** an `ACMReport` whose `signingStatus` transitions to `signed` (or an `AnnualReport` whose `decisionOutcome` transitions to `approved`)
- **WHEN** the registry callback writes the mirror
- **THEN** an `x-openregister-notifications` rule MUST notify the owner / `manage`-acl users, driven by the field-change on the mirror status, with no imperative dispatch

### Requirement: REQ-SIGN-009 — Existing sign-off status SHALL migrate to a consumed decision/signature reference without losing accounting data

A `lib/Repair/*` step (or OR data-migration) MUST map every existing sign-off status to a
**consumed decision/signature reference** carrying `kind: legacy-local`, preserving
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

## MODIFIED Requirements

### Requirement: REQ-SIGN-010 — The `ACMReport` signing behaviour (formerly local PKI) is delegated to docudesk

The `ACMReport` schema's signing behaviour — previously the local `signReport` lifecycle-action writing `signatureFingerprint` via `AcmReportGenerator::sign` — MUST be **modified** to a docudesk delegation (REQ-SIGN-001/004/005). The `signatureFingerprint`
property is retained only as `legacy-local` provenance (REQ-SIGN-009); all new signatures
flow through docudesk; the `sign` transition is outcome-driven (REQ-SIGN-003); the ACM
submission consequence stays in shillinq (REQ-SIGN-006).

#### Scenario: New ACM report signatures no longer use the local handler

- **GIVEN** an `ACMReport` created after this change
- **WHEN** it is signed
- **THEN** the signature MUST be a docudesk `signingRequest` outcome recorded in the consumer fields, and `AcmReportGenerator::sign` MUST NOT be invoked

## REMOVED Requirements

### Requirement: REQ-SIGN-011 — shillinq SHALL NOT own a local e-signature engine, approval state machine, or signing trail

The capability of shillinq **owning** signing/approval MUST be removed: the local PKI signing
handler (`AcmReportGenerator::sign`), the app-local approval/sign-off state machine embedded
in finance entities, and the `BookkeepingSigningTrail` owned trail are retired. The
authority for document e-signature is docudesk; the authority for governance sign-off is
decidesk; shillinq is a pure consumer (REQ-SIGN-001/002/005). Only the accounting
consequence (REQ-SIGN-006) and legitimate accounting status remain in shillinq.

#### Scenario: No owned signing/approval capability remains

- **GIVEN** the shillinq codebase after this change
- **WHEN** audited for an owned e-signature engine, an owned approval state machine, or an owned signing trail
- **THEN** none MUST exist; every signing/approval surface MUST be a consumer of docudesk or decidesk via the ADR-019 registry, while the accounting consequence and finance status enums remain local
