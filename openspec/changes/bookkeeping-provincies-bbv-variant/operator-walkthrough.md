# Operator walkthrough — bookkeeping-provincies-bbv-variant

> Task 32 artefact. End-to-end operator script for an Annemarie /
> province-staff persona to drive the two manifest pages from a clean
> seed state to a healthy compliance posture. Each step ends in an
> observable acceptance criterion (✓).

## Persona

**Annemarie de Vries** — VNG-aligned standards architect at a
provincie, responsible for BBV compliance reporting. Comfortable
reading the seven BBV programme codes and acting on the dashboard
without developer support.

## Pre-conditions

- Shillinq is installed and enabled, with the
  `bbv-provincies-budgets-2026.json` seed loaded
  (`lib/Settings/seeds/`).
- The active administration's `administrationType` is `provincie`.
- The operator's Nextcloud user is in the Shillinq app group with
  read + write access to `Budget` and `GLLine`.
- No browser tab is already open on the dashboard (we want fresh
  cache to test the refresh behaviour).

## Walkthrough

### Step 1 — Create 2-3 budgets across programmes

1. Open **Shillinq → Budgets** (or the seed already provides
   `mobiliteit €500k`, `water €300k`, `cultuur €150k`).
2. If creating manually:
   - Budget 1: `programmaStructure = mobiliteit`, `totalAmount = 500_000_00` cents,
     `fiscalYear = 2026`, `status = approved`.
   - Budget 2: `programmaStructure = water`, `totalAmount = 300_000_00` cents,
     `fiscalYear = 2026`, `status = approved`.
   - Budget 3: `programmaStructure = cultuur`, `totalAmount = 150_000_00` cents,
     `fiscalYear = 2026`, `status = provisional`.
3. Confirm: the Budgets index lists the three rows (✓).

### Step 2 — Post 5-10 GL lines spanning the three programmes

1. Open **Shillinq → General Ledger → GL Lines** (or the seed
   provides 3-4 lines per budget).
2. If creating manually, post mixed `posted` + `committed` lines:
   - 3 mobiliteit expense lines totalling 380_000_00 cents
     (76% utilised).
   - 2 water expense lines totalling 310_000_00 cents
     (103% utilised — triggers an overspend exception).
   - 1 cultuur expense line at 50_000_00 cents (33% utilised).
   - 1 GL line **deliberately left unmapped** (programme = null) to
     exercise the linker flow in step 6.
3. Confirm: the GL Lines index lists all the rows (✓).

### Step 3 — Open the dashboard; KPI cards match budget vs. GL totals

1. Open **Shillinq → BBV Provincie → BBV Compliance Dashboard**.
2. Confirm the four KPI cards render with non-zero values (✓):
   - **Total budget** = 950_000_00 cents (= sum of the three budgets).
   - **Spent** = 740_000_00 cents (= sum of the posted lines).
   - **Remaining** = budget − spent − committed.
3. Confirm the **budget-vs-actuals** bar chart shows three bars,
   one per programme (✓).
4. Confirm the **trend** line chart shows a continuous cumulative
   curve with no missing months (✓).

### Step 4 — Exceptions block surfaces the water overspend

1. Scroll to the **Exceptions** block on the dashboard.
2. Confirm `water` appears as a single red-status row with the
   overspent amount in cents (✓).
3. Click the row's link affordance.
4. Confirm the linker opens with `Programme = water` pre-filtered
   (✓).

### Step 5 — Filter the dashboard by `programmaStructure = mobiliteit`

1. Return to the dashboard.
2. Open the **Programme** filter facet and select `mobiliteit`.
3. Confirm the bar chart, trend chart, and KPI cards all re-query
   to a single-programme view (✓).
4. Clear the filter; the dashboard returns to the all-programmes
   view.

### Step 6 — Bulk-link the unmapped GL line

1. Open **Shillinq → BBV Provincie → Budget Links**.
2. Set the **Assignment status** filter to `unmapped`.
3. Confirm the single unmapped GL line from step 2 appears in the
   table (✓).
4. Tick the row's checkbox.
5. Click **Link to Programme**.
6. In the dialog:
   - Set **Target programme** = `economie`.
   - Leave **Effective date** at the default (today).
7. Click **Link**.
8. Confirm:
   - Toast reads `Linked 1 GL lines to Economie` (✓).
   - The row leaves the index (it is now mapped) (✓).
   - The mapping-status badge updates downward (smaller percentage
     of unmapped lines) (✓).

### Step 7 — Edit a GL line in detail view

1. Clear the **Assignment status** filter.
2. Click any mapped GL line to open the detail view.
3. Change `programmaStructure` from its current value to
   `bestuur`.
4. Save.
5. Confirm:
   - The detail form persists the new value on reload (✓).
   - The OR audit-trail sidebar shows the change with operator,
     timestamp, before / after, and source `manual-edit:`
     (✓).

### Step 8 — Verify the admin refresh-interval dropdown

1. Open **Nextcloud → Administration → Shillinq** (admin settings).
2. Locate the **Dashboard refresh interval** dropdown.
3. Confirm the four options render (`real-time`, `hourly`,
   `daily`, `weekly`) (✓).
4. Set the dropdown to `hourly` and click **Save**.
5. Confirm a success toast and that the value persists on reload
   (✓).
6. Set the dropdown back to `daily` (the default) and save.

## Acceptance summary

All eight steps must pass for the change to be considered
operator-ready. If any step fails, file the regression as a
sub-issue against this change and re-run the walkthrough after the
fix.

## Notes for the runner

- The walkthrough assumes the seed file is loaded. A fresh
  Shillinq install on a non-provincie administration will not show
  the navigation entries — that is intended behaviour, not a bug.
- The `programmeBudgetVsActuals` aggregation is materialised
  server-side on the GLLine schema; the dashboard reads the
  materialised value rather than recomputing in Vue. If the KPI
  cards lag the GL postings, wait one refresh cycle (default:
  daily, 02:00 UTC) or trigger a manual refresh.
- All monetary values are stored in cents (integer) per the
  fleet-wide money convention; the dashboard renders euros via
  the EUR currency formatter declared in the manifest KPI block.
