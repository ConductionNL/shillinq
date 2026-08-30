---
kind: config
depends_on: []
chain:
  - model-or-references-pilot   # THIS change (head, kind:config) — declarative $ref/inversedBy on two pilot clusters (GL posting graph + ARInvoice↔CustomerMaster) + UUID-carrying seed
---

# Change: model-or-references-pilot

## Why

Shillinq models **every** inter-object relationship as a flat scalar business key, never as an
OpenRegister object reference. Across the whole schema register
(`lib/Settings/shillinq_register.json`, 101 schemas) there are **zero** `$ref`, `inversedBy`, or
`objectConfiguration` declarations (verified: `grep -c '"\$ref"\|inversedBy\|objectConfiguration'`
= 0). Concretely, `GLLine.transactionId` is described as *"FK to the parent GLTransaction.id"* but
is just a string holding a slug; `GLLine.accountNumber` is *"FK to Account.accountNumber"* but is
just an RGS account code string.

OpenRegister's relation graph (`/uses`, `/used` endpoints; `RelationHandler::getUses/getUsedBy`;
`BulkRelationHandler` / `BulkValidationHandler` inverse-property analysis) only resolves
relationships when a property is **declared** with a `$ref` to the target schema **and** the stored
value is the target object's UUID. Because shillinq declares none, `/uses` and `/used` return empty
for every shillinq object. This blocks any object-to-object "related objects" UI (e.g. the nc-vue
`CnRelatedObjectsWidget` redesign) from ever showing related objects on shillinq.

Converting all 101+ schemas at once is too large and too risky. This change is a **PILOT** that
establishes the reference-modeling **pattern** on **two** high-value clusters — the **GL posting
graph** and the **ARInvoice ↔ CustomerMaster** receivables edge — so the same idiom can be rolled
out later, schema by schema, in follow-up changes. The two clusters connect: `ARInvoice` also
references `GLTransaction` (`glTransactionId`), bridging the receivables edge into the GL graph.

Note on register layout: GL schemas live under `components.schemas.*`, while `ARInvoice` and
`CustomerMaster` live as sibling blocks under `components.*` (each with a `slug`). Both clusters are
edited in the same file (`lib/Settings/shillinq_register.json`).

## What Changes

The pilot anchors on two clusters whose schemas and scalar links already exist (so the conversion
is faithful, not invented):

### Cluster A — GL posting graph
- **`GLLine.transactionId` → declarative reference to `GLTransaction`.** Add `$ref: GLTransaction`
  + `inversedBy: lines` so the parent `GLTransaction` exposes its lines via `/used`. The property
  continues to hold the **target object's UUID** (was a slug); `GLTransaction` gains an inverse
  `lines` array property declaration to receive the back-reference.
- **`GLLine.accountNumber` → declarative reference to `Account`.** Add a reference property
  (`accountRef`, `$ref: Account`, holds the Account object's UUID) **alongside** the existing
  `accountNumber` RGS-code string. The RGS code is human-facing and used by reporting; the new
  UUID reference is what the relation graph resolves. (Whether to fold `accountNumber` into the
  reference entirely is a follow-up decision — see Open Questions.)

### Cluster B — ARInvoice ↔ CustomerMaster (receivables)
- **`ARInvoice.customerId` → declarative reference to `CustomerMaster`.** Add `$ref: CustomerMaster`
  + `format: uuid` + `inversedBy: invoices` so `CustomerMaster` exposes its invoices via the reverse
  relation. The property holds the **CustomerMaster object's UUID** (live data currently carries a
  scalar business key, e.g. `"DEMO-C1"` / `customer-klant-1`). `CustomerMaster` gains an inverse
  `invoices` array property declaration.
- **`ARInvoice.glTransactionId` → declarative reference to `GLTransaction`.** Add `$ref: GLTransaction`
  + `format: uuid` (bridges Cluster B into Cluster A). Holds the GLTransaction object's UUID.
- **Pre-existing `x-openregister-relations` on ARInvoice is descriptive metadata, NOT the resolving
  idiom.** ARInvoice already declares an `x-openregister-relations` map (localField/relatedSchema)
  that the `/uses`/`/used` graph does **not** read. This change adds the property-level `$ref`
  (+ `inversedBy`) idiom the graph DOES resolve; the descriptive block is left in place.
