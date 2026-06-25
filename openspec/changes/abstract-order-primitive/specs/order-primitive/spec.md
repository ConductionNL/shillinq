# Order/Contract primitive

## ADDED Requirements

### Requirement: REQ-ORD-001 — Order is a single typed primitive
The system SHALL model purchase orders, sales orders, subsidies and ZZP engagements
as one `Order` schema discriminated by `orderType` (`purchase | sales | subsidie |
engagement`) and a `direction` (`incoming | outgoing`), rather than separate schemas.

#### Scenario: a subsidie is an Order of type subsidie
- **WHEN** a grant is recorded
- **THEN** it is an `Order` with `orderType = subsidie` carrying the regeling,
  beschikking and the five state-amounts on its `subsidie` field group.

#### Scenario: a purchase order is an Order of type purchase
- **WHEN** a PO is raised
- **THEN** it is an `Order` with `orderType = purchase` carrying supplier + 3-way fields.

### Requirement: REQ-ORD-002 — Type-aware lifecycle preserves each domain's states
Each `orderType` SHALL retain its own lifecycle state vocabulary, enforced by
`x-openregister-lifecycle` transitions gated on `orderType`, with no regulatory or
audit field dropped in the merge.

#### Scenario: subsidie keeps its statutory lifecycle
- **WHEN** a subsidie Order advances
- **THEN** it follows aanvraag → verleend → vastgesteld → uitbetaald → teruggevorderd
  → afgehandeld, identical to the retired Subsidie schema.

### Requirement: REQ-ORD-003 — Existing rows migrate without loss
A repair step SHALL convert every existing Subsidie and PurchaseOrder object into an
`Order` (type-tagged, all fields preserved) and the audit SHALL show equal counts
before and after.

#### Scenario: migration is lossless
- **WHEN** the migration runs
- **THEN** every Subsidie/PurchaseOrder field lands on the corresponding Order field
  and no row is dropped.
