# bookkeeping-credit-control-dunning Specification

## ADDED Requirements

### Requirement: REQ-CCD-017: The letter and record composition SHALL live in its own class

`DunningLetterComposer` SHALL prepare a run's letter (a declined contribution
refused, a voluntary one once, without costs, in its own letter) and compose the
DunningRun record with the template fallback of REQ-CCD-016.
`DunningRunService::executeStage()` SHALL delegate to it and keep the pause guard,
the channel dispatch and the save. The run count SHALL only be read for a
voluntary or declined contribution. Behaviour SHALL be unchanged.

#### Scenario: An ordinary invoice passes through without counting runs

- GIVEN an ordinary invoice
- WHEN its run is prepared
- THEN the params are unchanged and the run count is never read
- @e2e exclude service; covered by `DunningLetterComposerTest::testAnOrdinaryInvoicePassesThroughWithoutCountingRuns`

#### Scenario: A voluntary contribution gets its own letter once

- GIVEN a voluntary contribution with no earlier run
- WHEN its run is prepared
- THEN the costs are cleared and the letter's template and body are set, and a second run is refused
- @e2e exclude service; covered by `DunningLetterComposerTest::testAVoluntaryContributionGetsItsOwnLetterWithoutCosts`

#### Scenario: The record falls back to the registry template

- GIVEN runs with a named template, with none at stage 2, and with an empty one at stage 3 under an app config override
- WHEN their records are composed
- THEN they carry `tpl-custom`, `tpl-stage2-herinnering-nl` and the override
- @e2e exclude service; covered by `DunningLetterComposerTest::testTheRecordFallsBackToTheRegistryTemplate` and `DunningRunServiceTest`
