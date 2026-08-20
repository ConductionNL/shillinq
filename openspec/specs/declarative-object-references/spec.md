# declarative-object-references Specification

## Purpose
TBD - created by archiving change model-or-references-pilot. Update Purpose after archive.
## Requirements
### Requirement: GLLine SHALL reference its parent GLTransaction as a declarative OpenRegister reference

`GLLine.transactionId` SHALL be declared in `lib/Settings/shillinq_register.json` as an
OpenRegister object reference to the `GLTransaction` schema using the documented `$ref` idiom
(`openregister/docs/Features/schemas.md` §"Cascading with inversedBy"), not as a plain string
business key. The property SHALL carry `format: uuid` and SHALL store the **UUID** of the
referenced `GLTransaction` object so that OpenRegister's relation graph (`/uses`, `/used`) resolves
it. The declaration SHALL set `inversedBy: lines` so the parent `GLTransaction` exposes its lines
via the reverse relation. This is declarative per ADR-031 — no service class computes the relation.

#### Scenario: A GLLine declares a typed reference to its GLTransaction

- **GIVEN** the `GLLine` schema in `lib/Settings/shillinq_register.json`
- **WHEN** the register is imported
- **THEN** `GLLine.properties.transactionId` MUST declare `$ref` to `GLTransaction`,
  `format: uuid`, and `inversedBy: lines`

#### Scenario: /used on a seeded GLTransaction resolves its GLLines

- **GIVEN** a seeded `GLTransaction` object and seeded `GLLine` objects whose `transactionId`
  holds that transaction's UUID
- **WHEN** the OpenRegister `/used` endpoint is called for the `GLTransaction` object
- **THEN** the response MUST list the referencing `GLLine` objects (non-empty), whereas before the
  pilot it returned empty

### Requirement: GLTransaction SHALL declare the inverse lines relation

`GLTransaction` SHALL declare a `lines` property (type `array`, items `$ref: GLLine`) that receives
the back-reference from `GLLine.transactionId`'s `inversedBy`, per the OpenRegister relational
cascading idiom. The property SHALL be declarative only; no service populates it imperatively.

#### Scenario: GLTransaction exposes an inverse lines array

- **GIVEN** the `GLTransaction` schema
- **WHEN** the register is imported
- **THEN** `GLTransaction.properties.lines` MUST be declared as an array of `GLLine` references that
  is the inverse target named by `GLLine.transactionId.inversedBy`

### Requirement: GLLine SHALL reference its Account as a declarative OpenRegister reference

`GLLine` SHALL declare an Account reference property (e.g. `accountRef`) with `$ref: Account` and
`format: uuid`, holding the **UUID** of the referenced `Account` object, alongside the existing
`accountNumber` RGS-code string (which is retained for human-facing reporting). The reference is
what the relation graph resolves; the RGS code is not removed by this pilot.

#### Scenario: A GLLine declares a typed reference to its Account

- **GIVEN** the `GLLine` schema
- **WHEN** the register is imported
- **THEN** `GLLine` MUST declare an Account reference property with `$ref` to `Account` and
  `format: uuid`, and MUST retain `accountNumber` as a string

#### Scenario: /uses on a seeded GLLine resolves its Account

- **GIVEN** a seeded `GLLine` whose Account reference holds a seeded `Account` object's UUID
- **WHEN** the OpenRegister `/uses` endpoint is called for the `GLLine` object
- **THEN** the response MUST include the referenced `Account` object

### Requirement: ARInvoice SHALL reference its CustomerMaster as a declarative OpenRegister reference

`ARInvoice.customerId` SHALL be declared as an OpenRegister object reference to the `CustomerMaster`
schema using the documented `$ref` idiom, not as a plain string business key. (The `ARInvoice`
schema lives as a sibling block under `components.*`, keyed by `slug`, in
`lib/Settings/shillinq_register.json`.) The property SHALL carry `format: uuid` and SHALL store the **UUID** of the referenced
`CustomerMaster` object so that `/uses`/`/used` resolve it. The declaration SHALL set
`inversedBy: invoices` so `CustomerMaster` exposes its invoices via the reverse relation. The
pre-existing descriptive `x-openregister-relations` block on `ARInvoice` (which the relation graph
does NOT read) SHALL be left in place; it is not the resolving idiom. The customer target for this
pilot SHALL be the existing `CustomerMaster` schema — NOT the Nextcloud `contact` entity.

#### Scenario: ARInvoice declares a typed reference to its CustomerMaster

- **GIVEN** the `ARInvoice` schema block
- **WHEN** the register is imported
- **THEN** `ARInvoice.properties.customerId` MUST declare `$ref` to `CustomerMaster`,
  `format: uuid`, and `inversedBy: invoices`

#### Scenario: /used on a seeded CustomerMaster resolves its ARInvoices

