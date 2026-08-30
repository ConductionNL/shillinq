# Specification: bookkeeping-subsidie-verantwoording

**Status**: proposed
**Scope**: shillinq
**Tier**: T3 (operations + NL compliance core)
**Depends on**: bookkeeping-general-ledger (T1)

## Overview

Grant (subsidie) lifecycle management for Dutch organizations receiving public grants. Implements the ASV-model (Administratie SubsidieVerordening) per Awb 4.2, tracking from application through settlement, repayment, and optional settlement plans (afbetalingsregeling).

## Scope

- `Subsidie` register with ASV-model lifecycle
- `RepaymentInstallment` register for settlement plans
- Declarative lifecycle with approval gates
- Accounting entries for disbursement and repayment
- Settlement plan tracking (terugvordering sub-state)

## ADDED Requirements

### Requirement: REQ-SUB-001 — Subsidie register

The `Subsidie` schema SHALL track grant awards with:
- `administrationId` (FK to Administration)
- `subsidieName` (grant program name)
- `awardAmount` (MonetaryAmount)
- `state` (enum per ASV-model: aanvraag, verleend, ingetrokken, gewijzigd, vastgesteld, uitbetaald, teruggevorderd)
- `awardDate`, `disbursementDate`, `settlementDate` (timestamps)
- `attachmentUri` (docudesk reference to beschikking/award document)

#### Scenario: Spec conformance for REQ-SUB-001

- **GIVEN** the REQ-SUB-001 requirement above ("Subsidie register")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SUB-002 — Subsidie required fields

MUST include:
- `subsidieNumber` (unique identifier)
- `granteeOrganization` (name of recipient)
- `grantProgram` (Awb article reference, e.g. "Awb 4.2")
- `purposeDescription` (what grant is for)
- `budgetYear` (fiscal year)
- `currency` (EUR)
- `approvingAuthority` (issuing municipality/ministry)

#### Scenario: Spec conformance for REQ-SUB-002

- **GIVEN** the REQ-SUB-002 requirement above ("Subsidie required fields")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SUB-003 — Subsidie lifecycle

The `Subsidie.state` lifecycle SHALL declare eight transitions per ASV-model:
- `aanvraag → verleend` (grant approved, requires approval gate)
- `verleend → ingetrokken` (grant revoked before disbursement)
- `verleend → gewijzigd` (terms changed)
- `gewijzigd → vastgesteld` (changes confirmed)
- `vastgesteld → uitbetaald` (disbursement executed, creates GL posting)
- `uitbetaald → teruggevorderd` (repayment demanded)
- `teruggevorderd → afbetalingsregeling` (settlement plan if needed)

#### Scenario: Spec conformance for REQ-SUB-003

- **GIVEN** the REQ-SUB-003 requirement above ("Subsidie lifecycle")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SUB-004 — Approval gates

The transitions `aanvraag → verleend` and `uitbetaald → teruggevorderd` MUST require approval via `x-openregister-lifecycle.requires.approval-workflow` per ADR-022.

#### Scenario: Spec conformance for REQ-SUB-004

- **GIVEN** the REQ-SUB-004 requirement above ("Approval gates")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SUB-005 — Disbursement GL posting

When `vastgesteld → uitbetaald`, the system SHALL create a balanced `JournalEntry` (debit bank account, credit subsidy revenue) in `state: pending` (operator approval required before posting per ADR-022).

#### Scenario: Spec conformance for REQ-SUB-005

- **GIVEN** the REQ-SUB-005 requirement above ("Disbursement GL posting")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SUB-006 — ASV-model seed

The system SHALL ship `asv-model-lifecycle.json` with 8 canonical states + their Awb article citations:
- `aanvraag: "Awb 4:2, stap 1"`
- `verleend: "Awb 4:2, stap 2"`
- `vastgesteld: "Awb 4:2, stap 3"`
- ... (all 8 states with citations)

#### Scenario: Spec conformance for REQ-SUB-006

- **GIVEN** the REQ-SUB-006 requirement above ("ASV-model seed")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SUB-007 — RepaymentInstallment register

The `RepaymentInstallment` schema (for terugvordering settlements) SHALL track:
- `subsidieId` (FK to Subsidie)
- `installmentNumber` (1, 2, 3, ...)
- `dueDate` (payment due date)
- `amount` (MonetaryAmount)
- `paidDate` (when paid, if settled)
- `status` (enum: pending, due, overdue, paid)

#### Scenario: Spec conformance for REQ-SUB-007

- **GIVEN** the REQ-SUB-007 requirement above ("RepaymentInstallment register")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SUB-008 — Manifest entries

The `src/manifest.json` SHALL declare:
- `Subsidies > Overzicht` (type: index, lists all Subsidie records)
- `Subsidies > Verlende Subsidies` (type: index, filters state="verleend")
- `Subsidies > Terugvordelingen` (type: index, filters state="teruggevorderd")

#### Scenario: Spec conformance for REQ-SUB-008

- **GIVEN** the REQ-SUB-008 requirement above ("Manifest entries")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SUB-009 — Settlement plan sub-state

When `uitbetaald → teruggevorderd`, the operator MAY create a settlement plan (`RepaymentInstallment` records) if immediate repayment is not viable; in that case the Subsidie's `state` MUST remain `teruggevorderd` and the engine SHALL surface a sub-state indication that a payment plan exists.

#### Scenario: Spec conformance for REQ-SUB-009

- **GIVEN** the REQ-SUB-009 requirement above ("Settlement plan sub-state")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

### Requirement: REQ-SUB-010 — Compliance citation

This spec SHALL cite Awb 4.2 (Administrative Procedure Act, Title 4.2, Subsidies) + VNG ASV-model 2022 for the lifecycle and state definitions.

#### Scenario: Spec conformance for REQ-SUB-010

- **GIVEN** the REQ-SUB-010 requirement above ("Compliance citation")
- **WHEN** the implementing cycle authors register declarations, seeds, manifest entries, lifecycles, workflows, or guards per this requirement
- **THEN** the artefacts SHALL satisfy every SHALL/MUST clause stated, and reviewers MUST cite this requirement id in the implementing PR's acceptance section.

## Non-Goals

- No automatic disbursement processing
- No integration with grant application systems (separate systems)

## Reuse

- Lifecycle via OR `x-openregister-lifecycle` (ADR-031)
- Approval workflow via OR `approval-workflow` (ADR-022)
- GL posting via T1 JournalEntry materialization
- Seed import via repair step (ADR-022)

## Dependencies

- T1: GLTransaction (for disbursement posting), Account (cash/subsidy accounts)
- OR: lifecycle, approval-workflow
- docudesk: for award document attachment
