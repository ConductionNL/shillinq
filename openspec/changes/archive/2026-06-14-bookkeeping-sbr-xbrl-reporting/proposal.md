# Proposal: bookkeeping-sbr-xbrl-reporting

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`XBRLTaxonomy`, `SBRDocumentType`, `XBRLMapping`) +
`x-openregister-lifecycle` for XBRL document filing state management
+ aggregations for compliance validation. Implementation lands via
separate `opsx-apply` cycle.

## Summary

Introduce the **SBR (Standard Business Reporting) / XBRL reporting**
capability for Shillinq as one of the T3 compliance + reporting
capabilities (per `adr-001-bookkeeping-tier-roadmap.md`). This
capability enables Dutch financial institutions to report financial
statements using XBRL (eXtensible Business Reporting Language)
taxonomy and Standard Business Reporting (SBR) formats mandated by
the Dutch tax authority (Belastingdienst) and Central Bank of the
Netherlands (DNB). The change declares the `XBRLTaxonomy`,
`SBRDocumentType`, and `XBRLMapping` registers; the SBR filing
lifecycle (`draft → validated → submitted → approved` / `rejected`)
consuming OpenRegister lifecycle extensions; XBRL document
validation rules as aggregation predicates; and the mapping layer
between Shillinq chart-of-accounts and XBRL GL (General Ledger)
taxonomy.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md)
(GL entries for XBRL validation),
[`add-shillinq-chart-of-accounts`](../add-shillinq-chart-of-accounts/proposal.md)
(account codes to map to XBRL GL taxonomy).

## Motivation

Dutch financial reporting regulations require annual SBR/XBRL
submission for certain entity types. Standard Business Reporting has
been adopted as the mandatory format for annual financial statement
and tax filing submissions to Belastingdienst and DNB since 2012.
Shillinq's bookkeeping engine must natively support XBRL taxonomy
management, document generation, validation, and filing lifecycle to
meet Dutch regulatory requirements (per the "Handboek voor het
Financieel Jaarverslag" and SBR specifications).

Per ADR-022, XBRL lifecycle state management and validation rules
are declarative; per ADR-031, XBRL validation and mapping are
expressed as aggregations and schema field mappings, not imperative
PHP report services.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-sbr-xbrl-reporting`); declares 3 new registers
  (`XBRLTaxonomy`, `SBRDocumentType`, `XBRLMapping`) with filing
  lifecycle and validation aggregations; adds 3 manifest navigation
  entries (XBRL Taxonomies, SBR Documents, Mapping Validation).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-aggregations`, and
  relations.
- [ ] Project: docudesk — no source changes; XBRL document PDF
  export referenced per `bookkeeping-document-attachment-integration`.

## Scope

### In Scope

- One new capability spec (`bookkeeping-sbr-xbrl-reporting`) —
  see the `specs/` folder.
- The `XBRLTaxonomy` register storing official XBRL GL (General
  Ledger) taxonomy versions published by Belastingdienst, including
  taxonomy identifier, name, publication date, and taxonomy
  structure metadata.
- The `SBRDocumentType` register defining SBR document types
  (annual report, tax filing, VAT declaration) and filing rules
  (mandatory for entity types, submission deadlines).
- The `XBRLMapping` register containing the transformation rules
  mapping Shillinq chart-of-accounts (`Account.accountNumber`) to
  XBRL GL taxonomy concepts.
- The SBR filing lifecycle (`draft → validated → submitted →
  approved` / `rejected`) consuming OpenRegister lifecycle
  extensions for state management.
- XBRL document validation rules as aggregation predicates: GL
  completeness check, account mapping coverage, mandatory field
  validation, and compliance rule enforcement.
- Integration point with Belastingdienst's XBRL submission endpoint
  and DNB's regulatory reporting portal (endpoints declared; actual
  outbound submission in T4).

### Out of Scope

- **XBRL outbound generation and submission** — T4. T3 declares
  the validation rules and mapping shape but does NOT generate or
  transmit XBRL documents to Belastingdienst / DNB.
- **Implementation code** — spec-only change. PHP services,
  controllers, Vue components, tests, and CI changes land via
  separate `opsx-apply` cycle.
- **Multi-entity consolidation reporting** — separate capability.
- **Real-time regulatory reporting** — out-of-scope for T3 (annual
  filing focus).

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-sbr-xbrl-reporting`** — declares the three registers,
the SBR filing lifecycle, validation aggregation rules, the mapping
layer, and the submission contract.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-SBR-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.66`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 new schemas
  (`XBRLTaxonomy`, `SBRDocumentType`, `XBRLMapping`); declares
  lifecycle on `SBRDocumentType`, aggregations on validation rules.
- `src/manifest.json` — adds 3 navigation entries + their
  `type: index` + `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: at most one
  validation guard if OpenRegister's aggregation system is not yet
  stable).
- No new bespoke Vue components beyond schema-driven CRUD.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle` and
  `x-openregister-aggregations` for lifecycle and validation rules.
- **T1 General Ledger** — depends on
  `add-shillinq-general-ledger` for GL entry data required by
  XBRL GL mapping and validation.
- **T2 Chart of Accounts** — depends on
  `add-shillinq-chart-of-accounts` for account code to XBRL GL
  concept mappings.
- **T4 XBRL Outbound** — future capability; T3 provides the
  validated shape and mapping contract.

## Risks

### Risk 1: XBRL GL taxonomy schema stability

**Severity**: Medium
**Mitigation**: Belastingdienst publishes XBRL GL annual updates.
The spec declares a `taxonomyVersion` field and date-based effective
dating; the register holds multiple versions side-by-side. Migration
to new taxonomy versions during a fiscal year is a controlled
operator action. The spec references the official "XBRL GL
Conceptual Framework" (EBA/DNB standard).

### Risk 2: Account mapping coverage incomplete at filing deadline

**Severity**: Medium-High
**Mitigation**: REQ-SBR-007 declares a pre-filing validation
aggregation that rejects submission if any GL account lacks XBRL
mapping. Operator workflow flags unmapped accounts early; mapping is
completed before filing. The validation is declarative (no PHP
validation service).

### Risk 3: Belastingdienst submission endpoint stability

**Severity**: Low
**Mitigation**: T3 declares the submission contract (endpoint, schema,
auth). Actual outbound submission (T4) includes retry logic, error
handling, and submission webhook tracking. T3 does not attempt to call
the endpoint.

### Risk 4: Dutch regulatory changes to SBR format

**Severity**: Low
**Mitigation**: Belastingdienst issues guidance updates; spec is
versionable via `taxonomyVersion` and `SBRDocumentType.version`.
The register is designed for multi-version coexistence.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive — XBRL mappings remain queryable.

## Open Questions

1. **XBRL GL taxonomy version cadence** — Belastingdienst publishes
   annually. Default: adopt the current year's taxonomy at fiscal
   year start; allow operator override for multi-year filings.
   Resolved during implementing cycle's UX review.
2. **Belastingdienst endpoint authentication** — OAuth2 / mutual TLS /
   PKI certificate. Resolved during T4 (XBRL Outbound) design.
3. **Multi-entity consolidation** — out-of-scope for T3; separate
   capability (future).
4. **Real-time regulatory reporting** — out-of-scope for T3
   (annual-focus only).
