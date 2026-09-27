---
kind: code
depends_on: [tax-vat-return-from-books]
---

# Proposal: tax-digipoort-filing

## Summary

The Submit button on a VAT return changes a status and sends nothing; the
Digipoort adapter only logs; the SBR annual accounts report has no
generator. Transport to Digipoort belongs in integriq (ADR-091). This
change is shillinq's half: it produces the XBRL filing for the VAT return
and for the annual accounts, validates it, hands it to integriq's Digipoort
connector through the `digipoort-sbr` source, and marks it submitted,
accepted or rejected only on what Digipoort answers.

## Motivation

Two rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`) share the SBR filing path. Both are
marked `specified` in the matrix with no change directory behind them. The
OpenSpec pass of 2026-09-27 decided `build` for both, because "archived
... work shipped a log-only adapter, so the change is written here". This
change covers both.

**`tax-vat-file`**, "File the VAT return directly with the
Belastingdienst." Rated no, built state specified. The matrix evidence:
"lib/Settings/connections.json key digipoort-sbr: available false, 'Only a
log-only adapter is bound, and no screen or service calls it yet. Nothing
reaches Digipoort.'; lib/Service/External/Digipoort/LogDigipoortSbrAdapter.php",
reached on "nothing reaches it: the Submit button on VATReturnDetail is an
OpenRegister lifecycle transition that changes a status, it sends
nothing". No demand row. All five competitors rate it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, "opstellen, goedkeuren en digitaal indienen".
- moneybird: https://helpcenter.moneybird.nl/nl/articles/223650-de-btw-aangifte-indienen, "Vanuit Moneybird kun je je btw-aangifte rechtstreeks bij de Belastingdienst indienen".
- snelstart: https://kennisplein.snelstart.nl/klanten/s/article/btw-aangifte-algemene-informatie, "De aangifte wordt verzonden in SBR-formaat via het beveiligde kanaal Digipoort".
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/btw-aangifte-3041090, "Als het PKI-certificaat en de Digipoort zijn ingesteld, dan kunnen btw-aangiften direct vanuit Twinfield worden verstuurd".
- odoo: https://www.odoo.com/documentation/19.0/applications/finance/fiscal_localizations/netherlands.html, "SBR electronic reporting ... allows you to send your reports directly to the Dutch tax authorities".

**`tax-sbr`**, "Submit filings to the Belastingdienst and KvK through
SBR/XBRL." Rated no, built state specified. The matrix evidence:
"lib/Settings/connections.json key digipoort-sbr: available false, log-only
adapter; SbrXbrlFilings and SBRDocuments pages list XbrlInstance records
only; the sbr-xbrl report in lib/Reporting/ReportCatalogue.php has no
generator". No demand row. Two competitors rate it yes:

- exact-online: https://www.exact.com/nl/producten/accountancy/fiscaal/features-en-prijzen, VAT, Vpb and IB returns filed digitally, and https://www.exact.com/nl/producten/accountancy/jaarrekening/features-en-prijzen for the KvK filing.
- snelstart: https://kennisplein.snelstart.nl/snelstartpolaris/jaarrekeningen-in-accountant-pro, the annual accounts "gedeponeerd bij de KvK", with the VAT return over Digipoort (https://kennisplein.snelstart.nl/klanten/s/article/btw-aangifte-algemene-informatie).

## Affected Projects

- [ ] Project: `shillinq`: two XBRL instance builders, the `sbr-xbrl` generator, a lifecycle action and a status listener on `XbrlInstance`, the VAT return's link to its filing, and the `digipoort-sbr` connection declaration.

## Scope

### In Scope

- Building an `XbrlInstance` for a prepared VAT return, on the Belastingdienst OB entry point of the taxonomy release valid for the period.
- Building an `XbrlInstance` for posted annual accounts on the KvK entry point for the company's size class, as the `sbr-xbrl` report.
- Validating an instance against its entry point before it can be submitted.
- Handing a validated instance to integriq's Digipoort connector on `submit`, and moving it to submitted, accepted or rejected only on integriq's status reports.
- Submitting a VAT return through its instance, and downloading any instance.
- Declaring `digipoort-sbr` as called in `lib/Settings/connections.json` once the hand-off exists.

### Out of Scope

- Digipoort transport, the PKIoverheid certificate, the WUS or REST exchange and status polling. integriq (ADR-091), under source slug `digipoort-sbr`.
- Income and corporate tax returns (IB, Vpb) over SBR. The same path serves them later; no row asks now.
- The ICP statement over SBR. Same path, its own entry point, later.

## Approach

Shillinq builds and validates the document it owns and hands a file to
integriq, the way it already hands a UBL order for Peppol. Details in
design.md.

## New Dependencies

None in shillinq. XBRL is written with `XMLWriter`, as the existing
generators do.

## Impact

- `lib/Service/Sbr/VatReturnXbrlBuilder.php`, `lib/Service/Sbr/AnnualAccountsXbrlBuilder.php` and `lib/Reporting/Generator/SbrXbrlReportGenerator.php` (new).
- `lib/Lifecycle/Action/HandToDigipoortAction.php` and `lib/Listener/SbrFilingStatusListener.php` (new); `lib/Lifecycle/XbrlInstanceValidationGuard.php` (new).
- `lib/Settings/register.d/add-shillinq-sbr-xbrl-reporting.json`: `requires` on `validate`, `actions` on `submit`, `filingType` and `sourceReturnId` on `XbrlInstance`.
- `lib/Settings/register.d/bookkeeping-vat-btw-filing.json`: `BtwAangifte.xbrlInstanceId`.
- `lib/Settings/connections.json`: the `digipoort-sbr` entry.
- `DigipoortSbrAdapterInterface` stays as the port (the CSRD pack names it too); `lib/AppInfo/Application.php:477` binds it to a new `IntegriqSbrHandoffAdapter` when integriq is installed, and to `LogDigipoortSbrAdapter` otherwise.

## Cross-Project Dependencies

- integriq: a Digipoort/SBR connector under source slug `digipoort-sbr`. None exists on integriq development (no Digipoort or SBR source in its tree on 2026-09-27). This change proposes a contract that mirrors integriq's Peppol connector (REQ-003 of `peppol-access-point-connector`): shillinq emits `nl.conduction.sbr.filing.requested` with `{sourceApp, objectType, objectUri, filingType, recipient, payloadFileUri}`, and integriq emits `nl.conduction.sbr.filing.status` with `{objectUri, deliveryReference, status, errors}`. The event names are a proposal for integriq to confirm.
- `tax-vat-return-from-books` (open change in this repo): the prepared return snapshot the VAT instance is built from.
- `integration-config-to-openconnector` and `adopt-connection-registry` (open changes in this repo): they keep `digipoort-sbr` as a source slug reference and a connection row; this change only moves the row from not available to called.

## Risks

### Risk 1: A return is marked filed that never arrived
**Severity:** High. **Mitigation:** the return and its instance leave draft only on integriq's delivery report with a Digipoort reference; a failed hand-off leaves both unsubmitted with the reason.

### Risk 2: The taxonomy release changes each year
**Severity:** Medium. **Mitigation:** the entry point is taken from the `XBRLTaxonomy` record valid for the period (REQ-SBR-002), and an instance for a period without one is refused at validation.

### Risk 3: integriq's contract differs from the proposal
**Severity:** Medium. **Mitigation:** the hand-off is one action and one listener; the event names live in two constants.

## Rollback Strategy

Remove the action from `submit` and unregister the listener; instances can
still be built and downloaded. Set `digipoort-sbr` back to not available.

## Open Questions

- The event names and payload of the integriq Digipoort connector (Cross-Project Dependencies).
