# Tasks — ENSIA Zelfevaluatie (Self-Evaluation)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-ensia-zelfevaluatie` spec — they are recorded now so
> the spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [ ] Task 1: Confirm no `bookkeeping-ensia-zelfevaluatie` capability spec
  already exists, no `ENSIAJaarcyclus`/`Evaluatievraag`/`Bevinding` schemas
  are declared, and no `lib/Service/ENSIA*` / `lib/Service/Bevinding*` PHP
  classes are present (per ADR-031 anti-pattern enumeration); explicitly note
  this capability covers the Dutch ENSIA annual compliance cycle

- [ ] Task 2: Author `specs/bookkeeping-ensia-zelfevaluatie/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)`
  / `Depends on: bookkeeping-general-ledger, bookkeeping-document-attachment-integration`
  header, `REQ-ENSIA-NNN` requirements using RFC 2119 keywords, and `#### Scenario:`
  blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline

- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec
  and including Affected Projects / Scope / Approach / Risks (VNG question-set
  availability, OR workflow stability, college-verklaring template shape,
  XML export validation) / Rollback / Open Questions

- [ ] Task 4: Author `design.md` with Context (fragmentary ENSIA practice,
  Shillinq compliance envelope), Goals, Non-Goals, Decisions (D1: workflow-driven
  not questionnaire, D2: VNG-norm linking, D3: peer-review gates, D4: auto-finding
  generation, D5: audit-trail with reason, D6: college-verklaring template,
  D7: XML export), Reuse Analysis table, declarative-vs-imperative table, Risks

- [ ] Task 5: Declare the `ENSIAJaarcyclus` schema in `lib/Settings/shillinq_register.json`
  with all REQ-ENSIA-001 fields (jaar, organisatie [KvK + naam], status,
  startDatum, deadlineColleges, deadlineMinister, verantwoordingsdomeinen array,
  procesEigenaar user-ref, vraagSetVersion, verklaringFile file-ref, administrationId)
  and lifecycle states (in-voorbereiding, in-uitvoering, peer-review, college-akkoord,
  ingediend, afgerond)

- [ ] Task 6: Declare the `Evaluatievraag` schema in `lib/Settings/shillinq_register.json`
  with all REQ-ENSIA-002/003/008/009 fields (cyclusId FK, domein enum, onderwerp string,
  vraagCode string, vraagtekst text, antwoordType enum [ja-nee-nvt, volwassenheidsniveau-1-5,
  vrije-tekst], antwoord string, volwassenheidsScore integer 1-5 nullable, toelichting string,
  beantwoorder user-ref, peerReviewer user-ref nullable, peerReviewStatus enum,
  bewijsstukken array [file-ref + omschrijving], administrationId)

- [ ] Task 7: Declare the `Bevinding` schema in `lib/Settings/shillinq_register.json`
  with all REQ-ENSIA-005 fields (cyclusId FK, vraagId FK nullable, type enum
  [tekortkoming, verbeterpunt, risico-acceptatie], beschrijving text, impact string,
  kans string, mitigatieActie text, verantwoordelijke user-ref, streefDatum date,
  status enum [open, in-behandeling, gerealiseerd, geaccepteerd], administrationId)

- [ ] Task 8: Implement REQ-ENSIA-001 (cycle initialisation with VNG question-set
  loader) — fetch from openregister bio-nen7510 or configured API, map to
  Evaluatievraag records, record vraagSetVersion for audit trail, mark
  ENSIAJaarcyclus.status = "in-voorbereiding"

- [ ] Task 9: Implement REQ-ENSIA-002 (question assignment) — per-domein
  beantwoorder assignment UI, notification dispatch to assigned answerers,
  worklist filtering + grouping by onderwerp

- [ ] Task 10: Implement REQ-ENSIA-003 validation precondition — when
  volwassenheidsScore ≥ 3, require bewijsstukken.length > 0 AND toelichting.length ≥ 50
  before save; error message must guide user

- [ ] Task 11: Implement REQ-ENSIA-004 peer-review workflow — per-question
  peer-reviewer assignment, peerReviewStatus state machine (nog-niet-beoordeeld →
  akkoord / wijziging-gevraagd), commentaar routing back to beantwoorder,
  ENSIAJaarcyclus.status transition gate (block college-akkoord if any
  wijziging-gevraagd unresolved)

- [ ] Task 12: Implement REQ-ENSIA-005 automated finding generation — on peer-review
  completion, scan questions where volwassenheidsScore < VNG.normniveau,
  auto-generate concept-Bevinding (type="tekortkoming"), allow operator to suppress
  via acceptance without mitigation

