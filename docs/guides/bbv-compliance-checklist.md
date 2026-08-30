---
sidebar_position: 1
title: BBV compliance checklist (provincies)
description: Pre-audit checklist for the BBV compliance surface — mapping completeness, budget status hygiene, overspend resolution, and audit-trail export for Dutch provinces.
---

# BBV compliance checklist (provincies)

This guide is the operator's pre-audit walkthrough for the BBV
compliance surface in Shillinq, scoped to Dutch provinces. The seven
canonical BBV programmes used by provincies are:

| Code | Programme | Coverage |
| --- | --- | --- |
| `ruimte` | Ruimte | Spatial planning, area development. |
| `mobiliteit` | Mobiliteit | Roads, public transport, mobility infra. |
| `water` | Water | Surface water, drinking water, dykes (shared with waterschappen). |
| `milieu` | Milieu | Air quality, soil, waste, nature. |
| `cultuur` | Cultuur | Cultural heritage, libraries, museums. |
| `economie` | Economie | Economic development, tourism, employment. |
| `bestuur` | Bestuur | Province governance, internal services. |

Two operator surfaces drive compliance — the
[BBV Compliance Dashboard](../user-guide/bookkeeping/bbv-compliance-dashboard.md)
and the
[Budget-to-Programme Linker](../user-guide/bookkeeping/budget-to-programme-linker.md).
This checklist walks the operator through the pre-audit sweep.

## 1. Pre-audit checklist

Work top-to-bottom; the order minimises rework if one of the earlier
checks fails.

### 1.1 All GL lines mapped to a programme

- Open **Shillinq → BBV Provincie → Budget Links**.
- Set **Assignment status = unmapped** in the filter bar.
- Confirm the mapping-status badge reads **green** (less than 10%
  unmapped). Yellow / red is an audit-finding risk.
- Bulk-link any remaining unmapped expense GL lines via the
  guided dialog (see the Linker user guide). The audit trail
  captures every assignment automatically.

### 1.2 Budget statuses are current

- Open **Shillinq → BBV Provincie → BBV Compliance Dashboard**.
- Apply **Budget status = provisional** in the filter bar.
- Every `provisional` budget should either become `approved` before
  the audit, or be explicitly listed as a risk in the management
  letter (with a board-approval date).
- Apply **Budget status = amended** — every `amended` budget should
  cite the council resolution that approved the amendment in its
  `description` field.

### 1.3 No unresolved overspends

- On the dashboard, scroll to the **Exceptions** block.
- Empty state (`No overspends`) — pass; move on.
- Non-empty — for each programme in the list, either:
  - Re-map a misallocated GL line back to its correct programme via
    the Linker (the overspend was a misclassification, not a real
    overrun), or
  - Open an **amended** `Budget` record raising `totalAmount`, with
    the council resolution cited in the `description`. After the
    refresh interval the programme should drop out of the
    exceptions list.

### 1.4 Dashboard accessible to the audit team

- Confirm the audit-team Nextcloud group has read access to the
  Shillinq app.
- Confirm the active administration's `administrationType` is
  `provincie` for those users — the dashboard navigation entry is
  hidden otherwise (manifest `visibilityPredicate`).
- Hand the auditor the dashboard URL plus the
  [user guide](../user-guide/bookkeeping/bbv-compliance-dashboard.md);
  no screen-share session is required for a read-only walkthrough.

### 1.5 Audit-trail accessible

- Open any GL line via the
  [Linker detail view](../user-guide/bookkeeping/budget-to-programme-linker.md#section-3--viewing-assignment-history).
- Confirm the OpenRegister audit-trail sidebar lists at least the
  most recent assignment with operator, timestamp, before / after
  `programmaStructure`, and source.
- If the sidebar is empty for a line you know was edited, the
  audit-trail plugin is misconfigured — contact your
  Shillinq administrator before the audit.

## 2. Handling overspends

The Exceptions block on the dashboard surfaces overspent programmes
in real time. Two valid resolutions:

### 2.1 Correcting a GL line

If the overspend is caused by a GL line misallocated to the wrong
programme:

1. From the Exceptions block, click the programme name to open the
   Linker filtered to that programme.
2. Identify the misallocated GL line (usually the highest-amount
   row that doesn't belong, by `description`).
3. Open the GL line in detail view.
4. Set `programmaStructure` to the correct value.
5. Save. The audit trail captures the correction with source
   `Manual Edit`.
6. Wait for the dashboard refresh interval (or trigger a manual
   refresh). The programme should fall back under budget.

### 2.2 Amending a budget

If the overspend reflects a real overrun:

1. Open the relevant `Budget` record in the Budgets surface.
2. Raise `totalAmount` to cover the actual spend.
3. Set `status = amended`.
4. In `description`, cite the council resolution authorising the
   amendment (`Resolution YYYY-NNN`).
5. Save. The audit trail captures the amendment automatically.

## 3. Audit-trail export

Auditors typically request a flat export of every programme
assignment over the fiscal year. Use OpenRegister's audit-trail
export endpoint:

1. Open the OpenRegister admin surface
   (**Nextcloud → Administration → OpenRegister**).
2. Navigate to **Audit trail**.
3. Filter by:
   - **Schema** — `GLLine`.
   - **Action** — `update`.
   - **Field** — `programmaStructure`.
   - **Date range** — the fiscal year under audit.
4. Click **Export to CSV**.
5. The CSV columns include `objectId`, `operator`, `timestamp`,
   `field`, `before`, `after`, `source` — exactly what an
   auditor expects.

If your province uses a separate audit data lake, see the OpenRegister
audit-trail integration guide for the streaming export option.

## 4. Sign-off

Once every check passes:

- Take a screenshot of the dashboard with the **default filter set**
  (current fiscal year, all programmes, all approved budgets) for the
  audit binder.
- Hand the auditor the dashboard URL, the audit-trail CSV, and the
  reference to this checklist.
- The fiscal-year close can proceed.

## See also

- [BBV Compliance Dashboard](../user-guide/bookkeeping/bbv-compliance-dashboard.md)
- [Budget-to-Programme Linker](../user-guide/bookkeeping/budget-to-programme-linker.md)
- [Waterschappen BBV variant — design and operations](../Technical/waterschappen-bbv-variant.md)
  (the sister capability for water boards; the seven-programme list
  differs but the operator flow is identical).
