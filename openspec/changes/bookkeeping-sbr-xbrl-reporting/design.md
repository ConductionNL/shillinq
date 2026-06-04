# Design — SBR / XBRL Reporting

## Context

Dutch financial reporting regulations (Belastingdienst, DNB) mandate
XBRL (eXtensible Business Reporting Language) submission for annual
financial statements and tax filings since 2012. Standard Business
Reporting (SBR) is the official Dutch wrapper around XBRL GL (General
Ledger) taxonomy.

Shillinq's bookkeeping engine must support:
1. **XBRL Taxonomy Management** — load official XBRL GL taxonomy versions
   published annually by Belastingdienst.
2. **SBR Document Lifecycle** — manage filing state (`draft → validated →
   submitted → approved` / `rejected`).
3. **Account-to-XBRL Mapping** — transform chart-of-accounts codes to
   XBRL GL concepts.
4. **Compliance Validation** — enforce mandatory field presence, mapping
   coverage, and GL completeness before filing.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire SBR/XBRL-reporting surface as **declarative
  metadata** — schemas + lifecycle + aggregations + manifest entries —
  per ADR-031.
- Make the spec a **Dutch bookkeeper-readable contract** — regulatory
  filing flow recognisable end-to-end (taxonomy load, GL validation,
  mapping, filing submission).
- Declare the XBRL GL taxonomy structure and mapping rules so future
  T4 capability can attach outbound generation additively.
- Support **multiple XBRL GL taxonomy versions** (annual publication
  cycle) side-by-side.
- Enforce **pre-filing validation** declaratively via aggregations —
  no PHP validation service.

## Non-Goals

- No XBRL document generation or XML emission — T4.
- No submission to Belastingdienst or DNB — T4.
- No multi-entity consolidation — separate capability.
- No real-time regulatory reporting — annual focus only.
- No VAT/BTW or tax calculation automation — separate capability.

## Decisions

### D1 — XBRL GL Taxonomy is a versionable register

`XBRLTaxonomy` holds official XBRL GL concepts and mappings published
annually by Belastingdienst. Each fiscal year references its active
taxonomy version. Multiple versions coexist; operator selects the
active version per administration at fiscal year start.

### D2 — SBR filing is a lifecycle managed by OpenRegister

`SBRDocumentType` declares the filing state machine (`draft →
validated → submitted → approved` / `rejected`) consuming OR's
`x-openregister-lifecycle`. Transitions are gated by aggregation
validation rules (REQ-SBR-007).

### D3 — XBRL Mapping is schema-driven, not imperative

`XBRLMapping` records the transformation from Shillinq
`Account.accountNumber` to XBRL GL concept URI. No PHP mapping
service. Completeness validation (all GL accounts mapped) is a
pre-filing aggregation predicate.

### D4 — Compliance validation is declarative aggregation

Pre-filing validation checks are declared as `x-openregister-aggregations`:
- GL completeness: all GL entries assigned to accounts with XBRL mapping.
- Mapping coverage: all active GL accounts have XBRL mappings.
- Mandatory field presence: `SBRDocumentType.filingType` set,
  `FiscalYear` start/end set.
- Balance check: GL debit = credit (per chart-of-accounts standard).

No PHP `XBRLValidator` service.

### D5 — XBRL GL taxonomy structure is metadata, not computed

T3 declares the XBRL GL concept names, URIs, and hierarchy from the
official Belastingdienst taxonomy specification. T3 does not compute,
transform, or validate GL-to-XBRL mapping — that ships with T4 XBRL
outbound generation.

### D6 — Belastingdienst / DNB submission contract is declared

`SBRDocumentType.submissionEndpoint` and `.authMethod` define the
outbound contract (endpoint URL, required fields, auth scheme). T3
does not call the endpoint; T4 XBRL Outbound implements the actual
submission.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| SBR filing lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `SBRDocumentType` (`draft → validated → submitted → approved` / `rejected`); transitions gated by aggregation predicates |
| XBRL GL taxonomy versioning | Schema `taxonomyVersion` + `effectiveDate` | Multiple `XBRLTaxonomy` versions coexist; operator selects per administration at fiscal year start |
| Account-to-XBRL mapping | `XBRLMapping` register with source/target URIs | No imperative mapping service; aggregation validates completeness (all accounts mapped) |
| Pre-filing compliance validation | OR `x-openregister-aggregations` | 4 validation predicates: GL completeness, mapping coverage, mandatory fields, GL balance |
| GL entry data for validation | T1 `add-shillinq-general-ledger` (`JournalEntry` / `GLTransaction`) | Aggregations query GL entries by account; validate against active `XBRLTaxonomy` |
| Chart-of-accounts for mapping target | T2 `add-shillinq-chart-of-accounts` (`Account.accountNumber`) | `XBRLMapping` source = `Account.id`; target = XBRL GL concept URI from active `XBRLTaxonomy` |
| Filing status tracking | OR `x-openregister-lifecycle` state transitions | `SBRDocumentType.filingState` auto-managed by lifecycle |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on `SBRDocumentType` lifecycle transitions and `XBRLMapping` changes |
| Manifest navigation | T1 manifest pattern | 3 entries (XBRL Taxonomies, SBR Documents, Mapping Validation) + their pages |
| Regulatory endpoint contract | Schema documentation | `SBRDocumentType.submissionEndpoint`, `.authMethod`, `.schema` documented; no outbound implementation in T3 |