- [ ] Task 13: Implement REQ-ENSIA-006 college-verklaring generation — Word
  template library binding (reuse approval-workflow-management pattern),
  auto-fill org data, per-domein summary, top findings, handtekeningvelden,
  download as DOCX

- [ ] Task 14: Implement REQ-ENSIA-007 XML export — aggregation formatter
  conforming to ENSIA-XSD (organisation ID, per-domein questions + scores,
  evidence hashes SHA-256, college-brief reference, cycle timestamp),
  downloadable artifact, regenerable if corrections needed

- [ ] Task 15: Implement REQ-ENSIA-008 audit-trail diff — leverage OR
  auditTrail built-in; on pre-peer-review edits, no reason required; on
  post-peer-review edits, enforce reden field (mandatory); display change
  history with before/after values to external auditors via read-only view

- [ ] Task 16: Implement REQ-ENSIA-009 evidence attachment UI — file-reference
  (docudesk FK) + omschrijving binding per question, visible to peer-reviewers
  and auditors, integrated with document-attachment-integration contract

- [ ] Task 17: Add 5 manifest navigation entries per REQ-ENSIA-010
  (`ENSIA Cycles`, `Evaluations`, `Findings`, `Audit Trail`, `College Verklaring`)
  + their `type: index` / `type: detail` pages to `src/manifest.json`;
  `node tests/validate-manifest.js` exits 0

- [ ] Task 18: Update `openspec/architecture/adr-000-data-model.md` with
  `ENSIAJaarcyclus`/`Evaluatievraag`/`Bevinding` entries, reconciling against
  any existing `Compliance`/`Assessment` data-model entries

## Verification

`openspec validate` must exit clean on the change folder. Compliance-officer
persona peer review (e.g. leveraging a compliance-team reference persona)
confirms the ENSIA flow matches Dutch public-sector practice (cycle intake →
question answering with evidence → peer-review → college approval → XML export
→ portal submission → closure). Architecture reviewer confirms ADR-022 + ADR-024
+ ADR-031 compliance (no app-local ENSIA orchestrator table; lifecycle
declarative or ADR-031-exception-annotated guard; manifest carries the navigation).
External audit trail confirms question-change history is fully traced for
external IT auditor access. No source code changes outside
`openspec/changes/bookkeeping-ensia-zelfevaluatie/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle
(separate `opsx-apply`) is responsible for:

- **PHPUnit unit tests** — ENSIA lifecycle transitions, cycle initialisation
  (VNG question-set fetch), question assignment, berijking maturity validation,
  peer-review wijziging-blokking, automated finding generation from norm
  deviations, audit-trail recording with pre/post-peer-review reason
  requirements, evidence attachment, college-verklaring DOCX generation,
  XML export XSD compliance.

- **Playwright MCP browser tests** — 5 manifest navigation entries (Cycles
  index + detail, Evaluations index + detail, Findings index + detail, Audit
  Trail read-only index, College Verklaring generation page), cycle workflow
  end-to-end (intake → answering → peer-review → college-akkoord → XML export),
  evidence attachment flow, peer-reviewer wijziging-commentaar + resolution.

- **`composer test` green** — all tests passing at implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle
authors:

- **`docs/user-guide/bookkeeping/ensia-zelfevaluatie.md`** — Dutch-language
  user guide per ADR-030 journeydoc convention, covering ENSIA cycle lifecycle,
  question assignment, evidence attachment, peer-review, college-verklaring
  generation, XML portal export, audit-trail inspection for auditors.

- **`docs/images/`** — screenshots of ENSIA cycle intake, question-answering UI,
  evidence upload, peer-review wijziging-commentaar, college-verklaring DOCX,
  audit-trail diff-view.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle
adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:

- `ENSIA Zelfevaluatie`, `ENSIA Cycle`, `Cycle Status`, `In Preparation`,
  `In Progress`, `Peer Review`, `College Approval`, `Submitted`, `Completed`,
  `Evaluation Question`, `Maturity Level`, `Evidence`, `Description`,
  `Peer Reviewer`, `Change Requested`, `Finding`, `Shortcoming`, `Improvement
  Opportunity`, `Risk Acceptance`, `Mitigation Action`, `Owner`, `Target Date`,
  `College Declaration`, `Audit Trail`, `Change History`, `Submitted By`,
  `Reason`, `XML Export`, `Portal Upload`, `VNG Question Set`, `Evidence Document`.
