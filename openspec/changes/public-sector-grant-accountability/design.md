# Design: public-sector-grant-accountability

Read at shillinq development @655363ab0 on 2026-09-29.

## Decisions

### D1. Grant settlement

A `determine` action on `Subsidie` (declared by FQCN) takes `determinedAmount`, computes the difference with `paidOutAmount` in cents, sets `reclaimedAmount` and, when the grant was given and paid out more, writes a draft `ARInvoice` to the counterparty; when the grant was received, a draft `APTransaction`. `SubsidieVerantwoordingService::buildVerantwoordingForGrant` runs from a `report` action on the grant page and stores a `SubsidieVerantwoording`; `requiresAuditorStatement` decides whether an `AuditorStatement` is asked for.

### D2. SiSa appendix

A new schema `SisaIndicator` (grant, regulation code, indicator code, year, value, unit, source) in a new fragment `lib/Settings/register.d/public-sector-grant-accountability.json`, and `Subsidie.sisaRegulationCode`. `lib/Service/PublicSector/SisaAppendix.php::forYear(string $administrationId, string $fiscalYear)` sums the posted lines tagged with the grant into the spending and income indicators, keeps output indicators as entered, and returns the appendix rows. `GET /api/v1/public-sector/sisa/{year}` returns the table and a CSV in the column order of the BZK template. `SisaReportingService::calculateAuditOpinion` fills `SisaReport.auditOpinion` from the findings as the service describes. Sending to BZK stays manual: the connection is unavailable.

### D3. EU declaration

A new schema `EuDeclaration` (project, period, expenditures, eligible total, EU share, state draft, submitted, paid). `lib/Service/PublicSector/EuDeclarationBuilder.php::propose(string $projectId, string $periodFrom, string $periodTo)` takes `EuExpenditure` in state declared with `eligibilityConfirmed` and at least one certified `SupportingDocument`, adds `euCoFundingAmount`, and lists every left-out expenditure with the reason `EuExpenditureGuard::canSubmit` gives. Submitting the declaration runs `submit` on each included expenditure.
