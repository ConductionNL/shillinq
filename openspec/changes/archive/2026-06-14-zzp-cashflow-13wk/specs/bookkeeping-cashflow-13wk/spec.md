# Spec: bookkeeping-cashflow-13wk

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** 
- `../add-shillinq-accounts-receivable-core/specs/bookkeeping-accounts-receivable-core/spec.md` (T2 AR)
- `../add-shillinq-accounts-payable-core/specs/bookkeeping-accounts-payable-core/spec.md` (T2 AP)
- `openconnector` PSD2 API (existing)
- `pipelinq` CRM API (existing)

## ADDED Requirements

### Requirement: REQ-CF-000: Cashflow forecast horizon SHALL be a 13-week rolling window per IAS 7 + RJ 360

The forecast is expressed as one `CashflowForecastHorizon` register per administration, containing 13 weekly slots (`CashflowWeek` records) covering the next 13 calendar weeks (91 days). The window rolls forward every Monday 02:00 UTC: week-1 is archived, week-13 is appended.

Cashflow categorization follows IAS 7 statement-of-cashflows pattern (operationeel / investering / financiering) mapped to Dutch RJ 360 for regulatory reporting.

#### Scenario: Horizon initialization on first activation

- **GIVEN** ondernemer activates cashflow forecast for first time (no prior `CashflowForecastHorizon` exists)
- **WHEN** system initializes
- **THEN** a new `CashflowForecastHorizon` SHALL be created with `horizonStart: today's date`, `horizonEind: today + 91 days`, `openingSaldo` from PSD2 bankfeed or manual entry, `rolledOp: now()`

#### Scenario: Horizon roll every Monday

- **GIVEN** `CashflowForecastHorizon` with `horizonStart: 2026-05-18`, `horizonEind: 2026-08-17` (13 weeks)
- **WHEN** cron job fires at 2026-05-25T02:00:00Z (Monday)
- **THEN** horizon SHALL update to `horizonStart: 2026-05-25`, `horizonEind: 2026-08-24` (shifted by 1 week)
- **AND** `rolledOp: 2026-05-25T02:00:00Z`

### Requirement: REQ-CF-001: CashflowForecastHorizon Schema

The `CashflowForecastHorizon` register SHALL declare the following required fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `horizonId` | string (UUID) | Yes | Unique identifier |
| `ondernemingId` | string (FK) | Yes | FK to Corporation/Administration |
| `horizonStart` | date | Yes | First day of the 13-week window (Monday) |
| `horizonEind` | date | Yes | Last day of the window (Sunday, +90 days) |
| `rolledOp` | datetime | Yes | Timestamp of last weekly roll |
| `openingSaldo` | object | Yes | Opening balance breakdown: `zakelijkeRekening` (float), `spaardoel_btw` (float), `spaardoel_ib` (float), `spaardoel_buffer` (float), `totaal` (float) |
| `modelVersie` | string | No | Version string of the forecast model (e.g., "v4.1-klantspecifiek-betaalgedrag") |
| `kalibratieScore` | number (0-1) | No | Overall forecast accuracy score from prior month (MAPE weighting) |
| `amministrazioneId` | string (FK) | Yes | FK to Administration |
| `lifecycleState` | enum | Yes | One of `active`, `rolling`, `archived` |

**Relations:**
- 1:M with `CashflowWeek`
- 1:M with `CashflowARProjection`
- 1:M with `CashflowAPSchedule`
- 1:M with `CashflowScenario`

#### Scenario: Schema validation accepts opening saldo breakdown

- **GIVEN** the schema
- **WHEN** `{horizonStart: "2026-05-25", horizonEind: "2026-08-23", openingSaldo: {zakelijkeRekening: 14820.00, spaardoel_btw: 3200.00, spaardoel_ib: 5800.00, spaardoel_buffer: 8200.00}, administrazioneId: "adm-1"}` is saved
- **THEN** validation MUST pass

### Requirement: REQ-CF-002: CashflowWeek Schema — Weekly slot within the 13-week horizon

