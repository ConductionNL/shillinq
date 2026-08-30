---
sidebar_position: 5
title: BCF VAT Compensation Claims
description: Prepare, submit, and track quarterly Btw-compensatiefonds (BCF) claims so your municipality recovers non-recoverable VAT from Belastingdienst on schedule.
---

# BCF VAT Compensation Claims

Recover non-recoverable VAT through the Btw-compensatiefonds (BCF) — the
quarterly compensation scheme through which Dutch municipalities,
provinces, and water boards (`waterschap`) reclaim VAT charged on
qualifying expenditure.

A medium-sized gemeente typically recovers ~€3M per year through BCF.
Shillinq's BCF claim workflow keeps every quarter on schedule with the
breakdown, audit trail, and DigiKoppeling submission your auditor
expects, without spreadsheets.

## Goal

By the end of this guide you will be able to prepare a quarterly BCF
claim from your general ledger, send it for approval, submit it to
Belastingdienst via DigiKoppeling, and confirm settlement when the
payment lands.

## Prerequisites

- A Nextcloud account with **Shillinq** installed and enabled.
- Your active administration's `administrationType` is `gemeente`,
  `provincie`, or `waterschap` — the **Overheid → BCF-claims** menu
  entry is hidden for non-public-sector administrations
  (`manifest.visibilityPredicate`).
- VAT/BTW filing is configured for your administration (capability
  `bookkeeping-vat-btw-filing`) so VAT-rate metadata is present on every
  GL posting.
- Your BBV account mapping is complete (capability
  `bookkeeping-bbv-compliance`) and each compensable RGS account is
  flagged `bcfCompensable: true` with an explicit
  `compensablePercentage` (100 for fully-compensable accounts; lower for
  mixed-use facilities such as a leisure centre or town hall).
