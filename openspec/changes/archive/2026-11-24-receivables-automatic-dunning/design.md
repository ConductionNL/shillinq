# Design: receivables-automatic-dunning

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

**The ladder.** `DunningLadder` (`lib/Settings/register.d/bookkeeping-credit-control-dunning.json:11`)
holds ordered `stages`, each with `nr`, `daysAfterExpiryDate`, `name`,
`channel` (`EMAIL`, `eMAILPostRegistration`, `REGISTERED_POST`,
`COLLECTION_AGENCY_API`), an optional docudesk `templateId`, an optional
`statutoryEffect` (`14_DAYS_BRIEF_BIK`, `DEFAULT_ENTRY`) and an optional
terminal `action`; a `customerGroup` (`DEFAULT`, `GOVERNMENT`, `VIP`,
`AGGRESSIVE`) and a lifecycle `draft`, `active`, `archived`.
`KlantLadderOverride` (:200) replaces a ladder's stages for one customer, with
an activation guarded by `KlantLadderOverrideApprovalGuard`. `DunningRun`
(:355) records one executed stage, `DunningPauseDispute` (:975) pauses an
invoice. Pages: `DunningLadders` (`src/manifest.json:12351`),
`DunningKlantOverrides` (:12487), both under the settings gear, and
`DunningRuns` (:12615, menu Bookkeeping > Dunning Runs).

**The service.** `DunningRunService` (`lib/Service/DunningRunService.php`):

- `stageForOverdueDays()` (:134) returns the highest stage whose `daysAfterExpiryDate` is reached.
- `tickInvoice()` (:196) computes days overdue, skips a paused invoice, resolves the ladder with the customer's override (`resolveLadderForKlant()`, :311), picks the stage, applies the voluntary-contribution cap, skips a stage already fired for the invoice, and calls `executeStage()`. **It has no caller in `lib/`, `src/` or any register fragment.**
- `executeStage()` (:414) refuses a paused invoice, applies the voluntary cap, and saves a `DunningRun` with `lifecycleState = executed` and `deliveryStatus = PENDING`. Its docblock says the channel dispatch is "delegated to the channel hooks" and that it "does not own the SMTP/PostNL/incasso-bureau wiring". **It calls no adapter.** `DunningChannelAdapterInterface` is bound to `LogDunningChannelAdapter` (`lib/AppInfo/Application.php:439`) and nothing calls it; `sendRegisteredLetter()` (:1180) uses `PostNLAdapterInterface`, bound to `LogPostNLAdapter`.

**Matrix correction (`rec-reminders`).** The matrix names the channel adapter
as log-only. It is also never called: even a run started through
`POST /api/dunning/runs/execute` (`DunningController::executeRun()`,
`lib/Controller/DunningController.php:221`) records a run and sends nothing.

**Overdue.** `ARInvoice` declares `isOverdue` as a calculation and a
`mark-overdue` transition (`issued` to `overdue`) described as "Automated when
due date passes" (`register.d/add-shillinq-bookkeeping-compliance.json:395`).
Nothing performs it.

**Texts.** `DunningTemplateRegistry` (`lib/Service/Dunning/DunningTemplateRegistry.php`)
names a docudesk template per stage (`tpl-stage1-vriendelijk-nl` to
`tpl-stage5-overdracht-incasso-nl`), a tone per stage and nine merge fields
(`klantNaam`, `factuurNummer`, `invoiceDate`, `outstandingAmount`,
`expiryDate`, `iban`, `betalingstermijn`, `incassokosten`, `rente`). It has no
caller, and the docudesk templates it names are not seeded anywhere.

