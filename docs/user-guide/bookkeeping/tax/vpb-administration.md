---
sidebar_position: 1
title: Vpb (Corporate Tax) Administration
description: Manage Dutch corporate-tax (Vpb / Vennootschapsbelasting) deadlines, track provisional and final payments against the general ledger, and produce quarterly statements with the untagged-posting warning your tax adviser expects.
---

# Vpb (Corporate Tax) Administration

Manage Dutch corporate income tax — **Vennootschapsbelasting**, abbreviated
**Vpb** — from inside Shillinq. The Vpb workflow keeps every fiscal year on
schedule with the deadline diary, provisional-payment tracking against the
GL, quarterly statement aggregation, and the audit trail your tax adviser
and the Belastingdienst inspector expect, without a parallel spreadsheet.

A medium-sized BV typically owes Vpb in two waves per fiscal year — the
**voorlopige aanslag** (provisional assessment, paid in monthly or
quarterly instalments throughout the year) and the **definitieve aanslag**
(final assessment after the fiscal-year close, settled against what was
already paid). Shillinq's Vpb workflow ties the deadlines, payments, and
the GL-derived quarterly statement together on one page so your controller
sees the gap between provisional and final at every reporting cut-off.

## Goal

By the end of this guide you will be able to:

- Maintain the Vpb **deadline diary** for the current fiscal year —
  provisional-payment instalments, final-return due dates, and
  extension-request windows.
- Track every Vpb **payment** against its linked GL account and reconcile
  the payment record to the GL posting.
- Produce the **quarterly tax statement** for any closed quarter, review
  the per-account-type breakdown, see the untagged-posting warning for
  any tax-relevant account that lacks a `taxTreatment` tag, and export
  the statement to Excel or PDF for your adviser.
- Receive **deadline reminders** in the Nextcloud notification panel 7
  days and 1 day before every Vpb deadline, with a click-through to the
  deadline detail page.

## Prerequisites

- A Nextcloud account with **Shillinq** installed and enabled.
- The active administration has at least one closed fiscal period
  (T2 period-close has been run) so the quarterly statement has GL
  postings to aggregate. Open periods produce a partial statement and
  the period-close warning surfaces on the report.
