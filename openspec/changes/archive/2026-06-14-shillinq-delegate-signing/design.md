# Design — Delegate Signing & Sign-off (docudesk + decidesk)

## Context

Shillinq has grown three overlapping, app-local takes on "this is signed / approved":

1. **`BookkeepingSigningTrail`** — a Bookkeeping nav leaf (declared in `src/manifest.json`)
   presenting a "who signed what" trail. There is **no e-signature engine** behind it; it
   is a presentation of locally-recorded sign-off events.
2. **A local PKI signing handler** — `ACMReport`'s `signReport` lifecycle-action
   (`x-openregister-lifecycle-actions.signReport`, `trigger: beforeTransition`,
   `handler: OCA\Shillinq\Service\AcmReportGenerator::sign`) writes `signatureFingerprint`
   ("PKI certificate fingerprint") on the `draft → ready-for-submission` transition. A
   hand-rolled pseudo-signature with no eIDAS level, provider, or independent audit chain.
3. **Embedded sign-off status** on finance entities:
   - `ACMReport` — concerncontroller signs (`sign` transition, `concerncontroller` role,
     `signatureFingerprint`);
   - `ActuarialValuation` — actuaris signs (`approvalStatus`, `approvedBy`, `approvedAt`,
     `actuary`, `actuaryCertificationNumber`);
   - `AnnualReport` / jaarrekening — the AV/board **adopts** the annual accounts
     (`vaststellen`: `in-review → vastgesteld`; `vaststellenZonderReview`:
     `opgemaakt → vastgesteld`).

The fleet already owns both halves (ADR-012, one capability one home):

- **docudesk** — document e-signature: `signingRequest`, `signingSession`, `signerRecord`,
  `signingAuditEntry`, eIDAS modes/levels, providers, an independent audit chain.
- **decidesk** — governance sign-off decisions: `Decision` supertype (`decisionType`),
  routes/stages, decision **methods including signature/eIDAS-as-a-method**, chair-register,
  the adopt → approve → sign → publish lifecycle.

The design tension: signing/approval *triggers an accounting consequence* in shillinq (ACM
submission gating, annual-accounts year-end posting, IAS19 actuarial posting). The
resolution mirrors `administration-import-migration`'s split: the **flow** (signing,
approval) is delegated to its canonical owner via the ADR-019 registry; the **consequence**
(GL posting, lifecycle flip) stays in shillinq, gated on the consumed outcome. shillinq
keeps a *mirror* of the owner's status, never the authority.

## Goals

- shillinq stops **owning** any e-signature engine or approval state machine.
- Document e-signature on a finance document is a **docudesk `signingRequest`** via the
  ADR-019 registry; shillinq stores only the reference + mirrored status.
- Governance sign-off (concerncontroller, actuaris, AV/board adoption) is a **decidesk
  `Decision`** (signature-as-method); shillinq consumes the outcome.
- `BookkeepingSigningTrail` retired as owned capability; page survives as a **read-only
  federated consumer view** (docudesk + decidesk records), no local trail schema.
- The **accounting consequence stays in shillinq** and still fires on the consumed outcome.
- **No data loss**: existing sign-off status maps to a consumed decision/signature
  reference with `legacy-local` provenance; legitimate accounting status untouched.

## Non-Goals

- No building/changing docudesk's signing engine or decidesk's decision engine (consumed,
  not modified). New `decisionType` values or document-class enums are *their* changes.
- No touching the IFRS15/16 contract *accounting* schemas (`Contract`, `LeaseContract`,
  `FXContract`, …) — kept in shillinq; contract *approval* is `contract-lifecycle-management`.
- No removing OpenRegister's generic `ApprovalChainPanel`/`ApprovalStepList` (related, out
  of scope).
- No re-implementation of the GL posting / journal rules — they stay exactly as-is, only
  re-triggered by the consumed outcome instead of the local signing transition.
- No new shillinq signing schema; no app-local signing trail store.

## Reuse Analysis

