# Proposal: shillinq-delegate-signing

`kind: integration + deprecation` per ADR-019/ADR-022/ADR-012 — shillinq stops
**owning** an e-signature engine and a governance approval state machine. Document
e-signature is requested from **docudesk** through the ADR-019 integration registry;
governance sign-off is modelled as a **decidesk Decision** (signature-as-a-method) and
consumed as an outcome. shillinq keeps only the *accounting consequence* (the GL posting
and the lifecycle flip it already owns). The `BookkeepingSigningTrail` capability is
retired; its nav page survives as a read-only consumer view federating docudesk + decidesk
records. No new shillinq signing schema; one ADR-031 integration-consumer field set + one
ADR-031 exception-path replacement of the local PHP signing handler.

## Summary

Shillinq today re-implements signing and sign-off locally, three ways:

1. A **`BookkeepingSigningTrail`** navigation page under Bookkeeping — an app-local
   "who signed what" trail with no real signing engine behind it.
2. A **local PHP signing handler** — `ACMReport`'s `signReport` lifecycle-action
   (`OCA\Shillinq\Service\AcmReportGenerator::sign`) writes a PKI
   `signatureFingerprint` on the `draft → ready-for-submission` transition. This is a
   hand-rolled, single-app pseudo-signature: it has no eIDAS level, no provider, no
   independent audit chain, no signer-identity verification.
3. **Sign-off *status* embedded in finance entities** — `ACMReport`
   (concerncontroller signs), `ActuarialValuation` (`approvalStatus` / `approvedBy` /
   `approvedAt`, actuaris signs), and `AnnualReport` / jaarrekening
   (`vaststellen` / `vaststellenZonderReview` — the AV/board *adopts* the annual
   accounts). These encode an **approval/decision state machine** inside the finance app.

The fleet already has canonical owners for both halves (ADR-012 — one capability, one
home):

- **docudesk** owns document e-signature: `signingRequest`, `signingSession`,
  `signerRecord`, `signingAuditEntry`, with eIDAS modes/levels, providers, and an
  independent audit trail. This is the canonical e-signature engine.
- **decidesk** owns governance sign-off **decisions**: the `Decision` supertype
  (`decisionType`), decision routes/stages, decision **methods including
  signature/eIDAS-as-a-method**, chair-register, and the adopt → approve → sign →
  publish lifecycle. This is the canonical approval authority.

This change makes shillinq a **consumer** of both, per CROSS-APP INTERFACE CONTRACT #2:

- **Document e-signature** (signing a jaarrekening PDF, an ACM report, a management
  letter) → raised as a **docudesk `signingRequest`** via the ADR-019 integration
  registry. shillinq stores only the returned **signing reference + resulting status**
  on the originating finance object — never its own trail or PKI fingerprint.
- **Governance sign-off** (concerncontroller approves, AV/board adopts) → raised as a
  **decidesk `Decision`** (signature-as-method) via the registry. shillinq **consumes
  the decision outcome** to drive its own GL posting / lifecycle transition. shillinq
  does not own the approval state machine.
- **`BookkeepingSigningTrail`** is retired as an owned capability. The page MAY remain as
  a **read-only federated view** that surfaces docudesk signing records + decidesk
  decisions for finance documents — clearly a consumer view, no local trail schema.

The **accounting consequence stays local**: when a docudesk signature or a decidesk
sign-off decision completes, shillinq still performs the resulting GL posting / status
flip. That logic — and the IFRS15/16 contract *accounting* schemas, and all legitimate
financial status — is untouched. We remove the **ownership of the signing/approval
flow**, not the accounting.

**Depends on:**
- **docudesk** document e-signature API (`signingRequest` / `signingSession` /
  `signerRecord`; eIDAS modes) — consumed via ADR-019 integration registry.
- **decidesk** decision API (`Decision` supertype, decisionType, signature-as-method,
  adopt/approve lifecycle, decision outcome) — consumed via ADR-019 integration registry.
- ADR-019 integration-registry (cross-app calls go through the shared registry, not
  hard-coded HTTP).
