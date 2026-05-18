# Tasks — Document Attachment Integration

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-document-attachment-integration` spec — they are recorded
> now so the spec-review gate, dependency planning, and tier-cascade
> impact are all visible at proposal time. No source files are edited by
> this change itself.

## Tasks

- [ ] Task 1: Confirm no `bookkeeping-document-attachment-integration` capability spec already exists and that no `lib/Db/Attachment*` or `lib/Service/Attachment*` parallel-storage classes are present in shillinq (per ADR-022 anti-pattern enumeration)
- [ ] Task 2: Author `specs/bookkeeping-document-attachment-integration/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: none` header, `REQ-DA-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [ ] Task 4: Author `design.md` with Reuse Analysis table per hydra `rules.design`, citing ADR-022 (consume docudesk, no parallel storage) and the FK-URI vs internal-FK decision
- [ ] Task 5: Document the canonical FK URI scheme `docudesk://attachments/<uuid>/<filename>` in the spec and confirm with the docudesk team that the scheme matches its current public API
- [ ] Task 6: Declare the mime-type-per-role table (`invoice` → PDF + image/jpeg + image/png, `receipt` → PDF + image, `statement` → CAMT.053 XML + MT940 + text/csv, `contract` → PDF) per REQ-DA-002 as schema metadata
- [ ] Task 7: Specify the non-blocking failure-mode behaviour per REQ-DA-003 (save succeeds with URI persisted; audit captures docudesk unavailability; detail page renders warning banner)
- [ ] Task 8: Specify the auditor-role pass-through per REQ-DA-004 (auditor group in shillinq resolves against docudesk's auditor ACL via the Nextcloud group system)
- [ ] Task 9: Add the audit-side-panel manifest binding to bookkeeping detail pages in `src/manifest.json` so the attachment surface renders next to each bookkeeping object; `node tests/validate-manifest.js` exits 0
- [ ] Task 10: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph note declaring the URI contract and citing the consuming capabilities (AP, AR, bank-rec, journal-entries)

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review confirms the retention contract matches Belastingdienst expectations. Architecture reviewer confirms ADR-022 compliance (no app-local attachment storage; no `lib/Db/Attachment*`; no `lib/Service/Attachment*`). No source code changes outside `openspec/changes/add-shillinq-document-attachment-integration/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests for URI parsing + mime-type matching + non-blocking failure semantics (pre-declared on Tasks 5–8); Playwright MCP browser tests for the audit side-panel render + warning banner when docudesk is down (pre-declared on Task 9); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/attachments.md` per ADR-030 journeydoc convention and commits a side-panel screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Source Document`, `Attachment`, `Open in Documents`, `Document unavailable`, `Retry`.
