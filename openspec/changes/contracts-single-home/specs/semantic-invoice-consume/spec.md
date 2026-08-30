# Spec: semantic-invoice-consume (delta — contracts-single-home)

This delta MODIFIES REQ-SIC-001 and REQ-SIC-002 only. REQ-SIC-003 through
REQ-SIC-006 (provenance links, initial-state arrival, notifications, and
surviving the `abstract-order-primitive` consolidation) are untouched — they
already key everything to the `ns#Contract` / `ns#Invoice` kind URIs rather
than to schema slugs, so nothing about their normative behaviour changes.

## Why these requirements are being replaced, not just updated

The current spec's own text (re-verified at HEAD) already names the
collision this delta fixes, twice, without resolving it:

- REQ-SIC-001's `Scenario: ns#Contract resolves to Contract` says
  `ns#Contract` "MUST return the **(merged)** `Contract` schema declared by
  `contract-lifecycle-management.json`" — an accurate description of what
  `SettingsService::deepMergeConfig()` produces today (CLM's `Contract` and
  `bookkeeping-ifrs15-revenue.json`'s `Contract` deep-merge into one schema),
  written as if that merge were the *intended* shape rather than a defect.
- REQ-SIC-002's implementation note says handoff CREATEs "fail target
  validation" because of "the merged `required` lists of `Contract` and
  `ARInvoice` (union-merge debt **owned by `abstract-order-primitive`**)".
  This is a misattribution re-verified against `abstract-order-primitive`'s
  own proposal.md and design.md: that change's scope is the `Order`/
  `Subsidie`/`PurchaseOrder`/`DBA` family (per its "Order-family slug map",
  `consolidate-order-subsidie-collisions` design.md §D3) and its later
  Invoice-merge phase — it never mentions `Contract` or IFRS-15 at all. The
  `Contract` half of that merge-debt note belongs to `contracts-single-home`,
  not `abstract-order-primitive`, and is resolved by this change; the
  `ARInvoice` half remains a separate, still-open debt for whichever change
  consolidates `ARInvoice` into a canonical `Invoice`.

Once `contracts-single-home` renames the IFRS-15 side to `RevenueContract`,
`ns#Contract` resolves to CLM's `Contract` **cleanly** — not "merged," just
the one schema that was always meant to be the fleet's canonical contract —
and the `Contract`-side portion of REQ-SIC-002's validation-failure note no
longer applies.

## MODIFIED Requirements

### Requirement: REQ-SIC-001 — Shillinq schemas SHALL advertise the canonical finance kinds via `configuration.implements`, exactly one deployed schema per kind

Shillinq MUST declare the ADR-048 semantic-type markers so OpenRegister's
`SemanticTypeResolver` resolves the finance kinds to shillinq when it is
installed — following the live `Payee` → `ns#Vendor` precedent
(`lib/Settings/register.d/bookkeeping-accounts-payable-core.json`,
`configuration.implements`). Exactly ONE deployed (post-merge) schema per
kind MUST carry the marker, so resolution is deterministic without ambiguity
WARNs:

- `ARInvoice` implements `["https://openregister.app/ns#Invoice", "https://schema.org/Invoice"]`
  (NOT the quote-order-invoice `Invoice` schema — `ARInvoice` is the
  operational AR invoice: sub-ledger + GL materialisation, dunning, payment
  links, EN16931 invoice lines, existing notification rules).
- `Contract` implements `["https://openregister.app/ns#Contract"]` — the
  generic `contract-lifecycle-management` schema, and **only** that schema.
  `bookkeeping-ifrs15-revenue`'s revenue-recognition contract is named
  `RevenueContract` (`contracts-single-home`) precisely so it cannot be the
  second schema colliding on this slug; it carries no `ns#Contract` marker
  and is not a kind implementer.
- `SalesOrder` implements `["https://openregister.app/ns#SalesOrder", "https://schema.org/Order"]`.
- `Quote` implements `["https://openregister.app/ns#Quote"]` (so the
  quote → contract handoff also works shillinq-standalone; multiple
  installed providers of a SOURCE kind are acceptable per ADR-048).

The markers MUST be declared in a single ADR-037 overlay fragment (not by
editing the schemas' owning fragments), merged additively by
`SettingsService::deepMergeConfig()`.

@e2e exclude declarative register config; provider resolution is asserted server-side via the OR semantic-types API/unit tests, no UI surface

#### Scenario: ns#Invoice resolves to ARInvoice

- **GIVEN** shillinq is installed and its register imported
- **WHEN** the OR `SemanticTypeResolver` resolves
  `https://openregister.app/ns#Invoice`
- **THEN** it MUST return the `ARInvoice` schema, with no ambiguity WARN from
  a second shillinq schema implementing the same kind

#### Scenario: ns#Contract resolves to Contract

- **GIVEN** shillinq is installed and `bookkeeping-ifrs15-revenue`'s
  contract schema has been renamed to `RevenueContract`
  (`contracts-single-home`)
- **WHEN** `https://openregister.app/ns#Contract` is resolved
- **THEN** it MUST return `contract-lifecycle-management`'s `Contract`
  schema with **no ambiguity WARN** — the schema is no longer a merge of two
  full definitions, it is the one definition that was always meant to answer
  this kind

#### Scenario: Absent provider stays null-safe

- **GIVEN** shillinq is not installed (or disabled)
- **WHEN** any of the four kinds is resolved
- **THEN** the resolver MUST return `null` (per ADR-048) and the referencing
  app MUST keep working with the field unfillable — this change MUST NOT
  introduce any construct that breaks that degradation

### Requirement: REQ-SIC-002 — Shillinq SHALL accept kind-keyed handoffs that land the quote → contract → AR-invoice chain

Shillinq MUST declare the handoff chain against the LANDED ADR-051 dialect
(OpenRegister `lib/Service/Handoff/`, verified at OR HEAD 2026-07-06), keyed
to canonical kind URIs — NEVER to concrete schema slugs or app ids:

- **Consume side (provider bindings)**: the implementing schemas MUST carry
  a complete `configuration.handoffContract` binding block — the landed
  consume-side dialect, mapping each kind-contract field name to an own
  property (validated by OR's `HandoffContractBindingValidator`; a schema
  implementing a kind WITHOUT a complete binding is not a handoff provider):
  - `Contract` binds `ns#Contract` (title→title,
    counterparty→counterpartyReference, currency→currency,
    totalAmount→totalContractValue, startDate→startDate, endDate→endDate,
    source→sourceQuoteReference).
  - `ARInvoice` binds `ns#Invoice` (counterparty→customerId,
    currency→currency, totalAmount→grossAmount, dueDate→dueDate,
    source→sourceContractReference).
  - `ns#SalesOrder` carries no kind contract at OR HEAD
    (`HandoffKindContracts`) — `SalesOrder` gets the ADR-048 marker only.
- **H1** (`quote-accepted-to-contract`): declared as an
  `x-openregister-handoff` emitter entry on shillinq's own `Quote` schema
  with `trigger: lifecycle:accepted` and `targetSemanticType: ns#Contract`,
  so the quote → contract handoff works shillinq-standalone; the
  pipelinq-side quote emitter is authored with the pipelinq produce change
  (no quote schema exists at pipelinq HEAD). The mapping keys are the
  `ns#Contract` kind-contract fields (all mandatory fields mapped, per OR's
  `HandoffAnnotationValidator`).
- **H2** (`contract-to-initial-invoice`): declared as an emitter entry on
  `Contract` with `targetSemanticType: ns#Invoice` and `trigger: manual`.
  The landed v1 dialect has NO condition grammar, so the originally intended
  auto-trigger "on transition to `active` when `direction = outbound` AND
  handoff provenance present" is not expressible — a `lifecycle:active`
  trigger would also draft AR invoices for inbound/purchase contracts.
  Manual is the narrowest safe scope; it MAY be upgraded to a conditional
  lifecycle trigger when the dialect grows conditions. Idempotency is
  likewise operator-gated: the v1 engine performs no correlation-id dedupe.
  Recurring billing MUST remain with `RecurringInvoiceProfile`
  (`recurring-invoicing.json`) — H2 creates only the initial invoice.

Field translation is exclusively contract-field → binding → own property
(the emitter never names a concrete target property); every bound own
property MUST exist on the merged schemas at HEAD. NOTE (runtime reality,
re-verified for this delta): once `contracts-single-home` lands,
`Contract`'s `required` list is CLM's own four fields only — the
`ns#Contract` handoff CREATE (H1) validates cleanly against it. The
remaining validation-failure risk this note originally described is
narrowed to `ARInvoice`'s `required` list, which still unions with
whatever full schema eventually merges under that slug (`ARInvoice`
union-merge debt, owned by whichever change consolidates `ARInvoice` into a
canonical `Invoice` — **not** `contracts-single-home`, and **not**, contrary
to the prior text of this note, `abstract-order-primitive`, whose scope is
the `Order`/`Subsidie`/`PurchaseOrder`/`DBA` family per its own design.md
and never mentions `Contract` or `ARInvoice`). H2 (`contract-to-initial-
invoice`) MAY still fail target validation until that separate debt is
resolved and/or an ADR-041 intake listener lands.

@e2e exclude cross-app backend handoff; asserted via OR-side integration tests of the semantic-object-handoff engine, no shillinq UI surface in this change

#### Scenario: Accepted pipelinq quote lands as a shillinq contract

- **GIVEN** pipelinq and shillinq are installed and the OR handoff engine is
  live
- **WHEN** a pipelinq object whose schema implements `ns#Quote` reaches its
  accepted state
- **THEN** a shillinq `Contract` MUST be created via the kind-keyed H1 rule,
  populated per the declared mapping, in its initial lifecycle state,
  carrying provenance to the source quote, **and validation MUST pass using
  only `Contract`'s own `required` fields** (no longer blocked by a merged
  `required` list demanding IFRS-15 fields)

#### Scenario: Activated handed-off contract yields exactly one draft AR invoice

- **GIVEN** a shillinq `Contract` created by H1 (provenance present) with
  `direction = outbound` that has been activated
- **WHEN** the operator triggers the `contract-to-initial-invoice` handoff
  (manual trigger — the v1 dialect cannot condition an auto-trigger on
  direction/provenance)
- **THEN** ONE `ARInvoice` MUST be created in `draft` with provenance to the
  contract, subject to the separate, still-open `ARInvoice` union-merge
  debt noted above; the operator-gated manual trigger is the v1 idempotency
  boundary (the engine performs no correlation-id dedupe); recurring
  schedules are NOT created by this rule

#### Scenario: Handoff keys survive slug changes

- **GIVEN** the H1/H2 declarations
- **WHEN** they are inspected
- **THEN** they MUST reference kinds (`ns#Quote`, `ns#Contract`,
  `ns#Invoice`) and MUST NOT contain a pipelinq schema slug, a shillinq
  schema slug as a resolution key, or an app id as the routing key