- **Customer target = the EXISTING `CustomerMaster` schema** (least-disruptive pilot default). NOT
  re-pointed to the NC `contact` entity here — see Open Questions. (Note: `CustomerMaster` already
  has a `contactRef` field, the natural seam for the deferred NC-contact alignment.)

### Shared
- **Seed data (ADR-001):** seed a demo administration so `/uses` and `/used` demonstrably resolve
  for BOTH clusters — `Account` objects (currently **zero** seeded), a `GLTransaction` + its
  `GLLine`s, **and** a `CustomerMaster` + an `ARInvoice` whose `customerId` holds that
  CustomerMaster's UUID and whose `glTransactionId` holds the GLTransaction's UUID. (Currently
  **zero** ARInvoice and zero CustomerMaster objects are seeded.) Each reference field carries a
  **UUID**, not a slug/business key.
- **Idiom source:** match OpenRegister's documented relation idiom (`openregister/docs/Features/schemas.md`
  §"Cascading with inversedBy") and the in-fleet precedent in `procest/lib/Settings/procest_register.json`
  (67 `$ref` declarations resolved by schema slug). Match it exactly — no bespoke service.

This is `kind: config` per ADR-032: all behaviour is declared in the JSON schema register + seed
data; no PHP service class is introduced. Per ADR-031, adding reference relations between OR objects
is exactly the declarative path — see design.md.

## Affected Projects

- [ ] Project: shillinq — declarative `$ref`/`inversedBy` on the GL posting graph schemas AND the
  ARInvoice ↔ CustomerMaster schemas in `lib/Settings/shillinq_register.json`, plus UUID-carrying
  seed objects for both clusters in the same file's `objects[]` array.

## Scope

### In Scope
- **Cluster A:** declarative `$ref` + `inversedBy` on `GLLine.transactionId` (→ `GLTransaction`);
  the inverse `lines` property on `GLTransaction`; a UUID reference property on `GLLine` for
  `Account`.
- **Cluster B:** declarative `$ref` + `inversedBy` on `ARInvoice.customerId` (→ `CustomerMaster`,
  inverse `invoices`); a `$ref` on `ARInvoice.glTransactionId` (→ `GLTransaction`, bridging into
  Cluster A); the inverse `invoices` property on `CustomerMaster`.
- Seed `Account`, `GLTransaction`, `GLLine`, `CustomerMaster`, and `ARInvoice` objects (re-pointed
  where they already exist) carrying UUIDs, so `/uses` and `/used` resolve for both seeded clusters.
- The reference-modeling pattern documented in design.md for later roll-out.

### Out of Scope
- Converting any schema outside the two pilot clusters (the remaining schemas keep scalar keys).
- Re-pointing customer references at the Nextcloud `contact` entity (the pilot references the
  EXISTING `CustomerMaster` schema; NC-contact alignment is a deferred follow-up — see Open
  Questions).
- Removing or rewriting ARInvoice's pre-existing descriptive `x-openregister-relations` block.
- Any PHP migration/repair code converting pre-existing live business keys → UUIDs (would be a
  chained `kind: code` change — see Open Questions).
- The nc-vue `CnRelatedObjectsWidget` itself (consumer; lives in the shared lib).

## Approach

Declare the reference properties on the GL graph schemas using the OpenRegister `$ref` (+
`inversedBy`) idiom, and seed a self-contained demo cluster whose objects carry real UUIDs
cross-referencing each other. Detailed idiom, declarative-vs-imperative rationale, and seed shape
are in design.md.

## New Dependencies

None. Uses OpenRegister's existing relation-resolution machinery.

## Impact

- `lib/Settings/shillinq_register.json`:
  - Cluster A: `components.schemas.GLLine`, `components.schemas.GLTransaction` (and `Account`
    reference target).
  - Cluster B: `components.ARInvoice`, `components.CustomerMaster` (sibling blocks under
    `components.*`, keyed by `slug`).
  - Plus the `objects[]` seed array (Account, GLTransaction, GLLine, CustomerMaster, ARInvoice).
- Runtime: after re-seed/import, `/uses` and `/used` resolve for both seeded clusters — enabling
  related-objects UI on those objects (incl. CustomerMaster surfacing its invoices). No change to
  any PHP, controller, or route.

## Cross-Project Dependencies

None at the data layer. Downstream the nc-vue `CnRelatedObjectsWidget` (shared lib) can finally
surface shillinq object relations, but that consumer is not modified here.

