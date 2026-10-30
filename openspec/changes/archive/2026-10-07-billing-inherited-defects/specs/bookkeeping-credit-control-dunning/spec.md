# bookkeeping-credit-control-dunning Specification

## ADDED Requirements

### Requirement: REQ-CCD-016: A run SHALL fall back to the stage's default template

When neither the caller, the ladder stage nor the voluntary reminder letter names a
template, `DunningRunService::executeStage()` SHALL record the template id
`DunningTemplateRegistry::templateIdForStage()` gives for the stage, honouring the
`dunning.template.stage_N` app config override. A named template SHALL win.

#### Scenario: A stage without a template gets the registry default

- GIVEN a stage 2 run with no template id anywhere
- WHEN the stage is executed
- THEN the run records `tpl-stage2-herinnering-nl`
- @e2e exclude service; covered by `DunningRunServiceTest::testAStageWithoutATemplateGetsTheRegistryDefault`

#### Scenario: A named template wins

- GIVEN a run whose caller names template `tpl-custom`
- WHEN the stage is executed
- THEN the run records `tpl-custom`
- @e2e exclude service; covered by `DunningRunServiceTest::testANamedTemplateWinsOverTheRegistry`
