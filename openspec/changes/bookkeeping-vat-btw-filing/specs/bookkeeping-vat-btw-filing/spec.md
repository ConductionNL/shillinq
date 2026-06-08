# Spec: bookkeeping-vat-btw-filing

**Part of:** [`bookkeeping-vat-btw-filing`](../proposal.md) (T3)

**Depends on:**
- `bookkeeping-chart-of-accounts` (T1) — `Account` schema with `vatApplicable` flag
- `bookkeeping-journal-entries` (T1) — `JournalEntry` / `GLTransaction` for VAT source data
- `bookkeeping-accounts-payable-core` (T2) — AP invoices with VAT marking
- `bookkeeping-accounts-receivable-core` (T2) — AR invoices with VAT marking
- `bookkeeping-trial-balance` (T2) — GL account balances for reconciliation

**Referenced by:**
- `bookkeeping-bbv-compliance` (T3) — VAT return shape for BBV reporting
- `bookkeeping-vpb-corporate-tax` (T4-specialized) — VAT reconciliation for corp tax

---

## Overview

This spec defines the VAT/BTW (Value Added Tax) return and filing
capability for Shillinq. VAT compliance is mandatory for Dutch
registered businesses. The Belastingdienst requires quarterly (or
monthly for certain regimes) electronic filing with exact VAT amounts
by rate and type.

**Scope:**
1. Automatic VAT tracking from GL transactions (VAT collected/paid)
2. VAT return preparation by period and regime
3. VAT reconciliation aggregations
4. VAT return lifecycle and submission workflow
5. Support for regime variants: standard rate, reduced rates, KOR
   (small-business exemption), reverse-charge

**Out of scope:**
- Electronic filing to Belastingdienst (T4 or integration)
- VAT posting automation on invoice creation (T2 AP/AR responsibility)
- Advanced regimes (margin scheme, consignment, etc.)
- Multi-country VAT schemes beyond intra-EU reverse-charge

---

## Data Model

### VATReturn (Register)

**Schema.org:** `schema:DigitalDocument`

A VAT return for a fiscal period, tracking total VAT collected, VAT
paid, balance, and submission status.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| returnNumber | string | Yes | Unique return identifier (e.g., NL-2026-Q1) |
| period | enum | Yes | Reporting period: `quarter`, `month`, `year` |
| periodYear | integer | Yes | Fiscal year (e.g., 2026) |
| periodNumber | integer | Yes | Period within year (Q: 1-4, M: 1-12) |
| startDate | date | Yes | Period start date (inclusive) |
| endDate | date | Yes | Period end date (inclusive) |
| regime | enum | Yes | VAT regime: `standard`, `kor`, `reverse-charge` |
| administrationId | string | Yes | FK to `Administration` owning this return |
| statusCode | string | Yes | Lifecycle status: `draft`, `submitted`, `verified`, `filed` |
| submissionDate | datetime | No | When return was electronically submitted |
| verificationDate | datetime | No | When authority verified receipt |
| filingReference | string | No | Tax authority's filing acknowledgment ID |
| totalVATCollected | MonetaryAmount | No | Total VAT payable (sales + services, exc. reverse-charge) |
| totalVATPaid | MonetaryAmount | No | Total VAT deductible (purchases + services, reverse-charge) |
| vatBalance | MonetaryAmount | No | Balance: totalVATPaid - totalVATCollected (negative = owe) |
| totalTaxableAmount | MonetaryAmount | No | Total taxable turnover for the period |
| notes | string | No | Operator notes (review findings, exceptions) |

**Relations:**
- → `Administration` (many-to-one)
- ← `VATDeclaration` (one-to-many)
- ← `VATLine` (one-to-many)

**Lifecycle:** `draft → submitted → verified → filed`

- **draft:** Operator can add/edit VAT lines; system can recalculate from GL.
- **submitted:** Return locked; operator cannot edit lines; awaits authority verification.
- **verified:** Authority has acknowledged receipt; still viewable and exportable.
- **filed:** Authority has accepted return as final; marked read-only.