## Risks

### Risk 1: Existing seeded objects store business keys/slugs, not UUIDs
**Severity:** High
**Mitigation:** Declaring `$ref` alone does NOT make `/uses` resolve — the stored value must be the
target object's UUID. The pilot therefore re-seeds the GL cluster with UUID-carrying objects (and
seeds the missing `Account` objects). Live data created before the pilot keeps scalar keys until a
separate migration runs; this is acknowledged and bounded to the seed cluster. See design.md
Migration Plan and Open Questions (b).

### Risk 2: Slug-vs-UUID reference resolution ambiguity
**Severity:** Medium
**Mitigation:** procest's `$ref` resolves targets by **slug**; OpenRegister's `/uses` graph resolves
by **UUID**. The pilot standardizes on the UUID form documented in `openregister/docs/Features/schemas.md`
so the relation graph resolves; design.md pins the exact idiom and a verification step.

### Risk 3: Two clusters edited in two register layouts
**Severity:** Low
**Mitigation:** GL schemas live under `components.schemas.*`; ARInvoice/CustomerMaster live as
sibling blocks under `components.*` (keyed by `slug`). Both are in the same file; edits target the
correct path per cluster. ARInvoice's pre-existing descriptive `x-openregister-relations` block is
left intact (it does not feed `/uses`/`/used`) and is NOT confused with the new resolving `$ref`
idiom. Cluster boundary is pinned in Open Question (c).

## Rollback Strategy

Revert the single-file diff to `lib/Settings/shillinq_register.json` (schema-property additions +
seed objects) and re-import the register via the repair step. No tables, no code, no data outside
the seeded demo cluster are touched, so rollback is a clean file revert + re-import.

## Capabilities

### New Capabilities
- `declarative-object-references`: the reference-modeling pattern for shillinq — declaring
  inter-object relationships as OpenRegister `$ref` (+ `inversedBy`) properties holding target
  UUIDs (vs. flat scalar business keys), piloted on TWO clusters (the GL posting graph and the
  ARInvoice ↔ CustomerMaster receivables edge), plus the UUID-carrying seed clusters that make
  `/uses`/`/used` resolve.

### Modified Capabilities
<!-- None. bookkeeping-general-ledger / -journal-entries describe GL behaviour but not the relation
     representation; this change adds a new orthogonal capability (the reference idiom). -->

## Open Questions

1. **Customer reference target — CustomerMaster vs. NC `contact` entity.** **Decided for this
   pilot:** reference the EXISTING `CustomerMaster` schema (least-disruptive). Per project memory
   ("Contact is a Nextcloud entity — reuse NC addressbook + `contact` schema; don't invent a
   customer schema"), aligning customers with the NC `contact` entity remains the long-term
   direction — `CustomerMaster.contactRef` is the natural seam. **Deferred follow-up:** re-point
   customer references (and/or `CustomerMaster` itself) at the NC `contact` entity in a later
   change. Affected artifact: scope / spec / design.md Open Questions.
2. **Re-seed vs. data-migration.** The pilot re-seeds both clusters with UUIDs (declarative,
   ADR-032). Migrating pre-existing live objects from business keys (e.g. `ARInvoice.customerId =
   "DEMO-C1"`) → UUIDs would require a PHP repair step (`kind: code`). **Provisional decision:**
   re-seed only for the pilot; declare a follow-up `kind: code` migration change (`depends_on` this)
   if live data must be converted. Affected artifact: design.md / migration.md.
3. **Exact pilot cluster boundary.** **Decided:** TWO clusters — (A) GL posting graph
   (`GLLine → GLTransaction`, `GLLine → Account`) and (B) ARInvoice ↔ CustomerMaster
   (`ARInvoice.customerId → CustomerMaster`, `ARInvoice.glTransactionId → GLTransaction`). Excludes
   `GLTransaction.journalEntryId` (no `JournalEntry` schema exists), `ARInvoice.writeOffGlTransactionId`
   (deferred), and all other schemas. Affected artifact: scope / spec.
4. **Fold business-key strings into the reference, or keep both?** **Provisional decision:** keep
   the human-facing business-key strings (e.g. GL `accountNumber` RGS code; ARInvoice has no
   redundant customer business-key string beyond `customerId` itself, which IS the reference field)
   AND add UUID references side-by-side where a distinct human key exists; folding is a follow-up.