The `CashflowWeek` register SHALL declare one record per calendar week in the horizon, with aggregated inflows/outflows by category and computed ending saldo.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `weekId` | string (UUID) | Yes | Unique identifier |
| `horizonId` | string (FK) | Yes | FK to CashflowForecastHorizon |
| `weeknummer` | integer | Yes | ISO 8601 week number (e.g., 22) |
| `weekStart` | date | Yes | Monday of the week |
| `weekEind` | date | Yes | Sunday of the week |
| `openingSaldo` | float | Yes | Opening balance (previous week's closing or horizon opening) |
| `inflows_ar_geprognosticeerd` | float | Yes | Projected AR receipts (customer-specific betalingsgedrag-based) |
| `inflows_ar_gerealiseerd` | float | No | Actual AR receipts from bankfeed (filled post-settlement) |
| `inflows_pipeline` | float | No | Probabilistic revenue from pipelinq deals (deal.amount × deal.probability) |
| `inflows_overig` | float | No | Interest, refunds, other |
| `inflows_totaal` | float | Yes | SUM of all inflows |
| `outflows_ap_geprognosticeerd` | float | Yes | Projected AP payments (due-date scheduled) |
| `outflows_recurring_huur` | float | No | Recurring monthly rent |
| `outflows_recurring_verzekering` | float | No | Recurring insurance |
| `outflows_recurring_abonnementen` | float | No | Recurring subscriptions (software, cloud) |
| `outflows_recurring_dga_loon` | float | No | DGA salary withdrawal |
| `outflows_recurring_lijfrentepremie` | float | No | Pension contributions |
| `outflows_btw_afdracht` | float | No | Quarterly BTW settlement (if due this week) |
| `outflows_ib_aanslag` | float | No | Annual IB/VPB tax settlement (if due) |
| `outflows_investeringen` | float | No | Capital expenditure |
| `outflows_totaal` | float | Yes | SUM of all outflows |
| `nettoMutatie` | float | Yes | `inflows_totaal - outflows_totaal` |
| `eindSaldo` | float | Yes | `openingSaldo + nettoMutatie` |
| `bufferStatus` | enum | No | One of `BOVEN_BUFFER`, `VOORALARM`, `CRISIS` (per buffer policy) |
| `alerts` | array (objects) | No | Array of alert records (see REQ-CF-005) |
| `administrazioneId` | string (FK) | Yes | FK to Administration |

#### Scenario: Weekly aggregation from AR projections

- **GIVEN** two `CashflowARProjection` records for the same week: "Acme €8.400 expected on 2026-05-28" (week-22) and "Client B €3.100 expected on 2026-05-29" (week-22)
- **WHEN** `CashflowWeek` for week-22 is computed
- **THEN** `inflows_ar_geprognosticeerd` SHALL be €11.500 for that week

#### Scenario: Recurring cost expansion into weekly slots

- **GIVEN** recurring "huur €850 on day 1 of month"
- **WHEN** horizon weeks are populated
- **THEN** weeks containing day-1 of any month within the horizon SHALL include €850 in `outflows_recurring_huur`

### Requirement: REQ-CF-003: Customer-specific AR projection using betalingsgedrag history

For each open AR invoice, the system MUST project the expected receipt date based on the customer's historical payment pattern, not the contractual due date.

**Betalingsgedrag calculation (12-month moving average)**:

1. Query all settled `ARInvoice` records for this customer in the last 12 months.
2. If count < 5 invoices: use contractual `paymentTermDays` + 7 days as fallback; mark confidence "LAAG" (< 5 samples).
3. If count ≥ 5 invoices: compute mean offset = avg(actual_payment_date - due_date); confidence = min(1.0, count / 12).
4. Project forward: `verwachtOntvangstDatum = dueDate + meanOffset`.
5. Store in `CashflowARProjection` with `betrouwbaarheidScore` = confidence.

For variance analysis, support scenario weights: "80% probability on-time, 20% delayed by 14 days".

#### Scenario: Acme pays 13 days late on average

- **GIVEN** Acme has 9 settled invoices in 12 months with mean offset +13 days, confidence 0.75
- **AND** new invoice due 2026-05-15
- **WHEN** AR projection is computed
- **THEN** `verwachtOntvangstDatum` SHALL be 2026-05-28 (May 15 + 13 days)
- **AND** `betrouwbaarheidScore` SHALL be 0.75

#### Scenario: New customer without payment history

- **GIVEN** customer "New Corp" has 0 prior invoices
- **AND** invoice due date 2026-06-15 with contractual term net 30
- **WHEN** AR projection is computed
- **THEN** `verwachtOntvangstDatum` SHALL be 2026-06-22 (30 days + 7-day buffer)
- **AND** `betrouwbaarheidScore` SHALL be marked "LAAG" (< 5 samples)

### Requirement: REQ-CF-004: CashflowARProjection Schema

The `CashflowARProjection` register SHALL track one record per open AR invoice, with expected receipt date and confidence.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `projId` | string (UUID) | Yes | Unique identifier |
| `horizonId` | string (FK) | Yes | FK to CashflowForecastHorizon |
| `arInvoiceId` | string (FK) | Yes | FK to AR invoice |
| `klantId` | string | No | Customer identifier (de-normalized for reporting) |
| `factuurDatum` | date | Yes | Invoice issuance date |
| `vervalDatum` | date | Yes | Contract due date |
| `openstaandBedrag` | float | Yes | Outstanding amount in EUR |
| `verwachtOntvangstDatum` | date | Yes | Projected receipt date (per betalingsgedrag) |
| `verwachtOntvangstWeek` | string | No | ISO week string (e.g., "2026-w22") |
| `betalingsHistorie_gemiddeldeAfwijking` | string | No | Mean offset (e.g., "+13 days") |
| `betalingsHistorie_facturen12mnd` | integer | No | Count of invoices in 12-month sample |
| `betalingsHistorie_betaaldVoorVerval` | integer | No | Count paid on-or-before due date |
| `betrouwbaarheidScore` | float (0-1) | No | Confidence score |
| `administrazioneId` | string (FK) | Yes | FK to Administration |

**Relations:**
- M:1 with `ARInvoice` (read-only FK)
- M:1 with `CashflowForecastHorizon`

### Requirement: REQ-CF-005: Recurring costs automatic scheduling with lifecycle + CPI indexing

Recurring cost items (huur, verzekering, abonnementen, DGA-loon, pension) are defined once in a `CashflowRecurring` registry and auto-expanded into the 13-week horizon based on frequency, activation window, and optional annual indexing.

#### CashflowRecurring Schema:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `recurId` | string (UUID) | Yes | Unique identifier |
| `ondernemingId` | string (FK) | Yes | FK to Corporation |
| `label` | string | Yes | Human-readable name (e.g., "Huur kantoorruimte De Werkfabriek") |
| `categorie` | enum | Yes | One of: `RECURRING_HUUR`, `RECURRING_VERZEKERING`, `RECURRING_ABONNEMENTEN`, `RECURRING_SOFTWARE`, `RECURRING_DGA_LOON`, `RECURRING_LIJFRENTEPREMIE`, `RECURRING_LEASING`, `RECURRING_OVERIG` |
| `richting` | enum | Yes | `IN` (inflow) or `OUT` (outflow) |
| `frequentie` | enum | Yes | `MAANDELIJKS`, `KWARTAALS`, `JAARLIJKS`, `WEKELIJKS`, `TWEEWEKELIJKS` |
| `dagVanMaand` | integer | No | Day of month (1-31) for monthly recurrence; null for other frequencies |
| `maandVanJaar` | integer | No | Month (1-12) for annual recurrence; null for other frequencies |
| `standaardBedrag` | float | Yes | Base amount in EUR |
| `indexatieRegel` | enum | No | `FIXED` (no change), `CPI_AFGELOPEN_JAAR` (annual update per CBS CPI) |
| `geldigVan` | date | Yes | Effective start date; horizon-roll ignores weeks before this |
| `geldigTot` | date | No | Expiration date; null if indefinite. Horizon-roll ignores weeks after this |
| `administrazioneId` | string (FK) | Yes | FK to Administration |
| `accountNumberExpense` | string (FK) | No | GL account code for the recurring cost (used on settlement) |

**Relations:**
- M:1 with `Administration`

#### Scenario: Monthly rent recurring every 1st of month

- **GIVEN** `CashflowRecurring` with `label: "Huur kantoor"`, `frequentie: MAANDELIJKS`, `dagVanMaand: 1`, `standaardBedrag: 850`, `geldigVan: 2024-09-01`, `geldigTot: null`
- **WHEN** horizon weeks for May-June 2026 are computed
- **THEN** week containing 2026-06-01 SHALL include €850 in `outflows_recurring_huur`
- **AND** week containing 2026-05-01 SHALL include €850 if still within horizon

#### Scenario: Annual insurance with CPI indexing

- **GIVEN** `CashflowRecurring` with `label: "BAV-verzekering"`, `frequentie: JAARLIJKS`, `maandVanJaar: 7`, `standaardBedrag: 620` (as of 2024-07-01), `indexatieRegel: CPI_AFGELOPEN_JAAR`
- **AND** CBS published CPI for 2024 = +3.2%
- **WHEN** horizon for July 2025 is computed
- **THEN** projected amount SHALL be €620 × 1.032 = €639.84
- **AND** amount for July 2024 shall remain €620 (no retroactive reindex)

### Requirement: REQ-CF-006: AP scheduling from open invoices + due-date projection

All open `APTransaction` (accounts-payable invoices) within the 13-week horizon are automatically scheduled based on `dueDate` or `paymentTerms`, grouped by week, and summed into `outflows_ap_geprognosticeerd` per `CashflowWeek`.

#### Scenario: AP invoice due within horizon

- **GIVEN** open AP invoice "KPN Zakelijk €184" with `dueDate: 2026-05-30`
- **WHEN** horizon for weeks 22-23 (May 25 - June 7) is computed
- **THEN** `CashflowWeek` for week-22 SHALL include €184 in `outflows_ap_geprognosticeerd`

### Requirement: REQ-CF-007: Quarterly BTW-afdracht automatic projection per Belastingdienst calendar

The system MUST project quarterly BTW settlements on the following hardcoded Dutch dates (last business day of the month following quarter-end, per Belastingdienst regulation):

- Q1 (Jan–Mar): due 30 April (or next business day if weekend/holiday)
- Q2 (Apr–Jun): due 31 July
- Q3 (Jul–Sep): due 31 October
- Q4 (Oct–Dec): due 31 January (following year)

BTW amount is projected based on the current year's cumulative turnover and VAT rate (21% standard; 9% reduced; 0% exempt — per onderneming configuration or AR turnover split).

#### Scenario: Q2 BTW due July 31

- **GIVEN** `CashflowForecastHorizon` spanning May–August 2026
- **AND** projected Q2 BTW liability = €4.820 (based on Apr–Jun turnover)
- **WHEN** horizon for week-31 (July 26 – August 1) is computed
- **THEN** `CashflowWeek` for week-31 SHALL include €4.820 in `outflows_btw_afdracht`

### Requirement: REQ-CF-008: VA IB/VPB aanslag projection on peilmaanden

The system MUST project annual IB (inkomstenbelasting) / VPB (vennootschapsbelasting) settlements on the following peilmaanden per Belastingdienst calendar (subject to final aanslag lags of 3-6 months):

- May: partial/provisional aanslag for current year
- September: revised provisional aanslag
- November: final aanslag (for prior year or current-year update)

Aanslag amounts are estimated based on prior-year aanslag adjusted for projected current-year turnover growth.

#### Scenario: September VA aanslag

- **GIVEN** prior-year VA aanslag (Sept 2025) = €2.100
- **AND** projected current-year turnover growth = +15%
- **AND** `CashflowForecastHorizon` spanning Aug–Nov 2026
- **WHEN** horizon for week-36 (September 2026) is computed
- **THEN** `CashflowWeek` for the week containing September peilmaand SHALL include estimated €2.100 × 1.15 = €2.415 in `outflows_ib_aanslag`

### Requirement: REQ-CF-009: Buffer-policy definition with two-tier alerts

The operator SHALL configure a `CashflowBufferPolicy` record defining the minimum cash reserve for the business, with two alert levels:

#### CashflowBufferPolicy Schema:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `policyId` | string (UUID) | Yes | Unique identifier |
| `ondernemingId` | string (FK) | Yes | FK to Corporation |
| `policy` | enum | Yes | `MIN_FIXED_AMOUNT`, `MIN_MONTHS_VASTE_KOSTEN`, or `CUSTOM_FORMULA` |
| `berekendeBuffer` | float | Yes | Calculated buffer threshold (in EUR) based on policy |
| `alertOndergrens` | float | Yes | Critical alert threshold (red): saldo < this value |
| `alertVooralarm` | float | Yes | Pre-alert threshold (yellow): saldo < this value |
| `administrazioneId` | string (FK) | Yes | FK to Administration |

**Policy calculation**:
- `MIN_FIXED_AMOUNT`: buffer = operator-specified EUR amount.
- `MIN_MONTHS_VASTE_KOSTEN`: buffer = (1 month × average-monthly-fixed-costs). Fixed costs = huur + verzekering + abonnementen + avg-dga-salary.
- `CUSTOM_FORMULA`: buffer = operator-defined expression.

**Alert thresholds**:
- `alertVooralarm` = buffer × 150% (yellow warning: "buffer eroding but still positive")
- `alertOndergrens` = buffer × 50% (red critical: "crisis mode — immediate action required")

#### Scenario: Buffer-onderschrijding within 4 weeks

- **GIVEN** buffer policy "min 1 month vaste kosten" = €5.200
- **AND** week-24 saldo projection = €4.800
- **WHEN** `CashflowWeek` for week-24 is computed
- **THEN** `bufferStatus` SHALL be `CRISIS`
- **AND** an alert record SHALL be added: `{type: "BUFFER_ONDERSCHRIJDING", severity: "RED", suggestedActions: [...]}`

#### Scenario: Vooralarm (yellow alert)

- **GIVEN** buffer policy €5.200 with vooralarm at €7.800 (150%)
- **AND** week-23 saldo = €7.500
- **WHEN** `CashflowWeek` for week-23 is computed
- **THEN** `bufferStatus` SHALL be `VOORALARM`
- **AND** a yellow alert SHALL appear

### Requirement: REQ-CF-010: Crisis-mode activation at predicted negative saldo within 4 weeks

When the 13-week forecast predicts a negative ending saldo in any week within the next 4 weeks (weeks 1-4), the system SHALL activate "crisis mode", which:

1. Triggers daily (not weekly) horizon re-computation.
2. Activates a red "CRISIS ACTIEF" banner on the cashflow dashboard.
3. Surfaces concrete, ranked action-suggestions based on the saldo shortfall amount and timing.

**Crisis-mode deactivation**: Once the forecast shows all weeks in the next 4 weeks with positive saldo, crisis mode is deactivated automatically.

#### Scenario: Crisis-mode activated and deactivated

- **GIVEN** week-2 projected saldo = -€2.400
- **WHEN** dashboard loads
- **THEN** crisis mode SHALL be active
- **AND** suggested actions SHALL include: (1) "Deferrable expense: DGA-loon (€3.200) → move to week-5" (resolves shortfall), (2) "AR acceleration: Contact 'Acme' to pay early invoice 2026-0247 (€8.400, currently due week-4)" (prevents crisis)
- **AND** cron job recalculates daily (instead of Monday-only)

**Later** (after Acme payment received):
- **GIVEN** week-2 now projects +€6.100 saldo
- **WHEN** cron job recalculates
- **THEN** crisis mode SHALL be deactivated
- **AND** dashboard switches to weekly-only refresh schedule

### Requirement: REQ-CF-011: Scenario-analysis engine ("what if X")

The operator SHALL be able to create point-in-time copies of the current `CashflowForecastHorizon` with one or more adjustments, and re-compute the entire 13-week forecast for comparison.

#### CashflowScenario Schema:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `scenarioId` | string (UUID) | Yes | Unique identifier |
| `horizonId` | string (FK) | Yes | FK to parent CashflowForecastHorizon |
| `naam` | string | Yes | Scenario name (e.g., "Acme pays late by 4 weeks", "Accept project Y") |
| `description` | string | No | Scenario description |
| `aanpassingen` | array (objects) | Yes | Array of adjustment rules |
| `resultaat` | object | No | Computed forecast results (populated after calculation) |
| `createdAt` | datetime | Yes | Scenario creation timestamp |
| `administrazioneId` | string (FK) | Yes | FK to Administration |

**Adjustment types** (in `aanpassingen` array):

1. **AR_PROJECTION_OVERRIDE**: Shift a specific AR invoice's expected receipt date or modify probability.
   ```json
   {
     "type": "AR_PROJECTION_OVERRIDE",
     "arInvoiceId": "fact-2026-0247",
     "weekShift": 4,
     "kansVanBetaling": 0.40
   }
   ```

2. **RECURRING_COST_ADJUSTMENT**: Pause or increase a recurring cost.
   ```json
   {
     "type": "RECURRING_COST_ADJUSTMENT",
     "recurId": "rec-dga-loon",
     "adjustmentType": "PAUSE",
     "weeks": [22, 23, 24]
   }
   ```

3. **NEW_REVENUE**: Add a hypothetical new deal/project.
   ```json
   {
     "type": "NEW_REVENUE",
     "name": "Project X",
     "amount": 12000,
     "probability": 0.65,
     "expectedReceiptWeek": 26
   }
   ```

4. **BUFFER_POLICY_OVERRIDE**: Temporarily adjust buffer for "what if" stress-testing.
   ```json
   {
     "type": "BUFFER_POLICY_OVERRIDE",
     "bufferAmount": 10000
   }
   ```

#### Scenario: Acme doesn't pay

- **GIVEN** `CashflowForecastHorizon` with baseline saldo minimum of €8.500 in week-26
- **WHEN** operator creates scenario "Acme doesn't pay €8.400" with adjustment `{type: "AR_PROJECTION_OVERRIDE", arInvoiceId: "fact-2026-0247", weekShift: 100, kansVanBetaling: 0}`
- **THEN** system creates a `CashflowScenario` snapshot and re-computes all 13 weeks
- **AND** result shows saldo minimum drops to €100 in week-26
- **AND** `resultaat.minBufferWeek: "2026-w26"`, `minBufferBedrag: 100`, `onderschrijdingBuffer: true`
- **AND** operator can switch between "baseline" and "Acme pays late" scenarios on the dashboard for visual comparison

#### Scenario: Accept new 3-month project

- **GIVEN** pipelinq deal "Design project €18.000, 3 months, 65% probability to close"
- **WHEN** operator creates scenario "Accept Design project"
- **AND** adjustments include `{type: "NEW_REVENUE", name: "Design", amount: 18000, probability: 0.65, expectedReceiptWeek: 25, recurringMonthly: 6000}`
- **THEN** forecast recalculates
- **AND** total inflows over 13 weeks increase by ~€11.700 (€18.000 × 0.65)
- **AND** if project has associated costs (+€800/month), those are also factored in

### Requirement: REQ-CF-012: Bankfeed reconciliation with PSD2 integration

The system SHALL integrate with `openconnector` to fetch daily bank-feed data (saldo, transactions) from PSD2-connected Dutch business accounts (Bunq, Knab, ING zakelijk, Rabo zakelijk). Daily pull (by 08:00 AM; aligns with Monday cron at 02:00 AM).

**Reconciliation workflow**:

1. Daily bankfeed pull reads all cleared transactions since last pull.
2. System attempts automatic match: transaction amount + party reference → AR invoice or AP payment.
3. Unmatched transactions are flagged as "requires manual review".
4. Operator confirms matches (in a UI modal or report).
5. On confirmation, AR invoice transitions `issued → paid` (lifecycle action); AP payment is marked `settled`.

#### Scenario: Customer Acme pays on bankfeed

- **GIVEN** open AR invoice "fact-2026-0247, Acme BV, €8.400, due 2026-05-15"
- **AND** bankfeed detects inbound transaction "ACME BV, €8.400, reference 'INV 0247'"
- **WHEN** reconciliation runs
- **THEN** system suggests match to "fact-2026-0247"
- **AND** operator confirms in UI
- **AND** AR invoice transitions to state `paid`
- **AND** `CashflowWeek` for that week updates `inflows_ar_gerealiseerd: 8400` and re-computes saldo

#### Scenario: Unexpected bank transaction

- **GIVEN** inbound transaction "€1.200, reference 'Payment X, partial'"
- **WHEN** reconciliation runs
- **THEN** transaction is flagged as "unmatched" (no corresponding invoice found)
- **AND** operator is prompted in dashboard: "Unmatched inflow €1.200 on 2026-05-27 — is this a client payment, refund, or other?"

### Requirement: REQ-CF-013: Calibration reporting — post-month-end forecast accuracy

At month-end (configurable, default 1st of month 09:00 AM), the system MUST:

1. Compare actual vs forecast for the prior month (just-completed week-4).
2. Calculate MAPE (Mean Absolute Percentage Error) by category: AR, AP, recurring costs, taxes.
3. Update customer-specific betalingsgedrag model (recalculate mean offset + confidence).
4. Update pipeline-conversion model (deal probability calibration).
5. Store calibration report in `CashflowCalibrationReport` register.

#### CashflowCalibrationReport Schema:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `reportId` | string (UUID) | Yes | Unique identifier |
| `horizonId` | string (FK) | Yes | FK to CashflowForecastHorizon |
| `calibrationPeriod` | string | Yes | Period evaluated (e.g., "2026-05") |
| `generatedAt` | datetime | Yes | Timestamp of calibration run |
| `ar_mape` | float (%) | Yes | MAPE for AR projections |
| `ap_mape` | float (%) | Yes | MAPE for AP projections |
| `recurring_mape` | float (%) | Yes | MAPE for recurring costs |
| `tax_mape` | float (%) | Yes | MAPE for tax projections |
| `betalingsgedragUpdates` | array | No | List of customers with re-calculated offsets |
| `pipelineConversionUpdates` | array | No | List of deals with re-calibrated probability |
| `administrazioneId` | string (FK) | Yes | FK to Administration |

#### Scenario: May calibration run

- **GIVEN** May 2026 forecast for weeks 18-22 (Apr 27 – May 31)
- **AND** actual realized: AR in €32.100 (forecast: €31.500), AP out €8.900 (forecast: €8.750), recurring €10.200 (forecast: €10.200)
- **WHEN** calibration runs on 2026-06-01 09:00
- **THEN** report generated with:
  - `ar_mape: 1.9%` (forecast within 2%)
  - `ap_mape: 1.7%`
  - `recurring_mape: 0%` (perfect)
  - Betalingsgedrag updates for 3 customers with new payment-history samples
  - Pipeline conversion: "Design deal" closes at 72% actual (was 65% projected) → probability updated to 0.72

### Requirement: REQ-CF-014: Three spaardoel virtual accounts (1-2-3 strategy)

The system MUST support three earmarked reserve buckets on the opening-saldo breakdown, with optional auto-allocation rules:

1. **spaardoel_btw**: Reserve for quarterly BTW afdracht (typically 3-month trailing turnover × 21%).
2. **spaardoel_ib**: Reserve for annual IB/VPB aanslag (typically 40-50% of prior-year aanslag).
3. **spaardoel_buffer**: Emergency buffer (1-3 months fixed costs).

The three spaardoelen are shown separately on the cashflow dashboard. Optional auto-reallocation rule: when an AR invoice is paid (via bankfeed reconciliation), a percentage (configurable, default 0%) is automatically moved from `zakelijkeRekening` → `spaardoel_btw` (for VAT) and `spaardoel_ib` (for tax).

#### Scenario: Auto-reallocation on invoice receipt

- **GIVEN** auto-reallocation enabled: 21% → spaardoel_btw, 15% → spaardoel_ib
- **AND** AR invoice €1.000 + €210 VAT (=€1.210 gross) is paid via bankfeed
- **WHEN** reconciliation confirms receipt
- **THEN** transaction is split: €1.000 (net) stays in zakelijkeRekening, €210 → spaardoel_btw (21% rule overridden by VAT amount), €0 → spaardoel_ib (covered by net €1.000 × 15%)
- **AND** if net > 15% allocation: €150 → spaardoel_ib, remainder in zakelijkeRekening

### Requirement: REQ-CF-015: Dashboard visualization — 13-week bar chart with alerts

The cashflow dashboard MUST display:

1. **13-week bar chart**: X-axis = week number (w18–w30), Y-axis = EUR. Bars stacked: inflows (green), outflows (red). Net saldo as line graph (overlay).
2. **Buffer zone**: Horizontal band or line marking the configured buffer threshold + vooralarm threshold.
3. **Alerts**: Weeks with CRISIS or VOORALARM status highlighted (red or yellow background).
4. **Detail breakdown**: Clicking a week shows: AR inflows (by customer), AP outflows (by creditor), recurring costs, taxes.
5. **Scenario switcher**: Dropdown to switch between "Baseline" and named scenarios.
6. **Export to PDF**: Button to generate bank-meeting summary (see REQ-CF-016).

#### Scenario: Visualisation renders correctly

- **GIVEN** `CashflowForecastHorizon` with 13 weeks computed
- **WHEN** dashboard loads
- **THEN** bar chart renders with all 13 weeks
- **AND** buffer line is visible
- **AND** weeks with saldo < buffer are highlighted

### Requirement: REQ-CF-016: PDF export for bank/accountant meetings

The system MUST generate a PDF export of the 13-week cashflow forecast, suitable for sharing with a bank (for rekening-courant or financing discussions) or an accountant. The PDF SHALL include:

1. **Horizon summary table**: Week-by-week inflows, outflows, net, saldo, buffer status.
2. **13-week bar chart** (same as dashboard visualization).
3. **Assumptions documented**: 
   - Customer-specific betalingsgedrag offsets (per major customer, top 5 by AR balance).
   - Recurring costs breakdown.
   - BTW/IB-aanslag methodology (hardcoded calendar vs GL estimate).
   - Pipeline deals included (deal name, probability, expected close week).
4. **Scenario comparison** (if operator selects multiple scenarios to export): baseline vs "crisis" vs "conservative" side-by-side.
5. **Stress test**: "What if the top 3 customers all delay by 14 days?" — quick scenario with impact summary.

#### Scenario: PDF export for bankgesprek

- **GIVEN** operator requests PDF export with scenario "Baseline"
- **WHEN** "Export PDF" button clicked
- **THEN** system generates PDF with all sections above
- **AND** PDF is downloadable or emailed to pre-configured recipient (accountant, bank contact)

### Requirement: REQ-CF-017: Model versioning and forward compatibility

The system MUST track the `modelVersie` on each `CashflowForecastHorizon` to support future enhancements without breaking historical forecasts.

**Current model version**: `v4.1-klantspecifiek-betaalgedrag` (per design.md).

**Future versions** (placeholder for T3+ enhancements):
- `v5.0-sezoenseffecten`: Seasonal adjustment factors (e.g., -30% turnover in July for tourism).
- `v5.1-fxrisk`: Foreign customer AR with FX variance.
- `v6.0-factoring`: Supply-chain financing impact on saldo timing.

#### Scenario: Upgrade does not break historical forecasts

- **GIVEN** historical `CashflowForecastHorizon` records with `modelVersie: "v4.0"`
- **WHEN** system is upgraded to v4.1
- **THEN** old records remain queryable with v4.0 logic (no retroactive recompute)
- **AND** new horizons default to `modelVersie: "v4.1"`

---

## Cross-Project References

This spec depends on and integrates with:

- **bookkeeping-accounts-receivable-core** (T2) — AR register, invoice lifecycle, dunning workflow
- **bookkeeping-accounts-payable-core** (T2) — AP register, payment scheduling, dunning
- **bookkeeping-general-ledger** (T1) — GL account chart, journal entry posting
- **bookkeeping-audit-trail** (T2) — Automatic audit on scenario creation, recurring changes
- **openconnector** (existing) — PSD2 bankfeed API for saldo/transaction pulls
- **pipelinq** (existing) — CRM/sales-pipeline deal read API
- **openregister** (existing) — `x-openregister-lifecycle`, `x-openregister-aggregations` core engines