**Aggregations:**
- `SUM(VATLine.vatAmount WHERE type='collected')` → `totalVATCollected`
- `SUM(VATLine.vatAmount WHERE type='paid')` → `totalVATPaid`
- `totalVATPaid - totalVATCollected` → `vatBalance`

---

### VATDeclaration (Register)

**Schema.org:** `schema:DigitalDocument`

A grouping of VAT lines for a return, organized by VAT rate or type
(collected/paid/reverse-charge). Supports regime-specific aggregations.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| declarationNumber | string | Yes | Unique within return (e.g., VAT-2026-Q1-COLLECTED) |
| returnId | string | Yes | FK to `VATReturn` |
| type | enum | Yes | Declaration type: `collected`, `paid`, `reverse-charge` |
| taxRate | number | No | VAT rate as percentage (21, 9, 0, etc.) — null if mixed-rate |
| totalVATAmount | MonetaryAmount | Yes | Sum of VAT amounts in this declaration |
| totalTaxableAmount | MonetaryAmount | Yes | Sum of taxable amounts (before VAT) |
| lineCount | integer | No | Count of VATLine records in this declaration |
| notes | string | No | Notes specific to this declaration (rate changes, reversals, etc.) |

**Relations:**
- → `VATReturn` (many-to-one)
- ← `VATLine` (one-to-many)

---

### VATLine (Register)

**Schema.org:** `schema:Thing`

A line in a VAT return, sourced from a GL account for a period. Captures
VAT amount, rate, type, and GL reference for audit.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lineNumber | integer | Yes | Sequence within return (1, 2, 3, ...) |
| returnId | string | Yes | FK to `VATReturn` |
| declarationId | string | Yes | FK to `VATDeclaration` |
| glAccountNumber | string | Yes | Account code from `Account.accountNumber` (audit link) |
| glAccountName | string | No | Cached account name at line creation time |
| glTransactionId | string | No | FK to source GL transaction / journal entry |
| type | enum | Yes | Line type: `collected` (sales), `paid` (purchases), `reverse-charge` (intra-EU/import) |
| taxableAmount | MonetaryAmount | Yes | Amount before VAT |
| taxRate | number | Yes | VAT rate as percentage (21, 9, 0, -1 for reverse-charge) |
| vatAmount | MonetaryAmount | Yes | Computed VAT amount (taxableAmount × taxRate / 100) |
| description | string | No | GL account description or transaction description |
| reverseChargeApplicable | boolean | No | Marked as reverse-charge eligible (intra-EU, import service) |

**Relations:**
- → `VATReturn` (many-to-one)
- → `VATDeclaration` (many-to-one)

**Immutability:** Once `VATReturn.statusCode = 'submitted'`, no new
`VATLine` records can be appended. Operator must rebase return to
`draft` to update lines.

---

## ADDED Requirements

### Requirement: REQ-VAT-001 — The system SHALL create and register a VAT return for a specific fiscal period and regime

The system SHALL create and register a VAT return for a specific fiscal
period and regime.

#### Scenario: Operator creates a Q1 2026 VAT return

**GIVEN**
- Accounting administration is set to 2026
- GL transactions have been posted with `vatApplicable: true` markers
- Period is Q1 2026 (1 Jan – 31 Mar)

**WHEN**
- Operator navigates to VAT Returns list
- Operator clicks "New Return"
- Operator selects period Q1 2026 and regime "standard"
- System auto-calculates `totalVATCollected`, `totalVATPaid`, `vatBalance`
  from GL

**THEN**
- `VATReturn` is created with `statusCode = 'draft'`
- `VATDeclaration` records are auto-generated for collected/paid/reverse-charge
- `VATLine` records are populated from GL transactions in that period
- Return shows on VAT Returns list with totals

---

### Requirement: REQ-VAT-002 — The system SHALL automatically derive VAT lines from GL transactions where the account is VAT-applicable

