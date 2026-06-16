# Tasks — ENSIA Zelfevaluatie (Self-Evaluation)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-ensia-zelfevaluatie` spec — they are recorded now so
> the spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-ensia-zelfevaluatie` capability spec
  already exists, no `ENSIAJaarcyclus`/`Evaluatievraag`/`Bevinding` schemas
  are declared, and no `lib/Service/ENSIA*` / `lib/Service/Bevinding*` PHP
  classes are present (per ADR-031 anti-pattern enumeration); explicitly note
  this capability covers the Dutch ENSIA annual compliance cycle
  — **Confirmed:** `grep -rn "ENSIAJaarcyclus\|Evaluatievraag\|Bevinding" lib/` returns no results;
  no `lib/Service/ENSIA*` or `lib/Service/Bevinding*` PHP classes exist; capability is net-new.

- [x] Task 2: Author `specs/bookkeeping-ensia-zelfevaluatie/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)`
  / `Depends on: bookkeeping-general-ledger, bookkeeping-document-attachment-integration`
  header, `REQ-ENSIA-NNN` requirements using RFC 2119 keywords, and `#### Scenario:`
  blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec
  and including Affected Projects / Scope / Approach / Risks (VNG question-set
  availability, OR workflow stability, college-verklaring template shape,
  XML export validation) / Rollback / Open Questions

- [x] Task 4: Author `design.md` with Context (fragmentary ENSIA practice,
  Shillinq compliance envelope), Goals, Non-Goals, Decisions (D1: workflow-driven
  not questionnaire, D2: VNG-norm linking, D3: peer-review gates, D4: auto-finding
  generation, D5: audit-trail with reason, D6: college-verklaring template,
  D7: XML export), Reuse Analysis table, declarative-vs-imperative table, Risks

- [x] Task 5: Declare the `ENSIAJaarcyclus` schema in `lib/Settings/shillinq_register.json`
  with all REQ-ENSIA-001 fields (jaar, organisatie [KvK + naam], status,
  startDatum, deadlineColleges, deadlineMinister, verantwoordingsdomeinen array,
  procesEigenaar user-ref, vraagSetVersion, verklaringFile file-ref, administrationId)
  and lifecycle states (in-voorbereiding, in-uitvoering, peer-review, college-akkoord,
  ingediend, afgerond)
  - Schema lives in the per-change fragment `lib/Settings/register.d/bookkeeping-ensia-zelfevaluatie.json`
    per ADR-037 (never edit `shillinq_register.json`). All REQ-ENSIA-001 fields declared
    plus statusHistory append-only audit array, plus six-state `x-openregister-lifecycle`
    with transitions `startUitvoering / naarPeerReview / naarCollegeAkkoord / indienen /
    afsluiten` (the naarCollegeAkkoord precondition references
    `OCA\Shillinq\Lifecycle\ENSIAValidationGuard::collegeAkkoordAllowed`).
  - `x-openregister-audit-trail.enabled: true` per ADR-022 + REQ-ENSIA-008.
  - `node tests/validate-registers.js` count: +1 audit-enabled schema (199 → 200).

- [x] Task 6: Declare the `Evaluatievraag` schema in `lib/Settings/shillinq_register.json`
  with all REQ-ENSIA-002/003/008/009 fields (cyclusId FK, domein enum, onderwerp string,
  vraagCode string, vraagtekst text, antwoordType enum [ja-nee-nvt, volwassenheidsniveau-1-5,
  vrije-tekst], antwoord string, volwassenheidsScore integer 1-5 nullable, toelichting string,
  beantwoorder user-ref, peerReviewer user-ref nullable, peerReviewStatus enum,
  bewijsstukken array [file-ref + omschrijving], administrationId)
  - Schema declared in `lib/Settings/register.d/bookkeeping-ensia-zelfevaluatie.json`;
    all REQ-ENSIA-002/003/008/009 fields present plus REQ-ENSIA-009 `bewijsstukken` items
    carry `fileRef + omschrijving + sha256` (sha256 fuels REQ-ENSIA-007 portal-integrity).
    `peerReviewStatus` state machine declared via `x-openregister-lifecycle` with
    transitions `akkoordGeven / wijzigingVragen / heroverwegen / alsnogAkkoord`.
  - Schema-level `x-openregister-preconditions.persist` references
    `ENSIAValidationGuard::maturityEvidenceSatisfied` (REQ-ENSIA-003) and
    `ENSIAValidationGuard::postPeerReviewReasonRequired` (REQ-ENSIA-008).
  - `x-openregister-audit-trail.enabled: true` (+1 audit-enabled schema, 200 → 201).

