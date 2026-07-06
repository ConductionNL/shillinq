# Spec: semantic-invoice-consume (delta)

New capability: shillinq as the consume side of the cross-app semantic handoff
chain *quote → contract → AR invoice* (ADR-048 semantic references, ADR-041
event provenance, ADR-031 declarative business logic; dialect owned by the
hydra change `semantic-object-handoff`).

## ADDED Requirements

### Requirement: REQ-SIC-001 — Shillinq schemas SHALL advertise the canonical finance kinds via `configuration.implements`, exactly one deployed schema per kind

Shillinq MUST declare the ADR-048 semantic-type markers so OpenRegister's
`SemanticTypeResolver` resolves the finance kinds to shillinq when it is
installed — following the live `Payee` → `ns#Vendor` precedent
(`lib/Settings/register.d/bookkeeping-accounts-payable-core.json`,
`configuration.implements`). Exactly ONE deployed (post-merge) schema per kind
MUST carry the marker, so resolution is deterministic without ambiguity WARNs:

- `ARInvoice` implements `["https://openregister.app/ns#Invoice", "https://schema.org/Invoice"]`
  (NOT the quote-order-invoice `Invoice` schema — `ARInvoice` is the
  operational AR invoice: sub-ledger + GL materialisation, dunning, payment
  links, EN16931 invoice lines, existing notification rules).
- `Contract` implements `["https://openregister.app/ns#Contract"]`.
- `SalesOrder` implements `["https://openregister.app/ns#SalesOrder", "https://schema.org/Order"]`.
- `Quote` implements `["https://openregister.app/ns#Quote"]` (so the
  quote → contract handoff also works shillinq-standalone; multiple installed
  providers of a SOURCE kind are acceptable per ADR-048).

