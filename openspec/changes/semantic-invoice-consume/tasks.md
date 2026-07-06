# Tasks: semantic-invoice-consume

> Precondition for Tasks 2–3: the hydra change `semantic-object-handoff` (and
> its OpenRegister implementation) has landed. Before writing any
> `x-openregister-handoff` block, READ the landed dialect spec and VERIFY the
> key shape against OpenRegister HEAD — design.md D3 is provisional.
> Task 1 and Tasks 4–6 have no such dependency.

## 1. Canonical-kind markers

### Task 1: Declare `configuration.implements` for the four finance kinds
- **spec_ref**: `openspec/changes/semantic-invoice-consume/specs/semantic-invoice-consume/spec.md#requirement-req-sic-001--shillinq-schemas-shall-advertise-the-canonical-finance-kinds-via-configurationimplements-exactly-one-deployed-schema-per-kind`
- **files**: `lib/Settings/register.d/semantic-invoice-consume.json` (new)
- **acceptance_criteria**:
  - GIVEN the new overlay fragment WHEN the register is imported THEN
    `ARInvoice` implements `["https://openregister.app/ns#Invoice", "https://schema.org/Invoice"]`,
    `Contract` → `["https://openregister.app/ns#Contract"]`,
    `SalesOrder` → `["https://openregister.app/ns#SalesOrder", "https://schema.org/Order"]`,
    `Quote` → `["https://openregister.app/ns#Quote"]` — declared under
    `components.schemas.<Schema>.configuration.implements`, mirroring the
    Payee/ns#Vendor precedent in `bookkeeping-accounts-payable-core.json`
  - GIVEN the imported register WHEN OR's SemanticTypeResolver resolves
    `ns#Invoice` THEN it returns `ARInvoice` with no ambiguity WARN (exactly
    one marker-holder per kind; the QOI `Invoice` schema gets NO marker)
  - GIVEN the merged config WHEN inspected THEN no `required` array of any
    existing schema changed (union-merge gotcha; overlay declares no
    `required`) and the QOI `Invoice`'s existing `configuration`
    (objectNameField/linkedTypes) is untouched
- [ ] Implement
- [ ] Test

## 2. Handoff acceptance (kind-keyed)

### Task 2: Declare H1 — accepted `ns#Quote` → draft `Contract`
- **spec_ref**: `openspec/changes/semantic-invoice-consume/specs/semantic-invoice-consume/spec.md#requirement-req-sic-002--shillinq-shall-accept-kind-keyed-handoffs-that-land-the-quote--contract--ar-invoice-chain`
- **files**: `lib/Settings/register.d/semantic-invoice-consume.json`
- **acceptance_criteria**:
  - GIVEN the landed `x-openregister-handoff` dialect (verify shape against
    OpenRegister HEAD first) WHEN H1 is declared on `Contract` THEN it is keyed
    `sourceKind: ns#Quote` / `targetKind: ns#Contract` (no slugs, no app ids as
    routing keys), maps only target fields verified at HEAD (design.md D3),
    sets no lifecycle field, and names `provenanceProperty: sourceQuoteReference`
  - GIVEN the H1 mapping WHEN the pipelinq quote schema has landed THEN source
    field names are aligned to it (re-verify against pipelinq HEAD — no quote
    schema exists there at spec-authoring time); otherwise the source side is
    left explicitly marked provisional in the fragment description
- [ ] Implement
- [ ] Test

### Task 3: Declare H2 — activated outbound handed-off `Contract` → ONE draft `ARInvoice`
- **spec_ref**: `openspec/changes/semantic-invoice-consume/specs/semantic-invoice-consume/spec.md#requirement-req-sic-002--shillinq-shall-accept-kind-keyed-handoffs-that-land-the-quote--contract--ar-invoice-chain`
- **files**: `lib/Settings/register.d/semantic-invoice-consume.json`
- **acceptance_criteria**:
  - GIVEN a Contract with handoff provenance and `direction = outbound` WHEN it
    transitions to `active` THEN exactly one `ARInvoice` is created in `draft`
    with `sourceContractReference` set; a repeated transition creates no second
    invoice (idempotent on the handoff correlation id)
  - GIVEN the rule WHEN inspected THEN no `RecurringInvoiceProfile` is created
    and no recurring-invoicing behaviour is duplicated (design.md D5)
  - GIVEN a handoff payload proposing `lifecycleState = issued` WHEN processed
    THEN the created invoice is still `draft` and no GLTransaction was
    materialised (REQ-SIC-004)
- [ ] Implement
- [ ] Test

## 3. Provenance + notifications

