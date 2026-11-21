---
kind: code
depends_on: [background-job-consolidation, sales-invoice-sending]
---

# Proposal: receivables-automatic-dunning

## Summary

Shillinq can define an escalating reminder ladder, per customer if needed, and
list dunning runs, and no reminder ever reaches a customer: no job runs the
ladder, the page that could start a run does not exist, and the service that
executes a stage records it without sending anything. This change adds a daily
background job that marks invoices overdue, picks the due stage of each
customer's ladder, sends the reminder by email with the invoice attached,
records whether it went out, and hands postal and collection-agency stages to
a person instead of pretending they were sent.

## Motivation

Three rows describe one capability. The OpenSpec pass of 2026-09-27 decided
`build` for all three (`openspec/parity/gap-decisions.json`).

**`rec-reminders`** (shillinq matrix), "Send payment reminders automatically
with escalating wording." Rated partial, built. The matrix evidence:
"DunningLadder stages kept on DunningLadders (settings) and
lib/Service/DunningRunService.php:193 tickInvoice/:400 executeStage implement
the ladder, but they are reached only from lib/Controller/DunningController.php
(/api/dunning/runs/execute), which no frontend file calls ..., no BackgroundJob
in appinfo/info.xml runs dunning, and the channel adapter is
LogDunningChannelAdapter / LogPostNLAdapter (lib/Service/Dunning/)". Note:
"Escalating ladders can be defined and runs listed, but nothing sends a
reminder automatically: no job, no page calling the run endpoint, log-only
channel." No demand row. Four competitors rate it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, "Met Exact Online kun je betalingsherinneringen automatisch laten versturen. De software bepaalt zelf het aantal contactmomenten, de timing en de boodschap".
- moneybird: https://helpcenter.moneybird.nl/nl/articles/207926-automatisch-een-factuur-herinnering-versturen, "Per stap stel je in welke tekst bij de herinnering wordt verstuurd en wanneer ... Herinnering automatisch verzenden".
- snelstart: https://kennisplein.snelstart.nl/klanten/s/article/starten-met-herinneringen-en-aanmaningen, "Je kunt je eigen aanmaanproces instellen ... een eerste en/of tweede herinnering en een eerste en/of tweede aanmaning"; https://www.snelstart.nl/ondernemer/debiteurenbeheer automates follow-up.
- odoo: https://www.odoo.com/documentation/19.0/applications/finance/accounting/payments/follow_up.html, follow-up levels "triggered after a number of overdue days", automatic or manual, by email, SMS or post.

**`rec-ladder-per-customer`** (shillinq matrix), "Give a particular customer a
different reminder schedule." Rated partial, built. The matrix evidence:
"KlantLadderOverride page (src/manifest.json DunningKlantOverrides,
settingsSection in src/menu-layout.json) and DunningRunService.php:297
resolveLadderForKlant applies it, but that service runs only from the uncalled
/api/dunning endpoints". Note: "The per-customer override can be kept; it only
matters once dunning actually runs, which nothing triggers." No demand row.
Two competitors rate it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, Debtor Agent "helpt je het juiste communicatieprofiel te kiezen voor elke klant".
- moneybird: https://helpcenter.moneybird.nl/nl/articles/207517-instellingen-bij-contacten, "stel dan een standaard factuur- en offerte-workflow in bij het contact"; the workflow holds the reminder steps (https://helpcenter.moneybird.nl/nl/articles/207926-automatisch-een-factuur-herinnering-versturen).

**`prod-overdue-chasing`** (pipelinq matrix, `openspec/parity/capabilities.json`
in ConductionNL/pipelinq), "Have every overdue invoice chased with a reminder
without doing it by hand." Rated no, built state none, owner shillinq. Its
built evidence: "pipelinq only reads invoices from shillinq
(lib/Service/ShillinqInvoiceReader.php:175 notes whether one "is in dunning")
for marketing signals; chasing is shillinq's DunningRunService, itself rated
partial in shillinq and not reached from any pipelinq page". Demand: changelog
https://www.hubspot.com/spotlight. One competitor rates it yes:

- hubspot-crm: https://www.hubspot.com/spotlight, "Automates collections, so every overdue invoice gets a personalized nudge, and follows up as the conversation unfolds".

