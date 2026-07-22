# Design: model-or-references-pilot

## Context

Shillinq's register declares 100+ schemas and seeds 72 objects, with **zero** `$ref`/`inversedBy`/
`objectConfiguration` declarations. Every relationship is a flat scalar: `GLLine.transactionId`
holds a GLTransaction **slug** (`"gl-txn-2026-q1-revenue"`); `GLLine.accountNumber` holds an RGS
account **code** (`"1300"`); `ARInvoice.customerId` holds a customer business key (live data
carries e.g. `"DEMO-C1"` / `customer-klant-1`, NOT a UUID).

OpenRegister resolves the relation graph (`/uses`, `/used`, `RelationHandler::getUses/getUsedBy`,
`BulkValidationHandler::performComprehensiveSchemaAnalysis` which builds `inverseProperties` from
each property's `inversedBy`, and `BulkRelationHandler` which writes the back-reference) **only**
when (a) a property declares a `$ref` to a target schema and (b) the stored value is the target
object's UUID. Shillinq satisfies neither, so `/uses`/`/used` are empty for every shillinq object,
and no related-objects UI can show object relations.

**Two pilot clusters** (both with schemas + scalar links already present, so the pilot converts
real links rather than inventing schemas):
- **Cluster A — GL posting graph:** `GLTransaction`, `GLLine`, `Account` (under `components.schemas.*`).
- **Cluster B — receivables:** `ARInvoice` + `CustomerMaster` (sibling blocks under `components.*`,
  keyed by `slug`). `ARInvoice.customerId` is described "FK to CustomerMaster UUID" but holds a
  business key; `ARInvoice.glTransactionId` bridges into Cluster A.

Register-layout correction (vs. an earlier reading that searched `components.schemas` keys only):
`ARInvoice` and `CustomerMaster` DO exist — verified via
`grep '"slug": "ARInvoice"\|"slug": "CustomerMaster"' lib/Settings/shillinq_register.json` — but
live at `components.ARInvoice` / `components.CustomerMaster`, not under `components.schemas.*`.

Important: `ARInvoice` already declares an `x-openregister-relations` map
(`customer`/`glTransaction`/… with `localField`/`relatedSchema`/`cardinality`). This is
**descriptive metadata** that the `/uses`/`/used` graph does **NOT** read. This pilot adds the
property-level `$ref` (+ `inversedBy`) idiom the graph DOES resolve, and leaves the descriptive
block intact.

## Goals / Non-Goals

**Goals**
- Establish the OpenRegister reference idiom (`$ref` + `inversedBy`, value = target UUID) on TWO
  self-contained clusters, matching the documented OR idiom and procest precedent exactly.
- Make `/uses`/`/used` demonstrably resolve for both seeded clusters.
- Leave a documented, copy-pasteable pattern for later per-schema roll-out.

**Non-Goals**
- Converting any schema outside the two pilot clusters (the rest keep scalar keys).
- Re-pointing customers at the NC `contact` entity (pilot uses the EXISTING `CustomerMaster`;
  NC-contact alignment is a deferred follow-up — `CustomerMaster.contactRef` is the seam).
- Rewriting/removing ARInvoice's descriptive `x-openregister-relations` block.
- Migrating pre-existing live object data (a `kind: code` follow-up if ever needed).
- Modifying the nc-vue `CnRelatedObjectsWidget` consumer.

## The reference idiom (pinned)

From `openregister/docs/Features/schemas.md` (relational cascading) and procest precedent
(`procest/lib/Settings/procest_register.json`, 67 `$ref` declarations):

Single-object reference with inverse (the `GLLine → GLTransaction` edge):

```json
"transactionId": {
  "type": "string",
  "format": "uuid",
  "$ref": "GLTransaction",
  "inversedBy": "lines",
  "description": "Reference to the parent GLTransaction (holds its object UUID)."
}
```

Inverse array on the parent (`GLTransaction.lines`):

```json
"lines": {
  "type": "array",
  "items": { "type": "string", "format": "uuid", "$ref": "GLLine" },
  "description": "Inverse of GLLine.transactionId; back-reference populated by OpenRegister."
}
```

Single-object reference without inverse (the `GLLine → Account` edge):

```json
"accountRef": {
  "type": "string",
  "format": "uuid",
  "$ref": "Account",
  "description": "Reference to the Account (holds its object UUID). accountNumber RGS code retained."
}
```

Cluster B — single-object reference with inverse (the `ARInvoice → CustomerMaster` edge):

```json
"customerId": {
  "type": "string",
  "format": "uuid",
  "$ref": "CustomerMaster",
  "inversedBy": "invoices",
  "description": "Reference to the CustomerMaster this invoice bills (holds its object UUID)."
}
```

Inverse array on `CustomerMaster`:

```json
"invoices": {
  "type": "array",
  "items": { "type": "string", "format": "uuid", "$ref": "ARInvoice" },
  "description": "Inverse of ARInvoice.customerId; back-reference populated by OpenRegister."
}
```

Cluster B — bridge into Cluster A (`ARInvoice → GLTransaction`, no inverse):

```json
"glTransactionId": {
  "type": "string",
  "format": "uuid",
  "$ref": "GLTransaction",
  "description": "Reference to the materialised issue GLTransaction (holds its object UUID)."
}
```

Note: procest resolves `$ref` by schema **slug**; OpenRegister's `/uses` graph resolves stored
**UUIDs**. The pilot stores UUIDs (the value form `/uses`/`/used` require) and declares `$ref` by
schema name, matching both the docs example and the in-register schema-key convention. ARInvoice's
existing `x-openregister-relations` map is a separate descriptive dialect that the graph ignores;
it is left untouched.

## Decisions

- **Pilot TWO clusters: GL posting graph (A) AND ARInvoice ↔ CustomerMaster (B).** Why: both have
  schemas + scalar links already present, so conversion is faithful (not invented). B is high-value
  (receivables relate customers ↔ invoices ↔ GL) and bridges into A via `ARInvoice.glTransactionId`.
- **Customer reference target = the existing `CustomerMaster` schema, NOT the NC `contact` entity.**
  Why: least-disruptive for the pilot — `CustomerMaster` exists and `ARInvoice.customerId` already
  describes itself as a CustomerMaster FK. The "Contact is an NC entity" memory rule still holds as
  the long-term direction; `CustomerMaster.contactRef` is the seam for a deferred NC-contact
  alignment follow-up. Alternative (re-point to NC `contact` now) rejected — out of scope, larger
  blast radius.
- **Store UUIDs, declare `$ref` by schema name + `inversedBy` for parent edges.** Why: this is the
  exact form `/uses`/`/used` resolve. Alternative (slug `$ref` like procest) rejected — slugs don't
  populate the UUID relation graph.
- **Add `accountRef` alongside `accountNumber`, don't replace it.** Why: `accountNumber` is the
  human-facing RGS code used by reporting; replacing it would ripple beyond the pilot. (For
  Cluster B no analogous redundant string is added — `customerId` IS the reference field, and
  `ARInvoice` keeps `invoiceNumber` etc. unchanged.) Folding is a follow-up.
- **Leave ARInvoice's `x-openregister-relations` descriptive block intact.** Why: it is metadata the
  relation graph does not read; rewriting it is unnecessary churn and out of scope.
- **Re-seed both clusters with UUIDs; do not migrate live data here.** Why: `kind: config` is
  declarative JSON; converting live business keys (e.g. `ARInvoice.customerId = "DEMO-C1"`) → UUIDs
  needs PHP (a repair step) = `kind: code`. Keeping them separate avoids a mixed-kind change
  (ADR-032). See Migration Plan.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Decision | Rationale |
|---|---|---|
| Declaring the `GLLine → GLTransaction` reference (A) | **Declarative** | A `$ref` + `inversedBy` property in the schema register; OpenRegister's `BulkValidationHandler`/`BulkRelationHandler` resolve and back-populate it. No service. |
| Declaring the `GLLine → Account` reference (A) | **Declarative** | A `$ref` property holding the Account UUID; resolved by the OR relation graph. No service. |
| Populating `GLTransaction.lines` inverse (A) | **Declarative** | OpenRegister writes the back-reference from `inversedBy`; the leaf app declares only. |
| Declaring the `ARInvoice → CustomerMaster` reference (B) | **Declarative** | A `$ref` + `inversedBy: invoices` property; resolved + back-populated by OR. No service. |
| Declaring the `ARInvoice → GLTransaction` bridge (B) | **Declarative** | A `$ref` property holding the GLTransaction UUID; resolved by the OR relation graph. No service. |
| Populating `CustomerMaster.invoices` inverse (B) | **Declarative** | OpenRegister writes the back-reference from `inversedBy`; the leaf app declares only. |
| Making `/uses`/`/used` return both clusters | **Declarative (via seed data)** | Achieved by seeding UUID-carrying objects, not by a query service. |
| ARInvoice's `x-openregister-relations` descriptive block | **Declarative metadata, left as-is** | Not read by the relation graph; no behaviour, no rewrite. |
| Converting **pre-existing live** scalar keys → UUIDs | **Imperative — DEFERRED to a `kind: code` follow-up** | Requires reading existing objects and rewriting values; this is the ADR-031 exception path and MUST be a separate `depends_on` change, never folded into this `kind: config` change. |

Adding reference relations between OR objects is the canonical declarative path: it is *declared in
the schema register*, not implemented in a new Service class. This change adds **no** PHP.

## Seed Data (ADR-001)

Data lives in OpenRegister; shillinq owns no tables. Declaring `$ref` alone does **not** make
`/uses`/`/used` resolve — the stored value must be the target object's UUID. Today the GL seeds use
slugs/codes, **zero `Account` objects are seeded**, and **zero `ARInvoice` and zero `CustomerMaster`
objects are seeded**, so even after schema changes the relation graph would stay empty. The pilot
therefore seeds a self-contained UUID-cross-referencing demo administration in `objects[]`
(placeholders use the nil UUID `00000000-0000-0000-0000-000000000000`):

**Cluster A — GL posting graph:**
1. **Account objects (new — none exist today).** e.g. an AR account (RGS `1300`) and a revenue
   account (RGS `8000`), each a distinct OpenRegister object with its own UUID.
2. **One `GLTransaction`** (the existing `gl-txn-2026-q1-revenue` demo, re-pointed), gaining the
   inverse `lines` array (populated by OR from the line back-references).
3. **Its `GLLine`s** (the existing `gl-line-*` demo lines, re-pointed) where:
   - `transactionId` holds the **GLTransaction object's UUID** (was the slug `gl-txn-2026-q1-revenue`);
   - `accountRef` holds the matching **Account object's UUID** (alongside the retained
     `accountNumber` RGS code).