**Customer ladder choice.** `CustomerMaster.dunningPolicyRef` ("FK to OR
dunning-policy record") is declared and read by no code.

**Jobs.** `appinfo/info.xml` registers 22 background jobs; none runs dunning.
`background-job-consolidation` moved every job to `lib/BackgroundJob/` and
added a registration contract test.

## Goals / Non-Goals

**Goals**

- Every overdue invoice of an administration with dunning on gets the right reminder on the right day, by email, without anyone pressing a button.
- A customer with its own ladder or override is chased on that schedule.
- The runs list shows what actually went out.

**Non-Goals**

- Letters and collection agencies sent automatically.
- New calculation rules for collection costs or interest.

## Decisions

### D1. One daily `TimedJob`

`lib/BackgroundJob/DunningTickJob.php` extends `TimedJob` with a 24-hour
interval, registered once in `appinfo/info.xml`. Per administration whose
setting `dunning.enabled` is on, it lists `ARInvoice` in `issued` or `overdue`
with `dueDate` before today, in pages of 100, and calls `tickInvoice()` for
each. A run for one invoice never stops the job; failures are counted in a
`DunningJobReport` the `DunningRuns` page shows.

Alternative considered: an OpenRegister scheduled workflow, as
`RecurringInvoiceGenerator::runScheduled` uses. Rejected here because the
dunning chain calls adapters and mails, and ADR-069 names `lib/BackgroundJob/`
with `info.xml` registration as the convention for such work.

### D2. Mark overdue first

Before ticking, the job applies `mark-overdue` to every `issued` invoice past
its due date, so the invoice state, the AR list and the portal all agree with
the reminder.

### D3. One stage per run, from the first unsent

`tickInvoice()` picks the lowest-numbered stage whose threshold is reached and
that has not fired for the invoice, and fires a later stage only when at least
its threshold difference has passed since the previous stage fired. The
choice lives in `DunningStageSelector`, which does no I/O, so the tick and its
preview ask the same question; the old "highest stage reached" rule stays
there as `highestReached()` for display.

Alternative considered: keep "highest stage reached". Rejected: on the first
run a long-overdue invoice would skip the friendly reminder and the statutory
14-day letter and go straight to collection.

### D4. The ladder comes from the customer, then the administration

The job passes as base ladder the `DunningLadder` named by the customer's
`dunningPolicyRef`, else the administration's active ladder with
`customerGroup = DEFAULT`. `resolveLadderForKlant()` then applies an active
override as it does today. An invoice with no ladder is skipped and counted.

### D5. Stage texts live on the ladder; the invoice is attached

Each stage gains `subject` and `body` per language (`nl`, `en`), with the merge
fields of `DunningTemplateRegistry` written as `{klantNaam}` and so on, and a
seeded default text per tone. `DunningStageRenderer` fills them from the
invoice and customer. The mail attaches the invoice PDF the way
`sales-invoice-sending` does. A docudesk template named in `templateId` is
used instead when docudesk's rendering contract is available (ADR-075); that is
an option, not a requirement.

Alternative considered: require docudesk templates. Rejected: none is seeded,
and a reminder mail is text plus the invoice, not a generated document.

### D6. `executeStage()` dispatches and records the truth

`executeStage()` renders the stage, calls `DunningChannelAdapterInterface::send()`
and saves the run with the adapter's outcome. `MailDunningChannelAdapter`
(bound at `Application.php:439`) sends `EMAIL` through `IMailer` and returns
`DELIVERED` when the mailer accepted the message, `FAILED` with the reason
otherwise. Every other channel returns `MANUAL`, and the run's creation raises
a notification to the administration's `ar-controller` members to send the
letter or hand over the file.

### D7. No collection costs before the 14-day period for a consumer

For a consumer debtor (a `CustomerMaster` without a KvK number or VAT id) a
stage that carries collection costs fires only when a stage with
`statutoryEffect = 14_DAYS_BRIEF_BIK` was delivered at least 15 days earlier,
as article 6:96 lid 6 BW requires the debtor be given 14 days after that
letter. Otherwise the costs are stripped from the stage, as the voluntary
contribution rule already strips them.

### D8. Preview before trusting it

`DunningRuns` gains a panel "Next run" listing, per invoice, the stage the next
run would send and on which channel, computed by the same `tickInvoice()` path
in a dry-run mode that writes nothing. Enabling dunning for an administration
shows this list first.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Moving an invoice to overdue | Declarative transition `mark-overdue`, applied by the job | The transition exists; only a trigger is missing. |
| Ladder, stages, overrides and stage texts | Declarative data on `DunningLadder` and `KlantLadderOverride` | Configuration, edited on the existing pages. |
| Picking the stage and sending it | Imperative, `DunningTickJob` and `DunningRunService` | Time-based selection across invoices and a mail dispatch. |
| Telling a person to send a letter | Declarative: `x-openregister-notifications` on `DunningRun` creation with `deliveryStatus = MANUAL` | A state notification. |

## Seed Data

No schema is added. `DunningLadder` stages gain `subject` and `body`,
`DunningRun.deliveryStatus` gains `MANUAL`, and the administration settings
gain `dunning.enabled`.

Seed ladder "Standaard" (customer group DEFAULT) for "Adviesbureau Kade B.V.":

1. day 7, EMAIL, "Vriendelijke herinnering factuur {factuurNummer}": "Beste {klantNaam}, wellicht is het u ontgaan ..."
2. day 21, EMAIL, "Tweede herinnering factuur {factuurNummer}".
3. day 35, EMAIL, statutory effect `14_DAYS_BRIEF_BIK`, "Aanmaning: betaal binnen 14 dagen om incassokosten te voorkomen".
4. day 56, REGISTERED_POST, "Ingebrekestelling".
5. day 70, COLLECTION_AGENCY_API, action TRANSFER_INCASSO.

A `KlantLadderOverride` for customer "Gemeente Voorbeeld" with stages at day 14
and day 30 by email only, active.

## Risks / Trade-offs

- [A mail server outage marks many runs failed] → a failed run is retried on the next daily pass (the stage has not fired), and the job report shows the failures.
- [Customers without an email address] → the run is recorded `MANUAL` with the reason, and the `ar-controller` is notified.
- [Two instances run the job at once] → the stage idempotency check in `tickInvoice()` and a per-administration lock keep one run per stage.

## Migration Plan

No data migration. The job ships with `dunning.enabled` off for every
administration; enabling it is an administrator's step after the preview.
Rollback is turning the setting off or reverting the PR.

## Open Questions

- Mapping customers to ladder groups (government, VIP) needs a customer field this change does not add.
