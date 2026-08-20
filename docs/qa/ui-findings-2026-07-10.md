<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Shillinq UI test findings — 2026-07-10

Playwright UI pass over the live app (`localhost:8080`, Shillinq 0.9.12) plus a
static audit of every manifest list page against the real register schemas. This
records what was fixed in this pass and what is left as follow-up work.

## Fixed in this pass (live-verified)

1. **Accounts receivable list showed `—` for Total and Status.**
   The `AccountsReceivable` page (`src/manifest.json`) mapped columns to
   `totalAmount` and `state`, which do not exist on the `ARInvoice` schema. The
   real fields are `grossAmount` and `lifecycleState`. Fixed; the list now shows
   the invoice total (e.g. `15.584,80`) and status (`paid`).

2. **Quick draft invoice never loaded customers.**
   `InvoiceQuickDraftModal.vue` fetched customers only in a `watch` on the `open`
   prop transitioning `false → true`. The modal is mounted with `open` already
   `true` (the manifest `open-modal` action passes `props.open = true`), so the
   watcher never fired and the customer picker was permanently empty. Made the
   watcher `immediate: true`.

3. **Quick draft invoice always failed to save (HTTP 400).**
   The POST payload omitted the schema-required `invoiceNumber`,
   `administrationId`, and `periodId`, so every save returned
   *"The required properties (invoiceNumber, administrationId, periodId) are
   missing"*. `buildInvoicePayload` now supplies all three: `administrationId`
   from the app settings, `periodId` derived as the `YYYY-MM` bucket of the
   invoice date, and a provisional `DRAFT-<date>-<time>` invoice number. Added
   unit tests (`tests/vitest/invoiceQuickDraft.spec.js`). End-to-end verified: a
   draft now saves and appears in Accounts receivable.

4. **Onboarding tour routed to an uncompletable form.**
   The "create your first invoice" tour step pointed at the Accounts-receivable
   *Add* button, which opens the full EN 16931 form requiring FK ids a new user
   cannot know, so the tour could never advance. Re-pointed the tour to the
   dashboard **Create invoice** quick draft (steps `open-quick-draft` /
   `fill-quick-draft` in `src/manifest.json`).

5. **Manifest schema validation failure (pre-existing).**
   The Dashboard `create-invoice` header action carried `variant: "primary"`,
   which the app-manifest-v2 action schema forbids (`additionalProperties:false`).
   Removed; `node tests/validate-manifest.js` now passes.

## Follow-up: list columns that reference non-existent schema fields

A static audit of all 223 manifest pages against the register schemas, spot-checked
against live object data, found more list columns keyed to fields the schema does
not define (they render as `—`). These were **not** changed in this pass because
several are report/aggregation pages whose columns may be computed server-side;
each needs per-page verification before editing.

**Confirmed against live data (field absent, data present):**

| Page | Schema | Bad key(s) | Likely correct field |
|------|--------|-----------|----------------------|
| `Urenregistratie` | `UrenRegistratie` | `workDate`, `category` | `date` (no `category` field) |
| `InventoryPostingHistory` | `GLTransaction` | `entryNumber`, `entryDate`, `debitAmount`, `creditAmount` | `transactionNumber`, `postingDate` (debit/credit are per-leg, not top-level) |
| `EmuRapportage` | `Account` | `period`, `sector`, `emuSaldo` | none on `Account` — page likely wired to the wrong schema |
| `DunningLadders` | `DunningLadder` | `lifecycleState` | schema has no lifecycle field |

**Candidates (empty tables in demo, could not confirm at runtime):**
`BankReconciliation` (`BankStatement`), `VATByPeriod` (`VATAuditRecord` — the API
returned **404 for this schema**, so the page may be pointed at a schema that does
not exist), `UnmatchedItems` (`ReconciliationMatch`), `DunningKlantOverrides`
(`KlantLadderOverride`), `OninbareAfschrijvingen` (`OninbaarAfschrijving`).

## Follow-up: other UX gaps observed

- **`/apps/shillinq/invoice/generate` (Generate Invoice)** — clicking **Save as
  Draft** with an incomplete form fires no request and shows no validation
  message (silent no-op). Needs client-side validation feedback.
- **Provisional invoice numbering** — the quick draft assigns a
  `DRAFT-<date>-<time>` number because there is no invoice-number sequence
  endpoint. A proper sequence (assigned on post) should replace it.

## Full route sweep — all 131 manifest routes

Every static (param-free) route in `src/manifest.json` (131 of them) was driven
through the app's Vue router and given a render verdict. Result: **1 genuinely
broken page**, since fixed; the rest render (`OK`) or show a legitimate empty
state (`EMPTY`, no demo data).

6. **`/bookkeeping/activity-feed` returned 404 and showed "Could not load log
   entries".** The `BookkeepingActivityFeed` logs page pointed its `source` at
   the legacy Nextcloud Activity endpoint `/apps/activity/activity/list`, which
   was removed in Activity 7.x (the app is installed and enabled at 7.0.0, but
   that route 404s). The OCS Activity API (`/ocs/v2.php/apps/activity/api/v2/...`)
   cannot replace it directly: it requires the `OCS-APIRequest` header and wraps
   rows in `ocs.data`, neither of which the logs widget provides for a string
   URL source. Fixed by pointing the feed at the OpenRegister `/api/audit-trails`
   endpoint — the same store its sibling pages (Audit trail, Change history,
   Signing trail) already use successfully — scoped to the approval/decision
   object types, with the widget's known-good column keys
   (`created/user/action/schema/objectId/summary`). Verified: the feed now
   renders a 20-row activity table with no console errors.

*False positives note:* an initial router-driven pass also flagged
`bookkeeping/audit-trail`, `bookkeeping/signing-trail`,
`bookkeeping/change-history`, and `compliance/ensia/audit-trail` as blank. Each
was re-checked with a real full-page load and renders its audit table correctly
— the audit-trail widgets load asynchronously and had not populated within the
sweep's per-route wait. They are **not** broken.

## Coverage note

Parallel smoke-testing across browser sessions `browser-2..7` was abandoned: in
this environment those pool sessions share a single Chrome instance, so
concurrent agents raced and contaminated each other's page state. The full
131-route sweep above was therefore run sequentially on `browser-1` (driving the
app router), with every flagged page re-verified by a real navigation, plus the
static manifest/schema audit.