- The fiscal period you intend to claim against is **closed** (T2
  period-close has been run). Shillinq blocks submission against an
  open quarter — see [Troubleshooting](#troubleshooting).
- A user holds the role `bcf-administrator` so the submission can be
  approved (the role is separate from `bcf-operator`, who prepares the
  claim).

## The BCF claim lifecycle

Every BCF claim moves through four states, in order:

| State | What it means | Who advances it |
| --- | --- | --- |
| `draft` | The operator is preparing the claim. The compensable breakdown recalculates from the GL on every save. | Operator (`bcf-operator`) |
| `submitted` | The claim has been approved and queued for the next quarterly DigiKoppeling submission. Figures are frozen. | Approver (`bcf-administrator`) via the approval-workflow task |
| `accepted` | Belastingdienst received and accepted the claim (typically 14-30 days post-submit). | External (Belastingdienst) |
| `settled` | Belastingdienst settled the payment (typically 30-60 days post-acceptance). `settledOn` and `settledAmount` are filled. | External (settlement webhook from OpenConnector) |

The state diagram:

```
[draft] → (submit, after approval) → [submitted] → (accept, external) → [accepted] → (settle, webhook) → [settled]
   ↑                                                                                                       │
   └─────────────────────────────── (revert, rare, admin only) ───────────────────────────────────────────┘
```

## Step 1 — Open the BCF claims index

1. Sign in to Nextcloud and open **Shillinq**.
2. In the left sidebar choose **Overheid → BCF-claims**.

You will see every claim for your administration, sorted by quarter
(newest first). Columns: **Claimnummer**, **Jaar**, **Kwartaal**,
**Bedrag**, **Status**. The status column is colour-coded by lifecycle
state.

If you do not see the menu entry, your active administration is not a
public-sector administration. Switch administrations from the
administration switcher in the top bar.

## Step 2 — Create a claim for the closed quarter

1. From the BCF-claims index, click **Nieuwe claim** (+ icon).
2. Fill in the create form:
   - **Quarter**: pick the last closed quarter (e.g. `2026-Q1`).
     Shillinq blocks any quarter that is older than the install date or
     not yet closed.
   - **Administration**: pre-selected when you only belong to one
     public-sector administration; otherwise pick the one to claim
     against.
   - **Notes** (optional): operator notes. These are stored on the
     claim for context but **not** transmitted to Belastingdienst.
3. Click **Aanmaken**.

Shillinq creates the claim in `draft` state and navigates to the
detail page. The compensable-VAT total and per-account breakdown are
computed server-side from your GL on save — operators never edit those
figures directly.

## Step 3 — Review the compensable breakdown

The detail page shows:

- **Header**: quarter, administration, total compensable amount, state
  badge.
- **Compensable VAT Breakdown table**: one row per compensable RGS
  account that contributed to the quarter total, with the posted VAT
  amount, the BBV `compensablePercentage`, and the weighted compensable
  amount (`amount × percentage / 100`).
- **Operator Notes**: editable in `draft` state.
- **Attachments**: drag-and-drop supporting documents (PDF, spreadsheet)
  to the file area.
- **Audit Trail** (sidebar): every state change, ordered newest first.
  Immutable per the Archiefwet retention policy — claims cannot be
  deleted.

Verify the **Total Compensable Amount** matches the figure your
controller expects from the quarterly internal review. If a known
compensable account is missing from the breakdown, that almost always
means its BBV mapping is missing the `bcfCompensable: true` flag — fix
the mapping (it audits the change), then re-save the claim and the
breakdown updates.

If the figure looks correct, you are ready to submit.

## Step 4 — Send the claim for approval

1. On the detail page click **Indienen ter goedkeuring** (the primary
   action button on a `draft` claim).
2. Shillinq performs two server-authoritative checks before opening
   the approval workflow:
   - The quarter must be closed (`FiscalPeriod.status = closed`).
   - The recomputed `totalCompensableAmount` must be **greater than 0**.
3. On success Shillinq creates an approval task assigned to role
   `bcf-administrator` and displays the message **"Claim submitted for
   approval. Awaiting bcf-administrator review."**.
4. The claim **stays in `draft` state** until a `bcf-administrator`
   approves the task. The 7-day timeout is the default — see your admin
   to adjust it.

## Step 5 — Approver decision

A user with the role `bcf-administrator` receives the approval task in
their tasks list. From the task they see the quarter, the total amount,
the per-account breakdown, the operator's name, and the timestamp.

- **Approve**: the claim transitions to `submitted` state, the approver
  name + comment are appended to the audit trail, and the claim is
  queued for the next scheduled DigiKoppeling submission (typically the
  first day of the next quarter at 09:00). The operator is notified.
- **Reject**: the claim stays in `draft` state. The rejection reason is
  appended to the audit trail and the operator is notified so they can
  revise and re-submit.

## Step 6 — Quarterly DigiKoppeling submission

Once a quarter, Shillinq's scheduled workflow
(`shillinq-bcf-quarterly-digikoppeling-submission`) invokes the
OpenConnector `digikoppeling-bcf` source for every claim in `submitted`
state for the previous closed quarter. The source signs the payload and
posts it to Belastingdienst. On success Shillinq stamps `submittedOn`.

You do not click anything for this step — the cron handles it. The
schedule defaults to the first day of every quarter at 09:00 and is
operator-adjustable via the OpenRegister `ScheduledWorkflow` admin UI
when Belastingdienst deadlines shift.

## Step 7 — Settlement

When Belastingdienst settles the payment, OpenConnector receives a
CloudEvent (`nl.conduction.bcf-claim-settled`) and forwards it to
OpenRegister's generic webhook handler. The handler verifies the
signature, looks up the claim, and atomically applies:

- `state` → `settled`
- `settledOn` → the settlement date from the webhook
- `settledAmount` → the settled amount from the webhook

Every webhook receive/apply/reject is logged in the audit trail with
the event id, timestamp, and payload hash. From the detail page you can
**Export PDF** for your administration's archive and the auditor.

## Frequently asked questions

### My claim total is €0 — why can't I submit?

REQ-BCF-003 blocks submission of an empty claim. Confirm that:

1. The quarter's GL contains debit-side VAT postings to accounts whose
   BBV mapping is flagged `bcfCompensable: true`.
2. The compensable accounts' `compensablePercentage` is not 0.
3. The quarter is the one you intend to claim (a wrong quarter shows
   the wrong set of postings).

### What is `compensablePercentage` for?

Some accounts cover mixed use — for example a town hall that is used
for both public services (compensable) and rented out for commercial
events (not compensable). Set `compensablePercentage` to the share that
is compensable. Shillinq weights each posting by `amount × percentage /
100` and feeds the weighted total into the claim. Every change to a
mapping is audit-trailed for auditor review.

### What happens to past claims if I change a BBV mapping?

Nothing. Submitted, accepted, and settled claims are frozen at
submission time — the breakdown stored on the claim does **not**
recompute. Only `draft` claims see the new mapping next save.

### The settlement webhook didn't arrive — what now?

REQ-BCF-007 includes a manual fallback. As `bcf-administrator`, open
the claim detail page and use the **Markeer als afgewikkeld**
(manually settle) action. You must enter the settlement date and
settled amount; the action is audit-trailed (source: operator) so the
audit trail still reconciles when the webhook lands late.

### Can I delete a claim?

No. BCF claims are subject to the Archiefwet retention policy and the
audit trail is immutable per ADR-022. Mistaken `draft` claims stay on
the index but never reach Belastingdienst because they are never
approved. Add a note to the claim explaining why it is being left in
`draft`.

### What's the cadence of the submission workflow?

The default schedule is quarterly (`0 9 1 */3 *` — first day of every
quarter at 09:00). Your administrator can adjust it from the
OpenRegister `ScheduledWorkflow` admin UI.

## Troubleshooting

| Error message | Cause | Fix |
| --- | --- | --- |
| **"Kwartaal is niet gesloten"** | The fiscal period for the claim quarter is still open. | Close the period first via the Period Close workflow, then return and submit. |
| **"Claim is leeg"** | The recomputed total is 0 — there are no compensable postings. | Review BBV mapping for `bcfCompensable` + `compensablePercentage`; verify the quarter has the expected debit-side VAT postings. |
| **"Toegang tot deze administratie is niet toegestaan"** | The user does not belong to the administration that owns the claim. | Switch administration or ask an admin to grant membership. |
| **"Goedkeuringstermijn verstreken"** | The 7-day approval timeout was exceeded; the task is closed. | The claim stays in `draft`. Re-submit it for approval. |
| **Settlement webhook didn't update the claim** | Lost webhook, expired OpenConnector certificate, or wrong administration scope. | See the [manual fallback FAQ](#the-settlement-webhook-didnt-arrive--what-now). |

If you cannot resolve an error, copy the **audit trail entry** for the
failure and send it to your administrator — the audit trail is the
authoritative reproduction for support cases.

## Where this fits

- Capability spec: `openspec/changes/bookkeeping-bcf-vat-compensation/specs.md`
- Schema fragment: `lib/Settings/register.d/bookkeeping-bcf-vat-compensation.json`
- Server engine: `OCA\Shillinq\Service\BcfClaimService` + `OCA\Shillinq\Lifecycle\BcfClaimGuard`
- Quarterly cron: `OCA\Shillinq\Repair\InitializeSettings::registerBcfQuarterlyDigikoppelingWorkflow()`
- Admin guide: `docs/admin/bcf-configuration.md`