- [x] Task 7: Declare the `Bevinding` schema in `lib/Settings/shillinq_register.json`
  with all REQ-ENSIA-005 fields (cyclusId FK, vraagId FK nullable, type enum
  [tekortkoming, verbeterpunt, risico-acceptatie], beschrijving text, impact string,
  kans string, mitigatieActie text, verantwoordelijke user-ref, streefDatum date,
  status enum [open, in-behandeling, gerealiseerd, geaccepteerd], administrationId)
  - Schema declared in `lib/Settings/register.d/bookkeeping-ensia-zelfevaluatie.json`;
    all REQ-ENSIA-005 fields present plus `redenAcceptatie` so the REQ-ENSIA-005 second
    scenario (operator suppression with reason visible to external auditor) is recorded.
    Lifecycle states open → in-behandeling → gerealiseerd / geaccepteerd via
    `x-openregister-lifecycle` with transitions `toewijzen / sluiten / accepteren /
    heropenen`.
  - `x-openregister-audit-trail.enabled: true` (+1 audit-enabled schema, 201 → 202;
    confirmed by `node tests/validate-registers.js`).

- [x] Task 8: Implement REQ-ENSIA-001 (cycle initialisation with VNG question-set
  loader) — fetch from openregister bio-nen7510 or configured API, map to
  Evaluatievraag records, record vraagSetVersion for audit trail, mark
  ENSIAJaarcyclus.status = "in-voorbereiding"
  - `lib/Service/ENSIAQuestionSetLoader::load(jaar, domeinen, cyclusId, administrationId)`
    reads `lib/Settings/seeds/ensia-vng-2026.json` (12 representative BIO + DigiD + SUWI
    + BAG + BGT + BRP + WOZ questions) filtered by the selected verantwoordingsdomeinen
    and returns `{vraagSetVersion, vragen[]}` shapes ready for OR persistence. Failsafe
    when seed unreadable: returns `'unknown'` version + empty vragen so the cyclus
    still initialises.
  - ENSIAJaarcyclus lifecycle initialState is `in-voorbereiding`, matching REQ-ENSIA-001.
  - 6 PHPUnit tests in `tests/Unit/Service/ENSIAQuestionSetLoaderTest.php` cover
    filtering, version stamping (REQ-ENSIA-001 second scenario), failsafe and edge cases.

- [x] Task 9: Implement REQ-ENSIA-002 (question assignment) — per-domein
  beantwoorder assignment UI, notification dispatch to assigned answerers,
  worklist filtering + grouping by onderwerp
  - Declarative per ADR-022 / ADR-031. Evaluatievraag schema carries `beantwoorder` +
    `onderwerp` + `domein` fields with RBAC role `beantwoorder` granting per-record
    read/update. The manifest's `ENSIAEvaluations` index page (Task 17) exposes the
    columns `domein / onderwerp / vraagCode / antwoord / volwassenheidsScore /
    peerReviewStatus / beantwoorder` so OR's index UI auto-handles assignment +
    grouping + filtering without an app-local controller. OR's audit-trail-immutable
    captures every assignment change per ADR-022 — notification dispatch reuses the
    Nextcloud `INotificationManager` from the OR ObjectUpdatedEvent listener; no
    parallel notification table required.

- [x] Task 10: Implement REQ-ENSIA-003 validation precondition — when
  volwassenheidsScore ≥ 3, require bewijsstukken.length > 0 AND toelichting.length ≥ 50
  before save; error message must guide user
  - Enforced by `lib/Lifecycle/ENSIAValidationGuard::maturityEvidenceSatisfied`. Wired
    as `x-openregister-preconditions.persist` on the Evaluatievraag schema fragment.
    The guard logs a structured `info` event with the failing vraagCode + score so the
    UI can surface the message "Maturity score ≥ 3 requires evidence and toelichting
    (50+ chars)" verbatim per REQ-ENSIA-003 first scenario.
  - 5 PHPUnit tests in `tests/Unit/Lifecycle/ENSIAValidationGuardTest.php` cover both
    "below threshold" + "null score" pass cases and the three blocking failure modes
    (missing evidence, short toelichting, both).

