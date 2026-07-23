# Tasks — abstract-order-primitive

> **2026-07-22 honesty pass**: every box below was previously marked `[x]` despite
> NO `Order` schema existing anywhere in the register (verified: `grep -rn
> '"slug": "Order"' lib/Settings` returned nothing before this pass). This is the
> orphaned-capability defect class. Boxes are reset to reflect what is ACTUALLY
> built and wired as of this pass — see `SCHEMA-ANALYTICS-AND-PLAN.md` for the
> architecture decision this pass made (flat single-schema model, not the
 > allOf-composition model that document originally proposed — see "Design
> divergence" note in `specs/order-primitive/spec.md`) and a collision bug found
> and fixed along the way (a second, colliding `Grant` schema definition that
> would have deep-merged onto the pre-existing WBSO/BBV/NSO/Tozo `Grant` stub).

## Phase 1 — Schema (non-destructive)
- [x] Add `Order` schema (`register.d/zz-order-primitive.json`) with `orderType`
      (purchase|sales|subsidie|engagement|booking|quote|blanket — only purchase/
      subsidie/engagement are populated by this change) + `direction`
      discriminators, shared core (orderNumber/counterpartyId/counterpartyName/
      currency/orderDate/endDate/totalAmount/description/paymentTerms/
      projectReference/costCenter/administrationId/state), and
      subsidie/purchase/engagement type-namespaced field groups carrying every
      field of the retired-in-place Subsidie / PurchaseOrder / DBAOpdracht
      schemas (no regulatory field dropped — verified by
      `OrderPrimitiveSchemaTest`).
- [x] Add type-aware `x-openregister-lifecycle`: `state` carries the union of
      all three vocabularies; every `states`/`transitions` entry is tagged
      `orderType` so a subsidie Order can never legally reach a purchase/
      engagement state and vice-versa (`OrderPrimitiveSchemaTest::
      testEveryTransitionIsGatedToItsOwnOrderTypeStates`). The subsidie
      vocabulary (aanvraag..afgehandeld) and purchase vocabulary
      (draft..cancelled) are verified byte-identical to the retired schemas'
      own vocabularies. **Not ported**: Subsidie's `postTransition`
      (JournalEntry creation) and `notifications` blocks — states/transitions/
      requires.fields are faithful, the imperative side-effects are not; this
      is a conscious, documented scope cut, tracked as follow-up.
- [x] RBAC (`x-openregister-rbac`) with administrationId-scoped roles covering
      all three domains (subsidie-coordinator/manager/bookkeeper/auditor/
      ondernemer/compliance_officer/controleur) and audit-trail opt-in
      (`add-shillinq-audit-trail.json` → `Order`).
- [x] **Collision fix (found during this pass)**: the earlier partial build's
      `zz-order-extensions.json` added a schema named `Grant` (folded-Subsidie
      shape) that collided with the PRE-EXISTING, unrelated `Grant` schema
      (WBSO/BBV/NSO/Tozo stub, `lib/Settings/shillinq_register.json`). Since
      `SettingsService::deepMergeConfig` recursively merges same-slug
      `components.schemas` entries across register.d fragments, this would have
      silently corrupted the real WBSO Grant schema the moment the base `Order`
      schema existed and made `SetOrderExtensionComposition` resolve. Fixed by
      removing `zz-order-extensions.json` and abandoning the allOf-composition
      design (see spec.md's design-divergence note for why). Regression-guarded
      by `OrderPrimitiveSchemaTest::testExactlyOneGrantSchemaDefinitionSurvives`.
- [x] Deleted `SetOrderExtensionComposition` (composition-based extension
      wiring) — superseded by the flat single-schema model; would otherwise
      have started corrupting `PurchaseOrder`/`Quote`/`SalesOrder`/
      `BlanketOrder`/`Grant` the moment `Order` existed (OpenRegister's
      `ObjectService::findAll()` has no allOf-aware cross-schema query —
      confirmed against `SchemaMapper::resolveAllOf()` — so a single unified
      index can only ever show literal `Order` rows regardless).

## Phase 2 — Migration + guards
- [x] Repair step `FoldIntoOrder` rewritten: folds `Subsidie` → Order
      (orderType=subsidie), `PurchaseOrder` → Order (orderType=purchase,
      totalInclVat cents/100 → totalAmount decimal EUR, original cent fields
      preserved verbatim), `DBAOpdracht` → Order (orderType=engagement).
      Idempotent (`migratedFrom.schema` + `.key` marker), fail-soft per row,
      admin resolved as a real `IUser` (never a string), `_rbac:false` +
      `_multitenancy:false` on every OR read/write, source rows NEVER deleted
      or mutated. Unit-tested (`FoldIntoOrderTest`, 8 tests) including the
      cent→EUR conversion and lossless field-preservation assertions.
      **Not built**: SalesOrder/Quote/BlanketOrder/booking folds — `sales`/
      `booking`/`quote`/`blanket` are reserved discriminator values only; no
      migration ships for them in this change (out of the explicit scope:
      Subsidie + PurchaseOrder + DBA).
- [x] `RetireSubsidieSchema` re-pointed from the retired `Grant` extension
      target to `Order` (TARGET + marker filter updated); still data-safe
      (never deletes an unmigrated Subsidie, never drops the schema row while
      objects remain). Unit-tested (`RetireSubsidieSchemaTest`, 4 tests)
      including a regression test that an Order folded from a DIFFERENT
      source schema is never mistaken for proof of migration.
- [x] `occ shillinq:orders:audit` — count-equality check (`OrdersAuditCommand`,
      read-only, exit 0/1). Unit-tested (`OrdersAuditCommandTest`, 3 tests).
- [ ] **Live-verify the migration against a running instance with real
      Subsidie/PurchaseOrder/DBAOpdracht rows** (seed data or a live import).
      Only unit-tested against fixture arrays in this pass — genuinely
      PENDING, not claimed done. `occ maintenance:repair` +
      `occ shillinq:orders:audit` should be run on a live instance before this
      is considered production-safe.

### 2026-07-23 pass (issue #503) — schema-import blocker fixed, migration STILL held
- [x] **Diagnosed + fixed the schema-import blocker** live-verification found:
      the `Order` schema slug did not import as its own row — OpenRegister's
      schema-import lookup (`ImportHandler::importSchema()` →
      `SchemaMapper::find()`) is case-insensitive AND explicitly bypasses
      multitenancy (`_multitenancy: false`), so a slug is unique
      INSTANCE-WIDE, not per-app/per-org. On 8080, id 1585 (slug `order`) is a
      LIVE, foreign schema owned by a completely different app (`decidesk`,
      not a stale/leftover artifact), in the SAME organisation as shillinq's
      own schemas (`286a9152-4b09-4714-9115-fabbbad342d0`) — importing a
      schema literally named `Order` would have matched it and, since
      `ImportHandler::importSchema()` proceeds to `updateFromArray()` on any
      version-newer match, OVERWRITTEN decidesk's live schema with this
      shape. This is more severe than the earlier Grant deep-merge collision
      (a full overwrite, not a merge). Fixed by renaming the schema slug to
      the distinct `OrderPrimitive` (0 collisions found instance-wide, DB
      read-verified) — `zz-order-primitive.json`, `FoldIntoOrder::TARGET`,
      `RetireSubsidieSchema::TARGET`, `OrdersAuditCommand`,
      `add-shillinq-audit-trail.json`, and `order-workspace.json`'s schema
      refs updated consistently. `SubsidieOrderConsolidationSchemaTest`'s
      "Order slug claimed exactly once" assertion updated to "stays
      unclaimed" (0), since the primitive no longer reclaims the slug that
      07709a0f freed for it.
- [x] **Found + fixed an adjacent pre-existing bug** while investigating: the
      07709a0f rename (booking-context `Order` → `BookingOrder`, done to free
      the slug) never updated `BookingCancellationGuard`/
      `InvoiceFromBookingGuard`'s `findOne(schema: 'Order', ...)` fallback
      lookup (used only when the lifecycle engine calls the guard without a
      pre-loaded `$object`) — untested because every existing test
      pre-supplies `$object`. Fixed both to `schema: 'BookingOrder'`;
      regression tests added exercising the fallback path directly.
- [x] **Field-shape cross-check against live schemas** (read-only DB probe):
      pulled the actual `properties` of the live PurchaseOrder (id 1115),
      Subsidie (id 4982) and DBAOpdracht (id 1166) schemas on 8080 and
      diffed every field name `FoldIntoOrder`'s builders read
      (`verleendBedrag`, `poNumber`, `totalInclVat`, `klantId`,
      `opdrachtNaam`, `startDatum`, `intakeStatus`, …) against them — zero
      drift found between the fixture shapes the unit tests use and the real
      deployed schemas.
- [ ] **Migration STILL HELD** (`FoldIntoOrder`/`RetireSubsidieSchema` remain
      un-registered in `appinfo/info.xml`). Not because the fold logic is in
      doubt (unit-tested + field-shape cross-checked above), but because (a)
      8080 currently has ZERO Subsidie/PurchaseOrder/DBAOpdracht objects to
      fold — there is no real data on this instance to prove the migration
      safe against, and (b) `RetireSubsidieSchema` deletes objects, which
      must never run unproven against a shared instance's regulatory data.
      Re-enable only after an actual `occ maintenance:repair` +
      `occ shillinq:orders:audit` run against an instance that DOES have real
      source rows.

## Phase 3 — UI + nav
- [x] Fixed `src/manifest.d/order-workspace.json`'s dangling references: the
      `orderType` filter's option list was `["booking","sales","purchase",
      "grant","engagement","quote","blanket"]` referencing a discriminator
      value (`grant`) this schema never uses (`subsidie`) and types with no
      migrated data; narrowed to `["purchase","subsidie","engagement"]` — what
      is actually populated by this change. `Order`/`OrderLine`/`Payment`
      schema references now resolve (Order exists; OrderLine/Payment already
      existed from the earlier partial build, unpopulated by this change's
      fold — see zz-order-base.json's updated description).
- [ ] Type-aware detail page / widget work, retiring the bespoke Subsidie/PO/
      DBA pages: **NOT built in this pass**. Out of scope for this wave — the
      manifest fix only makes the existing page's schema references resolve;
      it does not verify the frontend renders correctly, nor does it retire
      any existing page.
- [ ] menu-layout: remove the 6 subsidie + PO + DBA nav entries: **NOT done**.
      The old nav entries are untouched; removing them without confirming the
      new workspace actually renders would strand users (same caution the
      original proposal.md called for).

## Phase 4 — Compliance + cleanup
- [ ] Re-point subsidie/PO compliance providers to Order (preserve rule ids):
      **NOT done** in this pass.
- [x] Retire the duplicate Subsidie schema (add-shillinq-bookkeeping-
      operations.json): already done by the prereq
      `consolidate-order-subsidie-collisions` change (verified: that file's
      Subsidie entry is gone; its 11 OTHER, unrelated schemas — VatReturn,
      IcpStatement, VatCorrection, etc. — correctly remain).
