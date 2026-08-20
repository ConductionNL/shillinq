# semantic-schema-markers Specification

## Purpose
TBD - created by archiving change fix-semantic-org-markers. Update Purpose after archive.
## Requirements
### Requirement: REQ-SEM-001 Schema semantic markers SHALL be valid, consistent `schema:` CURIEs

Every `x-schema-org` marker on a Shillinq OpenRegister schema MUST be a valid
CURIE of the form `schema:<Type>` (ADR-048 schema.org marker convention). A bare
type name without the `schema:` prefix MUST NOT be used, because fleet consumers
(ADR-051 handoffs, MDM type-matching, GEMMA/softwarecatalog mappers) resolve
markers by their `schema:` CURIE and silently skip bare values.

Contract-typed schemas MUST use `schema:Contract` consistently — the IFRS 15
customer-contract schema MUST NOT be marked `schema:CreativeWork`, so it agrees
with the CLM `Contract` (`schema:Contract` / `ns#Contract`) and the
`semantic-invoice-consume` `ns#Contract` handoff kind.

Every order-family schema MUST carry an `x-schema-org` marker, including the
generic base `Payment` schema, which currently has none.

#### Scenario: All markers are prefixed CURIEs

- **WHEN** every `x-schema-org` value across `lib/Settings/register.d/*.json` and `lib/Settings/shillinq_register.json` is inspected
- **THEN** each is a `schema:`-prefixed CURIE (or an `ns#` fleet CURIE), and none is a bare type name
- **AND** in `bookkeeping-quote-order-invoice.json` the former bare values are now `schema:Offer`, `schema:Order`, `schema:ParcelDelivery`, and `schema:Invoice` (both invoice sites)
- @e2e exclude static schema metadata, not browser-observable — verified by inspection / a lint over register.d

#### Scenario: The IFRS 15 Contract is marked schema:Contract

- **WHEN** the `Contract` schema in `bookkeeping-ifrs15-revenue.json` is inspected
- **THEN** its `x-schema-org` is `schema:Contract`, not `schema:CreativeWork`
- **AND** it is consistent with the CLM `Contract` marker
- @e2e exclude static schema metadata — verified by inspection

#### Scenario: The generic Payment schema carries a marker

- **WHEN** the base `Payment` schema in `zz-order-base.json` is inspected
- **THEN** it declares an `x-schema-org` marker that is a valid `schema:` CURIE (e.g. `schema:PayAction` or `schema:MoneyTransfer`)
- @e2e exclude static schema metadata — verified by inspection

