# Order/Contract primitive

> **Design divergence note (2026-07-22)**: `SCHEMA-ANALYTICS-AND-PLAN.md`
> (written at the 2026-06-22 blocker) proposed a composition-via-`allOf` model
> (a thin base `Order` + separate `Grant`/`SalesOrder`/`PurchaseOrder`/`Quote`/
> `BlanketOrder` extension schemas). That model was evaluated during the build
> that shipped this spec and REJECTED for two reasons: (1) OpenRegister's
> `ObjectService::findAll()` has no allOf-aware cross-schema query (confirmed
> against `SchemaMapper::resolveAllOf()`, which merges `properties`/`required`
> for validation only, never object storage/search) — a single "Order
> workspace" index can only ever show literal `Order` rows, so the extension
> model could never deliver the unified index this change exists to build; (2)
> the earlier partial build's `Grant` extension schema was a live slug
> collision with a pre-existing, unrelated `Grant` schema (WBSO/BBV/NSO/Tozo
> subsidy stub) that would have silently corrupted via
> `SettingsService::deepMergeConfig`. The requirements below describe — and the
> shipped code implements — the flat single-schema model instead: ONE `Order`
> schema with type-namespaced field groups (`subsidie`/`purchase`/
> `engagement`), each carrying its source schema's full field set verbatim.

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

#### Scenario: a DBA engagement is an Order of type engagement
- **WHEN** a ZZP engagement (DBAOpdracht) is recorded
- **THEN** it is an `Order` with `orderType = engagement` carrying the
  modelovereenkomst reference, risicoklasse (risicoNiveau) and DBA-specific
  fields on its `engagement` field group.

### Requirement: REQ-ORD-002 — Type-aware lifecycle preserves each domain's states
Each `orderType` SHALL retain its own lifecycle state vocabulary, enforced by
`x-openregister-lifecycle` transitions gated on `orderType`, with no regulatory or
audit field dropped in the merge.

#### Scenario: subsidie keeps its statutory lifecycle
- **WHEN** a subsidie Order advances
- **THEN** it follows aanvraag → verleend → vastgesteld → uitbetaald → teruggevorderd
  → afgehandeld, identical to the retired Subsidie schema.

#### Scenario: a transition never crosses orderType boundaries
- **WHEN** an Order of `orderType = subsidie` is evaluated for a transition
- **THEN** only transitions tagged `orderType = subsidie` (and whose `from`/`to`
  states belong to the subsidie vocabulary) are legal — a purchase or
  engagement transition can never apply.

> Scope note: the retired Subsidie schema's `postTransition` (JournalEntry
> creation) and `notifications` blocks are NOT ported onto the Order schema in
> this change — only the state vocabulary and `from`/`to`/`requires.fields`
> transition shape are faithfully preserved. Porting the imperative
> side-effects is tracked as follow-up (see tasks.md).

### Requirement: REQ-ORD-003 — Existing rows migrate without loss
A repair step SHALL convert every existing Subsidie, PurchaseOrder and DBAOpdracht
object into an `Order` (type-tagged, all fields preserved) and an audit command
SHALL show equal counts between source rows and their migrated Order rows.

#### Scenario: migration is lossless
- **WHEN** the migration runs
- **THEN** every Subsidie/PurchaseOrder/DBAOpdracht field lands on the
  corresponding Order field (base or type group) and no row is dropped or
  mutated at the source.

#### Scenario: money units are normalised without losing precision
- **WHEN** a PurchaseOrder (integer EURO CENTS per ADR-022) is folded
- **THEN** the shared `Order.totalAmount` is the decimal-EUR projection
  (`totalInclVat / 100`) while the original integer-cent fields
  (`totalExclVat`/`totalVat`/`totalInclVat`) are preserved verbatim inside the
  `purchase` field group.

#### Scenario: the audit command detects unmigrated rows
- **WHEN** `occ shillinq:orders:audit` runs and a source schema has more rows
  than it has matching folded Orders
- **THEN** the command reports a MISMATCH for that schema and exits non-zero.

> Scope note: this change migrates Subsidie + PurchaseOrder + DBAOpdracht only.
> `sales`/`booking`/`quote`/`blanket` are reserved `orderType` discriminator
> values for future folds (SalesOrder/BookingOrder/Quote/BlanketOrder) — no
> migration for them ships in this change. The migration has been verified
> against unit-test fixtures only; live-instance verification (`occ
> maintenance:repair` + `occ shillinq:orders:audit` against real data) is
> PENDING — see tasks.md.