| Need | Reused / canonical owner | What this change adds in shillinq |
|---|---|---|
| Document e-signature (eIDAS) | **docudesk** `signingRequest`/`signingSession`/`signingAuditEntry` | Consumer fields (`signingRequestRef`/`signingStatus`/`signingLevel`/`signedDocumentRef`); registry request + callback consumption |
| Governance sign-off / adoption | **decidesk** `Decision` (signature-as-method, adopt/approve) | Consumer fields (`decisionRef`/`decisionOutcome`); registry request + outcome consumption |
| Cross-app transport | **ADR-019 integration registry** | shillinq→docudesk and shillinq→decidesk calls; no hard-coded HTTP |
| Signed-document artifact | docudesk output → **NC Files** | `signedDocumentRef` (link, don't re-store) |
| Object CRUD/lifecycle/notifications | **OpenRegister** generic surface (ADR-022) | Outcome-driven lifecycle + notification rules on the mirror fields |
| GL posting / journal | **shillinq** existing journal/GL surface | Kept; re-triggered by consumed outcome (the *consequence*, not the flow) |
| Migration of existing status | `lib/Repair/*` step | Map sign-off status → `legacy-local` consumed reference, lossless |
| Generic approval UI | OR `ApprovalChainPanel`/`ApprovalStepList` | Noted as related; not removed |

## Decisions

### D1 — Two delegation targets, by *kind* of signing

A precise split, used verbatim from INTERFACE CONTRACT #2:

- **Document e-signature** (sign *this PDF*: jaarrekening, ACM report, management letter) →
  **docudesk** `signingRequest`. This is "apply a cryptographic signature to a document
  artifact."
- **Governance sign-off** (a person/body *decides* to approve/adopt) → **decidesk**
  `Decision` with signature-as-a-method. This is "a decision with a body, a quorum, a route,
  and an audit obligation."

The two often co-occur (the AV *adopts* the jaarrekening → a decidesk Decision, *and* the
PDF is *signed* → a docudesk request), and that is fine: they are different artifacts with
different owners. shillinq references both and derives one accounting consequence.

### D2 — shillinq stores a *mirror*, never the authority

Each finance entity gains a small consumer field set:

- Document signing: `signingRequestRef` (docudesk id), `signingStatus` (mirror: requested /
  in-progress / signed / declined / expired), `signingProvider`, `signingLevel` (eIDAS
  level, read-back for the dossier), `signedDocumentRef` (NC Files ref to the signed PDF).
- Governance decision: `decisionRef` (decidesk `Decision` id), `decisionOutcome` (mirror:
  pending / approved / rejected).

These mirrors are **written only by the registry callback** from the canonical owner —
never by an app-local transition. shillinq's lifecycle is *gated on* the mirror. There is no
shillinq-owned approval transition that could diverge from decidesk, and no local
fingerprint that could diverge from docudesk.

### D3 — Replace the PKI handler with an integration exception-path

`AcmReportGenerator::sign` (writes a PKI fingerprint on a transition) is **removed**. The
`ACMReport.signReport` lifecycle-action is re-pointed at a new
`SigningDelegationService`, an ADR-031 *exception path* whose only jobs are:

1. on `requestSignature`, open a docudesk `signingRequest` for the report PDF **through the
   ADR-019 registry**, set `signingStatus = requested`, and **hold** the report in `draft`
   (it does not self-advance);
2. on the docudesk *signed* callback, write `signingRequestRef` / `signingStatus = signed` /
   `signingLevel` / `signedDocumentRef`, **then** flip the lifecycle
   (`draft → ready-for-submission`) and run the *existing* ACM submission-gating
   consequence.

It performs **no signing** — no PKI, no certificate handling — so the local signing
dependency is removed entirely. A symmetric `SignoffDecisionService` does the same for the
decidesk `Decision` path.

### D4 — Lifecycle transitions become outcome-driven, not engine-driven

- `ACMReport.sign` (`draft → ready-for-submission`) becomes gated on
  `signingStatus == signed` (document) and/or the decidesk concerncontroller
  `decisionOutcome == approved` (governance) — not on a local fingerprint write.
- `ActuarialValuation` approval gates on the decidesk `decisionOutcome == approved`; its
  `approvalStatus`/`approvedBy`/`approvedAt` become a **mirror** of the decision, not the
  authority. The IAS19 posting still fires on approval.
- `AnnualReport.vaststellen` / `vaststellenZonderReview` (`→ vastgesteld`) become
  **outcome-driven adoption**: gated on the decidesk **adoption** `Decision`
  outcome `approved`. The year-end / retained-earnings posting that adoption triggers stays
  in shillinq and fires on the consumed outcome.

The transition *definitions* stay (so deep-links, existing reports, and the accounting
consequence keep working); only their **guard** moves from "local engine wrote a value" to
"canonical owner returned an outcome."

### D5 — `BookkeepingSigningTrail` retired, page survives as a federated view

Per the established `src/menu-layout.json` `removals` pattern (a removed leaf's **page stays
routable for deep links**), `BookkeepingSigningTrail` is added to `removals`. Its page
component is re-implemented as a **read-only federated consumer view** that, through the
ADR-019 registry, lists:

- docudesk `signingRequest` / `signingAuditEntry` records for finance documents, and
- decidesk `Decision` records (sign-off / adoption) for finance objects.

It owns **no local trail schema** and stores **no signing events** — it is unmistakably a
consumer view. This is the only thing the "trail" should ever have been.

### D6 — Keep the accounting consequence local (the whole point)

The line we do **not** cross: removing *ownership of the signing/approval flow* must not
remove the *accounting consequence*. Three consequences stay in shillinq, now triggered by
the consumed outcome:

- `ACMReport` signed → submission gate opens (ACM report becomes submittable).
- `AnnualReport` adopted → year-end / retained-earnings posting runs.
- `ActuarialValuation` approved → IAS19 actuarial posting runs.

A dedicated test (and the hydra `orphan-auth` gate) guards the boundary: the consequence
fires on outcome; only the flow ownership is gone.

### D7 — Lossless migration via `legacy-local` provenance

`lib/Repair/DelegateSigningMigrationRepair.php` maps existing sign-off status to a
**consumed decision/signature reference** carrying `kind: legacy-local`:

- an already-signed `ACMReport` → synthetic `signingRequestRef` `legacy-local:<fingerprint>`,
  `signingStatus = signed`, preserving the old `signatureFingerprint` as provenance;
- an already-approved `ActuarialValuation` → synthetic `decisionRef`
  `legacy-local:<approvedBy>@<approvedAt>`, `decisionOutcome = approved`;
- an already-adopted `AnnualReport` (`vastgesteld`/`gedeponeerd`) → synthetic `decisionRef`
  `legacy-local:vaststellen`, `decisionOutcome = approved`.

The finance **status enums are never rewritten** (`ACMReport.status`, the AnnualReport
states, the ActuarialValuation valuation fields stay exactly as they are). The migration is
additive + provenance-preserving, so an auditor sees an unbroken chain and rollback is
clean.

### D8 — Everything cross-app goes through the ADR-019 registry

No hard-coded `https://docudesk…` or `https://decidesk…` HTTP. Both the request side
(open `signingRequest` / open `Decision`) and the consume side (callback writing the mirror)
route through the shared integration registry. This is asserted by a gate/test (no raw
fleet hostnames in `lib/Service/Signing/`).

### D9 — i18n with ENGLISH source keys

All federated-view labels and consumer-status strings use English source keys —
`t('shillinq', 'Awaiting docudesk signature')` → nl `'Wacht op docudesk-ondertekening'`,
`t('shillinq', 'Adopted by the general meeting')` → nl `'Vastgesteld door de AV'` — with
`nl` translations in the same commit; notification subjects in both `nl` and `en`,
metadata-only.

## Migration / Rollout

1. Ship the consumer field sets (additive) + outcome-driven guards behind the existing
   lifecycle; old objects keep working.
2. Run `DelegateSigningMigrationRepair` to back-fill `legacy-local` references.
3. Remove `AcmReportGenerator::sign`; re-point `signReport` at `SigningDelegationService`.
4. Add `BookkeepingSigningTrail` to `menu-layout.json` `removals`; deploy the federated
   view component.
5. New signatures/sign-offs flow through docudesk/decidesk; the GL consequence fires on
   outcome.

## Risks (summary; full list in proposal.md)

- A regulated report cannot be locally insta-signed any more (correct — real signatures take
  a signer); mitigated by lossless migration + clear "awaiting docudesk signature" status.
- Two systems disagreeing on adopted/signed → eliminated by the pure-consumer mirror.
- Dropping the GL consequence with the signing ownership → explicit guard scenarios + test.
- Migration mislabelling accounting status as signing status → repair touches only the
  enumerated signing/approval fields; accounting enums asserted unchanged.

## Alternatives Considered

- **Keep the local PKI signature, "good enough."** Rejected: a pseudo-eIDAS signature on a
  regulated ACM report / jaarrekening is a compliance liability and duplicates docudesk
  (ADR-012). docudesk does real eIDAS levels + providers + an independent audit chain.
- **Keep the approval state machine in shillinq, sync to decidesk.** Rejected: two writable
  authorities drift; the board minutes and the books must never disagree on "adopted."
  Pure consumer = one authority.
- **Delete `BookkeepingSigningTrail` entirely (page + route).** Rejected: the
  `menu-layout.json` pattern is to *remove the nav leaf, keep the page routable*; a
  read-only federated view of the canonical records is genuinely useful to finance users
  and costs no ownership.
- **Move the GL posting consequence into decidesk/docudesk too.** Rejected: the accounting
  consequence is shillinq's domain (ADR-022 — shillinq owns its GL). Only the
  signing/approval *flow* is delegated.