- The chart of accounts has the tax-relevant accounts tagged — at
  minimum, every account that contributes to revenue, deductible
  operating expenses, non-operating items, or special deductions
  should have a `taxTreatment` tag set (see
  [Step 4 — Tag GL postings for tax treatment](#step-4--tag-gl-postings-for-tax-treatment)).
- A user holds the role `bookkeeping-operator` to maintain deadlines
  and payments. The `bookkeeping-controller` role is required to
  mark a quarterly statement final or to dismiss the untagged-posting
  warning.

## Where to find the Vpb workflow

In the Shillinq sidebar choose **Bookkeeping → Corporate tax (Vpb)**.
The cluster has four entries:

| Entry | What it does |
| --- | --- |
| **Tax deadlines** | Calendar-like list of every Vpb deadline (provisional payment, final return, extension request) with status, fiscal year, quarter, and the linked fiscal period. |
| **Tax payments** | Ledger of every Vpb payment with its linked GL account, payment type (provisional / final / adjustment), reconciliation status, and the related deadline. |
| **Quarterly statement** | The aggregation view — pick a fiscal year + quarter to produce the per-account-type breakdown and the net taxable income figure. |
| **Vpb settings** | Configuration: enable / disable deadline types, set the reminder window, customise the tax-treatment tag categories (normal / deductible / non-deductible / special) and the accounts they apply to. |

> **Screenshot:** the Bookkeeping → Corporate tax (Vpb) sidebar cluster
> with the four entries.
>
> *Screenshot pending — captured once the live screenshots run is
> scheduled. See [Screenshot status](#screenshot-status) below.*

## Step 1 — Open the tax deadline list

1. Sign in to Nextcloud and open **Shillinq**.
2. In the sidebar choose **Bookkeeping → Corporate tax (Vpb) → Tax
   deadlines**.

You will see every Vpb deadline for the active administration. Default
sort is by **Deadline date** ascending so the next upcoming deadline is
at the top. Columns: **Deadline date**, **Deadline type**, **Status**,
**Fiscal year**, **Quarter**, **Related period**.

The status column is colour-coded by lifecycle state — `pending` (open,
not yet acted on), `submitted` (sent to the Belastingdienst), `filed`
(confirmed filed by the Belastingdienst), and `archived` (closed out
after the fiscal year is done).

## Step 2 — Add or edit a deadline

1. From the tax deadline list click **Create** (+ icon) to open the
   `TaxDeadlineForm` create dialog.
2. Fill in the form. The form is schema-driven (see
   `lib/Settings/register.d/bookkeeping-vpb-corporate-tax.json` →
   `TaxDeadline`):
   - **Deadline date** (required) — the calendar date the obligation is
     due.
   - **Deadline type** (required, one of: `provisional-payment`,
     `final-return`, `extension-request`).
   - **Description** — short free-text summary. Defaults to a
     templated string driven by the deadline type and fiscal period.
   - **Fiscal year** (required, integer).
   - **Quarter** (optional, 1–4) — set for quarterly provisional
     instalments; leave empty for the final return and extension
     requests.
   - **Related period** — pick the fiscal period the deadline rolls up
     to, so the quarterly statement can find the deadline when the
     period closes.
3. Click **Create**.

Shillinq creates the deadline in `pending` status and you are returned
to the index. To edit a deadline, click its row and use the **Edit**
action on the detail page.

## Step 3 — Track the deadline lifecycle

From the tax deadline list, use the row actions to advance status:

- **Mark filed** — sets `status = filed` once the Belastingdienst
  confirms receipt. Appends an audit-trail event with the operator name
  and timestamp.
- **Mark paid** — used for a `provisional-payment` deadline once the
  matching payment is recorded. See
  [Step 6 — Record and reconcile a payment](#step-6--record-and-reconcile-a-payment)
  for the payment side.
- **Archive** — sets `status = archived` once the fiscal year is
  fully settled. Archived deadlines stay in the audit trail but are
  hidden from the default list (use the **Status: archived** filter to
  see them).

The deadline detail page at `/bookkeeping/vpb/deadlines/:id` shows:

- **Summary** — deadline date, type, status, fiscal year, quarter,
  related period, description.
- **Related GL postings** — every GL line linked to the fiscal period
  that touched a tax-relevant account, so you can spot a missing
  posting before the deadline.
- **Linked payment tracking** — every `TaxPaymentTracking` record that
  references this deadline.
- **Audit trail** (sidebar) — every status change ordered newest first.
  Immutable.
- **Files** + **Notes** tabs (sidebar) — supporting documents and
  free-text notes. The Files tab is backed by Nextcloud Files per
  ADR-022; the Notes tab uses the standard `notes` plugin.

> **Screenshot:** the tax deadline detail page showing the four sidebar
> tabs (Audit trail, Files, Notes, Linked payments).
>
> *Screenshot pending — see [Screenshot status](#screenshot-status).*

## Step 4 — Tag GL postings for tax treatment

The quarterly statement aggregates by **tax treatment** — `normal`,
`deductible`, `nonDeductible`, or `special`. Without a tag the
quarterly statement counts the posting under the **Untagged** bucket
and the untagged-posting warning surfaces on the report (REQ-VPB-010).

Two ways to tag:

- **Per posting** — open the GL detail page, select the **Tax
  treatment** field, save. The tag is appended to the `taxTreatment`
  field on the `GLLine` object.
- **Per account** — from the Vpb settings page configure default tax
  treatments per account (REQ-VPB-015). Postings to that account
  inherit the default unless explicitly overridden.

The default tag for a new posting is `normal`. The default is enforced
by the schema (`bookkeeping-vpb-corporate-tax.json` →
`GLLine.taxTreatment.default`); the field is required for accounts
flagged tax-relevant.

## Step 5 — Configure the reminder window

By default Shillinq dispatches deadline reminders 7 days and 1 day
before every deadline. Each reminder lands in the recipient's Nextcloud
notification panel with a click-through to the deadline detail page
(REQ-VPB-013).

To adjust:

1. **Bookkeeping → Corporate tax (Vpb) → Vpb settings**.
2. Under **Reminder window**, set the days-before-deadline values. Add
   or remove a value (e.g. add `14` for a two-week warning, remove
   `1` if same-day reminders are noise).
3. Click **Save**.

Reminder dispatch is handled by the `TaxNotificationService` background
job which runs daily — the schedule is fixed and operator-adjustable
via the OpenRegister `ScheduledWorkflow` admin UI for installations
that need a different cadence.

## Step 6 — Record and reconcile a payment

1. In the sidebar choose **Bookkeeping → Corporate tax (Vpb) → Tax
   payments**.
2. Click **Create** (+ icon) to open the payment create dialog. Fill
   in:
   - **Payment date** (required) — when the bank posted the
     instalment.
   - **Payment type** (required, one of: `provisional`, `final`,
     `adjustment`).
   - **Amount** (required, `MonetaryAmount`).
   - **Linked GL account** (required) — the Vpb-clearing account the
     payment was debited to.
   - **Related deadline** (optional) — pick the deadline the payment
     covers so the deadline-detail page picks it up under **Linked
     payment tracking**.
   - **Description** — short free-text note.
3. Click **Create**. The payment is saved in `pending` status.

To reconcile a payment against its GL posting:

1. Open the payment from the tax payments index, or use the
   **Reconcile** row action on the index for a single payment.
2. Shillinq looks up GL postings on the linked account whose amount
   and date match within the reconciliation tolerance. The match is
   shown on the detail page with the variance (GL amount − payment
   amount).
3. If the match is correct click **Confirm reconciliation** — the
   payment status flips to `reconciled` and the audit trail records
   the matched GL line id.
4. If the variance is non-zero a reconciliation suggestion is shown
   (typically a small currency-conversion or rounding adjustment).

Bulk reconciliation is available on the index via **Mass actions →
Reconcile selected**: Shillinq attempts to match every selected payment
and flags unmatched records for manual review.

> **Screenshot:** the tax payment reconciliation view showing the GL
> match panel and the variance figure.
>
> *Screenshot pending — see [Screenshot status](#screenshot-status).*

## Step 7 — Produce the quarterly statement

1. **Bookkeeping → Corporate tax (Vpb) → Quarterly statement**.
2. Pick the fiscal year and quarter from the selector at the top.
   The URL updates to `/bookkeeping/vpb/reports/:fiscalYear/:quarter`
   so the statement is bookmarkable.
3. Shillinq runs the `TaxReport` aggregation:
   - filter `GLLine` by fiscal period + tag classification;
   - group by account type (revenue, operating expenses, non-operating,
     special deductions);
   - sum amounts per group;
   - compute **net taxable income** (revenue − deductible − special
     deductions, treating non-deductible expenses as add-backs).

The statement shows:

- **Header** — fiscal year, quarter, administration, generation
  timestamp, generated-by user.
- **Per-account-type breakdown** — one row per account, with account
  code, account name, posted amount, `taxTreatment` tag, and the
  account's tax impact.
- **Subtotals** by account type and the net taxable income figure.
- **Untagged posting warning** — count of GL postings in tax-relevant
  accounts that lack a `taxTreatment` tag, with a click-through to a
  filtered GL view so the operator can fix the tags before submitting
  the statement to the adviser (REQ-VPB-010).

Click **Export** to download the statement as Excel or PDF. The
export uses `ExportService` + `CnMassExportDialog` and includes the
breakdown table plus the header (REQ-VPB-011).

> **Screenshot:** the quarterly statement showing the breakdown table,
> subtotals, net taxable income, and the untagged-posting warning
> banner.
>
> *Screenshot pending — see [Screenshot status](#screenshot-status).*

## Step 8 — Roll up the annual summary

After the fiscal year's four quarterly statements are produced, Shillinq
rolls them up into an **annual summary** that compares the realised
year-to-date figures against the estimated provisional payments
(REQ-VPB-012). The annual summary lives on the same Quarterly statement
view — pick the fiscal year and the **Year-to-date** option in the
quarter selector.

The annual summary shows:

- The four quarterly subtotals.
- The annual revenue / expenses / net taxable income figure.
- The **variance against provisional payments** — the difference between
  what was paid in provisional instalments and the estimated tax
  liability based on the year-to-date figures.
- A **payment-plan suggestion** for the final-return settlement based
  on the variance.

The annual summary is the figure your adviser uses to draft the
definitieve aanslag at fiscal-year close.

## Frequently asked questions

### My quarterly statement shows postings under "Untagged" — what now?

Untagged postings are GL lines in tax-relevant accounts (revenue,
deductible expense accounts) that lack a `taxTreatment` tag (`normal`,
`deductible`, `nonDeductible`, `special`). Click the **Untagged
postings** warning banner at the top of the statement — Shillinq opens
a filtered GL view of the untagged lines. Tag each one (or set a
per-account default in Vpb settings) and refresh the statement.

### Can I edit a quarterly statement after it has been exported?

The export is a snapshot, not a freeze — re-running the aggregation
after tagging changes will produce a different figure. If you have
already filed the statement with your adviser, the operator workflow
is to leave the original export in the file area (Files tab) and run
the aggregation again under a new file name. The annual summary
roll-up uses the live aggregation, not the exported snapshots.

### What if a deadline reminder doesn't arrive?

`TaxDeadlineReminderJob` is registered in `appinfo/info.xml` and runs on a
24-hour interval, so it appears in `oc_background_jobs` and on the admin
**Jobs** page. If it is absent there, that is a broken install, not the
expected state.

Two reasons a reminder does not arrive, in practice:

1. The recipient's Nextcloud notification panel is paused or filtered
   for the `shillinq-vpb` notification source. Check Settings →
   Notifications.
2. The `TaxDeadlineReminderJob` background job did not run on the day
   the reminder was scheduled. Check the
   `oc_background_jobs` table or the admin **Jobs** page; the job
   id is `OCA\\Shillinq\\BackgroundJob\\TaxDeadlineReminderJob`.

### Can I delete a payment or a deadline?

No. Vpb deadlines and payments are subject to the Wet op de
Vennootschapsbelasting record-retention requirements and the audit
trail is immutable per ADR-022. Mistaken records are archived
(`status = archived`) with an audit-trail note explaining why.

### Where do I see the audit trail?

Every deadline and payment detail page has an **Audit trail** tab in
the sidebar showing the ordered list of state changes — `created`,
status changes, reconciliation events, archive events — with the
operator name, timestamp, and (where applicable) the matched GL line
id. The audit trail is immutable.

## Troubleshooting

| Error message | Cause | Fix |
| --- | --- | --- |
| **"Fiscal period is open — partial statement"** | The fiscal period for the selected quarter has not been closed; aggregation runs on open data. | Close the period via the Period Close workflow, then re-run the statement. |
| **"Untagged postings detected (N)"** | One or more GL postings in tax-relevant accounts lack a `taxTreatment` tag. | Click the warning banner; tag the postings or set a per-account default in Vpb settings. |
| **"Reconciliation variance exceeds tolerance"** | The payment amount and the matched GL posting differ by more than the configured tolerance. | Review the suggested adjustment; either confirm an exception (audit-trailed) or correct the GL posting. |
| **"Deadline reminder failed to dispatch"** | The recipient's notification source is filtered, or the background job did not run. | Check the recipient's notification settings; check the admin Jobs page for the `TaxNotificationJob` last-run. |
| **"Toegang tot deze administratie is niet toegestaan"** | The user does not belong to the administration that owns the deadline or payment. | Switch administration or ask an admin to grant membership. |

## Screenshot status

The screenshots referenced above (sidebar cluster, deadline detail
page, payment reconciliation view, quarterly statement) are captured by
the Playwright `docs-screenshots` spec against a seeded live instance.
The Vpb screenshot run is queued behind the live-instance follow-up for
this change (see the
[bookkeeping-vpb-corporate-tax tasks.md "Deferred" section](../../../../openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md))
— the screenshots land in `docs/static/img/user-guide/bookkeeping/tax/`
on the follow-up commit. The text above is the authoritative description
in the meantime.

## Where this fits

- Capability spec: `openspec/changes/bookkeeping-vpb-corporate-tax/specs/bookkeeping-vpb-corporate-tax/spec.md`
- Schema fragments: `lib/Settings/register.d/bookkeeping-vpb-corporate-tax.json`, `lib/Settings/register.d/bookkeeping-vpb-corporate-tax-balans.json`
- Manifest fragment: `src/manifest.d/bookkeeping-vpb-corporate-tax.json` (and `…-balans.json`)
- Server engine: `OCA\Shillinq\Service\TaxReportService`, `OCA\Shillinq\Service\TaxPaymentReconciliationService`, `OCA\Shillinq\Service\TaxNotificationService`, `OCA\Shillinq\Service\TaxReportCalculator`
- Controllers: `OCA\Shillinq\Controller\TaxReportController`, `OCA\Shillinq\Controller\TaxPaymentController`
- Notification background job: `OCA\Shillinq\BackgroundJob\TaxDeadlineReminderJob`
- Architecture ADRs: ADR-002 (controllers + routes), ADR-003 (services + notifications), ADR-009 (testing), ADR-010 (docs), ADR-022 (audit trails + immutability), ADR-037 (no monolith edit — register / manifest fragments)