The system SHALL automatically derive VAT lines from GL transactions
where `Account.vatApplicable = true`.

#### Scenario: Q1 sales create a VAT collected line

**GIVEN**
- GL account 4000 (Revenue) has `vatApplicable = true` and `accountType = 'revenue'`
- Journal entry on 15 Jan with debit €15,000 to 1200 (AR) and credit €15,000 to 4000
- VAT rate metadata on account is 21%

**WHEN**
- Operator creates VAT Return for Q1 2026
- System scans GL for entries in Q1 where destination account has `vatApplicable = true`

**THEN**
- `VATLine` is created with:
  - `type = 'collected'`
  - `taxableAmount = €15,000`
  - `taxRate = 21`
  - `vatAmount = €3,150`
  - `glTransactionId = {journal entry ID}`

---

### Requirement: REQ-VAT-003 — The system SHALL track VAT at multiple rates (21%, 9%, 0%) and reverse-charge scenarios in one return

The system SHALL track VAT at multiple rates (21%, 9%, 0%) and
reverse-charge scenarios in one return.

#### Scenario: Return with multiple rates and reverse-charge

**GIVEN**
- GL account 4010 (Services) has `vatApplicable = true`, rate 21%
- GL account 4020 (Food sales) has `vatApplicable = true`, rate 9%
- GL account 4030 (Export sales) has `vatApplicable = true`, rate 0%
- GL account 5010 (Intra-EU purchases) has reverse-charge applicable

**WHEN**
- Operator creates VAT Return for Q2 2026

**THEN**
- `VATLine` records are created for each rate:
  - Service sales (21%): €5,000 taxable → €1,050 VAT
  - Food sales (9%): €2,000 taxable → €180 VAT
  - Export sales (0%): €3,000 taxable → €0 VAT
  - EU purchases (reverse-charge): €10,000 taxable → €-2,100 VAT (payable)
- `VATDeclaration` for rate 21%: €5,000 + €1,050
- `VATDeclaration` for rate 9%: €2,000 + €180
- `VATDeclaration` for reverse-charge: €10,000 + €-2,100

---

### Requirement: REQ-VAT-004 — The system SHALL support VAT regime selection (standard, reduced rates, KOR small-business exemption, reverse-charge)

The system SHALL support VAT regime selection: standard rate,
reduced rates, KOR (small-business exemption), reverse-charge.

#### Scenario: KOR-eligible business exempted from VAT

**GIVEN**
- Business qualifies for KOR (turnover < €20,000 or € 1,600/month)
- `VATReturn.regime = 'kor'`

**WHEN**
- Operator creates VAT Return for Q1 2026 with `regime='kor'`
- System calculates totals from GL

**THEN**
- `totalVATCollected = €0` (KOR exempt, no VAT payable)
- `totalVATPaid = €0` (KOR exempt, no VAT deductible)
- `vatBalance = €0`
- Return displays note: "KOR exemption applied — no VAT filing required"
- Manifest hides VAT-rate-specific sections (9%, 0%)

---

### Requirement: REQ-VAT-005 — The system SHALL manage VAT return state transitions through draft → submitted → verified → filed

The system SHALL manage VAT return state transitions through
`draft → submitted → verified → filed`.

#### Scenario: Operator submits a VAT return

**GIVEN**
- `VATReturn` with `statusCode = 'draft'`
- All required VATLine records populated
- Operator has reviewed and confirmed totals

**WHEN**
- Operator clicks "Submit Return"
- System validates that `totalVATCollected` and `totalVATPaid` > 0
  (or both 0 for KOR)
- Operator confirms submission

**THEN**
- `VATReturn.statusCode` transitions to `submitted`
- `VATReturn.submissionDate` is set to current timestamp
- Audit trail records submission with operator ID
- Return is locked (operator cannot edit VAT lines)
- System displays next steps: "Awaiting authority verification"

---

### Requirement: REQ-VAT-006 — The system SHALL calculate the VAT balance (refund vs. payment) for a return

The system SHALL calculate the VAT balance (refund vs. payment) for a return.

