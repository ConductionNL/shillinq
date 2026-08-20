---
kind: code
depends_on: []
---

# Proposal: compliance-deadline-calendar

## Summary

Surface Shillinq's compliance and operational **deadlines** where users already
live — the **Nextcloud Calendar** and the **Notifications** bell. A dedicated,
app-generated calendar receives VEVENTs for BTW / ICP / VPB filing deadlines
(derived from the existing VAT/ICP/VPB period data), payment-run execution dates,
AR invoice due dates (optional, per-user), and contract renewal / opzegtermijn
alerts (sourced from the existing `ContractObligation` rows, **extending**
`ObligationTaskBridge` rather than duplicating it). A per-user setting toggles each
category, and a scheduled job raises an NC Notification ahead of each due date.

## Motivation

A bookkeeping deadline missed is a fine or a rejected filing. Shillinq already
computes every one of these dates — VAT/ICP/VPB periods, payment-run execution
dates, invoice due dates, contract opzegtermijnen — but they live inside the app,
invisible until someone opens the right screen. Nextcloud ships a first-class
Calendar and a notification centre; the idiomatic fix is to publish the deadlines
there so they appear in the user's normal agenda and reminder flow (and sync to
their phone via CalDAV). The plumbing already exists: `ObligationTaskBridge`
resolves an `OCP\Calendar\ICalendarManager` backend and writes VTODOs for contract
obligations, and `IcsService` builds RFC 5545 VEVENTs for bookings. This change
consolidates deadline publication onto that seam and adds the missing calendar,
category toggles, and reminder notifications.

## Affected Projects

- [x] Project: `shillinq` — a dedicated app-generated calendar + a
  `ComplianceDeadlineCalendarService` that publishes VEVENTs from the existing
  VAT/ICP/VPB, payment-run, AR-invoice and contract-obligation sources; an
  extension of `ObligationTaskBridge` for the contract category; a per-user
  category-toggle setting; and a scheduled reminder-notification job.

## Capabilities

- `compliance-deadline-calendar` (NEW).

## Scope

### In Scope

- A dedicated app-generated NC calendar (via `OCP\Calendar\ICalendarManager` /
  an `ICalendarProvider`), reusing the backend-resolution seam already proven in
  `ObligationTaskBridge`; fail-soft (a calendar backend absence never blocks the
  source records).
- VEVENT publication for: **BTW / ICP / VPB filing deadlines** (deadline derived
  from the period's end via the existing period data), **payment-run execution
  dates**, **AR invoice due dates** (optional, off by default per user), and
  **contract renewal / opzegtermijn alerts** (by extending `ObligationTaskBridge`).
- A **per-user setting** to toggle each deadline category on/off; disabling a
  category removes its VEVENTs.
- A **scheduled background job** that raises an NC Notification a configurable
  number of days before each upcoming deadline.

### Out of Scope

- Two-way calendar editing (VEVENTs are app-owned and read-only to the user; the
  register row remains the source of truth, mirroring `ObligationTaskBridge`).
- Computing the deadlines themselves — the VAT/ICP/VPB/payment-run/AR/contract
  sources already own their dates; this change only reads and publishes them.
- SMS / email reminder channels (covered by the existing
  templates-notifications-settings surface).

## Approach

`ComplianceDeadlineCalendarService` ensures a single app-owned calendar exists and
publishes/updates one VEVENT per upcoming deadline, keyed on a stable UID
(`{source}:{objectId}`) so re-runs are idempotent and category-disable can delete.
The contract-renewal category calls into an extended `ObligationTaskBridge` so the
contract path has one home. A `DeadlineReminderJob` (NC `TimedJob`) scans upcoming
deadlines daily and raises NC Notifications per the user's lead-time setting.
Details in design.md.

## New Dependencies

None. Uses `OCP\Calendar\ICalendarManager`, `OCP\Notification\IManager`,
`OCP\BackgroundJob\TimedJob`, and the existing deadline sources.

## Impact

- New `lib/Service/ComplianceDeadlineCalendarService.php`,
  `lib/BackgroundJob/DeadlineReminderJob.php`, a small per-user settings surface,
  and an extension of `lib/Service/ObligationTaskBridge.php` (additive VEVENT
  publication alongside the existing VTODO).
- Listener/job registration in `lib/AppInfo/Application.php`.
- No schema-breaking change.

## Cross-Project Dependencies

None. All sources are shillinq-internal; publication targets Nextcloud core APIs
(Calendar, Notifications). No cross-app RPC.

## Risks

### Risk 1: No CalDAV/calendar backend on the instance

**Severity:** Medium — **Mitigation:** Reuse `ObligationTaskBridge`'s fail-soft
resolution: when no backend resolves, publication logs the reason and returns a
`failed` link-status WITHOUT throwing; source records are never blocked and the
in-app deadline views continue to function.

### Risk 2: Stale or duplicate VEVENTs after a deadline changes

**Severity:** Medium — **Mitigation:** Stable per-source UID
(`{source}:{objectId}`) + upsert semantics; a changed date updates the same VEVENT,
a category-disable or a resolved/paid source deletes it.

### Risk 3: Notification spam

**Severity:** Low — **Mitigation:** One reminder per deadline per user honouring
the per-user lead-time; category toggles suppress unwanted classes entirely.

## Rollback Strategy

Additive. Rollback = disable the calendar service + reminder job and revert the
`ObligationTaskBridge` extension + settings surface. The app calendar can be
deleted; source records and existing VTODO links are unaffected.

## Open Questions

- Whether to model the calendar as a full `ICalendarProvider` (app supplies a
  virtual calendar) or as objects created in a real user calendar — resolved in
  design.md by preferring a dedicated app-owned calendar via `ICalendarManager`,
  falling back to the bridge seam.
