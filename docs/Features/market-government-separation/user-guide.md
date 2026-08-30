# WMO Compliance — Wet Markt en Overheid

> **Status:** Phase 1+2 shipped, Phase 3 architecture-only (deferred Q1–Q2 2027).
> **Spec:** [`openspec/changes/bookkeeping-market-government-separation`](../../../openspec/changes/bookkeeping-market-government-separation/specs/bookkeeping-market-government-separation/spec.md)
> **Roles:** `concerncontroller` (read/write), `finansieel-beleidsadviseur` (read/write), `juridisch-beleidsadviseur` (ABB read/write), `griffier` (ABB read/write), `gemeentesecretaris` (alert escalation), `auditor` (read).

## What WMO compliance does

The Wet Markt en Overheid (WMO, Mededingingswet hoofdstuk 4b) requires every bestuursorgaan (gemeente, provincie, waterschap, GR, RUD) that performs commercial activities on the open market to:

1. **Register every commercial activity** including ACM meldingstatus, kostprijsmethode, market context, and ABB exemption (REQ-WMO-001).
2. **Calculate an integral cost price (IKP) per activity per period** covering direct labour, materials, depreciation, indirect overhead (BBV taakveld 0.4 sleutel), capital cost (WACC), and a profit mark-up (REQ-WMO-002).
3. **Split mixed-purpose transactions** (e.g. an energy invoice for a sport hall used 64% for public schoolgymnastics and 36% for commercial dance lessons) between `PUBL` and `MO` dimensions using the geldende OverheadDistributionRule (REQ-WMO-003).
4. **Publish a jaarrekening-bijlage** with per-activity compliance status (groen / rood) plus prior-year comparison and ABB references (REQ-WMO-004).
5. **Manage Algemeen-Belang-Besluiten (ABB)** through a 10-state workflow with publication verification via the DROP API and automatic task generation (REQ-WMO-005).
6. **Generate ACM rapportages** in the ACM-standaardformulier-mo-2024 format with digital signature + 7-year retention (REQ-WMO-006).
7. **Detect cross-subsidy risks** monthly across 6 scenarios (loss-financing, omzet-spike, overhead under-allocation, ABB-stale, manual-override accumulation, overhead-onderschatting) plus the Phase-3 bevoordeling-risk scenario (REQ-WMO-007 + REQ-WMO-012).
8. **Maintain an immutable audit trail** with 7-year retention per Mededingingswet bewaartermijn (REQ-WMO-010) and a one-click ACM-handhavings-pakket export.

All registers, lifecycle workflows, and scheduled tasks are declared in OpenRegister fragments per ADR-031 — no bespoke shillinq backgound jobs run for any of this.

## Day-to-day workflow

### 1. Adding a commercial activity

Navigate to **Bookkeeping → WMO Compliance → Commercial Activities** and click **Add**. Fill in the required fields (code, naam, bestuursorgaan, organisatieonderdeel, marktsegment, kostenplaats / kostendrager FKs, ACM melding status). The activity is created in `state: active`; if it qualifies for an exemption, set `isExempted: true` and link an existing ABB via `exemptionBesluitId`.

The system auto-generates an annual review task assigned to the concerncontroller when `lastReviewedAt` is older than 365 days. Use **Mark reviewed** on the detail page to refresh the timestamp.

### 2. Monthly IKP calculation

The `wmo-ikp-monthly-calculation` ScheduledWorkflow runs on the 1st of each month at 03:00 UTC and produces a `voorlopig` IKP record per active activity for the prior month. The detail page shows the 6 component groups with their EUR totals and the cost-recovery ratio. Operators can adjust the `gehanteerdTarief` (actual price charged) to flip the compliance flag.

### 3. Year-end definitief lock

On 31 March of the following year, the `wmo-ikp-year-end-lock` workflow aggregates all monthly voorlopig records for the closed FY into a single `definitief` IKP-YTD record, signed by the accountant. Operators can manually trigger the lock earlier from the IKP detail page if all monthly records are ready.

### 4. Transaction splits