#### Scenario: Return shows €1,050 owed

**GIVEN**
- `VATReturn` for Q1 2026
- `totalVATCollected = €3,150` (from sales)
- `totalVATPaid = €2,100` (from purchases)

**WHEN**
- Operator views VAT Return detail

**THEN**
- `vatBalance = totalVATPaid - totalVATCollected = €2,100 - €3,150 = -€1,050`
- Display shows "Amount due: €1,050" in red
- Suggestion for payment method / bank transfer details

---

### Requirement: REQ-VAT-007 — The system SHALL provide a VAT report dashboard showing VAT by period, regime and balance status

The system SHALL provide a dashboard view showing VAT by period, regime, and
balance status.

#### Scenario: Operator views VAT Report for 2026

**GIVEN**
- Multiple VAT returns exist for 2026 (Q1, Q2, Q3)
- Each return has different regime (standard, KOR, reverse-charge)

**WHEN**
- Operator navigates to VAT Reports dashboard

**THEN**
- Display shows table with columns:
  - Period (Q1, Q2, Q3, ...)
  - Regime (Standard, KOR, etc.)
  - VAT Collected (€ amount)
  - VAT Paid (€ amount)
  - Balance (€ amount, color-coded: red=owed, green=refund)
  - Status (Draft, Submitted, Verified, Filed)
- Chart shows quarterly trend of VAT balance
- Option to export as CSV or PDF

---

### Requirement: REQ-VAT-008 — The system SHALL prevent modification of VAT returns after submission (rebase required to edit)

The system SHALL prevent modification of VAT returns after submission.

#### Scenario: Operator attempts to edit submitted return

**GIVEN**
- `VATReturn` with `statusCode = 'submitted'`
- New GL transactions posted to same period

**WHEN**
- Operator tries to add a new `VATLine` to the return
- Operator tries to edit an existing `VATLine`

**THEN**
- System displays error: "Return is locked. Rebase to draft to update."
- Option to transition back to `draft` (confirms intent to re-submit)
- Upon `draft` transition, operator can add/edit lines and recalculate
- New `submissionDate` is cleared; return awaits re-submission

---

### Requirement: REQ-VAT-009 — The system SHALL track all VAT return changes and submissions with a full audit trail

The system SHALL track all VAT return changes and submissions with a
full audit trail.

#### Scenario: Operator reviews return history

**GIVEN**
- VAT Return has been edited multiple times
- Return was submitted, rebased to draft, edited, re-submitted

**WHEN**
- Operator clicks "View Audit Trail" on return detail

**THEN**
- Audit shows:
  - 2026-01-15 10:23 — Draft created (Operator: Alice)
  - 2026-01-20 14:45 — Line added (Operator: Alice)
  - 2026-02-01 09:00 — Submitted (Operator: Bob)
  - 2026-02-05 16:30 — Verified by authority (System)
  - 2026-02-10 13:15 — Rebased to draft (Operator: Alice)
  - 2026-02-12 11:00 — Re-submitted (Operator: Bob)
- Each entry shows operator, timestamp, before/after values

---

### Requirement: REQ-VAT-010 — The system SHALL track reverse-charge VAT (intra-EU purchases, imports, cross-border services) separately

The system SHALL track reverse-charge VAT (intra-EU purchases,
imports, cross-border services) separately.

#### Scenario: Company purchases software from German vendor (reverse-charge)

**GIVEN**
- GL account 5500 (Intra-EU Purchases) has `vatApplicable=true`,
  `reverseChargeApplicable=true`
- Journal entry: €10,000 debit to 5500, credit to bank
- VAT rate on account is 0% (reverse-charge, no payable VAT)

**WHEN**
- Operator creates VAT Return for Q1 2026
- System scans GL for reverse-charge accounts

**THEN**
- `VATLine` created with:
  - `type = 'reverse-charge'`
  - `taxableAmount = €10,000`
  - `taxRate = 0` (or -1 to signal reverse-charge)
  - `vatAmount = €0` (operator liable under reverse-charge rule)
  - `reverseChargeApplicable = true`
