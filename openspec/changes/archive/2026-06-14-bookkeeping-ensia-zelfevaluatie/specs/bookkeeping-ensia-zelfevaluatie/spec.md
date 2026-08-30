# Spec: bookkeeping-ensia-zelfevaluatie

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** T1 general-ledger (`../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md`),
T2 document-attachment (`./bookkeeping-document-attachment-integration/spec.md`)

## ADDED Requirements

### Requirement: REQ-ENSIA-001 — ENSIA Jaarcyclus initialiseren met VNG-vragenset

Jaarcyclus-initialisatie MUST load the current-year BIO question set from
openregister bio-nen7510 (or configured external API) and generate
`Evaluatievraag` records per domain.

**Entities affected:**
- `ENSIAJaarcyclus` — new register (jaar, organisatie KvK, status, deadlines,
  verantwoordingsdomeinen array, procesEigenaar, vraagSetVersion, verklaringFile).
- `Evaluatievraag` — new register (cyclusId FK, domein, onderwerp, vraagCode,
  vraagtekst, antwoordType, antwoord, volwassenheidsScore, toelichting,
  beantwoorder, peerReviewer, peerReviewStatus, bewijsstukken array).

#### Scenario: CISO starts new ENSIA cycle for 2026 with BIO + DigiD

- **GIVEN** a CISO user with role `functionaris-informatiebeveiliging`
- **WHEN** they initiate "Nieuwe ENSIA-cyclus 2026" and select verantwoordingsdomeinen
  `["BIO", "DigiD", "SUWI"]`
- **THEN** the system MUST fetch the 2026 BIO question set (version ≥ BIO-1.04)
  and domain-specific questions from openregister bio-nen7510, create one
  `ENSIAJaarcyclus` record with `status: "in-voorbereiding"`, and generate
  `Evaluatievraag` records for each question with `status: "nog-niet-beantwoord"`.

#### Scenario: Question set version is recorded for audit trail

- **GIVEN** the cycle initialisation in REQ-ENSIA-001
- **WHEN** questions are fetched from openregister
- **THEN** the `ENSIAJaarcyclus.vraagSetVersion` field MUST be set to the
  fetched version (e.g., "BIO-1.04-2026") so external auditors can trace
  which question set was used.

### Requirement: REQ-ENSIA-002 — Vraag-toewijzing per onderwerp-eigenaar

Evaluatievraag assignment MUST allow the proceseigenaar to assign per-domein
answerers, who receive notifications and see their worklist grouped per
onderwerp.

#### Scenario: Teamleider infrastructuur assigned to BIO-access-control questions

- **GIVEN** a cycle with `status: "in-voorbereiding"` and assigned `procesEigenaar`
- **WHEN** the procesEigenaar assigns `beantwoorder: <infra-teamlead>` to all
  questions in domein=`"BIO"` onderwerp=`"Toegangsbeveiliging"`
- **THEN** the infra-teamlead MUST receive a notification with deeplink to
  their worklist, filtered to only their assigned questions, grouped by onderwerp.

### Requirement: REQ-ENSIA-003 — Volwassenheidsscore met onderbouwing-eis

Maturity-level answers (type `volwassenheidsniveau-1-5`) at score ≥ 3 MUST
require at least one evidence document and a toelichting field ≥ 50 characters.

#### Scenario: System requires evidence for maturity-3 answer

- **GIVEN** a question of type `volwassenheidsniveau-1-5`
- **WHEN** the beantwoorder enters `volwassenheidsScore: 3` and attempts to save
- **THEN** if `bewijsstukken.length === 0` OR `toelichting.length < 50`,
  the save MUST fail with error message: "Maturity score ≥ 3 requires evidence
  and toelichting (50+ chars)".

#### Scenario: Score ≤ 2 does not require evidence

- **GIVEN** a question of type `volwassenheidsniveau-1-5`
- **WHEN** the beantwoorder enters `volwassenheidsScore: 2` and a 10-char toelichting
- **THEN** save MUST succeed without evidence requirement.

