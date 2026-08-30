# Tasks: integration-leaves-consume

## 1. HEAD re-verification (REQ-CPA-003 — before any code)
- [ ] Verify OpenRegister's calendar leaf at HEAD can *list* an object's linked events (not only create); if not, refuse the calendar leg and record the refusal in this change per REQ-CPA-003.
- [ ] Verify the contacts leaf (`ContactsController` / `ContactMatchingService`) matches on email/name and persists a per-object link; confirm `linkedTypes: ["contacts"]` is the trigger.
- [ ] Verify which of `ARInvoiceDetail` / `ExpenseClaimDetail` / `ContractDetail` declare curated `sidebarProps.tabs` at HEAD (only curated pages are edited in §4).

## 2. Files widgets (manifest)
- [ ] Add `{"type":"integration","integrationId":"files"}` widget to `ReceiptDetail` (title "Receipt image") and `ExpenseClaimDetail` (title "Receipt evidence") in `src/manifest.json` (or the owning `src/manifest.d/` overlay), mirroring the `invoice-files` widget shape incl. `layout` entry.
- [ ] Add the files widget to `BankConnectionDetail` (title "Imported statements") and `BtwAangifteDetail` (title "Filed return attachment").

## 3. Register fragment (linkedTypes)
- [ ] Add `lib/Settings/register.d/zzz-integration-leaves.json` declaring `configuration.linkedTypes` — `VatReturn: ["calendar"]`, `ARInvoice: ["calendar"]`, `CustomerMaster: ["contacts"]`, `Payee: ["contacts"]` — with `_meta` (SPDX + change id), zzz-sorted so no later fragment's array replaces it. Do NOT touch `Invoice` (`["mail"]`) or `Contract` (`["decidesk-decisions"]`).
- [ ] Confirm `SettingsService::deepMergeConfig()` array-replacement semantics against the merged output (dump the merged register and assert the four arrays are present and the two existing ones unchanged).

## 4. Talk on curated pages
- [ ] For each curated dossier page found in §1 (`ARInvoiceDetail`, `ExpenseClaimDetail`, `ContractDetail`), add `talk` to its curated `sidebarProps.tabs` list; leave non-curated pages untouched.

## 5. Verification (live, UI — API green is not UI green)
- [ ] Receipt: upload a receipt image, open `ReceiptDetail` and `ExpenseClaimDetail`, confirm the image is listed and opens (REQ-CPA-005).
- [ ] Bank: import a statement, confirm it is reachable from `BankConnectionDetail`; file a VAT return with `attachmentUri`, confirm it lists on `BtwAangifteDetail`.
- [ ] Calendar: with a published Q2 BTW deadline VEVENT, open `BtwAangifteDetail` and confirm the event shows and that page-render created no duplicate VEVENT (REQ-CPA-006); repeat for an `ARInvoice` `dueDate` with the REQ-CDC-004 opt-in on.
- [ ] Contacts: match + link a `CustomerMaster` by email; create-and-link for a `Payee` with no match; reload both pages and confirm the links persist (REQ-CPA-007).
- [ ] Talk: start an approval conversation on `ExpenseClaimDetail`; reopen and confirm the same conversation is rebound (REQ-CPA-008).
- [ ] Diff-check: a detail page without curated tabs has an unchanged manifest entry.
