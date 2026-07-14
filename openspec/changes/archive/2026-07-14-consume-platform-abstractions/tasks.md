# Tasks — consume-platform-abstractions

## Item 1 — NC Flow + Talk leaves

- [x] Verify `FlowProvider`/`TalkProvider` ship in OpenRegister and shillinq's manifest has zero flow/talk references
- [x] Add `installIntegrationRegistry()` / `registerBuiltinIntegrations()` / `registerLeafIntegrations()` calls to `src/main.js` (decidesk/pipelinq precedent)
- [x] Confirm no page's `sidebarProps.tabs` override is silently blown open (verified: none touched)

## Item 2 — Missing-document worklist

- [x] Verify OR's `_isnull` / `filter: {field: null}` operator against `zoeken-filteren` spec + shillinq's existing `UnmatchedItems` precedent
- [x] Add `src/manifest.d/consume-platform-abstractions-missing-document-worklist.json` — two `type:"index"` pages (SupplierInvoice, Receipt) filtered on `sourceDocumentUri: null`
- [x] Add both pages as `Bookkeeping` menu children

## Item 3 — Inventory quarantine status

- [x] Verify the audit's premise against HEAD (InventoryStock.status mirror, schema target, dispatch-time enforcement)
- [x] Refuse — premise is false at HEAD; file shillinq#443 with the full trace instead of forcing a fake zero-PHP fix

## Item 4 — Public REST API exposure

- [x] Verify `EndpointService::handleSchemaRequest()` + Endpoint/Consumer object shapes against HEAD
- [x] Verify whether Endpoint/Consumer are declaratively authorable from shillinq's own config surface
- [x] Refuse the config (openconnector-repo boundary + non-declarative Consumer + unwired apiKey auth); file openconnector#159
- [x] Add `docs/api/third-party-rest-access.md` documenting the real path/auth shape

## Verification

- [x] `npm run check:manifest` passes on `src/manifest.json` (unmodified)
- [x] Merged manifest (base + all `manifest.d/*.json` fragments incl. the new one) validates against the v2 schema with no new errors
- [x] `npm run check:manifest-budget` — no new violation beyond the pre-existing tripwire
- [x] Hydra gates run, pre-existing noise only
- [x] PHPUnit baseline unchanged (no PHP touched)
- [x] Vitest/jest suite green