### Task 4: Add the provenance reference properties
- **spec_ref**: `openspec/changes/semantic-invoice-consume/specs/semantic-invoice-consume/spec.md#requirement-req-sic-003--handed-off-objects-shall-carry-provenance-links-back-to-their-source-objects`
- **files**: `lib/Settings/register.d/semantic-invoice-consume.json`
- **acceptance_criteria**:
  - GIVEN the overlay WHEN imported THEN `Contract.sourceQuoteReference`
    (nullable, `referenceSemanticType: ns#Quote`) and
    `ARInvoice.sourceContractReference` (nullable,
    `referenceSemanticType: ns#Contract`) exist on the merged schemas
  - GIVEN an operator-created Contract/ARInvoice without these fields WHEN
    validated THEN it passes (properties additive + nullable, not in any
    `required`)
- [ ] Implement
- [ ] Test

### Task 5: Declare handoff-received notification rules + fix the misplaced notifications block
- **spec_ref**: `openspec/changes/semantic-invoice-consume/specs/semantic-invoice-consume/spec.md#requirement-req-sic-005--finance-operators-shall-be-notified-when-a-handed-off-object-arrives-adr-031`
- **files**: `lib/Settings/register.d/semantic-invoice-consume.json`, `lib/Settings/register.d/shillinq-notifications.json`
- **acceptance_criteria**:
  - GIVEN the overlay WHEN imported THEN `Contract` and `ARInvoice` carry an
    `x-openregister-notifications` rule with trigger `created` conditioned on
    the provenance property being non-null, recipients `object-acl` (manage) +
    group `shillinq-finance`, bilingual nl/en metadata-only subjects — house
    shape as in `shillinq-notifications.json`, declared under
    `components.schemas.<Schema>` (the shape the deep-merge actually delivers
    to the import)
  - GIVEN `shillinq-notifications.json` at HEAD nests ARInvoice rules under
    `components.ARInvoice` (sibling of `components.schemas`) WHEN this task
    runs THEN — after confirming against HEAD import behaviour that the block
    is indeed dead config — it is relocated under
    `components.schemas.ARInvoice` (pre-existing fix, same batch), and the
    hydra notification-dialect gate (gate-18) passes
- [ ] Implement
- [ ] Test

## 4. Seed + verification

### Task 6: Seed data + end-to-end verification
- **spec_ref**: `openspec/changes/semantic-invoice-consume/specs/semantic-invoice-consume/spec.md#requirement-req-sic-006--the-consume-side-shall-survive-the-abstract-order-primitive-consolidation-without-consumer-visible-change`
- **files**: `lib/Settings/register.d/semantic-invoice-consume.json`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN imported into a clean env THEN the design.md Seed
    Data objects (1 handed-off draft Contract + 1 handed-off draft ARInvoice,
    nil-UUID quote provenance) exist and render with their provenance link
  - GIVEN the shipped fragment WHEN grepped THEN it contains no
    `"slug": "Order"` declaration and adds no property to the
    `bookings-deposit-to-invoice.json` `Order` schemas (REQ-SIC-006), and all
    handoff/marker keys are kind URIs (no schema slug used as a resolution key)
  - GIVEN the full register import (`SettingsService` fragment merge) WHEN run
    THEN the fragment-signature version bump triggers re-import and
    `openspec validate semantic-invoice-consume --strict` passes
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate semantic-invoice-consume --strict` passes
- [ ] Manual testing against acceptance criteria (clean-env import + resolver
      check + NC bell check for the notification rule)
- [ ] Code review against spec requirements
- [ ] Hydra mechanical gates pass (`/hydra-gates`), incl. gate-18
      notification-dialect on the touched fragments

## Tests (company-wide ADR-009)
- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — N/A
      if the change stays zero-PHP as designed (kind: config); the handoff
      engine's tests live with `semantic-object-handoff` in OpenRegister
- [ ] Newman/Postman tests for new/changed API endpoints — N/A: no new
      endpoints; OR's semantic-types API is covered on the OR side
- [ ] Browser tests (Playwright MCP) for UI changes — N/A: no UI change
      (@e2e exclusions recorded per requirement in the spec)
- [ ] JSON fragments re-validated after merge (`python3 -m json.tool` on every
      touched register.d file)

## Documentation (company-wide ADR-010)
- [ ] Feature documentation updated in `docs/` — one section: "Objects handed
      off from pipelinq land as drafts" (where they appear, what the provenance
      link means, who gets notified)
- [ ] Screenshot captured and committed to `docs/images/` — the seeded
      handed-off draft invoice with its provenance link

## i18n (company-wide ADR-005)
- [ ] Dutch (`nl_NL`) and English (`en_US`) strings: the notification subjects
      ship bilingual inside the notification rules (nl/en keys, English as the
      i18n source key convention); no frontend strings added