Every JournalEntry that lands on an account tagged with a CommercialActivity's `kostenplaats` / `kostendrager` triggers `ActivityCostAllocationSplitter::compose` declaratively (via `x-openregister-event-listeners` on ActivityCostAllocation). The split uses the OverheadDistributionRule effective on the posting date.

If the automatic split is wrong (e.g. wrong drager matched), open the allocation detail page and click **Manually override**. The form requires **two distinct approver user-ids** (4-ogen-akkoord) and a motivering; the original split is marked `status: overridden` and a new active allocation replaces it.

### 5. Algemeen-Belang-Besluiten (ABB)

Navigate to **Bookkeeping → WMO Compliance → Public Interest Decisions** and create a new ABB. The workflow is:

```
concept → raadsvoorstel → raadsbesluit → publicatie → acm-notified → bezwaar → geldig → evaluatie-due → (herziening | intrekking)
```

Each transition validates preconditions (e.g. publicatie requires gemeenteblad-kenmerk + datum; geldig requires ACM-kenmerk). After publicatie, the DROP API is consulted to verify the gemeenteblad reference; the result is stored on `dropVerification` and visible on the detail page. A failed verification raises an AlertLog but does not block the transition.

When an ABB enters `intrekking` or `herziening`, the linked CommercialActivity records are automatically flagged for operator review.

### 6. ACM rapportage

For each quarterly or annual reporting period, the concerncontroller generates an ACM-rapport from the **ACM Reports** index. The system aggregates omzet (revenue GL sum), integrale kostprijs (from the latest definitief IKP), kostendekkingsratio, and ABB references per activity. After review, click **Sign** (PKI fingerprint required) to flip the report to `ready-for-submission`, then **Submit** to mark it `verzonden` and start the 7-year retention countdown. The report can also be exported to gemeenteblad via openconnector.

### 7. Cross-subsidy alerts

The `wmo-cross-subsidy-detector` ScheduledWorkflow walks every active activity monthly and emits AlertLog records for the 6 risk scenarios. Open alerts surface on the dashboard and on each activity's detail page. Operators resolve alerts by marking them `reviewed-no-action` (with motivation) or `remediated` (with remediation notes).

Open alerts older than 4 weeks are automatically reassigned to the gemeentesecretaris by the `wmo-alert-escalation` walker on Mondays at 05:00 UTC.

### 8. Audit trail + handhavings-pakket

Every mutation on a WMO entity logs to `WMOAuditLog` with before/after JSON snapshots and ms-precision timestamps. The audit log is append-only and retained for 7 years.

For an ACM handhavings vordering, navigate to the **WMO Audit Log** index, select a fiscal year, and click **Export ACM-handhavings-pakket**. The export bundles per-activity snapshots, IKP records per period, allocation records, ABB PDFs, and the audit log CSV into a single transferable zip with a `manifest.json` index.

## Compliance status colours

| Compliance flag | Meaning |
|-----------------|---------|
| `groen` | `gehanteerdTarief >= kostprijsPerEenheid` (or `omzet >= integraleKostprijs` when no per-unit data) — meets Art. 25i Mededingingswet integrale-kostprijs eis. |
| `rood`  | Tariff below cost — operator must justify (e.g. ABB exemption) or raise the tariff. |

## i18n

All WMO labels (Commercial Activity, Integral Cost Price, ABB, ACM Report, …) are available in Dutch and English via `l10n/nl.json` / `l10n/en.json` with English-source keys per the project convention.

## Limitations (Phase 3 deferral, Q1–Q2 2027)

- Activity-transition workflow (publiek → commercieel openingsbalans at marktwaarde) is architecture-only.
- Raads-voorstel governance coupling via `bookkeeping-governance` is opt-in; freeform `raadsBesluitId` works without it.
- Multi-deelnemer activity per-deelnemer cost splitting (Gemeenschappelijke Regelingen) is architecture-only.
- BDO / COELO benchmark feed integrations are deferred; operators currently enter benchmark prices manually.

See `openspec/changes/bookkeeping-market-government-separation/design.md` decisions D9+ for the deferred surface.