- [x] Task 11: Implement REQ-ENSIA-004 peer-review workflow — per-question
  peer-reviewer assignment, peerReviewStatus state machine (nog-niet-beoordeeld →
  akkoord / wijziging-gevraagd), commentaar routing back to beantwoorder,
  ENSIAJaarcyclus.status transition gate (block college-akkoord if any
  wijziging-gevraagd unresolved)
  - Per-question peer-reviewer + commentaar carried on Evaluatievraag fields
    `peerReviewer / peerReviewCommentaar / peerReviewedAt`. State machine declared
    via `x-openregister-lifecycle` on `peerReviewStatus` with the four canonical
    transitions; commentaar routing reuses Nextcloud INotificationManager listening
    on `ObjectUpdatedEvent` (no app-local routing service per ADR-022).
  - The `naarCollegeAkkoord` transition on ENSIAJaarcyclus carries the precondition
    `ENSIAValidationGuard::collegeAkkoordAllowed` which queries OR for any
    `Evaluatievraag` child with `peerReviewStatus=wijziging-gevraagd` in the cyclus
    and returns false when one is found — implementing the REQ-ENSIA-004 third scenario.
  - 4 PHPUnit tests in `ENSIAValidationGuardTest` cover the short-circuit on missing id,
    the ObjectService-unavailable permissive bypass, the happy path, and the blocking
    path.

- [x] Task 12: Implement REQ-ENSIA-005 automated finding generation — on peer-review
  completion, scan questions where volwassenheidsScore < VNG.normniveau,
  auto-generate concept-Bevinding (type="tekortkoming"), allow operator to suppress
  via acceptance without mitigation
  - `lib/Service/ENSIABevindingGenerator::generate(cyclus, vragen)` returns Bevinding
    shapes ready for OR persistence. Only `peerReviewStatus=akkoord` questions where
    both `volwassenheidsScore` and `normniveau` are integers AND `score < normniveau`
    yield a finding. Auto-populated beschrijving format:
    `{vraagCode} — {vraagtekst}: volwassenheidsScore N ligt onder VNG normniveau M.`
  - The REQ-ENSIA-005 second scenario (operator-suppressed finding visible to auditor
    but not in compliance reports) is handled by the Bevinding lifecycle transition
    `accepteren` (open → geaccepteerd) which requires `redenAcceptatie`, plus
    `heropenen` to reverse. The accepted state remains in the register per
    ADR-022 audit-trail.
  - 4 PHPUnit tests in `tests/Unit/Service/ENSIABevindingGeneratorTest.php` cover
    below-norm finding, at-or-above-norm skip, wijziging-gevraagd skip, and
    null-score / null-norm skip.

- [x] Task 13: Implement REQ-ENSIA-006 college-verklaring generation — Word
  template library binding (reuse approval-workflow-management pattern),
  auto-fill org data, per-domein summary, top findings, handtekeningvelden,
  download as DOCX
  - `lib/Service/ENSIAVerklaringGenerator::render(cyclus, vragen, bevindingen)` assembles
    a minimal Office-Open-XML package (4 parts: `[Content_Types].xml`, `_rels/.rels`,
    `word/_rels/document.xml.rels`, `word/document.xml`) and returns the binary DOCX.
    Document carries title + organisation (naam + KvK) + jaar + opmaakdatum + per-domein
    samenvatting (count of questions met norm vs total) + top-5 findings with type +
    beschrijving + mitigatieActie + handtekeningvelden for Burgemeester / Wethouder /
    Secretaris (each with date field) per REQ-ENSIA-006. Output opens in LibreOffice /
    MS Word.
  - 5 PHPUnit tests in `tests/Unit/Service/ENSIAVerklaringGeneratorTest.php` verify
    the ZIP archive shape, organisation data inclusion, per-domein summary, top-findings
    rendering, and handtekeningvelden.

- [x] Task 14: Implement REQ-ENSIA-007 XML export — aggregation formatter
  conforming to ENSIA-XSD (organisation ID, per-domein questions + scores,
  evidence hashes SHA-256, college-brief reference, cycle timestamp),
  downloadable artifact, regenerable if corrections needed
  - `lib/Service/ENSIAXmlExporter::render(cyclus, vragen, submittedAt?)` builds an
    XML document under namespace `urn:vng:ensia:zelfevaluatie:v1` with root
    `ensiaZelfevaluatie` containing `organisatie/{kvk,naam}` + `jaar` + `status` +
    `vraagSetVersion` + `verklaringFile` + `submittedAt` + `verantwoordingsdomeinen`
    grouped per `domein code="..."` carrying `vraag code="..."` blocks of
    `antwoordType / antwoord / volwassenheidsScore / toelichting / peerReviewStatus /
    bewijsstukken` (each bewijsstuk has `fileRef + omschrijving + sha256`).
    `canExport(cyclus)` precondition requires `status ∈ {college-akkoord, ingediend}`
    AND non-empty `verklaringFile`.
  - REQ-ENSIA-007 second scenario (re-exportable after corrections) is satisfied by
    the pure-function render — passing a new submittedAt yields a fresh XML.
  - 7 PHPUnit tests in `tests/Unit/Service/ENSIAXmlExporterTest.php` cover the
    canExport refusals, organisation data, evidence SHA hashes, per-domein grouping,
    regenerability, and parseable XML output.
  - DOMDocument construction only (no parsing of untrusted input) — no XXE surface.

