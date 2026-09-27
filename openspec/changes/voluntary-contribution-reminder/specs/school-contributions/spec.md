# school-contributions Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- extracurricular-fee-to-shillinq
- voluntary-contribution-reminder

## Purpose

A voluntary school contribution (Wet vrijwillige ouderbijdrage) gets one reminder
at most. That reminder is its own friendly letter, not the ladder's first stage,
and the guardian can answer it by saying they will not pay, which closes the
invoice (`ARInvoice`, schema:Invoice). Decision D28; builds on REQ-SCON-007 and
REQ-SCON-008 of `extracurricular-fee-to-shillinq`. ADR-046 (portal contribution),
ADR-005 (security), ADR-031 (lifecycle guard as the imperative exception).

## ADDED Requirements

### Requirement: The voluntary reminder has its own template in English and Dutch (REQ-SCON-011)

Shillinq SHALL ship two templates in its docudesk template registry,
`tpl-dunning-voluntary-contribution-en` and `tpl-dunning-voluntary-contribution-nl`.
Each SHALL say that the contribution is voluntary and that the child takes part
whether the guardian pays or not, and SHALL say where to pay and where to say "I
will not pay". Neither SHALL name a payment term or due date, collection costs,
interest or a bank account. The template SHALL be chosen by
`ARInvoice.contribution.language`: a language starting with `en` picks English,
anything else, and a missing language, picks Dutch. The raise SHALL write
`contribution.language` from the charge.

#### Scenario: A Dutch guardian gets the Dutch voluntary reminder

- GIVEN a voluntary contribution invoice with `contribution.language = nl`, 60 euro, line text "Ouderbijdrage 2026-2027 (vrijwillig)"
- WHEN the reminder is rendered
- THEN the template id is `tpl-dunning-voluntary-contribution-nl`
- AND the body names the line text and 60,00 and says the contribution is voluntary and the child takes part
- AND the body names no term, costs, interest or IBAN, and no merge field is left unfilled
- @e2e exclude template rendering has no screen; covered by `VoluntaryReminderTemplateTest::testTheDutchReminderIsVoluntaryAndNamesNoTermsCostsOrInterest`

#### Scenario: An English guardian gets the English one, an unknown language gets Dutch

- GIVEN one invoice with `contribution.language = en_GB` and one without a language
- WHEN each reminder is rendered
- THEN the first uses `tpl-dunning-voluntary-contribution-en` and the second `tpl-dunning-voluntary-contribution-nl`
- @e2e exclude template rendering has no screen; covered by `VoluntaryReminderTemplateTest::testTheLanguagePicksTheTemplateAndFallsBackToDutch`

### Requirement: Every route to the voluntary reminder selects that template (REQ-SCON-012)

For an invoice whose `contribution.voluntary` is true, `DunningRunService::executeStage()`
SHALL write the voluntary template's id, rendered subject and rendered body on
the `DunningRun`, over the stage's `templateId` and over any template id, subject
or body the caller passed. `tickInvoice()` reaches the run through
`executeStage()` and SHALL therefore get the same template. Every other invoice
SHALL keep the stage template as before.

#### Scenario: The tick sends the voluntary letter, not stage 1

- GIVEN a voluntary contribution 60 days late on a ladder whose stage 1 uses `tpl-friendly`
- WHEN the dunning ticks it
- THEN the one run carries `templateId = tpl-dunning-voluntary-contribution-nl` and a rendered body that says the contribution is voluntary
- @e2e exclude scheduled dunning; covered by `DunningRunServiceTest::testAVoluntaryContributionGetsOneReminderAtMost`

#### Scenario: An operator cannot send the generic letter by hand

- GIVEN a voluntary contribution invoice
- WHEN `executeStage()` is called with `templateId = tpl-dunning-stage1-nl` and a rendered body that names an IBAN
- THEN the saved run carries the voluntary template id and its own body
- @e2e exclude operator dunning endpoint; covered by `DunningRunServiceTest::testExecuteStageAlwaysUsesTheVoluntaryTemplate`

