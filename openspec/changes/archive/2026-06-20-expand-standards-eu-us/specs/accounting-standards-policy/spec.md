# accounting-standards-policy

Expands the standards catalogue for the EU + US market and adds the additive
digital-compliance model.

## MODIFIED Requirements

### Requirement: REQ-ASP-001 — StandardsPolicy schema

The system SHALL provide a `StandardsPolicy` schema (OpenRegister register
fragment per ADR-037) holding an ordered list of frameworks, each with a `key`,
an `enabled` boolean and an integer `precedence`. The `key` enum SHALL cover the
EU + US bases of accounting: `ifrs`, `ifrs-eu`, `dutch-gaap`, `de-hgb`, `fr-pcg`,
`it-oic`, `es-pgc`, `dutch-tax`, `us-gaap`, `us-tax-basis`, `us-cash-basis`,
`us-modified-cash`, `us-frf-smes`, `ipsas`, `bbv`, `us-gasb`, `us-fasab`, `esrs`,
`ifrs-sustainability`.

#### Scenario: extended enum is accepted

- **WHEN** a policy enables `de-hgb` and `us-gasb`
- **THEN** the schema accepts both keys and the resolver ranks them like any other

#### Scenario: digital-compliance keys are NOT bases of accounting

- **WHEN** an author tries to add `saf-t` or `vida` as a StandardsPolicy framework key
- **THEN** it is rejected — those are ComplianceCatalogue obligations (additive facts), not ranked bases

## ADDED Requirements

### Requirement: REQ-ASP-004 — versioned static ComplianceCatalogue (additive, not OR)

The system SHALL model digital-compliance / tax-data obligations as a **versioned
static catalogue in code** (`ComplianceCatalogue`, stamped with a `VERSION`/`asOf`),
NOT as OpenRegister config — because they are regulatory **facts** (identical for
every tenant, changing only with regulation). The catalogue SHALL be read-only and
**additive** (every applicable obligation is met — no ranking, no precedence
resolver) and SHALL expose query helpers for business logic (`applicableTo(country)`,
`byType()`). The only per-tenant input — which jurisdictions an administration
operates in — SHALL be derived from existing data, introducing no per-tenant schema.

#### Scenario: catalogue is versioned and well-formed

- **WHEN** business logic reads the catalogue
- **THEN** it returns a version string and entries each carrying `id`, `jurisdiction`, `type`, `standard`, `status` and `effectiveDate`, with unique ids

#### Scenario: applicability is derived per jurisdiction, additively

- **WHEN** `applicableTo("NL")` is called
- **THEN** it returns NL's own obligations plus EU-wide obligations (NL is an EU member), excluding other countries' mandates and US-only entries — all applying simultaneously with no "winner"

#### Scenario: non-EU jurisdiction excludes EU-wide obligations

- **WHEN** `applicableTo("US")` is called
- **THEN** only US entries are returned (EU-wide obligations do not leak in)

### Requirement: REQ-ASP-005 — Operative rule corpus (RuleCatalogue, static code)

The system SHALL provide a versioned static **rule corpus** — the operative
bookkeeping rules derived from standards and laws (invoicing/EN 16931, VAT,
retention, ledger integrity, chart of accounts, reporting, and the
recognition/measurement/presentation requirements of the frameworks). Rules SHALL
be stored as per-domain JSON files under `lib/Standards/rules/` (one obligation
per rule, each carrying `id`, `domain`, `jurisdiction`, `framework`, `source`,
`statement`, `severity`, `machineCheckable`, `effectiveDate`, `sourceUrl`) and
loaded/merged by `RuleCatalogue`, with globally-unique ids. The corpus is the
machine-readable source for turning rules into specs/validations.

#### Scenario: corpus loads and is queryable

- **WHEN** business logic calls `RuleCatalogue::byJurisdiction("NL")`
- **THEN** it returns NL + EU-wide + global rules, with `machineCheckable()` a subset, all ids unique

### Requirement: REQ-ASP-006 — Standards machinery defines behaviour, not navigation

The rule corpus, the compliance catalogue and policy resolution SHALL be consumed
by business logic / validation / spec-generation as **reference data and
behaviour** — they SHALL NOT add menu entries or pages. The only standards UI is
the existing apply/order settings screen (`StandardsPolicyEditor`, a per-tenant
*choice*). Any surfacing of rule or compliance *status* SHALL be **report-only**
and is out of scope unless a report is explicitly required.

#### Scenario: no navigation is added for reference data

- **WHEN** the rule corpus or compliance catalogue is added or extended
- **THEN** no new menu item or page is introduced — only static data + accessors consumed by behaviour

#### Scenario: choice keeps its one screen

- **WHEN** an administrator needs to set which frameworks apply and in what order
- **THEN** they use the single existing `StandardsPolicyEditor` settings screen — the only standards-related UI
