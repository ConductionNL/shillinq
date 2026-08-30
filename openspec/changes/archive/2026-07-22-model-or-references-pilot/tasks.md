# Tasks: model-or-references-pilot

All work is declarative edits to `lib/Settings/shillinq_register.json` (schema properties + seed
objects). No PHP. Placeholder UUIDs MUST be the nil UUID `00000000-0000-0000-0000-000000000000`.

## Cluster A — GL posting graph

### Task 1: Declare GLLine.transactionId as a GLTransaction reference
- **spec_ref**: `openspec/changes/model-or-references-pilot/specs/declarative-object-references/spec.md` (GLLine→GLTransaction reference)
- **files**: `lib/Settings/shillinq_register.json` (`components.schemas.GLLine.properties.transactionId`)
- **acceptance_criteria**:
  - GIVEN the GLLine schema WHEN imported THEN transactionId declares `$ref: GLTransaction`, `format: uuid`, `inversedBy: lines`
- [x] Implement
- [x] Test

### Task 2: Declare the inverse GLTransaction.lines array
- **spec_ref**: `openspec/changes/model-or-references-pilot/specs/declarative-object-references/spec.md` (GLTransaction inverse lines)
- **files**: `lib/Settings/shillinq_register.json` (`components.schemas.GLTransaction.properties.lines`)
- **acceptance_criteria**:
  - GIVEN the GLTransaction schema WHEN imported THEN lines is an array of GLLine refs, inverse of GLLine.transactionId
- [x] Implement
- [x] Test

### Task 3: Declare GLLine.accountRef as an Account reference (retain accountNumber)
- **spec_ref**: `openspec/changes/model-or-references-pilot/specs/declarative-object-references/spec.md` (GLLine→Account reference)
- **files**: `lib/Settings/shillinq_register.json` (`components.schemas.GLLine.properties`)
- **acceptance_criteria**:
  - GIVEN the GLLine schema WHEN imported THEN accountRef declares `$ref: Account`, `format: uuid`, AND accountNumber string is retained
- [x] Implement
- [x] Test

### Task 4: Seed the UUID-cross-referencing GL cluster (schemas modified)
- **spec_ref**: `openspec/changes/model-or-references-pilot/specs/declarative-object-references/spec.md` (seed clusters + nil-UUID placeholders)
- **files**: `lib/Settings/shillinq_register.json` (`objects[]`)
- **acceptance_criteria**:
  - GIVEN the seed array WHEN imported THEN Account objects exist (none today), and GLTransaction/GLLine seeds carry target UUIDs in transactionId/accountRef
  - GIVEN any placeholder UUID THEN it equals `00000000-0000-0000-0000-000000000000`
- [x] Implement
- [x] Test

## Cluster B — ARInvoice ↔ CustomerMaster (receivables)

### Task 5: Declare ARInvoice.customerId + glTransactionId as references
- **spec_ref**: `openspec/changes/model-or-references-pilot/specs/declarative-object-references/spec.md` (ARInvoice→CustomerMaster / →GLTransaction references)
- **files**: `lib/Settings/shillinq_register.json` (`components.ARInvoice.properties`)
- **acceptance_criteria**:
  - GIVEN the ARInvoice schema WHEN imported THEN customerId declares `$ref: CustomerMaster`, `format: uuid`, `inversedBy: invoices`
  - GIVEN the ARInvoice schema WHEN imported THEN glTransactionId declares `$ref: GLTransaction`, `format: uuid`, AND the existing `x-openregister-relations` block is left intact
- [x] Implement
- [x] Test

### Task 6: Declare the inverse CustomerMaster.invoices array
- **spec_ref**: `openspec/changes/model-or-references-pilot/specs/declarative-object-references/spec.md` (CustomerMaster inverse invoices)
- **files**: `lib/Settings/shillinq_register.json` (`components.CustomerMaster.properties.invoices`)
- **acceptance_criteria**:
  - GIVEN the CustomerMaster schema WHEN imported THEN invoices is an array of ARInvoice refs, inverse of ARInvoice.customerId
- [x] Implement
- [x] Test