### Requirement: REQ-ENSIA-004 — Peer-review-flow met wijziging-blokking

Peer-review MUST gate college-akkoord: per-question peer-review status
(akkoord / wijziging-gevraagd) must be tracked, and any unresolved
wijziging-gevraagd MUST block cycle advance to `college-akkoord`.

#### Scenario: Peer-reviewer requests change with commentaar

- **GIVEN** a cycle in `peer-review` status with peer-reviewers assigned
- **WHEN** a peer-reviewer marks a question `peerReviewStatus: "wijziging-gevraagd"`
  with commentaar
- **THEN** the commentaar MUST route back to the original beantwoorder,
  visible in their notification and worklist.

#### Scenario: Unresolved wijziging blocks college-akkoord transition

- **GIVEN** a cycle with one question still in `peerReviewStatus: "wijziging-gevraagd"`
- **WHEN** the procesEigenaar attempts to advance cycle to `college-akkoord`
- **THEN** the transition MUST fail with error: "Cannot advance to college-akkoord:
  {N} questions have unresolved peer-review wijzigingen".

#### Scenario: All questions akkoord allows college-akkoord

- **GIVEN** a cycle where all questions have `peerReviewStatus: "akkoord"`
- **WHEN** the procesEigenaar advances cycle to `college-akkoord`
- **THEN** the transition MUST succeed, and the `ENSIAJaarcyclus` status
  becomes `college-akkoord`.

### Requirement: REQ-ENSIA-005 — Bevinding automatisch genereren

When peer-review is complete, the system MUST auto-generate `Bevinding` records
(type `tekortkoming`) for questions where `volwassenheidsScore < VNG.normniveau`
(typically 3).

#### Scenario: Auto-generated tekortkoming from low maturity score

- **GIVEN** peer-review completion with question BIO-9.1.1 having
  `volwassenheidsScore: 2` and `VNG.normniveau: 3`
- **WHEN** the cycle transitions to `peer-review complete`
- **THEN** the system MUST create a `Bevinding` record with
  `type: "tekortkoming"`, `vraagId: <BIO-9.1.1>`, auto-populated
  `beschrijving` (question + score gap), `status: "open"`, and
  no owner/deadline initially.

#### Scenario: Operator can suppress individual finding

- **GIVEN** an auto-generated Bevinding
- **WHEN** the operator sets `status: "geaccepteerd"` (acceptance without mitigation)
  with reason
- **THEN** the finding MUST remain in the register for audit trail but
  not appear in compliance reports until manually reopened.

### Requirement: REQ-ENSIA-006 — College-verklaring genereren

College-declaration generation MUST produce a Word document on VNG template
with auto-filled organisation data, per-domain summaries, and top findings.

#### Scenario: College verklaring document generated with org data

- **GIVEN** a cycle in `college-akkoord` status with complete answers +
  resolved findings
- **WHEN** the procesEigenaar triggers "Genereer collegeverklaring"
- **THEN** the system MUST produce a Word document (DOCX format) containing:
  - organisation name + KvK (from `ENSIAJaarcyclus.organisatie`)
  - evaluation date
  - per-domein summary (e.g., "DigiD: 4 of 5 questions maturity ≥ 3")
  - top 5 findings + mitigation plan summary
  - blank handtekeningvelden with date fields for college-ondertekenaars
  - ready for college-vergadering review and signing.

### Requirement: REQ-ENSIA-007 — XML-export naar landelijke ENSIA-portal

XML export MUST conform to ENSIA-XSD and include all answers, scores,
evidence hashes, and college-brief reference.

#### Scenario: XML export downloadable for portal upload

