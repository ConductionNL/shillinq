# Spec: Invoice VAT + Kassakoppeling Compliance

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (operational + NL regulatory)
**Depends on:** `bookkeeping-general-ledger` (T1), `bookkeeping-accounts-receivable-core` (T2)

## Preamble

This spec extends Shillinq's invoice capability with per-line VAT (BTW) rate differentiation and kassakoppeling-compliant audit trails for bookings-generated invoices. Dutch SMB and government MUST file VAT monthly or quarterly per Belastingdienst rules; each invoice line MUST carry a rate (21%, 9%, 6%, 0%) and a service-category (product/service/exempt) that gates VAT applicability. On invoice issuance, VAT accrual MUST materialise into the GL control account per rate bucket. An immutable `VATAuditRecord` MUST capture every lifecycle event (issued, paid, written-off, reversed) for Belastingdienst audit.

All requirements use RFC 2119 language (MUST, SHOULD, MAY). The spec is declarative-first per ADR-031 (no app-local PHP VAT service) and audit-trail-first per ADR-022 (append-only immutable records).

This capability **extends the original Shillinq invoicing scope with Dutch VAT compliance**. No `bookkeeping-invoice-vat-kassakoppeling` capability spec existed before this change; no `VATAuditRecord`, `VATGLAccounts`, or `ServiceCategoryOverride` schema was declared previously; the existing `lib/Service/VATCalculationService.php` and `lib/Service/VATReturnService.php` PHP classes are scoped to other capabilities (`invoice-from-time-and-expense` BillableInvoice + `bookkeeping-vat-btw-filing` periodic returns) and are NOT extended or invoked here per ADR-031.

---

## ADDED Requirements

### Requirement: InvoiceLine extended with per-line VAT fields (REQ-VAT-001)

Every line item on an `ARInvoice` (the `lines[]` array per the AR core schema) MUST carry three new fields:

