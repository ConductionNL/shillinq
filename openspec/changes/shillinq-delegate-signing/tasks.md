# Tasks — Delegate Signing & Sign-off (docudesk + decidesk)

> Consumer-first per ADR-019/ADR-022/ADR-031: shillinq stops owning signing/approval.
> Document e-signature is a docudesk `signingRequest`; governance sign-off is a decidesk
> `Decision` (signature-as-method). shillinq stores only mirrored references and consumes
> the outcome to drive its **existing** GL posting / lifecycle flip. The only PHP shipped is
> the ADR-031 integration exception path (raise request + consume callback + post
> consequence); it contains **zero** signing/approval logic. The accounting consequence is
> never delegated — it stays in shillinq.

## Phase 0: Deduplication Check

- [ ] Task 1: Document, per ADR-012, the canonical owners and the local duplicates:
  - **docudesk** OWNS document e-signature: confirm schemas `signingRequest`,
    `signingSession`, `signerRecord`, `signingAuditEntry` and eIDAS modes/levels/providers
    exist there (`src/views/signing/`).
  - **decidesk** OWNS governance sign-off: confirm the `Decision` supertype
    (`decisionType`), decision routes/stages, decision **methods including
    signature/eIDAS-as-a-method**, chair-register, and adopt/approve lifecycle exist there.
  - Inventory the shillinq local duplicates this change retires/delegates: the
    `BookkeepingSigningTrail` leaf in `src/manifest.json`; the local PKI handler
    `OCA\Shillinq\Service\AcmReportGenerator::sign` (writes `signatureFingerprint` on the
    `ACMReport` `sign` transition); the embedded sign-off status on `ACMReport`
    (concerncontroller), `ActuarialValuation` (`approvalStatus`/`approvedBy`/`approvedAt`),
    and `AnnualReport` (`vaststellen`/`vaststellenZonderReview`).
  - Confirm what must **stay in shillinq** and is NOT touched: the GL posting / journal
    surface, the finance status enums (`ACMReport.status`, the AnnualReport states, the
    ActuarialValuation valuation fields), and the IFRS15/16 contract *accounting* schemas
    (`Contract`, `ContractModification`, `LeaseContract`, `ContractAsset`,
    `ContractLiability`, `ContractCostAsset`, `FXContract`).
  - Note OpenRegister's generic `ApprovalChainPanel`/`ApprovalStepList` as **related, not
    in scope to remove**. Document findings explicitly even where "no overlap to keep".

## Phase 1: Consumer field sets on the finance schemas (ADR-037 fragments)

- [ ] Task 2: In `lib/Settings/register.d/bookkeeping-market-government-separation.json`,
  add to `ACMReport` the document-signing consumer fields per REQ-SIGN-001
  (`signingRequestRef`, `signingStatus`, `signingProvider`, `signingLevel`,
  `signedDocumentRef`) and the governance-decision consumer fields per REQ-SIGN-002
  (`decisionRef`, `decisionOutcome`); mark the existing `signatureFingerprint` as
  `deprecated`/`x-delegated-to: docudesk`. Keep `status` and all other accounting fields.

- [ ] Task 3: In `lib/Settings/register.d/bookkeeping-pension-ias19.json`, add to
  `ActuarialValuation` the governance-decision consumer fields (`decisionRef`,
  `decisionOutcome`) per REQ-SIGN-002; annotate `approvalStatus`/`approvedBy`/`approvedAt`
  as `x-mirror-of: decidesk-decision` (consumed, not app-owned). Keep `actuary`,
  `actuaryCertificationNumber`, and all valuation fields.

- [ ] Task 4: In `lib/Settings/register.d/bookkeeping-titel-9-jaarrekening.json`, add to
  `AnnualReport` the governance-decision consumer fields (`decisionRef`, `decisionOutcome`)
  per REQ-SIGN-002 for the **adoption** decision. Keep all five states
  (`concept/opgemaakt/in-review/vastgesteld/gedeponeerd`).