- **GIVEN** a seeded `CustomerMaster` object and a seeded `ARInvoice` whose `customerId` holds that
  customer's UUID (placeholder UUIDs use `00000000-0000-0000-0000-000000000000`)
- **WHEN** the OpenRegister `/used` endpoint is called for the `CustomerMaster` object
- **THEN** the response MUST list the referencing `ARInvoice` object(s) (non-empty), whereas before
  the pilot it returned empty

### Requirement: CustomerMaster SHALL declare the inverse invoices relation

`CustomerMaster` SHALL declare an `invoices` property (type `array`, items `$ref: ARInvoice`) that
receives the back-reference from `ARInvoice.customerId`'s `inversedBy`. The property SHALL be
declarative only; no service populates it imperatively.

#### Scenario: CustomerMaster exposes an inverse invoices array

- **GIVEN** the `CustomerMaster` schema block
- **WHEN** the register is imported
- **THEN** `CustomerMaster.properties.invoices` MUST be declared as an array of `ARInvoice`
  references that is the inverse target named by `ARInvoice.customerId.inversedBy`

### Requirement: ARInvoice SHALL reference its GLTransaction as a declarative OpenRegister reference

`ARInvoice.glTransactionId` SHALL be declared with `$ref: GLTransaction` and `format: uuid`, holding
the **UUID** of the materialised issue `GLTransaction`, bridging the receivables cluster into the GL
posting graph. The reference is what the relation graph resolves.

#### Scenario: ARInvoice declares a typed reference to its GLTransaction

- **GIVEN** the `ARInvoice` schema block
- **WHEN** the register is imported
- **THEN** `ARInvoice.properties.glTransactionId` MUST declare `$ref` to `GLTransaction` and
  `format: uuid`

#### Scenario: /uses on a seeded ARInvoice resolves both its CustomerMaster and GLTransaction

- **GIVEN** a seeded `ARInvoice` whose `customerId` holds a seeded `CustomerMaster` UUID and whose
  `glTransactionId` holds a seeded `GLTransaction` UUID (placeholders use
  `00000000-0000-0000-0000-000000000000`)
- **WHEN** the OpenRegister `/uses` endpoint is called for the `ARInvoice` object
- **THEN** the response MUST include both the referenced `CustomerMaster` and `GLTransaction` objects

### Requirement: The pilot SHALL seed self-contained UUID-cross-referencing clusters for both edges

`lib/Settings/shillinq_register.json` `objects[]` SHALL seed a demo administration in which the
reference properties of BOTH clusters carry real object UUIDs (not slugs, RGS codes, or business
keys), so `/uses` and `/used` resolve out of the box. The seed SHALL include at least:
- **Cluster A:** `Account` objects (currently **zero** seeded), one `GLTransaction`, and its
  `GLLine`s whose `transactionId` holds the GLTransaction UUID and whose Account reference holds an
  Account UUID.
- **Cluster B:** one `CustomerMaster` (currently **zero** seeded) and one `ARInvoice` (currently
  **zero** seeded) whose `customerId` holds the CustomerMaster UUID and whose `glTransactionId`
  holds the (Cluster A) GLTransaction UUID.

Example/placeholder UUIDs in spec and seed text SHALL use the nil UUID
`00000000-0000-0000-0000-000000000000`.

#### Scenario: Seeded clusters make the relation graph resolve without manual data entry

- **GIVEN** a freshly imported shillinq register
- **WHEN** the seeded clusters are queried via `/uses` (from a `GLLine` and from the `ARInvoice`)
  and `/used` (from the `GLTransaction` and from the `CustomerMaster`)
- **THEN** all MUST return the cross-referenced objects, demonstrating both reference edges
  end-to-end

#### Scenario: Seed data uses the nil UUID for placeholders, never realistic UUIDs

- **GIVEN** the seed objects and spec examples
- **WHEN** a placeholder UUID is needed
- **THEN** it MUST be `00000000-0000-0000-0000-000000000000` and MUST NOT be a realistic-looking
  UUID or secret

### Requirement: The reference pattern SHALL be declarative-only (no service class)

The pilot SHALL declare all reference behaviour in the JSON schema register and seed data, per
ADR-031 (declarative schema over service classes) and ADR-032 (`kind: config`). No PHP service,
controller, or repair step SHALL be introduced by this change. Conversion of pre-existing live
objects' scalar keys to UUIDs (if ever required) SHALL be a separately declared `kind: code`
follow-up change, not folded into this one.

#### Scenario: No PHP is added by the pilot

- **GIVEN** the change diff
- **WHEN** it is reviewed
- **THEN** it MUST touch only `lib/Settings/shillinq_register.json` (schema properties + seed
  objects) and openspec artifacts, with no new files under `lib/`