- `vatRate` (enum integer): one of `21`, `9`, `6`, `0` (percentage). Defaults to the administration's standard rate (typically 21).
- `vatAmount` (number, decimal euros): computed as `bankerRound(amount × vatRate / 100, 2)` per REQ-VAT-007. Aligns with the existing AR T2 `ARInvoice.taxAmount` and `lines[].amount` convention (decimal euros, 2-decimal banker's rounding). Internal arithmetic is performed in integer cents to avoid floating-point drift; the stored value is the 2-decimal euro representation.
- `serviceCategory` (enum string): one of `product`, `service`, `exempt`. Defaults to `product`. Determines VAT rate applicability (see REQ-VAT-002).

**Rationale:** Dutch VAT law requires invoices to show VAT rate per line. Service/product distinction allows audit-trail filtering by category. Decimal-euros storage (two decimal places) matches the existing AR T2 schema; integer-cent arithmetic prevents floating-point drift inside the computation.

#### Scenario: Standard service-rate invoice line

- **GIVEN** an administration in the Netherlands with standard VAT rate of 21
- **WHEN** a user creates an `ARInvoice` line for `1 × "Server installation"` at €1000.00 (`amount = 1000.00`)
- **AND** selects `serviceCategory="service"`
- **THEN** the system auto-suggests `vatRate=9` (reduced service rate)
- **AND** computes `vatAmount = bankerRound(1000.00 × 9 / 100, 2) = 90.00` (€90.00)
- **AND** stores the line with these values

#### Scenario: Books with reduced product rate

- **GIVEN** an administration with a book-retailing business
- **WHEN** a user creates an `ARInvoice` line for `10 × "Beginner's Dutch"` at €15.00 each (`amount = 150.00`)
- **AND** selects `serviceCategory="product"`
- **THEN** the user MAY manually select `vatRate=6` (books / reduced product rate)
- **AND** the system computes `vatAmount = bankerRound(150.00 × 6 / 100, 2) = 9.00` (€9.00) per line

### Requirement: Service-category validation gates VAT rate (REQ-VAT-002)

Before `ARInvoice.issue` succeeds, the lifecycle precondition MUST validate every line:

- `serviceCategory="product"` permits `vatRate ∈ {0, 6, 21}`.
- `serviceCategory="service"` permits `vatRate ∈ {0, 9, 21}`.
- `serviceCategory="exempt"` permits only `vatRate = 0`.

If validation fails, invoice issuance MUST be blocked with an error message citing the failed line and the permitted rates. Administrations MAY record exceptions in `ServiceCategoryOverride` (REQ-VAT-002bis); the precondition MUST consult overrides before rejecting.

**Rationale:** Prevents operator error (e.g., 21% VAT on a tax-exempt service). Rare exceptions are explicit and audit-trailed.

#### Scenario: Invalid rate rejected on issue

- **GIVEN** an `ARInvoice` in `state="draft"` with line 2 carrying `serviceCategory="service"` and `vatRate=6`
- **WHEN** the user attempts the `issue` transition
- **THEN** the lifecycle precondition rejects the transition with message: `"Line 2: Service category 'service' does not permit 6% VAT. Check admin settings for service-category overrides."`
- **AND** the invoice remains in `state="draft"`

#### Scenario: Override consulted before rejection

- **GIVEN** an `ARInvoice` line with `serviceCategory="service"` and `vatRate=21`
- **AND** a `ServiceCategoryOverride` record exists with `serviceCategory="service"`, `vatRate=21`, `administrationId` matching the invoice
- **WHEN** the user attempts the `issue` transition
- **THEN** the lifecycle precondition consults `ServiceCategoryOverride` first
- **AND** finds a matching override
- **AND** allows the transition to succeed
- **AND** appends an audit-trail entry linking the override (`overrideId`, `reason`) to the issued invoice

### Requirement: ServiceCategoryOverride exception register (REQ-VAT-002bis)

The system MUST declare a `ServiceCategoryOverride` schema in `lib/Settings/shillinq_register.json` capturing administration-specific exceptions to the default REQ-VAT-002 service-category / VAT-rate matrix.

| Field | Type | Required | Purpose |
|-------|------|----------|---------|
| `administrationId` | string | Yes | FK to the administration owning the override |
| `serviceCategory` | enum (product, service, exempt) | Yes | The category for which an unusual rate is allowed |
| `vatRate` | enum (21, 9, 6, 0) | Yes | The rate that becomes permissible for this category |
| `reason` | string | Yes | Operator-authored audit reason (free text, retained for Belastingdienst inspection) |
| `createdAt` | datetime | Yes | Set on creation; immutable |
| `createdBy` | string | Yes | Nextcloud user id of the creator; immutable |

Override records MUST be append-only (immutable per ADR-022). Lookup is keyed on `(administrationId, serviceCategory, vatRate)`. The precondition in REQ-VAT-002 MUST evaluate against the union of the default matrix and matching overrides.

#### Scenario: Override creation captures actor and reason

- **GIVEN** an administration `adm-1` and a user `accountant1`
- **WHEN** `accountant1` POSTs an override for `serviceCategory="service", vatRate=21, reason="Special consulting agreement per contract X-2026"`
- **THEN** the record persists with `createdBy="accountant1"` and `createdAt=NOW()`
- **AND** subsequent issue attempts for `service / 21%` lines on this administration succeed

### Requirement: VAT accrual GL posting on invoice issuance (REQ-VAT-003)

When `ARInvoice` transitions to state `issued`, the lifecycle engine MUST materialise a balanced GL transaction per the T1 `bookkeeping-general-ledger` REQ-JE-007 pattern with the following posting shape:

- **Debit** the AR control account (`Account.isARControlAccount = true`) by `SUM(line.amount) + SUM(line.vatAmount)` (the gross invoice total in decimal euros, matching `ARInvoice.totalAmount`).
- **Credit** each line's revenue `accountNumber` by `line.amount` (net of VAT).
- **Credit**, by VAT-rate bucket, the configured `VATPayable*` GL account:
  - `vatRate=21` lines → `VATGLAccounts.vat21Account`
  - `vatRate=9` lines → `VATGLAccounts.vat9Account`
  - `vatRate=6` lines → `VATGLAccounts.vat6Account`
  - `vatRate=0` lines → `VATGLAccounts.vat0Account`

Each VAT bucket sums the `vatAmount` of all lines in that bucket. The materialisation MUST be declared via `x-openregister-lifecycle-action` on the `ARInvoice.issue` transition; no PHP service MAY be added per ADR-031.

**Rationale:** VAT is a liability accrued on invoice date per Dutch tax law (accrual basis). Materialising at issue time ensures GL balances match the invoice register immediately.

#### Scenario: Mixed-rate invoice GL posting

- **GIVEN** an `ARInvoice` in `state="draft"` with two lines:
  - Line 1: `amount=100.00`, `vatRate=21`, `vatAmount=21.00`, revenue account `8000`
  - Line 2: `amount=200.00`, `vatRate=9`, `vatAmount=18.00`, revenue account `8001`
- **AND** `VATGLAccounts` for the administration mapping `vat21Account=2020`, `vat9Account=2021`
- **AND** the AR control account is `1200`
- **WHEN** the lifecycle engine materialises the `issue` transition
- **THEN** the GL transaction emitted is balanced and contains:
  - Debit `1200` (AR control): `339.00`
  - Credit `8000` (revenue): `100.00`
  - Credit `8001` (revenue): `200.00`
  - Credit `2020` (VATPayable21): `21.00`
  - Credit `2021` (VATPayable9): `18.00`
- **AND** `ARInvoice.glTransactionId` is set to the new transaction's id

### Requirement: Immutable VATAuditRecord for kassakoppeling compliance (REQ-VAT-004)

Every `ARInvoice` line MUST generate one append-only `VATAuditRecord` for each lifecycle event the invoice passes through (`issued`, `paid`, `written-off`, `reversed`). Records MUST NOT be modified after creation. Each record captures (all immutable copies taken at event time):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `invoiceNumber` | string | Yes | `ARInvoice.invoiceNumber` (e.g. `"INV-C-2026-0001"`) |
| `invoiceDate` | date | Yes | `ARInvoice.invoiceDate` at issue |
| `lineSequence` | integer | Yes | Line index within the invoice (1-based) |
| `lineDescription` | string | Yes | `line.description` copy |
| `lineAmount` | number | Yes | `line.amount` in decimal euros (immutable copy; mirrors AR T2 convention) |
| `vatRate` | enum (21, 9, 6, 0) | Yes | The rate applied |
| `vatAmount` | number | Yes | `line.vatAmount` in decimal euros (immutable copy; banker's-rounded to 2 decimals) |
| `serviceCategory` | enum (product, service, exempt) | Yes | The category applied |
| `lifecycleEvent` | enum (issued, paid, written_off, reversed) | Yes | Which event this record captures |
| `eventDate` | datetime | Yes | When the event occurred |
| `paymentDate` | date | No | Populated only on `lifecycleEvent="paid"` |
| `settlementPeriod` | string | Yes | FK to `FiscalPeriod` (`periodId`) bound at issue time per REQ-VAT-005 |
| `administrationId` | string | Yes | FK to the administration |

The schema MUST be declared `x-openregister-immutable: true` (read-only after create) so subsequent updates / deletes are refused at the OR layer. The `created/updated` audit trail is captured by the standard `x-openregister-audit-trail` channel per ADR-022.

**Rationale:** Kassakoppeling requires a tamper-proof per-line audit trail. Belastingdienst audits inspect these records; modification after the fact is regulatory non-compliance.

#### Scenario: Complete audit trail for a paid service invoice

- **GIVEN** an `ARInvoice` issued on `2026-05-15` with one service line (`amount=200.00`, `vatRate=9`, `vatAmount=18.00`, `serviceCategory="service"`)
- **WHEN** the invoice transitions to `issued`
- **THEN** `VATAuditRecord` #1 is created with:
  - `invoiceNumber="INV-C-2026-0001"`, `invoiceDate=2026-05-15`, `lineSequence=1`
  - `lineDescription="Website hosting - May 2026"`, `lineAmount=200.00`, `vatRate=9`, `vatAmount=18.00`, `serviceCategory="service"`
  - `lifecycleEvent="issued"`, `eventDate=2026-05-15T14:32:00Z`, `paymentDate=null`
  - `settlementPeriod="2026-05"`, `administrationId="adm-1"`
- **WHEN** a bank-reconciliation match clears the invoice on `2026-05-20` and the lifecycle transitions to `paid`
- **THEN** `VATAuditRecord` #2 is created with the SAME line copy data, except:
  - `lifecycleEvent="paid"`, `eventDate=2026-05-20T09:15:00Z`, `paymentDate=2026-05-20`
- **AND** `VATAuditRecord` #1 remains byte-for-byte unchanged (immutable)
- **AND** any attempt to PATCH or DELETE record #1 is refused with `409 SCHEMA_IMMUTABLE` per ADR-022

### Requirement: Settlement period binding at invoice issuance (REQ-VAT-005)

When the `ARInvoice.issue` lifecycle action materialises VAT accrual, the system MUST bind the resulting `VATAuditRecord` to a `FiscalPeriod` (the existing T1 `periodId` register, used here as the settlement period) based on:

1. The invoice's `invoiceDate` (issuance date).
2. The administration's `vatFilingFrequency` setting (`monthly`, `quarterly`, or `annual`).

The `VATAuditRecord.settlementPeriod` MUST be immutable once set. If the administration later reconfigures its `vatFilingFrequency`, existing records MUST remain bound to their original period; only invoices issued after the reconfiguration take the new frequency.

**Rationale:** Audit trail must remain internally consistent even after administration settings change. Tax filing follows the period rules in effect at issuance.

#### Scenario: Period binding survives admin reconfiguration

- **GIVEN** an administration configured for `vatFilingFrequency="monthly"`
- **AND** an `ARInvoice` issued on `2026-05-15`
- **WHEN** the VAT accrual lifecycle action runs
- **THEN** `VATAuditRecord.settlementPeriod="2026-05"` is set
- **WHEN** later the administration is reconfigured to `vatFilingFrequency="quarterly"` effective `2026-07-01`
- **AND** a new `ARInvoice` is issued on `2026-07-10`
- **THEN** the new invoice's `VATAuditRecord.settlementPeriod="2026-Q3"`
- **AND** the May invoice's `VATAuditRecord.settlementPeriod` remains `"2026-05"` (no migration; immutable)
- **AND** the VAT-by-period aggregation (REQ-VAT-009) for `"2026-05"` continues to include the May record unchanged

### Requirement: VATGLAccounts per-administration configuration (REQ-VAT-006)

The system MUST declare a `VATGLAccounts` schema in `lib/Settings/shillinq_register.json` capturing the per-administration GL account mapping for VAT-payable buckets:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `administrationId` | string | Yes | FK to the administration; one record per administration |
| `vat21Account` | string | Yes | GL account number for 21% VAT payable (default `2020`) |
| `vat9Account` | string | Yes | GL account number for 9% VAT payable (default `2021`) |
| `vat6Account` | string | Yes | GL account number for 6% VAT payable (default `2022`) |
| `vat0Account` | string | Yes | GL account number for 0% VAT payable (default `2023`) |
| `createdAt` | datetime | Yes | Set on create |
| `updatedAt` | datetime | Yes | Updated on each change |

The installer MUST insert a default `VATGLAccounts` record on first run with the four default accounts. The admin UI MUST validate that:

1. All four accounts exist in `Account` for the administration.
2. The four account numbers are unique within the record.

**Rationale:** Tax filings segregate VAT by rate for reporting. Separate GL accounts allow drill-down and reconciliation per rate during period-end close. Defaults from the RGS (Referentie Grootboekschema) baseline.

#### Scenario: Admin configures VAT GL accounts via Tax Configuration

- **GIVEN** a freshly installed administration `adm-2`
- **WHEN** the admin opens `Settings > Accounting > Tax Configuration`
- **THEN** the form pre-populates `vat21Account=2020`, `vat9Account=2021`, `vat6Account=2022`, `vat0Account=2023` (RGS defaults)
- **WHEN** the admin saves the form with `vat21Account=2020, vat9Account=2020` (duplicate)
- **THEN** the system rejects the save with: `"VAT GL accounts must be unique per rate bucket."`

### Requirement: Banker's rounding per Dutch fiscal standard (REQ-VAT-007)

VAT amounts MUST be computed and stored under the following rules:

- **Per-line VAT amount**: `vatAmount = bankerRound(amount × vatRate / 100, 2)` in decimal euros (2 decimal places). The banker's rounding rule MUST be `round-to-even` (a.k.a. IEEE 754 `roundTiesToEven`): when the fractional remainder is exactly 0.5 cent (`x.xx5`), round to the nearest even cent. Internal arithmetic MAY use integer-cent intermediates to avoid floating-point drift, but the stored value is the decimal-euro representation.
- **Invoice total VAT**: the sum of per-line `vatAmount` values; no invoice-level rounding adjustment line MAY be added.
- **GL posting amounts**: equal to the per-line `vatAmount` sums; no rounding difference between invoice and GL.

The change MUST declare a reusable rounding helper `bankerRound(amount, decimals)` available to both the schema's `x-openregister-calculations` block (for `vatAmount` derivation) and the lifecycle materialisation template (for GL bucket totals).

**Rationale:** Dutch fiscal standard (Belastingdienst) expects banker's rounding per line; no "rounding adjustment" line. This prevents audit discrepancies.

#### Scenario: Banker's rounding applies at .5-cent boundary

- **GIVEN** a service line with `amount=33.33` and `vatRate=9`
- **WHEN** the VAT amount is computed
- **THEN** `vatAmount = bankerRound(33.33 × 9 / 100, 2) = bankerRound(2.9997, 2) = 3.00` (€3.00)
- **GIVEN** a second line with `amount=33.34` and `vatRate=9`
- **WHEN** the VAT amount is computed
- **THEN** `vatAmount = bankerRound(33.34 × 9 / 100, 2) = bankerRound(3.0006, 2) = 3.00` (€3.00)
- **GIVEN** a third line with `amount=33.35` and `vatRate=9`
- **WHEN** the VAT amount is computed
- **THEN** `vatAmount = bankerRound(33.35 × 9 / 100, 2) = bankerRound(3.0015, 2) = 3.00` (€3.00)
- **AND** the invoice total VAT is `9.00` (sum of three lines, no rounding adjustment)

### Requirement: Precondition failure blocks issuance with actionable guidance (REQ-VAT-008)

When any precondition fails on `ARInvoice.issue` (service-category mismatch per REQ-VAT-002, missing `VATGLAccounts` record per REQ-VAT-006, missing `FiscalPeriod` for `settlementPeriod` resolution per REQ-VAT-005), the lifecycle engine MUST:

1. Block the transition; the invoice remains in `state="draft"`.
2. Emit an error message that:
   a. Names the specific failure (line, field, value) in operator language;
   b. Provides actionable guidance (which admin panel resolves it, what to change);
   c. Is recorded in the audit trail per ADR-022 with the actor user id and the original draft state.
3. For UI clients, the message MAY include a deep link to the relevant admin panel (e.g. Tax Configuration). For API clients, the message MUST be sufficient to resolve without UI navigation.

**Rationale:** Silent invoice failures are a recurring SMB complaint; clear actionable messages reduce support load.

#### Scenario: Missing VATGLAccounts blocks issuance with deep link

- **GIVEN** an administration with no `VATGLAccounts` record
- **WHEN** a user attempts to issue an `ARInvoice` containing VAT-bearing lines
- **THEN** the lifecycle precondition rejects the transition with:
  - Error: `"Cannot issue invoice: VAT GL accounts not configured for administration. Go to Settings > Accounting > Tax Configuration to assign VAT GL accounts."`
  - Deep link: `/index.php/apps/shillinq/settings/tax-configuration`
- **AND** the audit trail records the rejection with `user_id`, original draft snapshot, and failure reason

### Requirement: VAT-by-period aggregation for compliance reporting (REQ-VAT-009)

The system MUST expose a declarative aggregation query (`x-openregister-aggregations` per ADR-031) on `VATAuditRecord` named `vatByPeriod`, keyed by `(administrationId, settlementPeriod)`, returning:

| Aggregate | Computation |
|-----------|-------------|
| `totalNetAmount` | `SUM(lineAmount)` across records in the period, filtered to `lifecycleEvent="issued"` (decimal euros) |
| `totalVAT21` | `SUM(vatAmount WHERE vatRate=21)` |
| `totalVAT9` | `SUM(vatAmount WHERE vatRate=9)` |
| `totalVAT6` | `SUM(vatAmount WHERE vatRate=6)` |
| `totalVAT0` | `SUM(vatAmount WHERE vatRate=0)` |
| `totalGrossAmount` | `totalNetAmount + totalVAT21 + totalVAT9 + totalVAT6 + totalVAT0` |
| `invoiceCount` | `COUNT(DISTINCT invoiceNumber)` |
| `recordCount` | `COUNT(*)` of audit records in the period |

The aggregation MUST be invoked by the two manifest entries declared in REQ-VAT-010.

**Rationale:** Monthly / quarterly VAT filings require these totals broken down by rate. The dashboard enables bookkeepers to cross-check invoice totals before filing.

#### Scenario: May 2026 VAT reconciliation totals

- **GIVEN** an administration `adm-1` with three invoices issued in May 2026, each generating one `VATAuditRecord` with `lifecycleEvent="issued"`:
  - Invoice A: product line `lineAmount=100.00`, `vatRate=21`, `vatAmount=21.00`
  - Invoice B: service line `lineAmount=200.00`, `vatRate=9`, `vatAmount=18.00`
  - Invoice C: exempt line `lineAmount=300.00`, `vatRate=0`, `vatAmount=0.00`
- **WHEN** the bookkeeper queries `vatByPeriod(administrationId="adm-1", settlementPeriod="2026-05")`
- **THEN** the result is:
  - `totalNetAmount=600.00`, `totalVAT21=21.00`, `totalVAT9=18.00`, `totalVAT6=0.00`, `totalVAT0=0.00`
  - `totalGrossAmount=639.00`, `invoiceCount=3`, `recordCount=3`

### Requirement: Manifest entries for VAT by Period and VAT Reconciliation (REQ-VAT-010)

The change MUST add two navigation entries to `src/manifest.json` under the existing `Belastingen` (Taxes) menu group:

1. **VAT by Period** (`id="VATByPeriod"`, type=`index`) — lists all settlement periods for the active administration, grouped by filing frequency (monthly / quarterly / annual). Columns: `period`, `totalNetAmount`, `totalVAT21`, `totalVAT9`, `totalVAT6`, `totalVAT0`, `totalGrossAmount`, `invoiceCount`. Each row links to the detail page.
2. **VAT Reconciliation** (`id="VATReconciliation"`, type=`detail`) — shows the breakdown for a single period: list of contributing invoices, line-by-line `VATAuditRecord` entries, GL account balances for the period from the `VATGLAccounts` mapping, and a "Ready for Filing" checklist.

Both entries MUST live under the existing `Belastingen` parent menu group (not as top-level menu items) per the IA placement (`DETAIL_TAB` / `SETTING` family). The detail page's `indexRoute` MUST point to `VATByPeriod`.

**Rationale:** Operator-facing navigation for a recurring compliance task. Respecting the IA placement keeps the sidebar uncluttered; the existing tax sub-tree is the natural home.

#### Scenario: Bookkeeper navigates from VAT by Period to Reconciliation

- **GIVEN** a bookkeeper logged into Shillinq with two settlement periods (May 2026 and June 2026) populated
- **WHEN** they click `Belastingen > VAT by Period` in the sidebar
- **THEN** they see a table with one row per period showing `period`, `totalNetAmount`, `totalVAT21`, `totalVAT9`, `totalVAT6`, `totalVAT0`, `totalGrossAmount`, `invoiceCount`
- **WHEN** they click the `2026-05` row
- **THEN** they navigate to the detail page showing the contributing invoices, audit records, the GL balance for `vat21Account` and `vat9Account`, and an unchecked "Ready for Filing" checklist

---

## Dependencies

- **T1 `bookkeeping-general-ledger`** — REQ-VAT-003 depends on the GL `JournalEntry` materialisation pattern (REQ-JE-007) for VAT accrual posting.
- **T2 `bookkeeping-accounts-receivable-core`** — REQ-VAT-001 extends `ARInvoice.lines[]` items; REQ-VAT-008 hooks into the `ARInvoice.issue` lifecycle transition.
- **T3 `bookkeeping-period-close`** — REQ-VAT-005 uses the existing `FiscalPeriod` register for settlement-period binding.

## Open Questions

1. **Reverse-charge VAT (B2B intra-EU)**: Should 0% VAT on B2B EU services follow the reverse-charge mechanism (separate flag on `InvoiceLine`), or is this deferred to T5 `bookkeeping-btw-oss-eu`?
2. **Payment-date vs issue-date VAT accrual**: Current spec assumes accrual on issue (accrual basis). Should a cash-basis option exist for small traders (toggle on `Administration`)? Currently treated as out-of-scope; revisit in T3 follow-up.
3. **Rounding tie-breaker confirmation**: Confirmed `roundTiesToEven` (REQ-VAT-007) — but spec-time review with a Dutch fiscal advisor for >€1M-revenue administrations is recommended.
4. **Override approval workflow**: REQ-VAT-002bis allows any admin to create a `ServiceCategoryOverride`. Should overrides require dual approval (creator + reviewer) above a revenue threshold? Deferred — current spec captures `createdBy` for audit traceability.
5. **VAT on invoice discounts / credit notes**: Credit notes (`ARInvoice` reverse / negative-amount) generate `VATAuditRecord` with `lifecycleEvent="reversed"` — but the proportional VAT handling for partial credit notes is implementation-detail and may need a follow-up requirement.

## Acceptance Criteria

- All REQ-VAT-001 through REQ-VAT-010 (and REQ-VAT-002bis) scenarios pass `openspec validate --strict`.
- The schema delta in `lib/Settings/shillinq_register.json` adds three immutable schemas (`VATAuditRecord`, `ServiceCategoryOverride`, `VATGLAccounts`) and extends `ARInvoice.lines[]` items with `vatRate`, `vatAmount`, `serviceCategory`.
- VAT accrual GL posting balances exactly on every issued invoice (sum debits = sum credits, no rounding-adjustment line).
- `VATAuditRecord` is immutable per ADR-022; PATCH and DELETE return `409 SCHEMA_IMMUTABLE`.
- `VATGLAccounts` defaults are seeded on installer first-run.
- Bookkeeper persona (SMB owner) can complete a full VAT filing prep workflow using the two manifest entries.
- ADR-022 (immutable audit, integer cents) and ADR-031 (declarative-first, no app-local PHP VAT service) compliance is documented in `proposal.md` and `design.md` and verified during reviewer pass.
