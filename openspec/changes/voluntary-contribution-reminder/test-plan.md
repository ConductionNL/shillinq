# Test Plan: voluntary-contribution-reminder

All cases are PHPUnit unit tests (`tests/Unit/`), run with
`vendor/bin/phpunit -c phpunit-unit.xml --filter <Class>`. Shillinq ships no
screen for this change (portaliq renders the action), so there is no Playwright
case. Every fix has a test that fails before the fix.

## Test Cases

### TC-1: The Dutch template is voluntary and names no terms, costs or interest
- **spec_ref**: `openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md#requirement-the-voluntary-reminder-has-its-own-template-in-english-and-dutch-req-scon-011`
- **type**: functional
- **persona**: a parent who reads the reminder
- **preconditions**: the real `lib/Settings/docudesk-templates.json`
- **steps**: `VoluntaryReminderTemplate::render()` for a Dutch invoice
- **expected result**: id `tpl-dunning-voluntary-contribution-nl`; the body says vrijwillig and that the child takes part; no `{{`; no term, IBAN, incasso or rente words
- **test command**: `--filter VoluntaryReminderTemplateTest`

### TC-2: The English template, and the fallback to Dutch
- **spec_ref**: `...#requirement-the-voluntary-reminder-has-its-own-template-in-english-and-dutch-req-scon-011`
- **type**: functional
- **preconditions**: invoices with `en_GB`, `de` and no language
- **steps**: render each
- **expected result**: `-en` for the first, `-nl` for the others; the English body names no term, costs or interest
- **test command**: `--filter VoluntaryReminderTemplateTest`

### TC-3: The raise writes the language
- **spec_ref**: `...#requirement-the-voluntary-reminder-has-its-own-template-in-english-and-dutch-req-scon-011`
- **type**: functional
- **steps**: `ContributionInvoiceBuilder::buildInvoice()` with language `en`
- **expected result**: `contribution.language = en`
- **test command**: `--filter ContributionInvoiceBuilderTest`

### TC-4: The tick and a direct call both use the voluntary template
- **spec_ref**: `...#requirement-every-route-to-the-voluntary-reminder-selects-that-template-req-scon-012`
- **type**: regression
- **preconditions**: a ladder whose stage 1 uses `tpl-friendly`
- **steps**: `tickInvoice()`; separately `executeStage()` with a generic template id and body
- **expected result**: both runs carry the voluntary id and body; a compulsory invoice keeps the stage template
- **test command**: `--filter DunningRunServiceTest`

### TC-5: A guardian declines their own open voluntary contribution
- **spec_ref**: `...#requirement-a-guardian-can-say-they-will-not-pay-a-voluntary-contribution-req-scon-013`
- **type**: functional
- **persona**: a parent in the portal
- **preconditions**: a portal account with the claim, an overdue voluntary invoice, one pending request
- **steps**: `ContributionDeclineService::decline()`, twice
- **expected result**: invoice `declined` with `declinedAt`, request `voided`; the second call writes nothing and still answers declined
- **test command**: `--filter ContributionDeclineServiceTest`

### TC-6: Everything else is one forbidden answer
- **spec_ref**: `...#requirement-a-guardian-can-say-they-will-not-pay-a-voluntary-contribution-req-scon-013`
- **type**: security
- **preconditions**: a compulsory, a foreign, a paid invoice; a URL-shaped id; a supplier audience; an unavailable object service
- **steps**: decline each
- **expected result**: forbidden for all but the last, which is downstream_error; no save
- **test command**: `--filter ContributionDeclineServiceTest`

### TC-7: The receiver gates the assertion and the audience
- **spec_ref**: `...#requirement-a-guardian-can-say-they-will-not-pay-a-voluntary-contribution-req-scon-013`
- **type**: security
- **steps**: `PortalContributionDeclineController::decline()` without, with a supplier and with a parent assertion
- **expected result**: 401, 403, 200; 502 when the service reports downstream_error
- **test command**: `--filter PortalContributionDeclineControllerTest`

### TC-8: Only the parent manifest carries the action
- **spec_ref**: `...#requirement-a-guardian-can-say-they-will-not-pay-a-voluntary-contribution-req-scon-013`
- **type**: functional
- **steps**: `PortalContributionProvider::getContribution()` for parent and customer
- **expected result**: parent has `decline` with field `invoiceId` and the endpoint; customer has only `pay`
- **test command**: `--filter PortalContributionProviderTest`

### TC-9: Dunning leaves a declined contribution alone
- **spec_ref**: `...#requirement-a-declined-contribution-is-closed-req-scon-014`
- **type**: regression
- **steps**: `tickInvoice()` and `executeStage()` on a declined invoice
- **expected result**: null and a refusal; no run saved
- **test command**: `--filter DunningRunServiceTest`

### TC-10: The guard and the register declare the closed state
- **spec_ref**: `...#requirement-a-declined-contribution-is-closed-req-scon-014`
- **type**: functional
- **steps**: `VoluntaryDeclineGuard::requireVoluntary()`; read the merged fragments
- **expected result**: the guard admits only a voluntary invoice; ARInvoice 0.15.0 has `declined`, both transitions require the guard, `isOverdue` and both aggregations exclude it
- **test command**: `--filter 'VoluntaryDeclineGuardTest|SchoolContributionsFragmentTest'`

## Coverage Summary

REQ-SCON-011 to 014 are covered by TC-1 to TC-10. Not covered here: portaliq's
rendering of the action (portaliq's own tests, D30 lane) and a live OpenRegister
lifecycle run of the guard (the unit test calls the guard directly).