The markers MUST be declared in a single ADR-037 overlay fragment (not by
editing the schemas' owning fragments), merged additively by
`SettingsService::deepMergeConfig()`.

@e2e exclude declarative register config; provider resolution is asserted server-side via the OR semantic-types API/unit tests, no UI surface

#### Scenario: ns#Invoice resolves to ARInvoice

- **GIVEN** shillinq is installed and its register imported
- **WHEN** the OR `SemanticTypeResolver` resolves
  `https://openregister.app/ns#Invoice`
- **THEN** it MUST return the `ARInvoice` schema, with no ambiguity WARN from a
  second shillinq schema implementing the same kind

#### Scenario: ns#Contract resolves to Contract

- **GIVEN** shillinq is installed
- **WHEN** `https://openregister.app/ns#Contract` is resolved
- **THEN** it MUST return the (merged) `Contract` schema declared by
  `contract-lifecycle-management.json`

#### Scenario: Absent provider stays null-safe

- **GIVEN** shillinq is not installed (or disabled)
- **WHEN** any of the four kinds is resolved
- **THEN** the resolver MUST return `null` (per ADR-048) and the referencing
  app MUST keep working with the field unfillable — this change MUST NOT
  introduce any construct that breaks that degradation

### Requirement: REQ-SIC-002 — Shillinq SHALL accept kind-keyed handoffs that land the quote → contract → AR-invoice chain

Shillinq MUST declare `x-openregister-handoff` acceptance rules (dialect:
hydra `semantic-object-handoff`; field mapping + provenance + ADR-041 events)
keyed to canonical kind URIs — NEVER to concrete schema slugs or app ids:

- **H1**: when a source object of kind `https://openregister.app/ns#Quote`
  reaches its accepted terminal state, a target object of kind `ns#Contract`
  MUST be created in shillinq with the declared field mapping and provenance.
- **H2**: when a contract of kind `ns#Contract` that carries handoff provenance
  transitions to `active` with `direction = outbound`, ONE draft target object
  of kind `ns#Invoice` MUST be created with field mapping and provenance.
  Recurring billing MUST remain with `RecurringInvoiceProfile`
  (`recurring-invoicing.json`) — H2 creates only the initial invoice.

The mapping's TARGET fields MUST exist on the shillinq schemas at HEAD
(`Contract`: contractNumber, title, contractType, direction,
counterpartyReference, startDate, endDate, totalContractValue, currency,
status, administrationId; `ARInvoice`: invoiceNumber, customerId,
administrationId, invoiceDate, dueDate, gross/vat/netAmount, currency,
lifecycleState, sourceDocumentUri). SOURCE field names for H1 MUST be aligned
to the pipelinq quote schema when it lands (none exists at pipelinq HEAD).

@e2e exclude cross-app backend handoff; asserted via OR-side integration tests of the semantic-object-handoff engine, no shillinq UI surface in this change

#### Scenario: Accepted pipelinq quote lands as a shillinq contract

- **GIVEN** pipelinq and shillinq are installed and the OR handoff engine is
  live
- **WHEN** a pipelinq object whose schema implements `ns#Quote` reaches its
  accepted state
- **THEN** a shillinq `Contract` MUST be created via the kind-keyed H1 rule,
  populated per the declared mapping, in its initial lifecycle state, carrying
  provenance to the source quote

#### Scenario: Activated handed-off contract yields exactly one draft AR invoice

- **GIVEN** a shillinq `Contract` created by H1 (provenance present) with
  `direction = outbound`
- **WHEN** it transitions to `active`
- **THEN** exactly ONE `ARInvoice` MUST be created in `draft` with provenance
  to the contract; re-processing the same transition MUST NOT create a second
  invoice (idempotent per the handoff dialect); recurring schedules are NOT
  created by this rule

#### Scenario: Handoff keys survive slug changes

- **GIVEN** the H1/H2 declarations
- **WHEN** they are inspected
- **THEN** they MUST reference kinds (`ns#Quote`, `ns#Contract`, `ns#Invoice`)
  and MUST NOT contain a pipelinq schema slug, a shillinq schema slug as a
  resolution key, or an app id as the routing key

### Requirement: REQ-SIC-003 — Handed-off objects SHALL carry provenance links back to their source objects

Shillinq MUST declare semantic provenance reference properties (ADR-048
`referenceSemanticType`) so every handed-off object links back to what produced
it, and the ADR-041 provenance envelope (sourceApp, source register/schema/id,
correlationId) delivered by the handoff MUST be persisted with the object:

- `Contract.sourceQuoteReference` — nullable string (uuid), with
  `referenceSemanticType: "https://openregister.app/ns#Quote"`.
- `ARInvoice.sourceContractReference` — nullable string (uuid), with
  `referenceSemanticType: "https://openregister.app/ns#Contract"`.

Both properties MUST be nullable and additive (operator-created contracts and
invoices remain valid without them). Resolution of the reference MUST degrade
null-safe when the providing app is uninstalled later.

@e2e exclude additive schema properties + stored provenance; asserted via schema import + object round-trip checks, no UI change in this change

#### Scenario: Handed-off invoice links to its contract

- **GIVEN** an `ARInvoice` created by H2
- **WHEN** the object is read
- **THEN** `sourceContractReference` MUST hold the source Contract's UUID and
  the persisted provenance envelope MUST identify sourceApp and correlationId
  of the originating handoff chain

#### Scenario: Operator-created objects are unaffected

- **GIVEN** an operator creates an `ARInvoice` by hand
- **WHEN** it is validated
- **THEN** it MUST validate with `sourceContractReference` absent/null — the
  provenance properties MUST NOT be required

### Requirement: REQ-SIC-004 — Handed-off objects SHALL start in the schema's initial lifecycle state and never trigger accounting side effects on arrival

A handoff MUST create objects in the target schema's declared
`x-openregister-lifecycle.initialState` — `draft` for both `Contract`
(contract-lifecycle-management) and `ARInvoice`
(add-shillinq-bookkeeping-compliance) — and MUST NOT write any other state.
Since `ARInvoice`'s `issued` transition materialises a balanced GLTransaction
and runs the credit-limit check, arrival-in-draft guarantees no GL posting, no
credit decision and no dispatch happens without an operator advancing the
lifecycle.

@e2e exclude lifecycle placement is declarative register config; asserted via object-state checks after a handoff, no UI surface

#### Scenario: Handed-off AR invoice arrives as draft

- **GIVEN** H2 fires for an activated handed-off contract
- **WHEN** the `ARInvoice` is created
- **THEN** `lifecycleState` MUST be `draft`, no GLTransaction MUST have been
  materialised, and the invoice MUST NOT have been dispatched

#### Scenario: Handoff cannot inject a non-initial state

- **GIVEN** a (malformed or malicious) handoff payload proposing
  `lifecycleState = issued`
- **WHEN** the acceptance rule processes it
- **THEN** the created object MUST still be in `draft` — the acceptance
  declaration maps no lifecycle field and the initial state is fixed by the
  schema's lifecycle declaration

### Requirement: REQ-SIC-005 — Finance operators SHALL be notified when a handed-off object arrives (ADR-031)

Shillinq MUST declare `x-openregister-notifications` rules (canonical dialect,
gate-18; house shape as in `shillinq-notifications.json` — trigger types
`created`/`updated`/`scheduled`, bilingual nl/en subjects, metadata-only) on
`Contract` and `ARInvoice`: trigger `created` with a condition that handoff
provenance is present (e.g. `sourceQuoteReference` / `sourceContractReference`
not null), recipients `object-acl` (manage) plus the `shillinq-finance` group.
The rules MUST live under `components.schemas.<Schema>` in the overlay fragment
(the shape the import actually merges); the pre-existing misplaced
`components.ARInvoice` block in `shillinq-notifications.json` MUST be relocated
under `components.schemas` in the same batch.

@e2e exclude declarative notification rules; delivery is asserted via the OR notification engine's own tests + a manual NC bell check during verification

#### Scenario: Handed-off contract notifies the finance group

- **GIVEN** the notification rules are imported
- **WHEN** H1 creates a `Contract` with `sourceQuoteReference` set
- **THEN** members of `shillinq-finance` MUST receive an NC notification whose
  subject (nl/en) names the contract number — metadata only, no amounts beyond
  the declared metadata fields

#### Scenario: Operator-created objects do not notify as handoffs

- **GIVEN** an operator creates a `Contract` without provenance
- **WHEN** the `created` trigger evaluates
- **THEN** the handoff-received rule MUST NOT fire (condition on the provenance
  property being present)

### Requirement: REQ-SIC-006 — The consume side SHALL survive the abstract-order-primitive consolidation without consumer-visible change

This change MUST NOT deepen the schema-dedup debt that BLOCKS
`abstract-order-primitive` (three colliding `Order` schemas, a triplicated
`Subsidie`, and the Invoice merge it names as its next template) and MUST
survive that consolidation:

- It MUST NOT declare any new `Order`-slug schema and MUST NOT add fields to
  the colliding `Order` declarations.
- All markers, handoff rules and provenance references MUST be keyed to kind
  URIs; the concrete schema a kind maps to is explicitly expected to change
  when the consolidation lands (e.g. `ARInvoice`/`Invoice` merging, `SalesOrder`
  folding into the Order primitive).
- All consume-side declarations MUST live in the single overlay fragment
  `semantic-invoice-consume.json`, so the consolidation moves the markers by
  re-pointing ONE file; kind-keyed consumers (pipelinq, the OR resolver, the
  handoff engine) MUST require no change.

@e2e exclude structural/architectural constraint on config placement; asserted by grep-style checks in verification, not a runtime UI flow

#### Scenario: Consolidation re-points the marker, consumers unchanged

- **GIVEN** a future consolidation replaces `ARInvoice` with a merged canonical
  invoice schema
- **WHEN** the `implements: ns#Invoice` marker and the H2 target mapping are
  moved to the merged schema by editing only `semantic-invoice-consume.json`
- **THEN** pipelinq's kind-keyed references and the handoff chain MUST keep
  resolving with no change on the produce side

#### Scenario: No new Order-slug debt

- **GIVEN** the shipped fragment
- **WHEN** it is inspected
- **THEN** it MUST contain no `"slug": "Order"` declaration and no property
  additions to the `Order` schemas declared by `bookings-deposit-to-invoice.json`