design.md sharpens the first row: `executeStage()` does not call any channel
adapter at all, and `tickInvoice()` has no caller anywhere, not even the
controller.

This change covers all three rows.

## Affected Projects

- [ ] Project: `shillinq`: a daily dunning job, the overdue transition, stage texts on the ladder, a mail channel adapter, honest outcomes for postal and agency stages, and a preview of the next run.

## Scope

### In Scope

- `DunningTickJob`, a `TimedJob` in `lib/BackgroundJob/` registered once in `appinfo/info.xml` (ADR-069), running daily.
- Moving issued invoices past their due date to `overdue` through the declared `mark-overdue` transition.
- Choosing each invoice's ladder: the customer's own ladder (`dunningPolicyRef`), else the administration's default, with the customer's override on top.
- Sending the due stage by email with the stage's subject and body (merge fields filled) and the invoice PDF, through a real `DunningChannelAdapterInterface` binding.
- Recording each run's delivery status from what actually happened.
- Stages on the postal or collection-agency channel recorded as manual and handed to an `ar-controller`.
- No collection costs for a consumer before the 14-day letter period has passed.
- A preview of the reminders the next run will send, on `DunningRuns`.

### Out of Scope

- Sending letters through PostNL or a registered-mail service, and handing files to a collection agency. Those channels are integriq's; their stages stay manual here.
- SMS reminders.
- Changing how collection costs and statutory interest are calculated (`dunning#bik` stays as it is).
- A pipelinq screen. Pipelinq reads the dunning state it already reads.

## Approach

The job asks `DunningRunService` for every administration with an active
ladder; per overdue invoice it calls the existing `tickInvoice()`, which
resolves the ladder, skips paused, voluntary-capped and already-fired stages,
and calls `executeStage()`. `executeStage()` gains the dispatch it never had:
it renders the stage texts, calls the bound channel adapter, and records the
adapter's outcome. The email adapter reuses the invoice mail path of
`sales-invoice-sending`. Details are in design.md.

## New Dependencies

None.

## Impact

- Schemas: `DunningLadder` stages gain `subject` and `body` per language (additive); `DunningRun.deliveryStatus` gains `MANUAL` (additive).
- Code: new `DunningTickJob`, `MailDunningChannelAdapter`, `DunningStageRenderer`; `DunningRunService::executeStage()` dispatches; `appinfo/info.xml` registers the job; the binding at `lib/AppInfo/Application.php:439` changes.
- Manifest: a preview panel on `DunningRuns`; the stage editor on `DunningLadders` shows subject and body.

## Cross-Project Dependencies

- pipelinq: `ShillinqInvoiceReader` (pipelinq `lib/Service/ShillinqInvoiceReader.php:175`) reads whether an invoice is in dunning; once the job runs, that state becomes real. No pipelinq change is needed.
- integriq: postal and collection-agency channels (for example its `DigitalPostSendRequestedEvent` for letters) would let those stages run by themselves later; not needed for this change.

## Risks

### Risk 1: The first run chases every old overdue invoice at once
**Severity:** High. **Mitigation:** the job starts disabled per administration; enabling it shows the preview of what the first run sends, and an administrator confirms. The ladder advances one stage per run at most, starting from the first stage not yet sent for that invoice, so a year-old invoice gets the friendly first reminder, not the collection-agency stage its age alone would pick.

### Risk 2: A reminder for an invoice that was just paid
**Severity:** Medium. **Mitigation:** the job reads the invoice state at send time; a paid, disputed or paused invoice is skipped, and the bank reconciliation and payment links settle invoices before the daily run.

### Risk 3: A postal stage silently marked sent
**Severity:** High. **Mitigation:** only the mail adapter can return `DELIVERED`; any channel without a real adapter records `MANUAL` and notifies an `ar-controller`.

## Rollback Strategy

Disable the job per administration, or revert the PR. Runs already recorded
stay; nothing is sent again.

## Open Questions

- Should the ladder be chosen by customer group (`DunningLadder.customerGroup`: default, government, VIP, aggressive) when the customer names none? `CustomerMaster` has no group field; this change uses the administration's default ladder and leaves group mapping open.