- [ ] Task 5: Make the lifecycle transitions **outcome-driven** per REQ-SIGN-003:
  - `ACMReport.sign` (`draft → ready-for-submission`) guard = `signingStatus == signed`
    and/or concerncontroller `decisionOutcome == approved` (no local fingerprint guard).
  - `AnnualReport.vaststellen` / `vaststellenZonderReview` (`→ vastgesteld`) guard =
    adoption `decisionOutcome == approved`.
  - `ActuarialValuation` approval guard = `decisionOutcome == approved`.
  Keep the transition definitions (deep-links + the accounting consequence rely on them).

## Phase 2: Integration exception-path services (ADR-031 PHP, ADR-019 registry)

- [ ] Task 6: Implement `lib/Service/Signing/SigningDelegationService.php` per
  REQ-SIGN-001/REQ-SIGN-005: `requestSignature(financeObject)` opens a docudesk
  `signingRequest` **through the ADR-019 integration registry** (no hard-coded HTTP), sets
  `signingStatus = requested`, holds the object (no self-advance); `onSigningCallback()`
  consumes the docudesk *signed/declined/expired* outcome, writes
  `signingRequestRef`/`signingStatus`/`signingLevel`/`signedDocumentRef`, and on `signed`
  flips the finance lifecycle then invokes the **existing** GL/submission consequence.
  No PKI/signing performed. SPDX headers + `@spec` annotations.

- [ ] Task 7: Implement `lib/Service/Signing/SignoffDecisionService.php` per
  REQ-SIGN-002/REQ-SIGN-005: `requestSignoff(financeObject, decisionType)` opens a decidesk
  `Decision` (signature-as-method) **through the registry**; `onDecisionCallback()` consumes
  the outcome, writes `decisionRef`/`decisionOutcome`, and on `approved` flips the finance
  lifecycle then invokes the **existing** GL consequence (ACM submission gate /
  AnnualReport year-end posting / ActuarialValuation IAS19 posting). No approval logic
  implemented locally. SPDX headers + `@spec` annotations.

- [ ] Task 8: Replace the local signing handler per REQ-SIGN-004: **remove**
  `AcmReportGenerator::sign()` (the PKI fingerprint writer) and re-point the
  `ACMReport.signReport` lifecycle-action handler at `SigningDelegationService`. Verify no
  remaining PKI/certificate signing code path exists in shillinq (`@spec exclude`-free).

- [ ] Task 9: Unit-test the services
  (`tests/Unit/Service/Signing/SigningDelegationServiceTest.php`,
  `SignoffDecisionServiceTest.php`): request goes via the registry (mock), callback writes
  the mirror, `signed`/`approved` fires the GL consequence exactly once, `declined`/
  `rejected` does not advance, idempotent re-callback is a no-op, and there is **no**
  local-signing branch.

## Phase 3: Keep the accounting consequence local (the boundary)

- [ ] Task 10: Assert/keep the three GL consequences per REQ-SIGN-006, now triggered by the
  consumed outcome (not the old local transition):
  - `ACMReport` `signed` → submission gate opens;
  - `AnnualReport` `adopted` → year-end / retained-earnings posting runs;
  - `ActuarialValuation` `approved` → IAS19 actuarial posting runs.
  Wire these through the **existing** journal/GL surface (ADR-022); add no new GL logic.
  Guard test: consequence fires on outcome; flow ownership is gone (hydra `orphan-auth`).

## Phase 4: Retire BookkeepingSigningTrail ownership (ADR-037 nav + federated view)

- [ ] Task 11: Add `"BookkeepingSigningTrail"` to `removals` in `src/menu-layout.json`
  per REQ-SIGN-007; the page stays routable for deep-links (established removal pattern).
  Do NOT create any local signing-trail schema.

