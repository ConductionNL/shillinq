---
kind: code
depends_on: [extracurricular-fee-to-shillinq]
---

# Proposal: voluntary-contribution-reminder

## Summary

A voluntary school contribution already gets one reminder at most, without costs
(REQ-SCON-008). That one reminder still goes out on the ladder's first stage
template: the generic "friendly reminder" that names a due date, an IBAN and a
payment term. This change gives the voluntary reminder its own template, in
English and Dutch, that says again the contribution is voluntary and the child
takes part either way, and names no term, no costs and no interest. Every route
to a reminder selects it. The guardian can answer "I will not pay" from the
portal, which closes the invoice so nothing more is asked.

## Motivation

Decision D28 (Ruben, 2026-09-27, `learniq-mi/learniq/_round1/compare/decisions.md`):
"A voluntary contribution's single reminder uses its own friendly template that
repeats that the contribution is voluntary and the child takes part regardless."

The Wet vrijwillige ouderbijdrage (in force since 2021-08-01) forbids pressure on
a parent to pay. The round 2 change `extracurricular-fee-to-shillinq` capped the
ladder at one reminder and stripped the costs, but reading `development` on
2026-09-27 shows three gaps:

1. `DunningRunService::tickInvoice()` and `executeStage()` keep the stage's
   `templateId`. On the seeded ladder that is `tpl-dunning-stage1-nl`, whose body
   asks the parent to transfer the amount to an IBAN "onder vermelding van het
   factuurnummer" and names the due date. Nothing in that text says the
   contribution is voluntary.
2. There is no English template for any stage, and the invoice does not record
   the guardian's language, so a reminder cannot follow the language the invoice
   note was written in.
3. A guardian who does not want to pay has no way to say so. The invoice stays
   open, shows in AR ageing and credit exposure, and the one reminder still
   fires. The law lets a parent refuse; the books should hear it.

The school contribution chain is the money half of learniq round 2 (D19, D30).
Recon E (`learniq-mi/learniq/_round2/recon/E-roles-and-lesson-shop.md`, section
1b) and the M1 rows 9.6 and 9.8 (rung 4, tier A, evidenced against Social
Schools, Klasbord, Kwieb and ParnasSys) are the market grounding the round 2
proposal cites; this change completes the voluntary branch of it.

## Affected Projects

- [ ] Project: `shillinq`: the voluntary reminder template (en, nl), its selection in
  the dunning service, `ARInvoice.contribution.language` and `declinedAt`, the
  `declined` lifecycle state with a guarded transition, and the portal `decline`
  action with its receiver.
- [ ] Project: `portaliq`: renders the `decline` endpoint-forward action on the
  parent manifest. No code here; it forwards the action like `pay`.

## Scope

### In Scope

- Two templates in `lib/Settings/docudesk-templates.json`:
  `tpl-dunning-voluntary-contribution-nl` and `-en`. Friendly tone, no payment
  term, no costs, no interest, the voluntary sentence repeated, and where to pay or
  refuse.
- `VoluntaryReminderTemplate`, which picks the template by language and fills its
  merge fields.
- `DunningRunService::executeStage()` (the one place every route passes) writes the
  voluntary template id and its rendered subject and body on the run, whatever the
  stage or the caller passed.
- `ARInvoice.contribution.language`, written by the raise, and
  `ARInvoice.contribution.declinedAt`.
- A `declined` lifecycle state on `ARInvoice`, with `decline` transitions from
  `issued` and `overdue` guarded by `VoluntaryDeclineGuard`. A declined invoice is
  not overdue and leaves AR ageing and credit exposure.
- A `decline` endpoint-forward action on the parent portal manifest and its
  receiver `POST /apps/shillinq/api/portal/contributions/decline`: it declines the
  guardian's own open voluntary contribution and voids its pending payment
  requests.
- Dunning never runs on a declined invoice: `tickInvoice()` skips it and
  `executeStage()` refuses it.

### Out of Scope

- Templates for the other ladder stages in English. They are unchanged.
- A back-office endpoint for recording a refusal. A bookkeeper records one with
  the `decline` lifecycle transition, which the guard limits to voluntary
  contributions.
- Reversing ledger postings. The contribution raise posts no GL transaction, so a
  decline has nothing to reverse.
- Telling the owning app (learniq, portaliq) in a new signal. It reads the voided
  request and the invoice's `declined` state.

## Approach

The template text lives where every other dunning template lives, the docudesk
template registry. A small reader loads the entry for the invoice's language and
fills `{{description}}`, `{{amount}}` and `{{invoiceNumber}}` from the invoice.
The dunning service already consults `VoluntaryContributionPolicy` in
`executeStage()`; the voluntary branch now also sets `templateId`,
`renderedSubject` and `renderedBody` from the reader, and a declined invoice is
refused there and skipped in `tickInvoice()`.

The decline reuses the portal payment receiver's ownership chain: verify the
assertion, gate the audience, resolve the guardian's `customerMasterId` from
their portal account, find the invoice by id with that owner. Only an open
voluntary contribution is declined; everything else gets the same 403, so the
endpoint is no existence oracle. The state, the timestamp and the voided requests
are plain OpenRegister saves, the same way the reconciliation settles an invoice.

## New Dependencies

None.

## Impact

- `lib/Service/DunningRunService.php`, `lib/Service/Dunning/VoluntaryContributionPolicy.php`,
  new `lib/Service/Dunning/VoluntaryReminderTemplate.php`.
- New `lib/Service/ContributionDeclineService.php`,
  `lib/Controller/PortalContributionDeclineController.php`,
  `lib/Lifecycle/VoluntaryDeclineGuard.php`, `lib/Portal/PortalSubjectResolver.php`
  (shared with `PortalPaymentSessionService`).
- `lib/Portal/PortalContributionProvider.php` (parent manifest action),
  `appinfo/routes.php` (one route).
- `lib/Settings/register.d/school-contributions.json` (ARInvoice 0.15.0),
  `lib/Settings/docudesk-templates.json`.
- `lib/Service/ContributionInvoiceBuilder.php` (writes the language).

## Cross-Project Dependencies

- Builds on `extracurricular-fee-to-shillinq` (merged, #1704).
- Portaliq forwards the `decline` action server to server, exactly like `pay`.
  The parent portal screen that renders both is portaliq's (D30, another lane).
- `arinvoice-lines-and-portal-amounts` (this lane, second PR) edits the customer
  manifest in the same provider file. The two touch different methods; land in
  either order.

## Risks

### Risk 1: A bookkeeper declines a compulsory invoice through the lifecycle
**Severity:** Medium. **Mitigation:** both `decline` transitions require
`VoluntaryDeclineGuard::requireVoluntary`, and the portal receiver checks the
same policy before it saves.

### Risk 2: The template drifts from the policy
**Severity:** Low. **Mitigation:** a unit test reads the real template file and
fails when a template names costs, interest, a term or an IBAN, or drops the
voluntary sentence.

### Risk 3: An invoice raised before this change has no language
**Severity:** Low. **Mitigation:** the reader falls back to Dutch, the language
every existing contribution was raised in by default.

## Rollback Strategy

Revert the PR. The `declined` state and the two new contribution fields are
additive; an invoice already declined keeps its state, which the reverted code
reads as a non-payable, non-dunnable unknown state.