**Net new code in implementation cycle**: 3 schema declarations + 1
lifecycle block + 4 aggregation predicates + 3 manifest entry pairs.
At most 1 single-method PHP validation guard (gated by ADR-031
exception if OR's aggregation system is not yet stable).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| SBR filing lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine; no domain-specific business logic |
| Account-to-XBRL mapping | Schema declarations + no service | Mapping is data (register records), not logic |
| Pre-filing validation | Declarative (`x-openregister-aggregations` predicates) | Pure SUM/COUNT/CHECK operations; no custom logic |
| XBRL taxonomy versioning | Versionable register schema | Multiple versions coexist; operator selection via UI |
| Belastingdienst submission endpoint | Schema contract declaration | Endpoint + auth method documented; outbound logic in T4 |

No service class authored in this envelope (subject to ADR-031
exception: at most one single-method validation guard if OR's
aggregation system needs a fallback).

## Seed Data

Three example `XBRLTaxonomy` records representing the current (2026)
and prior (2025) official XBRL GL taxonomy versions published by
Belastingdienst:

**XBRLTaxonomy seed objects (2 examples):**

1. **2026 XBRL GL (Current)**
   - taxonomyId: "xbrl-gl-2026"
   - name: "XBRL GL 2026 — Belastingdienst Official"
   - publicationDate: 2025-12-15
   - effectiveDate: 2026-01-01
   - taxonomyVersion: "2026-01"
   - status: "active"

2. **2025 XBRL GL (Prior Year)**
   - taxonomyId: "xbrl-gl-2025"
   - name: "XBRL GL 2025 — Belastingdienst Official"
   - publicationDate: 2024-12-15
   - effectiveDate: 2025-01-01
   - taxonomyVersion: "2025-01"
   - status: "active"

Two example `SBRDocumentType` records representing typical Dutch filing scenarios:

**SBRDocumentType seed objects (2 examples):**

1. **Annual Financial Statement**
   - name: "Jaarverslag (Annual Report)"
   - code: "JAARVERSLAG"
   - description: "Annual financial statement filing for all entities"
   - applicableEntityTypes: ["BV", "NV", "Eenmanszaak"]
   - filingDeadline: 2026-05-31
   - submissionEndpoint: "https://belastingdienst.nl/xbrl-filing/submit"
   - status: "active"

2. **Tax Declaration**
   - name: "Belastingaangifte (Tax Filing)"
   - code: "BELASTINGAANGIFTE"
   - description: "Mandatory tax filing for Dutch entities"
   - applicableEntityTypes: ["BV", "NV", "Eenmanszaak", "CV"]
   - filingDeadline: 2026-04-30
   - submissionEndpoint: "https://belastingdienst.nl/tax-filing/submit"
   - status: "active"

Two example `XBRLMapping` records:

**XBRLMapping seed objects (2 examples):**

1. **Assets Mapping**
   - sourceAccount: (FK to Account "1000" — Current Assets)
   - targetXBRLConcept: "http://xbrl.gl/concept/CurrentAssets"
   - taxonomyVersion: "2026-01"
   - mappingDate: 2026-01-01
   - status: "active"

2. **Revenue Mapping**
   - sourceAccount: (FK to Account "4000" — Operating Revenue)
   - targetXBRLConcept: "http://xbrl.gl/concept/OperatingRevenue"
   - taxonomyVersion: "2026-01"
   - mappingDate: 2026-01-01
   - status: "active"

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Belastingdienst annual taxonomy updates lag implementation cycle | `XBRLTaxonomy.taxonomyVersion` + seed data updated quarterly; multi-version coexistence handles transition grace periods |
| Account mapping incomplete at filing deadline | Pre-filing aggregation validation (REQ-SBR-007) rejects incomplete mappings; operator workflow flags unmapped accounts early |
| XBRL GL taxonomy schema complexity difficult to express in JSON Schema | Schema references official "XBRL GL Conceptual Framework" for hierarchy; simplified concept-level mapping in `XBRLMapping` |
| Regulatory endpoint authentication (OAuth2 / mutual TLS) not yet finalized by Belastingdienst | T3 declares `SBRDocumentType.authMethod` (placeholder); T4 implements per Belastingdienst guidance at implementation time |
| GL balance check aggregation slow with large chart-of-accounts | Pre-aggregated cache via OR's aggregation extension if performance gates trip; per-spec optimization in implementing cycle |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the three
   schemas (additive — no existing schema changes).
2. Seed XBRL GL taxonomy objects (2026, 2025 versions) loaded via
   `importFromApp()` repair step.
3. `src/manifest.json` is patched with 3 new menu entries + their
   pages (additive).

Down-direction: registers are non-destructive — reverting removes
the manifest entries; XBRL mappings remain queryable but unreferenced.

## Open Questions

1. **Belastingdienst taxonomy update cadence** — published annually
   (December); default load strategy TBD (import at fiscal year start
   vs. quarterly check). Resolved during implementing cycle.
2. **DNB real-time reporting requirements** — T3 is annual-only;
   real-time reporting is out-of-scope but may be future T5 capability.
3. **Multi-entity consolidation** — separate capability; T3 assumes
   single-entity annual filings.
4. **Authentication to Belastingdienst endpoint** — OAuth2 vs. mutual
   TLS vs. PKI certificate. Resolved during T4 (XBRL Outbound) design.