- ADR-022 apps-consume-or-abstractions (no redundant per-app CRUD; consume the canonical
  owner's surface).
- ADR-031 schema-declarative-business-logic (consumer fields + lifecycle as schema
  metadata; PHP only for the genuine integration exception path).
- ADR-037 modular-config-fragments (schema edits land in `lib/Settings/register.d/*`
  fragments; nav lives in `src/menu-layout.json`).
- ADR-012 deduplication (this change exists *because* signing/approval is duplicated;
  Phase 0 proves the delegation targets own it).
- Existing shillinq capabilities that own the *accounting* side and stay in shillinq:
  `bookkeeping-market-government-separation` (`ACMReport`),
  `bookkeeping-pension-ias19` (`ActuarialValuation`),
  `bookkeeping-titel-9-jaarrekening` (`AnnualReport`),
  `bookkeeping-general-ledger` / `bookkeeping-journal-entries` (the GL posting surface).

## Motivation

Three identical problems sit behind this:

**A pseudo-signature is worse than no signature.** `AcmReportGenerator::sign` writes a
"PKI fingerprint" string on a status transition. It is not eIDAS-graded, not provider-
backed, not independently auditable, and not verifiable by a counterparty — yet it *looks*
like a legal signature on a regulatory ACM report. docudesk exists precisely to do this
correctly (qualified/advanced/simple eIDAS levels, real providers, a tamper-evident
`signingAuditEntry` chain). Delegating removes a compliance liability.

**An approval state machine in a finance app drifts from the governance record.** When the
AV adopts the annual accounts (`AnnualReport.vaststellen`) or a concerncontroller signs off
an ACM report, that is a **governance decision** with a quorum, a body, a route, and an
audit obligation — all of which decidesk models as a first-class `Decision`. Re-encoding it
as a shillinq lifecycle transition means the same adoption exists in two systems that can
disagree; the board minutes (decidesk) and the books (shillinq) must never diverge on
"was this adopted?".

**A second signing trail is a maintenance and audit trap.** `BookkeepingSigningTrail` is an
app-local "who signed what" view with nothing real behind it. The authoritative trail is
docudesk's `signingAuditEntry` plus decidesk's decision record. Two trails means two
sources of truth for an auditor; ADR-012 forbids exactly this.

Closing all three makes shillinq do what it is *good at* — derive the accounting
consequence — and lets the canonical owners do signing and governance.

## Affected Projects

- [x] Project: shillinq — replace the local signing handler with a docudesk
  integration-consumer; replace embedded sign-off status with consumed decision/signature
  references; retire `BookkeepingSigningTrail` ownership (nav removal +
  read-only federated view); keep the GL posting / lifecycle-flip consequence.
- [ ] Project: docudesk — **consumed, not changed**. shillinq calls the existing
  `signingRequest` / `signingSession` surface through the ADR-019 registry. (If a finance
  document-class adapter is missing on docudesk's side, that is a separate docudesk change,
  flagged as an open question — not in this scope.)
- [ ] Project: decidesk — **consumed, not changed**. shillinq opens a `Decision`
  (signature-as-method) through the registry and reads the outcome. (Any new
  finance-specific `decisionType` value is a decidesk change, flagged — not in this scope.)
- [ ] Project: openregister — consumer only (object surface, lifecycle, notifications);
  no OR changes required. OR's generic `ApprovalChainPanel` / `ApprovalStepList` framework
  is **noted as related, explicitly out of scope to remove**.

## Scope

### In Scope

- **Retire `BookkeepingSigningTrail` ownership**: remove the leaf from the Bookkeeping
  navigation in `src/menu-layout.json` (`removals`); the page stays routable for deep
  links per the established `menu-layout.json` removal pattern, but is **re-pointed at a
  read-only federated view** that lists docudesk signing records + decidesk decisions for
  finance documents. No local signing-trail schema is created, and no app-local trail
  store survives.
- **Document e-signature via docudesk** (ADR-019 consumer): a finance object that needs a
  document signed (jaarrekening PDF, ACM report, management letter) raises a docudesk
  `signingRequest` through the integration registry. shillinq stores only the returned
  reference + status on the finance object:
  - `signingRequestRef` (docudesk `signingRequest` id),
  - `signingStatus` (mirror of docudesk's status: requested / in-progress / signed /
    declined / expired),
  - `signingProvider` / `signingLevel` (eIDAS level, read back for the dossier),
  - `signedDocumentRef` (NC Files reference to the signed artifact docudesk produced).
  These replace the local `signatureFingerprint` on `ACMReport`.
- **Replace the local PHP signing handler**: the `ACMReport` `signReport`
  lifecycle-action that calls `AcmReportGenerator::sign` (writes a PKI fingerprint) is
  replaced by an ADR-031 **integration exception-path** unit that (a) opens the docudesk
  `signingRequest` and (b) on the docudesk *signed* callback, flips the `ACMReport`
  lifecycle (`draft → ready-for-submission`) and records the reference. No PKI signing is
  performed in shillinq.
- **Governance sign-off via decidesk** (ADR-019 consumer): the embedded approval status on
  finance entities is reframed as a *consumed decision*:
  - `ACMReport` concerncontroller sign-off → a decidesk `Decision`
    (signature-as-method, decisionType = sign-off);
  - `ActuarialValuation` actuaris sign-off (`approvalStatus`/`approvedBy`/`approvedAt`) →
    a decidesk `Decision` (signature-as-method);
  - `AnnualReport` / jaarrekening adoption (`vaststellen` / `vaststellenZonderReview`,
    AV/board adopts) → a decidesk **adoption** `Decision`.
  Each finance entity gains a `decisionRef` (decidesk `Decision` id) + `decisionOutcome`
  (mirror: pending / approved / rejected) consumer field set. shillinq **consumes the
  outcome** to drive its transition — it does not own the approval transition logic.
- **Keep the accounting consequence local**: when the docudesk signature or the decidesk
  decision completes, shillinq performs the *resulting* GL posting / status flip through
  its existing journal/GL surface (ADR-022). This logic stays in shillinq. The
  `AnnualReport` adoption still triggers the year-end / retained-earnings posting; the
  `ACMReport` sign-off still gates submission; the `ActuarialValuation` approval still
  gates the IAS19 posting.
- **Migration (no data loss)**: a `lib/Repair/*` step maps every existing sign-off status
  value to a **consumed decision/signature reference** placeholder so historical objects
  remain valid and auditable (e.g. an already-signed `ACMReport` gets a synthetic
  `signingRequestRef` of kind `legacy-local` recording the pre-delegation fingerprint as
  provenance; an already-adopted `AnnualReport` gets a `decisionRef` of kind `legacy-local`
  preserving `vaststellen` provenance). Legitimate **accounting status is never touched**.
- **Notifications** (ADR-031 dialect): rules fire on the *consumed* status fields
  (signature signed/declined, decision approved/rejected) so finance users learn the
  outcome — driven by the mirrored status, not by an app-local signing engine.
- **i18n**: ENGLISH source keys, `nl` + `en` catalogs for the new consumer-view + status
  strings.

### Out of Scope

- **Building or changing docudesk's signing engine** — `signingRequest` /
  `signingSession` / eIDAS modes already exist there. shillinq only consumes them.
- **Building or changing decidesk's decision engine** — the `Decision` supertype,
  signature-as-method, and adopt/approve lifecycle already exist there. shillinq only
  consumes the outcome. A *new* finance `decisionType` value, if one is wanted, is a
  decidesk change.
- **The IFRS15/IFRS16 contract *accounting* schemas** (`Contract`, `ContractModification`,
  `LeaseContract`, `ContractAsset`, `ContractLiability`, `ContractCostAsset`, `FXContract`)
  — these are revenue/lease recognition artifacts, **kept in shillinq untouched**. Where a
  contract needs *approval*, that decision is raised on decidesk (INTERFACE CONTRACT #3),
  but that is the separate `contract-lifecycle-management` change's concern, referenced
  here, not duplicated.
- **procest's contract/decision delegation** — its own change (INTERFACE CONTRACT #3); out
  of scope here.
- **Removing OpenRegister's generic `ApprovalChainPanel` / `ApprovalStepList`** — noted as
  related, explicitly not in scope to remove.
- **The GL posting / journal logic itself** — unchanged; it stays in shillinq.

## Approach

1. **Phase 0 (ADR-012)** proves docudesk owns document e-signature and decidesk owns
   governance sign-off, and inventories every local signing/approval surface in shillinq
   (the `BookkeepingSigningTrail` page, `AcmReportGenerator::sign`, and the three entities'
   embedded status). Confirms the *accounting* side must stay.
2. **Schema fragments (ADR-037/ADR-031)**: add the **consumer field sets** to the three
   finance schemas in their existing fragments — `signingRequestRef` / `signingStatus` /
   `signingProvider` / `signingLevel` / `signedDocumentRef` for document e-signature;
   `decisionRef` / `decisionOutcome` for governance sign-off. Mark the obsolete owned fields
   (`signatureFingerprint`; the local `approve`/`sign` transitions' *ownership*) as
   delegated. Lifecycle transitions become **outcome-driven** (gated on the consumed
   status), not engine-driven.
3. **Integration exception path (ADR-031 PHP)**: a thin `SigningDelegationService` /
   `SignoffDecisionService` that, through the ADR-019 registry, (a) raises a docudesk
   `signingRequest` or a decidesk `Decision`, and (b) on the canonical owner's callback,
   records the reference and flips the finance lifecycle so the **existing** GL posting
   runs. Replaces `AcmReportGenerator::sign`. No signing/approval logic is implemented in
   shillinq — only request + consume-outcome + post.
4. **Nav (ADR-037)**: remove `BookkeepingSigningTrail` from Bookkeeping in
   `src/menu-layout.json` `removals`; re-point the still-routable page at a read-only
   federated consumer view.
5. **Migration (`lib/Repair/*`)**: map existing sign-off status → consumed
   decision/signature reference (`legacy-local` provenance); never drop accounting status.
6. **Tests + gates**: assert no local signing engine remains, the registry is the only
   cross-app path, the GL consequence still fires on outcome, and the migration is
   lossless.

## New Dependencies

None new in shillinq beyond the **ADR-019 integration registry** (already present) for the
docudesk and decidesk calls. No PKI/signing library is needed any more (delegated to
docudesk) — the local PKI signing in `AcmReportGenerator::sign` is **removed**, reducing
shillinq's dependency surface.

## Impact

- `src/menu-layout.json` — add `BookkeepingSigningTrail` to `removals`; page stays
  routable, re-pointed at the read-only federated view.
- `lib/Settings/register.d/bookkeeping-market-government-separation.json` — `ACMReport`:
  add docudesk signing consumer fields + decidesk `decisionRef`/`decisionOutcome`; mark
  `signatureFingerprint` delegated; rewrite the `sign` transition + `signReport` action to
  be **outcome-driven** (no local PKI). The GL/submission consequence stays.
- `lib/Settings/register.d/bookkeeping-pension-ias19.json` — `ActuarialValuation`: add
  `decisionRef`/`decisionOutcome`; mark `approvalStatus`/`approvedBy`/`approvedAt` as
  decision-mirror (consumed), not app-owned; IAS19 posting consequence stays.
- `lib/Settings/register.d/bookkeeping-titel-9-jaarrekening.json` — `AnnualReport`: add
  `decisionRef`/`decisionOutcome`; `vaststellen`/`vaststellenZonderReview` become
  **outcome-driven** adoption transitions consuming a decidesk adoption Decision; the
  year-end / retained-earnings posting consequence stays.
- `lib/Service/Signing/SigningDelegationService.php` — NEW ADR-031 exception-path:
  raises docudesk `signingRequest` via the registry, consumes the signed callback, flips
  the finance lifecycle, posts the GL consequence. Replaces `AcmReportGenerator::sign`.
- `lib/Service/Signing/SignoffDecisionService.php` — NEW ADR-031 exception-path: raises a
  decidesk `Decision` (signature-as-method) via the registry, consumes the outcome, flips
  the finance lifecycle, posts the GL consequence.
- `lib/Service/AcmReportGenerator.php` — REMOVE the `sign()` PKI handler; the `signReport`
  lifecycle-action handler is re-pointed at `SigningDelegationService`.
- `src/manifest.d/*` — the `BookkeepingSigningTrail` page component becomes a read-only
  federated view (docudesk signing records + decidesk decisions for finance documents); no
  local trail schema.
- `lib/Repair/DelegateSigningMigrationRepair.php` — NEW: maps existing sign-off status →
  consumed decision/signature reference (`legacy-local` provenance); lossless.
- `l10n/en.json`, `l10n/nl.json` — new keys (ENGLISH source strings) for the federated view
  + consumer status labels.
- `tests/Unit/Service/Signing/*`, `tests/integration/*`, `tests/e2e/*` — delegation,
  outcome-consumption, GL-consequence-still-fires, no-local-engine, lossless-migration.

## Cross-Project Dependencies

- **docudesk** — owns `signingRequest`/`signingSession`/eIDAS; shillinq consumes via the
  registry. The signed-document artifact (`signedDocumentRef`) is produced by docudesk and
  referenced (NC Files), not re-stored.
- **decidesk** — owns the `Decision` supertype, signature-as-method, adopt/approve
  lifecycle; shillinq consumes the *outcome*. The board/AV adoption and concerncontroller
  sign-off are decidesk Decisions; shillinq never owns the approval transition.
- **INTERFACE CONTRACT #3 / `contract-lifecycle-management`** — soft. Contract *approval*
  decisions also go to decidesk; the IFRS15/16 contract *accounting* schemas stay in
  shillinq. This change shares the same "raise a decidesk Decision, consume the outcome,
  keep the recognition local" pattern but does not touch contract schemas.
- **openregister** — `ApprovalChainPanel`/`ApprovalStepList` generic framework noted as
  related; not removed.

## Risks

### Risk 1: Removing the local signature leaves a regulated report unsignable until docudesk is reachable

**Severity**: High
**Mitigation**: the migration is lossless (existing signed reports keep their provenance
via `legacy-local` references and stay submitted/valid). For *new* signatures, the docudesk
request is raised through the ADR-019 registry with the registry's standard
availability/retry semantics; the `ACMReport` simply stays in `draft` until the docudesk
*signed* outcome arrives — it never silently self-signs. A clear "awaiting docudesk
signature" status replaces the instant local fingerprint. This is correct behaviour for a
real signature (it takes a signer), not a regression.

### Risk 2: Two systems disagree on "was this adopted/signed?"

**Severity**: High
**Mitigation**: by making shillinq a pure consumer of the decidesk `Decision` outcome (and
docudesk signing status), there is exactly **one** authority. shillinq stores a *mirror*
(`decisionOutcome` / `signingStatus`) keyed by reference; the registry callback is the only
writer of that mirror; shillinq's lifecycle is gated on it. There is no app-local approval
transition that can diverge.

### Risk 3: The GL consequence is accidentally dropped along with the signing ownership

**Severity**: High
**Mitigation**: explicit spec scenarios assert that when the docudesk signature / decidesk
decision completes, the shillinq GL posting / lifecycle flip **still fires** (ACM
submission gate, AnnualReport year-end posting, ActuarialValuation IAS19 posting). The
hydra `orphan-auth` / `redundant-controller` gates plus a dedicated test guard the boundary:
remove *ownership of the flow*, keep the *consequence*.

### Risk 4: Migration mislabels legitimate accounting status as signing status and drops it

**Severity**: Medium
**Mitigation**: the repair step maps **only** the enumerated signing/approval fields
(`signatureFingerprint`, `approvalStatus`/`approvedBy`/`approvedAt`, the adoption
transition record) to consumed references; the finance status enums (`ACMReport.status`,
`AnnualReport` `concept/opgemaakt/in-review/vastgesteld/gedeponeerd`,
`ActuarialValuation` valuation fields) are explicitly left untouched and asserted unchanged
by a migration test.

## Rollback Strategy

**During implementation (before merge):** revert the implementing PR.

**Post-merge, before adoption:** the consumer field sets are additive; the
`SigningDelegationService` / `SignoffDecisionService` namespace is self-contained. Restoring
`AcmReportGenerator::sign` and re-adding `BookkeepingSigningTrail` to the nav re-instates the
local flow without touching any accounting register. The `legacy-local` references make the
migration reversible.

**Production:** existing objects keep their `legacy-local` provenance references, so an
auditor sees an unbroken history across the delegation boundary regardless of rollback.

## Open Questions

1. **Does docudesk expose a finance document-class adapter today?** If `signingRequest`
   needs a document-type/category for jaarrekening vs ACM report vs management letter and
   that enum is missing, that is a small **docudesk** change (flagged), not shillinq's.
2. **Is "adoption" already a decidesk `decisionType`, or do we add one?** AV/board adoption
   of annual accounts may map onto an existing decidesk decisionType (adopt) or need a new
   value — a **decidesk** decision, flagged here.
3. **Read-only federated view source**: does the federated `BookkeepingSigningTrail` view
   query docudesk + decidesk live through the registry per render, or consume a
   denormalised mirror updated on callback? Leaning live-through-registry for truth, with a
   cached fallback; decided at design review.