**Cluster B — receivables:**
4. **One `CustomerMaster` (new — none exist today).** A demo customer with its own UUID.
5. **One `ARInvoice` (new — none exist today).** Its `customerId` holds the **CustomerMaster object's
   UUID** (live data carries a business key like `"DEMO-C1"`); its `glTransactionId` holds the
   **Cluster A `GLTransaction` UUID** (bridging the two clusters).

Result: `/used` on the GLTransaction returns its GLLines and on the CustomerMaster returns its
ARInvoice; `/uses` on a GLLine returns its GLTransaction + Account, and on the ARInvoice returns its
CustomerMaster + GLTransaction — the pattern works end-to-end across both clusters on seed data with
no manual entry.

Because OR resolves by UUID and seeds are authored as JSON, the seed author cannot know the
runtime-assigned UUIDs in advance. Two acceptable mechanisms (final choice at apply time, both
declarative): (a) author the clusters with stable seed-assigned UUIDs in the objects' `@self.id`
and reference those same UUIDs in the reference fields; or (b) use OpenRegister's seed
cross-reference mechanism (`@ref` / components.objects) per the OR seed conventions. The pilot
prefers (a) for transparency; either keeps the change `kind: config`.

## Risks / Trade-offs

- [Schema change without UUID data → `/uses` still empty] → Re-seed both clusters with UUIDs; the
  spec asserts non-empty `/uses`/`/used` on seed.