- **GIVEN** a cycle in `college-akkoord` with signed verklaringFile uploaded
- **WHEN** the procesEigenaar chooses "Indienen bij ENSIA-portal"
- **THEN** the system MUST generate an XML file conforming to ENSIA-XSD,
  containing:
  - organisation identification (KvK + naam)
  - per-domein question answers + scores
  - per-question evidence document hashes (SHA-256 for integrity)
  - college-brief file reference + signature timestamp
  - cycle status + submission timestamp
  - offer XML as download (no direct API to VNG portal in 2026).

#### Scenario: XML can be re-exported if corrections are needed

- **GIVEN** the cycle in `ingediend` status
- **WHEN** an answer is corrected and peer-review re-completed, then
  approved by college again
- **THEN** XML export MUST be regenerable with updated answers + new
  evidence hashes, provided cycle ≤ 1 May deadline has not passed.

### Requirement: REQ-ENSIA-008 — Wijzigings-audit-log met diff

Every answer change MUST record timestamp, user, old/new values, and
(post peer-review) a required reason field. External auditors MUST be
able to view the change history per question.

#### Scenario: Answer edit before peer-review requires no reason

- **GIVEN** a question in `in-uitvoering` status
- **WHEN** the beantwoorder changes `antwoord` from "Nee" to "Ja"
- **THEN** the system MUST record the change in audit-trail with timestamp,
  user, old value, new value. No reason required at this stage.

#### Scenario: Answer edit after peer-review requires reason

- **GIVEN** a question that has completed peer-review
- **WHEN** the beantwoorder attempts to change `antwoord` after peer-review
- **THEN** the system MUST require a `reden` field (mandatory) explaining
  why the answer is changing post-review before the change is saved.

#### Scenario: External auditor views change history with diff

- **GIVEN** a question with multiple edits recorded in `auditTrail`
- **WHEN** an external auditor (with read-only role) views the question
  detail
- **THEN** the auditor MUST be able to see the full change sequence
  with before/after values, timestamps, and user who made the change.

### Requirement: REQ-ENSIA-009 — Bewijsstuk-koppeling met omschrijving

Evidence documents MUST be attachable per question with file reference
(docudesk FK) and omschrijving (description).

#### Scenario: Evidence document attached with description

- **GIVEN** a question requiring evidence per REQ-ENSIA-003
- **WHEN** the beantwoorder uploads a policy document via docudesk and links
  it with omschrijving "Access control policy effective 2025-01-01"
- **THEN** the `Evaluatievraag.bewijsstukken` array MUST contain the file-reference
  (docudesk URI) and omschrijving, visible to both peer-reviewers and auditors.

### Requirement: REQ-ENSIA-010 — Manifest navigation entries

Five manifest entries MUST be added for ENSIA navigation.

#### Scenario: ENSIA menu visible in manifest

- **GIVEN** the shillinq app running with T2 ENSIA capability
- **WHEN** a user with ENSIA role navigates the app
- **THEN** the manifest MUST include nav entries for:
  - ENSIA Cycles (index + detail pages)
  - Evaluations (index + detail pages)
  - Findings (index + detail pages)
  - Audit Trail (read-only index page)
  - College Verklaring (document generation page)

## Standards Alignment

- **BIO** — Baseline Informatiebeveiliging Overheid (BIO-1.04 + BIO-2 transition)
- **ENSIA** — Eenduidige Normatiek Single Information Audit (VNG/IPO/UvW spec)
- **ISO 27001:2022** — alignment with BIO controls
- **GDPR/UAVG** — privacy overlap with BIO annex
- **Dutch Public-Sector Standards** — NEN 7510, NORA, Logius DigiD norms, SUWInet,
  Wet BAG, Wet BRP, Wet WOZ

## Cross-App Interactions

- **openregister bio-nen7510** — canonical source for VNG question sets
  (REQ-ENSIA-001)
- **docudesk** — evidence document storage + attachment FK contract
  (REQ-ENSIA-009)
- **planix** (optional) — mitigation action project-task cross-link (post-T2)
- **launchpad** — ENSIA cycle voortgang widget (read-only aggregation)
- **decidesk** — collegebesluit workflow for verklaring approval (existing pattern)