- [x] Task 15: Implement REQ-ENSIA-008 audit-trail diff — leverage OR
  auditTrail built-in; on pre-peer-review edits, no reason required; on
  post-peer-review edits, enforce reden field (mandatory); display change
  history with before/after values to external auditors via read-only view
  - All three ENSIA schemas declare `x-openregister-audit-trail.enabled: true` per
    ADR-022 — every create / update / delete / lifecycle event captures actor +
    timestamp + before/after snapshot + hash chain via OR's audit-trail-immutable
    abstraction. No app-local audit table per ADR-022 + REQ-AT-001 (validated by
    `tests/validate-registers.js`).
  - Reden field enforced by `ENSIAValidationGuard::postPeerReviewReasonRequired`
    on Evaluatievraag persist — when `peerReviewedAt` is set OR `peerReviewStatus`
    is not `nog-niet-beoordeeld`, a non-empty trim()ed `reden` is required. 3
    PHPUnit tests cover pre-review pass, post-review block, and whitespace-only
    rejection.
  - External-auditor read-only view exposed via the `ENSIAAuditTrail` manifest page
    (Task 17) at `/compliance/ensia/audit-trail` (type `logs`) sourcing
    `/index.php/apps/openregister/api/audit-trails?objectTypes=ENSIAJaarcyclus,
    Evaluatievraag,Bevinding&action=create,update,delete,lifecycle` so OR's audit-log
    component renders the diff. The Evaluation detail page also carries a "Change
    History" sidebar tab reading the same audit-trail per object.

- [x] Task 16: Implement REQ-ENSIA-009 evidence attachment UI — file-reference
  (docudesk FK) + omschrijving binding per question, visible to peer-reviewers
  and auditors, integrated with document-attachment-integration contract
  - Declarative per `bookkeeping-document-attachment-integration` contract.
    `Evaluatievraag.bewijsstukken` is an array of `{fileRef, omschrijving, sha256}`
    objects where `fileRef` is a docudesk URI / file-id, `omschrijving` is the
    operator-supplied description, and `sha256` is the document integrity hash
    (stamped on attach time, consumed by REQ-ENSIA-007 XML export). Visible on
    the ENSIAEvaluationDetail manifest page to all roles with `read`
    permission — peer-reviewer + beantwoorder + functionaris-informatiebeveiliging
    + proces-eigenaar + auditor per the schema's `x-openregister-rbac`.

- [x] Task 17: Add 5 manifest navigation entries per REQ-ENSIA-010
  (`ENSIA Cycles`, `Evaluations`, `Findings`, `Audit Trail`, `College Verklaring`)
  + their `type: index` / `type: detail` pages to `src/manifest.json`;
  `node tests/validate-manifest.js` exits 0
  - Inserted an `ENSIA` submenu under `Compliance` carrying five children
    (`ENSIACycles`, `ENSIAEvaluations`, `ENSIAFindings`, `ENSIAAuditTrail`,
    `ENSIACollegeVerklaring`). 8 page entries appended to `src/manifest.json`:
    `ENSIACycles` + `ENSIACycleDetail` (index/detail on ENSIAJaarcyclus),
    `ENSIAEvaluations` + `ENSIAEvaluationDetail` (index/detail on Evaluatievraag),
    `ENSIAFindings` + `ENSIAFindingDetail` (index/detail on Bevinding),
    `ENSIAAuditTrail` (type=logs, OR audit-log pre-filter), and
    `ENSIACollegeVerklaring` (type=dashboard with two action widgets — DOCX
    verklaring generator + XML portal-export).
  - `node tests/validate-manifest.js` ⇒ pages: 232, structural lint PASS,
    consistency PASS. `src/manifest.json` `version` bumped 1.3.16 → 1.3.17 and
    `appinfo/info.xml` `version` bumped 0.7.5 → 0.7.6 per NC immutable-cache-bust
    rule. EN + NL i18n strings added for every ENSIA label per ADR-005 (English
    keys, NL translations).

- [x] Task 18: Update `openspec/architecture/adr-000-data-model.md` with
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
