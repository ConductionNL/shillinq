# Tasks: integration-leaves-consume

## 1. HEAD re-verification (REQ-CPA-003 — before any code)
- [x] Verify OpenRegister's calendar leaf at HEAD can *list* an object's linked events (not only create); if not, refuse the calendar leg and record the refusal in this change per REQ-CPA-003. Verified 13 Nov 2026 on openregister main: `CalendarEventsController::index` (`GET /api/objects/{register}/{schema}/{id}/calendar-events`) lists an object's linked events; the leg stands.
- [x] Verify the contacts leaf (`ContactsController` / `ContactMatchingService`) matches on email/name and persists a per-object link; confirm `linkedTypes: ["contacts"]` is the trigger. Verified: `contacts#match` matches on email/name, and `contacts#index|create|createNew|destroy` keep a per-object link.
- [x] Verify which of `ARInvoiceDetail` / `ExpenseClaimDetail` / `ContractDetail` declare curated `sidebarProps.tabs` at HEAD (only curated pages are edited in §4). Found: `ExpenseClaimDetail` (src/manifest.json) and `ContractDetail` (src/manifest.d/contract-lifecycle-management.json) curate their tabs; `ARInvoiceDetail` does not, so it already gets `talk` in registry mode and is left alone.

## 2. Files widgets (manifest)
- [x] Add `{"type":"integration","integrationId":"files"}` widget to `ReceiptDetail` (title "Receipt image") and `ExpenseClaimDetail` (title "Receipt evidence") in `src/manifest.json` (or the owning `src/manifest.d/` overlay), mirroring the `invoice-files` widget shape incl. `layout` entry. Done for `ExpenseClaimDetail`. `ReceiptDetail` is refused: `src/manifest.d/receipt-extraction-consume.json` overlays it as a `custom` page rendered by the `ReceiptCapture` component, so a manifest widget there would never render; the receipt image stays reachable through that component and the sidebar Files tab. These pages had no body widgets, so the body is written out as the library's default (Data, Related) with the files widget below it, keeping what the page showed.
- [x] Add the files widget to `BankConnectionDetail` (title "Imported statements") and `BtwAangifteDetail` (title "Filed return attachment").

## 3. Register fragment (linkedTypes)
- [x] Add `lib/Settings/register.d/zzz-integration-leaves.json` declaring `configuration.linkedTypes` — `VatReturn: ["calendar"]`, `ARInvoice: ["calendar"]`, `CustomerMaster: ["contacts"]`, `Payee: ["contacts"]` — with `_meta` (SPDX + change id), zzz-sorted so no later fragment's array replaces it. Do NOT touch `Invoice` (`["mail"]`) or `Contract` (`["decidesk-decisions"]`).
- [x] Confirm `SettingsService::deepMergeConfig()` array-replacement semantics against the merged output (dump the merged register and assert the four arrays are present and the two existing ones unchanged). Found at HEAD: list arrays are CONCATENATED, not replaced, so restating `Invoice`/`Contract` would duplicate their entries; the fragment touches neither. Pinned by `tests/Unit/Service/IntegrationLeavesFragmentTest.php` over the fully merged register.

## 4. Talk on curated pages
- [x] For each curated dossier page found in §1 (`ARInvoiceDetail`, `ExpenseClaimDetail`, `ContractDetail`), add `talk` to its curated `sidebarProps.tabs` list; leave non-curated pages untouched. Added as a `talk` tab (`integration` widget, `integrationId: talk`) before the audit trail on `ExpenseClaimDetail` and `ContractDetail`; pinned by `tests/vitest/integrationLeavesManifest.spec.js`.

## 5. Verification (live, UI — API green is not UI green)
- [ ] Receipt: upload a receipt image, open `ReceiptDetail` and `ExpenseClaimDetail`, confirm the image is listed and opens (REQ-CPA-005).
- [ ] Bank: import a statement, confirm it is reachable from `BankConnectionDetail`; file a VAT return with `attachmentUri`, confirm it lists on `BtwAangifteDetail`.
- [ ] Calendar: with a published Q2 BTW deadline VEVENT, open `BtwAangifteDetail` and confirm the event shows and that page-render created no duplicate VEVENT (REQ-CPA-006); repeat for an `ARInvoice` `dueDate` with the REQ-CDC-004 opt-in on.
- [ ] Contacts: match + link a `CustomerMaster` by email; create-and-link for a `Payee` with no match; reload both pages and confirm the links persist (REQ-CPA-007).
- [ ] Talk: start an approval conversation on `ExpenseClaimDetail`; reopen and confirm the same conversation is rebound (REQ-CPA-008).
- [ ] Diff-check: a detail page without curated tabs has an unchanged manifest entry.
