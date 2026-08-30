# Design: compliance-deadline-calendar

## Architecture Overview

```
Deadline sources (existing, unchanged):
  VatReturn / IcpOpgaaf / VpbReturn  ── filing due dates
  PaymentRun                         ── execution dates
  ARInvoice                          ── due dates (opt-in)
  ContractObligation                 ── renewal / opzegtermijn (via ObligationTaskBridge)
            │
            ▼
ComplianceDeadlineCalendarService
  - ensureAppCalendar() via OCP\Calendar\ICalendarManager (ObligationTaskBridge seam)
  - upsert VEVENT per deadline, stable UID {source}:{objectId}
  - honour per-user category toggles; delete on category-off / source-resolved
            │                                   │
            ▼                                   ▼
  App-owned NC Calendar (VEVENTs)     DeadlineReminderJob (TimedJob, daily)
  (syncs via CalDAV)                   raises OCP\Notification per deadline/user
```

Contract deadlines flow through an **extended** `ObligationTaskBridge` (one home
for the contract-obligation path) rather than a second reader.

## API Design

No new public HTTP endpoints for publication (background-driven). One small
per-user settings read/write uses the standard NC user-config surface. The
category toggles are stored as user preferences (`IConfig::setUserValue`).

## Database Changes

None (ADR-022). Category toggles live in NC user preferences, not a register.
VEVENTs live in the NC calendar backend. No schema change to the deadline sources.

## Nextcloud Integration

- Controllers: a thin per-user settings controller for the category toggles
  (`#[NoAdminRequired]`, acts only on the current user — no IDOR).
- Services: `ComplianceDeadlineCalendarService` (new); `ObligationTaskBridge`
  (extended — additive VEVENT publication).
- Background jobs: `DeadlineReminderJob` (`OCP\BackgroundJob\TimedJob`).
- OCP seams: `OCP\Calendar\ICalendarManager` (calendar + VEVENT create-from-string),
  `OCP\Notification\IManager` (reminders), `OCP\IConfig` (per-user toggles).
- Registration in `lib/AppInfo/Application.php`.

## Security Considerations

- The settings controller reads/writes only the current user's preferences (no
  cross-user access).
- Publication runs in a background job under an administration scope; VEVENTs are
  written to the owning user's/app calendar only.
- Fail-soft: a missing calendar backend or a publication error is logged and
  swallowed into a `failed` status — it never blocks source CRUD (CWE-703 handled
  deliberately, consistent with `ObligationTaskBridge`).
- No secrets in VEVENT payloads; summaries carry only obligation/period labels.

## NL Design System

The category-toggle settings surface uses standard NC settings components and
`NcCheckboxRadioSwitch` toggles with `inputLabel`s; CSS variables only.

## File Structure

```
lib/
  Service/
    ComplianceDeadlineCalendarService.php   (new)
    ObligationTaskBridge.php                (modified — additive VEVENT publish)
  BackgroundJob/
    DeadlineReminderJob.php                 (new — TimedJob)
  Controller/
    DeadlineCalendarSettingsController.php  (new — per-user toggles)
  AppInfo/Application.php                    (modified — register job + service)
src/
  views/DeadlineCalendarSettings.vue         (new — category toggles)
```

## Seed Data

The deadlines are derived from existing seed sources (VatReturn, PaymentRun,
ARInvoice, ContractObligation), so this change adds **no new register schema** and
therefore no new register seed objects. Testable-on-install behaviour is provided
by:

- Per-user preference defaults: filing / payment-run / contract categories **on**,
  AR-due-date category **off** (matches REQ-CDC-004 default-off).
- On a fresh install with the existing VatReturn / PaymentRun / ContractObligation
  seed present, the calendar service publishes their VEVENTs on first run so the
  app calendar is non-empty for demonstration.

(No `_registers.json` additions required — this capability owns no schema.)

## Declarative-vs-imperative decision (ADR-031)

- **Declarative:** the per-user category-toggle *defaults* are declared config;
  category strings/i18n keys are English resource strings.
- **Imperative (justified):**
  - `ComplianceDeadlineCalendarService` — **external integration** with the NC
    Calendar API (VEVENT create/update/delete); not expressible declaratively.
  - `DeadlineReminderJob` — **scheduled bulk work** (TimedJob) raising NC
    Notifications; ADR-031 explicitly allows scheduled jobs.
  - The `ObligationTaskBridge` VEVENT extension — external integration glue,
    additive to the existing (already imperative, fail-soft) bridge.

Rationale: OpenRegister's declarative `x-openregister-notifications` dialect fires
on object events, not on time-relative lead times against externally-derived
deadlines, and OR has no declarative "publish VEVENT to CalDAV" surface — hence the
imperative calendar/notification services. All domain *dates* remain owned by the
existing sources; this change only reads and surfaces them.

## Trade-offs

- **Dedicated app calendar vs. writing into a user's default calendar.** Chosen: a
  dedicated app-owned calendar so app-managed VEVENTs are visually separable and
  safely deletable on category-off, and never collide with the user's own events.
  Fallback to the `ObligationTaskBridge` seam if a dedicated calendar cannot be
  created.
- **Extend ObligationTaskBridge vs. new contract reader.** Chosen: extend the
  bridge — it already resolves the backend and reads `ContractObligation`
  fail-soft; a second reader would duplicate both and drift.
- **Background publication vs. on-write publication.** Chosen: a daily background
  job for bulk publication + reminders (scales, no request-path latency), with the
  bridge publishing contract VEVENTs on obligation write for immediacy.
- **AR due dates default off.** Chosen: opt-in — invoice due dates are high-volume
  and would swamp the agenda; filing/payment/contract deadlines are low-volume and
  default on.

## Migration Plan

Additive. Deploy the service + job + settings, seed per-user defaults, register the
job. First job run populates the app calendar. Rollback = unregister the job,
disable the service, revert the bridge extension; delete the app calendar. Sources
untouched.

## Open Questions

- Exact reminder lead-time default (proposed 10 days for filing, 7 for
  payment/contract) — a per-user setting; the defaults are tunable at apply time
  and do not change observable requirement behaviour.