- `VATDeclaration` for reverse-charge shows line
- Note: "Operator liable for VAT under intra-EU reverse-charge rules"

---

### Requirement: REQ-VAT-011 — The system SHALL use aggregation queries to derive return totals reliably

The system SHALL use aggregation queries to derive return totals
reliably.

#### Scenario: System calculates totals from GL

**GIVEN**
- GL has 47 VAT-marked transactions in Q1 2026
- Rates: 21% (€50K), 9% (€8K), 0% (€5K), reverse-charge (€3K)

**WHEN**
- Operator creates VAT Return for Q1 2026
- System executes aggregation queries:
  - `SUM(GLTransaction.vatAmount WHERE account.vatApplicable=true AND vatRate=21% AND type=sales AND period=Q1-2026)`
  - `SUM(GLTransaction.vatAmount WHERE account.vatApplicable=true AND vatRate=9% AND type=sales AND period=Q1-2026)`
  - ... etc. for each rate and type

**THEN**
- `VATDeclaration` records are populated with aggregation results
- `VATLine` records show line-by-line GL source references
- `VATReturn.totalVATCollected` = sum of collected declarations
- `VATReturn.totalVATPaid` = sum of paid + reverse-charge declarations
- Balance is automatically calculated

---

### Requirement: REQ-VAT-012 — The system SHALL provide manifest navigation entries for VAT Returns list, detail and Reports dashboard

The system SHALL provide UI navigation for VAT Returns list, detail, and
Reports dashboard.

#### Scenario: Operator navigates to VAT management

**GIVEN**
- User has permission `shillinq:vat:view`

**WHEN**
- Operator opens Shillinq main menu
- Manifest has navigation entries for VAT

**THEN**
- Menu shows:
  - "VAT Returns" → `/apps/shillinq/vat-returns/` (index list)
  - "VAT Reports" → `/apps/shillinq/vat-reports/` (dashboard)
- Index page shows paginated list of returns with filters (period, regime, status)
- Detail page shows return summary, declarations, lines, audit trail, action buttons

---

## Implementation Notes

### GL Derivation Algorithm

When operator creates or rebases a VAT Return:

1. **Query GL transactions** in period where `Account.vatApplicable = true`
2. **Group by** (glAccountNumber, taxRate, type=collected|paid|reverse-charge)
3. **For each group**, create or update `VATLine`:
   - Calculate `vatAmount = taxableAmount × taxRate / 100`
   - Create `VATDeclaration` if not exists for (rate, type)
   - Aggregate totals into `VATReturn` fields
4. **Lock period** — once submitted, no new VAT lines appended (requires rebase)

### Regime Logic

- **Standard:** All rates (21%, 9%, 0%) apply per account; no exemptions
- **KOR:** Small-business exemption; `totalVATCollected = 0`, `totalVATPaid = 0`
- **Reverse-charge:** Intra-EU or import; use `reverseChargeApplicable` flag on GL accounts

### Validation Rules

- A `VATReturn` must have `startDate ≤ today` (cannot create future returns)
- A `VATReturn` must have `startDate >= {administrationId}.startDate`
- Submitted returns cannot be edited (must rebase to draft)
- `totalVATCollected` and `totalVATPaid` must be ≥ 0

---

## References

- [ADR-001: Bookkeeping tier roadmap](../../architecture/adr-001-bookkeeping-tier-roadmap.md) —
  VAT filing is T3 Operations
- [ADR-022: Apps consume OpenRegister abstractions](../../architecture/adr-022-apps-consume-openregister-abstractions.md) —
  lifecycle and aggregations from OR
- [ADR-031: Declarative business logic](../../architecture/adr-031-schema-declarative-business-logic.md) —
  no service classes; all logic declarative
- Dutch tax authority: [Belastingdienst VAT filing requirements](https://www.belastingdienst.nl) —
  quarterly/monthly returns, electronic submission