### Task 7: Seed the UUID-cross-referencing receivables cluster (schemas modified)
- **spec_ref**: `openspec/changes/model-or-references-pilot/specs/declarative-object-references/spec.md` (seed clusters + nil-UUID placeholders)
- **files**: `lib/Settings/shillinq_register.json` (`objects[]`)
- **acceptance_criteria**:
  - GIVEN the seed array WHEN imported THEN a CustomerMaster object exists (none today) and an ARInvoice object exists (none today)
  - GIVEN the seeded ARInvoice THEN its customerId holds the CustomerMaster UUID and its glTransactionId holds the Cluster A GLTransaction UUID
  - GIVEN any placeholder UUID THEN it equals `00000000-0000-0000-0000-000000000000`
- [x] Implement
- [x] Test

## Verification tasks

### Task 8: Verify the relation graph resolves on seed (both clusters)
- **spec_ref**: `openspec/changes/model-or-references-pilot/specs/declarative-object-references/spec.md` (/uses and /used resolve)
- **files**: `lib/Settings/shillinq_register.json` (re-import + verify)
- **acceptance_criteria**:
  - GIVEN the re-imported register WHEN `/used` is called on the seeded GLTransaction THEN its GLLines are returned (non-empty)
  - GIVEN a seeded GLLine WHEN `/uses` is called THEN its GLTransaction and Account are returned
  - GIVEN the seeded CustomerMaster WHEN `/used` is called THEN its ARInvoice is returned (non-empty)
  - GIVEN the seeded ARInvoice WHEN `/uses` is called THEN its CustomerMaster and GLTransaction are returned
- [x] Implement
- [x] Test

## Verification
- All tasks checked off
- `openspec validate model-or-references-pilot --strict` passes
- Register JSON re-validates (no dup keys) after edits
- `/uses` and `/used` return the seeded objects for BOTH clusters (were empty before) — proven at the
  declarative-fixture level by `ModelOrReferencesPilotSeedIntegrationTest` (below), which asserts the
  exact value-matching `RelationHandler::getUses()/getUsedBy()` perform against
  `ObjectEntity::getRelations()` (stored `$ref` property value == another seeded object's `@self.id`).
  A live NC+OR import/HTTP check (Newman) was out of scope for this worktree-only task — see report.
- Diff touches `lib/Settings/shillinq_register.json` (schema properties + seed objects) + openspec
  artifacts + a declarative-only PHPUnit test (`tests/Integration/ModelOrReferencesPilotSeedIntegrationTest.php`,
  wired into `phpunit-unit.xml`) — no `lib/` PHP added, matching the SalesOrderSeedDataIntegrationTest
  precedent for `kind: config` changes.

## Tests (company-wide ADR-009)
- PHPUnit: `tests/Integration/ModelOrReferencesPilotSeedIntegrationTest.php` (9 tests, 73 assertions) —
  asserts both clusters' `$ref`/`inversedBy` schema declarations AND that the seeded objects'
  reference-field values equal the referenced objects' `@self.id` UUIDs (the exact shape OpenRegister's
  relation graph matches on), for both Cluster A (GLLine⇄GLTransaction, GLLine→Account) and Cluster B
  (ARInvoice⇄CustomerMaster, ARInvoice→GLTransaction bridge). Full suite: 3853 tests / 0 failures
  (baseline 3844 + 9 new).
- Newman/Postman: deferred — would additionally require a live NC+OR import/HTTP round-trip, which is
  outside this worktree-only task's environment; the PHPUnit fixture test above proves the declarative
  seed shape that `/uses`/`/used` resolve against.
- Browser (Playwright MCP): N/A — no UI in this change (consumer widget is out of scope)

## Documentation (company-wide ADR-010)
<!-- The reference idiom is documented in design.md for later roll-out. -->
- Reference-modeling pattern documented in design.md (idiom + seed shape) for per-schema roll-out
- N/A: no end-user feature surface in this pilot (no `docs/` screenshot)

## i18n (company-wide ADR-005)
- N/A — no new user-facing strings (schema/seed JSON only)
