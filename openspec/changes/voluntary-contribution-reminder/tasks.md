# Tasks: voluntary-contribution-reminder

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 11. -->

No Nextcloud migration class (migration.md): task 1 is the register overlay the
repair step imports. Every fix starts with a test that fails.

## Implementation Tasks

### Task 1: Register: ARInvoice 0.15.0 with language, declinedAt and the declined state
- **spec_ref**: `openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md#requirement-a-declined-contribution-is-closed-req-scon-014`
- **files**: `lib/Settings/register.d/school-contributions.json`, `lib/Lifecycle/VoluntaryDeclineGuard.php`, `tests/Unit/Service/SchoolContributionsFragmentTest.php`, `tests/Unit/Lifecycle/VoluntaryDeclineGuardTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN built THEN ARInvoice is 0.15.0 with contribution.language, contribution.declinedAt, the declined enum value and state, and both decline transitions require the guard
  - GIVEN the merged register WHEN built THEN isOverdue is false for a declined invoice
- [x] Implement
- [x] Test

### Task 2: The voluntary reminder templates and their reader
- **spec_ref**: `openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md#requirement-the-voluntary-reminder-has-its-own-template-in-english-and-dutch-req-scon-011`
- **files**: `lib/Settings/docudesk-templates.json`, `lib/Service/Dunning/VoluntaryReminderTemplate.php`, `lib/Service/Dunning/VoluntaryContributionPolicy.php`, `lib/Service/ContributionInvoiceBuilder.php`, `tests/Unit/Service/Dunning/VoluntaryReminderTemplateTest.php`, `tests/Unit/Service/ContributionInvoiceBuilderTest.php`
- **acceptance_criteria**:
  - GIVEN a Dutch voluntary invoice WHEN rendered THEN the nl template says voluntary and names no term, costs, interest or IBAN
  - GIVEN en_GB, de and no language WHEN rendered THEN en, nl, nl; the raise writes contribution.language
- [x] Implement
- [x] Test

### Task 3: The dunning selects the template and leaves a declined invoice alone
- **spec_ref**: `openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md#requirement-every-route-to-the-voluntary-reminder-selects-that-template-req-scon-012`
- **files**: `lib/Service/DunningRunService.php`, `tests/Unit/Service/DunningRunServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a voluntary invoice WHEN ticked or executed with a generic template THEN the run carries the voluntary template and body
  - GIVEN a declined invoice WHEN ticked or executed THEN nothing runs
- [x] Implement
- [x] Test

### Task 4: The decline service, receiver, route and portal action
- **spec_ref**: `openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md#requirement-a-guardian-can-say-they-will-not-pay-a-voluntary-contribution-req-scon-013`
- **files**: `lib/Portal/PortalSubjectResolver.php`, `lib/Service/Payment/PortalPaymentSessionService.php`, `lib/Service/ContributionDeclineService.php`, `lib/Controller/PortalContributionDeclineController.php`, `lib/Portal/PortalContributionProvider.php`, `appinfo/routes.php`, `tests/Unit/Service/ContributionDeclineServiceTest.php`, `tests/Unit/Controller/PortalContributionDeclineControllerTest.php`, `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - GIVEN an open own voluntary invoice WHEN declined THEN it is declined, declinedAt is set and pending requests are voided; a repeat writes nothing
  - GIVEN a foreign, compulsory or paid invoice, a malformed id or a wrong audience WHEN declined THEN one forbidden answer and no save
- [x] Implement
- [x] Test

### Task 5: Seed data and docs
- **spec_ref**: `openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md#requirement-a-declined-contribution-is-closed-req-scon-014`
- **files**: `lib/Settings/register.d/school-contributions.json`, `docs/api/school-contributions.md`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeded THEN every contribution invoice has a language and one declined voluntary contribution exists
- [ ] Implement
- [ ] Test

### Task 6: Verify
- [ ] Diff-scoped checks, then `composer check:strict`, `npm run lint`, `npm run format`, `npm run test:l10n`, hydra gates, once
