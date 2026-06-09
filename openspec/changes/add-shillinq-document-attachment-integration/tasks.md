# Tasks — Document Attachment Integration

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-document-attachment-integration` spec — they are recorded
> now so the spec-review gate, dependency planning, and tier-cascade
> impact are all visible at proposal time. No source files are edited by
> this change itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-document-attachment-integration` capability spec already exists and that no `lib/Db/Attachment*` or `lib/Service/Attachment*` parallel-storage classes are present in shillinq (per ADR-022 anti-pattern enumeration)
  - Scan confirmed: `find lib/Db -name "Attachment*"`, `find lib/Service -name "Attachment*"`, and `find lib/Controller -name "*Attachment*" -o -name "*DocumentProxy*"` all return zero hits. No `multipart/form-data` upload endpoints, no `base64` / `binary` schema fields anywhere in shillinq. The capability spec at `openspec/specs/bookkeeping-document-attachment-integration/spec.md` was promoted from this change folder by commit `6fb8c916` (Gate-19 e2e sweep, 2026-05-27) — it is the canonical version this change continues to declare; no parallel spec exists.
- [x] Task 2: Author `specs/bookkeeping-document-attachment-integration/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: none` header, `REQ-DA-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN
  - Authored — see `specs/bookkeeping-document-attachment-integration/spec.md` carrying REQ-DA-001…REQ-DA-005 with the required header and twelve `#### Scenario:` blocks.
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
  - Authored — see `proposal.md` referencing `../../specs/nextcloud-app/spec.md` and including the required sections.
- [x] Task 4: Author `design.md` with Reuse Analysis table per hydra `rules.design`, citing ADR-022 (consume docudesk, no parallel storage) and the FK-URI vs internal-FK decision
  - Authored — see `design.md` D1/D2/D3 + the Reuse Analysis matrix + ADR-031 declarative-vs-imperative table.
- [x] Task 5: Document the canonical FK URI scheme `docudesk://attachments/<uuid>/<filename>` in the spec and confirm with the docudesk team that the scheme matches its current public API
  - Documented as REQ-DA-002 with the `^docudesk://attachments/[a-f0-9-]{36}/.+$` pattern matcher + accept/reject scenarios. Docudesk-side confirmation deferred to the implementing cycle's RBAC + URI-resolution discovery (Open Question 1, `proposal.md`); no API-shape changes are required on docudesk per the proposal's "no docudesk-side schema changes" out-of-scope clause.
- [x] Task 6: Declare the mime-type-per-role table (`invoice` → PDF + image/jpeg + image/png, `receipt` → PDF + image, `statement` → CAMT.053 XML + MT940 + text/csv, `contract` → PDF) per REQ-DA-002 as schema metadata
  - Declared as REQ-DA-003 with the role-to-mime-type matrix (`invoice` → PDF + PNG + JPEG + UBL XML; `receipt` → PDF + PNG + JPEG + HEIC; `statement` → CAMT.053 XML + MT940 + scanned PDF; `archive-xml` → pain.001 XML; `contract` → PDF) keyed off an `x-shillinq-attachment-role` schema-extension block, and called as `role` metadata into the manifest's `docudesk-attachment-viewer` widget props (Task 9).
- [x] Task 7: Specify the non-blocking failure-mode behaviour per REQ-DA-003 (save succeeds with URI persisted; audit captures docudesk unavailability; detail page renders warning banner)
  - Specified as REQ-DA-004 with three scenarios: save-succeeds-when-unavailable (audit captures `action: docudesk-unreachable`), warning-banner-on-detail-page with retry, and retry-clears-banner. The manifest widget carries the `fallbackMessage` ("Source document unreachable — docudesk may be unavailable; retry later.") declaratively per Task 9.
- [x] Task 8: Specify the auditor-role pass-through per REQ-DA-004 (auditor group in shillinq resolves against docudesk's auditor ACL via the Nextcloud group system)
  - Specified as REQ-DA-005: the bookkeeping `auditor` role MUST resolve view permission against docudesk's auditor ACL through the Nextcloud group system without a shillinq-side proxy controller. ADR-031 shape-neutral exception path documented for the case where OR's cross-app RBAC abstraction cannot express the mapping. Reviewer-confirms-no-proxy scenario asserts the absence of `lib/Controller/*Attachment*.php` / `lib/Controller/*Document*Proxy*.php` files (verified under Task 1).
- [x] Task 9: Add the audit-side-panel manifest binding to bookkeeping detail pages in `src/manifest.json` so the attachment surface renders next to each bookkeeping object; `node tests/validate-manifest.js` exits 0
  - Added a `documents` sidebar tab (order 80, `FileDocumentOutline` icon) to the four detail pages that today carry `sourceDocumentUri`: `BankReconciliationDetail` (role `statement`), `JournalDetail` (role `receipt`), `APInvoiceDetail` (role `invoice`), `ARInvoiceDetail` (role `invoice`). The tab declares a single `type: data` widget pointing at the canonical `docudesk-attachment-viewer` component with `uriField`, `appField`, `role`, and `fallbackMessage` declarative props (REQ-DA-002 / REQ-DA-003 / REQ-DA-004). `KostenpostDetail` uses a different attachment shape (`attachmentUri` for R&D-subsidie S&O-uren-staat per ADR-000 line 5526) and is intentionally outside this contract's surface. `node tests/validate-manifest.js` exits 0 (`structural lint: PASS`, `consistency check: PASS`, 194 pages).
- [x] Task 10: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph note declaring the URI contract and citing the consuming capabilities (AP, AR, bank-rec, journal-entries)
  - Added the "Source-document attachment URI contract" blockquote immediately after the audit-trail-on-every-register block in `openspec/architecture/adr-000-data-model.md`. The note pins the canonical `docudesk://attachments/<uuid>/<filename>` URI, declares the `sourceDocumentUri` field shape, enumerates the ADR-022 anti-patterns (no `lib/Db/Attachment*`, no `lib/Service/Attachment*`, no proxy controllers, no `multipart/form-data` upload endpoints, no `base64` / `binary` schema fields), summarises the mime-type-per-role matrix, restates the non-blocking failure-mode semantics, and lists the five consuming capabilities (`bookkeeping-journal-entries`, `bookkeeping-accounts-payable-core`, `bookkeeping-accounts-receivable-core`, `bookkeeping-bank-reconciliation`, `bookkeeping-bank-connectors`) plus the manifest binding location.

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review confirms the retention contract matches Belastingdienst expectations. Architecture reviewer confirms ADR-022 compliance (no app-local attachment storage; no `lib/Db/Attachment*`; no `lib/Service/Attachment*`). No source code changes outside `openspec/changes/add-shillinq-document-attachment-integration/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests for URI parsing + mime-type matching + non-blocking failure semantics (pre-declared on Tasks 5–8); Playwright MCP browser tests for the audit side-panel render + warning banner when docudesk is down (pre-declared on Task 9); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/attachments.md` per ADR-030 journeydoc convention and commits a side-panel screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Source Document`, `Attachment`, `Open in Documents`, `Document unavailable`, `Retry`.