### Requirement: A guardian can say they will not pay a voluntary contribution (REQ-SCON-013)

The parent portal manifest SHALL declare an endpoint-forward action `decline`
("I will not pay") with the field `invoiceId`, forwarded to
`POST /apps/shillinq/api/portal/contributions/decline`. The receiver SHALL verify
the `X-Portal-Subject` assertion, accept only the `parent` and `customer`
audiences, resolve the guardian's `customerMasterId` from their own portal account
and find the invoice by id or slug with that owner. When the invoice is a
voluntary contribution in `issued` or `overdue`, it SHALL set `lifecycleState` to
`declined`, stamp `contribution.declinedAt`, and set every `pending` payment
request on the invoice to `voided`. An invoice this guardian already declined
SHALL answer the same success without a write. Any other target (foreign,
compulsory, paid, written off, missing or malformed) SHALL get one uniform 403.
The customer manifest SHALL NOT carry the action.

#### Scenario: A guardian declines the ouderbijdrage

- GIVEN an overdue voluntary contribution owned by the guardian's customer and one pending payment request on it
- WHEN the guardian chooses "I will not pay"
- THEN the invoice is `declined` with `contribution.declinedAt` set, the request is `voided`, and the answer is 200 `declined`
- @e2e exclude server-to-server receiver; covered by `ContributionDeclineServiceTest::testAGuardianDeclinesTheirOwnVoluntaryContribution`

#### Scenario: A compulsory, foreign or paid invoice cannot be declined

- GIVEN a compulsory contribution, another guardian's voluntary contribution, and a paid voluntary contribution
- WHEN the guardian asks to decline each
- THEN each answer is the same 403 and nothing is saved
- @e2e exclude server-to-server receiver; covered by `ContributionDeclineServiceTest::testEverythingButAnOpenOwnVoluntaryContributionIsForbidden`

#### Scenario: The receiver refuses a request without a verified parent assertion

- GIVEN no assertion, and separately a supplier assertion
- WHEN the decline endpoint is called
- THEN the first answer is 401 and the second 403, before any read
- @e2e exclude server-to-server receiver; covered by `PortalContributionDeclineControllerTest::testTheReceiverGatesTheAssertionAndTheAudience`

### Requirement: A declined contribution is closed (REQ-SCON-014)

`ARInvoice` SHALL declare a lifecycle state `declined`, with transitions
`decline` from `issued` and `decline-overdue` from `overdue`, each requiring
`VoluntaryDeclineGuard::requireVoluntary`, so a bookkeeper can record a refusal
received by mail or phone for a voluntary contribution and for nothing else. A
declined invoice SHALL NOT count as overdue. `tickInvoice()` SHALL run nothing for it and
`executeStage()` SHALL refuse it.

#### Scenario: Dunning leaves a declined contribution alone

- GIVEN a declined voluntary contribution 60 days past due
- WHEN the dunning ticks it, and an operator asks for stage 1 directly
- THEN the tick runs nothing and the direct call is refused, and no run exists
- @e2e exclude scheduled dunning; covered by `DunningRunServiceTest::testADeclinedContributionIsNeverReminded`

#### Scenario: The lifecycle guard admits only a voluntary contribution

- GIVEN a voluntary and a compulsory contribution invoice
- WHEN the `decline` transition asks the guard
- THEN the guard admits the first and refuses the second
- @e2e exclude lifecycle guard; covered by `VoluntaryDeclineGuardTest::testOnlyAVoluntaryContributionMayBeDeclined`

## Non-Functional Requirements

- **Performance:** a decline costs two reads and at most three saves.
- **Accessibility:** the action is a plain labelled control rendered by portaliq (WCAG 2.2 AA through its runtime).
- **Internationalization:** the reminder exists in Dutch and English; the action label is English in the manifest, like `pay`.

## Notes

- The raise posts no GL transaction, so a decline reverses none.
- The owning app reads the refusal from the voided request and the invoice state; no new signal.
