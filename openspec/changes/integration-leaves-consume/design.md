# Design: integration-leaves-consume

## Mechanism choice per leaf

Two consumption mechanisms exist in this repo, both already exercised:

| Mechanism | Where it renders | In-repo precedent |
|---|---|---|
| Manifest integration widget `{"type":"integration","integrationId":"..."}` | Detail-page **body** | `invoice-files` on `ARInvoiceDetail`; `tender-files` / `verplichting-files` (tenderned overlay); `contract-decisions` on `ContractDetail` |
| Schema `configuration.linkedTypes` | **Sidebar** leaf tab + NC Mail sidebar link target | `Invoice: ["mail"]`; `Contract: ["decidesk-decisions"]` |

Decision: **files → manifest widgets** (matches the invoice-files precedent —
attachments are primary content, they belong in the body), **calendar /
contacts → `linkedTypes`** (they are reference surfaces, sidebar is right),
**talk → curated `sidebarProps.tabs` edits** (talk is already a registry-mode
sidebar leaf; only curated pages need the explicit entry).

## Key decisions

1. **No second calendar publisher.** `ComplianceDeadlineCalendarService`
   already writes BTW/ICP/VPB filing deadlines (REQ-CDC-002) and opt-in AR
   due-date VEVENTs (REQ-CDC-004) into the user's `shillinq-deadlines`
   calendar. The calendar leaf on `BtwAangifteDetail` / `ARInvoiceDetail` is a
   *view* of the object's linked events. If the OpenRegister calendar leaf
   turns out at HEAD to be create-capable only (no linked-event listing), the
   leg is refused per REQ-CPA-003 and recorded — not forced through with a
   bespoke event query.
2. **Array-replacement hazard in `deepMergeConfig()`.**
   `SettingsService::deepMergeConfig()` (lib/Service/SettingsService.php,
   ADR-037 fragment merge at ~line 1507) recurses into objects but lets a
   fragment's *array* replace the base wholesale. The new fragment therefore
   only declares `linkedTypes` on schemas that have none today
   (`VatReturn`, `ARInvoice`, `CustomerMaster`, `Payee`) and must sort AFTER
   every fragment defining those schemas — the established `zzz-` prefix trick
   (`zzz-mcp-tool-surface.json` uses it for exactly this reason). File name:
   `zzz-integration-leaves.json`.
3. **Contacts is a link, not a sync.** The contacts leaf surfaces
   OpenRegister's contact matching (`ContactMatchingService`) against
   `CustomerMaster.email`/`legalName` and `Payee.email`/`name`. No bulk
   export of masters into the address book — matching/linking only. `Payee`
   even has a `contactRef` property reserved for exactly this link.
4. **Curated tabs stay curated.** REQ-CPA-001's second scenario forbids
   silently adding registry leaves to a page that opted into
   `sidebarProps.tabs`. Adding `talk` to such a page is done by editing that
   page's curated list in the manifest — an explicit, reviewable diff — never
   by disabling the curation.

## Verification approach

Per-leg live verification on the shared :8080 instance (bind-mounted
checkout): open each detail page, confirm the leaf renders and round-trips
(file listed, contact linked, event shown, talk conversation created). API
green is not UI green — every leg's scenario is phrased against the rendered
page, not the endpoint.