- [Slug/business-key-vs-UUID confusion] → Pin UUID form in this design + verify via `/uses`/`/used`
  after import; live data (e.g. `ARInvoice.customerId = "DEMO-C1"`) stays a deferred migration.
- [ARInvoice descriptive `x-openregister-relations` mistaken for the resolving idiom] → Documented:
  it is metadata the graph ignores; the property-level `$ref` is what resolves.
- [Two register layouts (`components.schemas.*` vs `components.*`)] → Edits target the correct path
  per cluster; both in one file.
- [`accountRef` + `accountNumber` duplication] → Accepted for the pilot; folding deferred.

## Migration Plan

- **Deploy:** edit `lib/Settings/shillinq_register.json` (schema properties for both clusters + seed
  objects), then re-import the register via the existing repair step
  (`ConfigurationService::importFromApp()`).
- **Pre-existing live data:** NOT migrated by this change. Objects created before the pilot keep
  scalar keys (e.g. `ARInvoice.customerId = "DEMO-C1"`, `GLLine.transactionId = <slug>`) until a
  separately declared `kind: code` migration/repair change runs (it would map business keys/slugs →
  UUIDs). That change `depends_on` this one and is out of scope here.
- **Rollback:** revert the single-file diff and re-import. No tables/code/non-seed data touched.

## Open Questions

1. Customer reference target — `CustomerMaster` (pilot) vs NC `contact` entity. **Decided for the
   pilot:** reference the existing `CustomerMaster`. **Deferred follow-up:** align customers with the
   NC `contact` entity via `CustomerMaster.contactRef`, per the "Contact is an NC entity" memory rule.
2. Re-seed vs data-migration for live objects. (Provisional: re-seed only; declare a `kind: code`
   follow-up — `depends_on` this — if live conversion is required.)
3. Exact pilot cluster boundary. **Decided:** two clusters — GL graph (A) + ARInvoice ↔ CustomerMaster
   (B, incl. the `ARInvoice.glTransactionId` bridge). Excludes `GLTransaction.journalEntryId` (no
   `JournalEntry` schema) and `ARInvoice.writeOffGlTransactionId` (deferred).
4. Fold human-key strings into the reference vs keep both. (Provisional: keep GL `accountNumber`
   RGS code; B has no redundant customer string — `customerId` IS the reference.)