- [ ] Task 12: Re-implement the `BookkeepingSigningTrail` page component as a **read-only
  federated consumer view** per REQ-SIGN-007: through the ADR-019 registry, list docudesk
  `signingRequest`/`signingAuditEntry` records and decidesk `Decision` (sign-off/adoption)
  records for finance documents. No write actions, no local event store. Modals/dialogs in
  their own files; every `NcSelect` carries `inputLabel`; initial state via `IInitialState`
  + `loadState()` (ADR-004 gates).

## Phase 5: Notifications (ADR-031 dialect)

- [ ] Task 13: Declare `x-openregister-notifications` rules per REQ-SIGN-008 on the mirror
  fields: signature `signed`/`declined`, decision `approved`/`rejected`. `updated` triggers
  with field-change conditions on `signingStatus`/`decisionOutcome`; recipients via
  `{"kind":"field","field":"owner"}` + object-acl `manage`; subjects in `nl` + `en`,
  metadata-only. No imperative dispatch (gate-18); no app-local signing engine fires them.

## Phase 6: Migration (no data loss)

- [ ] Task 14: Implement `lib/Repair/DelegateSigningMigrationRepair.php` per REQ-SIGN-009:
  map every existing sign-off status to a **consumed decision/signature reference** with
  `kind: legacy-local` —
  - signed `ACMReport` → `signingRequestRef = legacy-local:<signatureFingerprint>`,
    `signingStatus = signed`;
  - approved `ActuarialValuation` → `decisionRef = legacy-local:<approvedBy>@<approvedAt>`,
    `decisionOutcome = approved`;
  - adopted `AnnualReport` (`vastgesteld`/`gedeponeerd`) → `decisionRef =
    legacy-local:vaststellen`, `decisionOutcome = approved`.
  Leave the finance status enums **untouched**. Idempotent; declare as an OR
  data-migration / `lib/Repair` step (never silently drop data).

- [ ] Task 15: Unit-test the migration
  (`tests/Unit/Repair/DelegateSigningMigrationRepairTest.php`): every legacy state maps to
  the correct `legacy-local` reference, the finance status enums are asserted **byte-for-byte
  unchanged**, and re-running is a no-op (idempotent).

## Phase 7: i18n

- [ ] Task 16: Add all new strings with ENGLISH source keys to `l10n/en.json` and Dutch
  translations to `l10n/nl.json` per REQ-SIGN-007/008 (federated-view labels, consumer
  status labels like "Awaiting docudesk signature" / "Adopted by the general meeting",
  notification subjects in both `nl` + `en`); verify the l10n gate and no Dutch source keys
  in `t('shillinq', …)`.

## Phase 8: Tests, Gates, Docs

- [ ] Task 17: Add a "no local signing engine remains" guard test per REQ-SIGN-004/005:
  assert `AcmReportGenerator::sign` is gone, no PKI/certificate signing code path exists in
  shillinq, no raw `docudesk`/`decidesk` hostnames in `lib/Service/Signing/` (registry-only),
  and no local signing-trail schema exists.

- [ ] Task 18: Add Newman integration assertions
  (`tests/integration/*.postman_collection.json`): a signing request mirrors status from a
  simulated docudesk callback; a sign-off decision mirrors outcome from a simulated decidesk
  callback; the GL consequence object appears after `signed`/`approved`; the
  `legacy-local`-migrated objects read back valid.

- [ ] Task 19: Author Playwright e2e UI specs (gate-19, UI-only) for the read-only federated
  `BookkeepingSigningTrail` view and the "request signature" / "request sign-off" finance
  actions; annotate scenarios with `@e2e` references; reason-bearing `@e2e exclude` only for
  true backend (callback/migration) scenarios.

- [ ] Task 20: Run `composer check:strict` + the full hydra gate suite (spdx,
  spec-coverage `@spec` on the new services + migration, route-auth for any new
  callback routes, notification-dialect gate-18, redundant-controller, orphan-auth,
  e2e-coverage) and fix everything including pre-existing issues encountered; update `docs/`
  + README (signing/sign-off now delegated to docudesk + decidesk); bump
  `appinfo/info.xml` `<version>` (bundle-affecting change).
