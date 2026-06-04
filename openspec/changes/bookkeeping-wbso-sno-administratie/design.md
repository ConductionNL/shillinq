# Design — Financial Administration Foundation

**status: draft**

## Context

Shillinq enables Dutch SMEs to manage their bookkeeping in a unified, compliant system. Without a foundational financial data model, all upstream specs (tier 2+: accounts payable/receivable, general ledger, tax filing) lack a single source of truth, forcing application code to invent its own persistence patterns.

This change establishes the **core registers** and **document lifecycle** that every downstream spec references. It is the tier-1 foundation per ADR-001.

## Goals

- **Single source of truth** — all financial data flows through `Account`, `Transaction`, `Document` registers
- **Audit trail immutability** — every create/update/delete is logged per ADR-022
- **RGS compliance** — accounts conform to the Dutch chart-of-accounts standard
- **Lifecycle clarity** — documents have clear state transitions (draft → filed → archived)
- **Query efficiency** — core indexes enable fast lookups by date, amount, status
- **Regulatory readiness** — structure enables turnover, deduction, and tax-position aggregations

## Non-Goals

- **GL posting logic** — deferred to tier-2 `bookkeeping-general-ledger`
- **Multi-currency support** — phase 2; phase 1 is EUR-only
- **Period close automation** — tier-3+
- **Tax calculation formulas** — tier-2+ (VAT, income tax, etc.)

## Decisions

### D1 — Three registers (`Account`, `Transaction`, `Document`), NOT a single journal table

Per ADR-031 and the reuse analysis below, separating these entities keeps the model normalized:
- `Account` is master data (chart of accounts), shared across years
- `Transaction` is time-series data (events), per-year sharded
- `Document` is file metadata (storage pointers), lifecycle-managed separately

The alternative (a single denormalized journal table) was rejected — mixes master data, transactions, and file references; violates schema-normalization principles.

### D2 — Document lifecycle with explicit `filed` and `archived` states

Documents flow: `draft → filed → archived`. The `filed` state signals "this document has been submitted for compliance" (e.g., tax-form filed with authorities). The `archived` state indicates legal retention is satisfied and the document may be purged per Archiefwet.

Per ADR-022, the `filed → archived` transition enforces an approval-workflow or time-based gate (e.g., "archivable after 7 years"). This prevents accidental deletion of legally required documents.

### D3 — RGS accounts with parent hierarchy for consolidation

Dutch SMEs use the RGS (Referentie Grootboek Schema) standard for chart-of-accounts codes. The `Account` register declares:
- `accountNumber`: RGS code (e.g., "1000" for bank, "4100" for sales revenue)
- `parentAccountNumber`: FK to parent account for hierarchy navigation (e.g., "1000" is child of "1")
- `accountType`: semantic category (assets, liabilities, equity, revenue, expenses) for rollup aggregation

Hierarchy depth is capped at 5 levels; parent lookup uses indexed queries.

### D4 — Transaction as an immutable event, status = operational state

`Transaction` records a financial event. Its `status` field is NOT a state machine (per D2's lifecycle distinction):
- `draft` — entered but not yet posted to the GL
- `posted` — recorded in general ledger, immutable from this point
- `reversed` — a reversal entry was created; original is logically deleted

Per ADR-022, `posted → reversed` requires an approval-workflow (only authorized users reverse).

### D5 — Document references file storage via docudesk, NOT local disk

`Document` stores metadata only:
- `documentType` and `documentNumber` for human lookup
- `fileReference` (URI) pointing to the file in docudesk
- `status` for lifecycle

This decouples app logic from file I/O; storage quota and retention are managed by docudesk.

### D6 — EUR-only in phase 1, multi-currency via future ADR

`Transaction.amount` is a single decimal field in EUR. Multi-currency support (phase 2) will introduce currency codes and FX conversion logic via a future ADR (linked from the spec). Phase 1 does not block multi-currency; the foundation is ready for it.

## Reuse Analysis

| Capability | What exists | Reuse strategy |
|---|---|---|
| Data persistence | OpenRegister CRUD | `Account`, `Transaction`, `Document` are registers; no custom Mapper/Entity. |
| Audit trail | OR audit-trail-immutable (ADR-022) | Every transaction and document change is logged; users cannot modify history. |
| Lifecycle engine | `x-openregister-lifecycle` (ADR-031) | `Document` declares `draft → filed → archived` with transition guards. |
| Approval workflow | OR approval-workflow (ADR-022) | `Transaction` reverse and `Document` archive transitions require approval. |
| RBAC | OR authorization | Read: `bookkeeper`, `auditor`, `administrator`. Write: `bookkeeper`, `administrator`. |
| Chart-of-accounts | RGS standard (government) | `Account` properties conform to Dutch standard. |
| File storage | docudesk (ADR-019) | `Document.fileReference` points to docudesk URI. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4) | 1 entry (Bookkeeping) behind general feature flag. |

**Net new code in implementation cycle**: 3 schema declarations + 1 lifecycle declaration + 2 approval-workflow bindings + 1 docudesk template + 1 manifest entry. No new PHP service.

## Seed Data

### Account seed (3–5 example accounts for testing)

```json
[
  {
    "accountNumber": "1000",
    "name": "Kas en bank",
    "accountType": "assets",
    "parentAccountNumber": "1",
    "status": "active",
    "currency": "EUR"
  },
  {
    "accountNumber": "4100",
    "name": "Omzet diensten",
    "accountType": "revenue",
    "parentAccountNumber": "4",
    "status": "active",
    "currency": "EUR"
  },
  {
    "accountNumber": "6000",
    "name": "Huisvestingskosten",
    "accountType": "expenses",
    "parentAccountNumber": "6",
    "status": "active",
    "currency": "EUR"
  }
]
```

### Transaction seed (2 example transactions)

```json
[
  {
    "transactionNumber": "INV-2026-001",
    "transactionType": "invoice",
    "transactionDate": "2026-01-15",
    "amount": 1500.00,
    "description": "Invoice to Customer ABC for consulting",
    "status": "posted"
  },
  {
    "transactionNumber": "REC-2026-001",
    "transactionType": "receipt",
    "transactionDate": "2026-01-20",
    "amount": 250.00,
    "description": "Office supplies receipt",
    "status": "draft"
  }
]
```

### Document seed (1 example document)

```json
[
  {
    "documentType": "invoice",
    "documentNumber": "INV-2026-001",
    "documentDate": "2026-01-15",
    "status": "filed",
    "fileReference": "docudesk://invoices/inv-2026-001.pdf"
  }
]
```

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Account hierarchy lookup N+1 queries | Cached parent-child index in OR; depth ≤ 5. |
| Transaction volume grows unbounded | Per-year Administration sharding; query indexes on (administrationId, transactionDate). |
| Document lifecycle bypass (draft marked filed without content) | `filed` transition requires file upload confirmation via approval-workflow. |
| Multi-currency future complexity | Phase 1 is EUR-only; phase 2 ADR will address FX rates, conversion, and reconciliation. |
| Compliance audit trail tamper risk | OR audit-trail-immutable enforces database-level immutability; signed entries per ADR-022. |

## Compliance & Standards

- **RGS (Referentie Grootboek Schema)**: `Account.accountNumber` follows RGS hierarchy
- **Dutch Accounting Standard (DAS)**: `Transaction` structure supports NL-GAAP period-close workflows
- **Archiefwet 1995**: `Document` lifecycle enforces 7-year retention before archive
- **BTW (VAT)**: Transaction metadata supports quarterly VAT declaration filing
