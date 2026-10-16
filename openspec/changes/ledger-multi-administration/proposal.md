---
kind: code
depends_on: []
---

# Proposal: ledger-multi-administration

## Summary

An accountancy office keeps many clients' books under one login. In
shillinq the administration switcher forgets the choice on the next page
load, lists are not limited to the chosen administration, a new client can
only start from a shipped RGS template, and nothing keeps a group's charts
of accounts alike. This change makes the switch stick and scope the lists,
lets an office save an administration as a template, and keeps
administrations that follow a template in step with it.

## Motivation

Three rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the OpenSpec pass of 2026-09-27.

**`led-multi-admin`**, "Keep several companies' books under one login and
switch between them." Rated partial, built. Matrix evidence:
"lib/Controller/AdministrationController.php:128 switch() only validates and
echoes the id (persists nothing), and
lib/Service/AdministrationContextService.php:181 always returns the FIRST
membership as activeAdministrationId ... generic index pages (e.g.
GeneralLedger) carry no administrationId filter." All five competitors rate
it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, "Extra administratie" per plan.
- moneybird: https://helpcenter.moneybird.nl/nl/articles/207450-meerdere-bedrijven-in-een-moneybird-account, "Als je meerdere administraties wilt beheren, kan dat in Moneybird binnen één account".
- snelstart: https://kennisplein.snelstart.nl/klanten/s/article/het-pakket-inorde, "Een extra administratie toevoegen ... via Administratiebeheer".
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/administratiegr-3041172, "Met administratiegroepen in Twinfield groepeer je".
- odoo: https://www.odoo.com/documentation/19.0/applications/general/companies/multi_company.html, the company switcher in the navbar.

**`led-master-data-sync`**, "Keep the chart of accounts, journals and cost
centres in sync across several companies automatically." Rated no, built
state none: "No sync of chart of accounts, journals or cost centres across
administrations." Roadmap demand: https://www.exact.com/nl/vooruitblik.
Ledger is a core area of the matrix. All five competitors are partial.

**`plt-client-templates`**, "Start a new client administration from a
template with the office's own chart of accounts." Rated partial, built:
"Shipped templates only, no office template." Changelog demand:
https://www.moneybird.nl/changelog/sjabloon-administraties-in-mijn-kantoor/.
Four competitors rate it yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Task-general-companies-gen-comp-cremaintcompt, a new administration from a template administration.
- moneybird: https://www.moneybird.nl/changelog/sjabloon-administraties-in-mijn-kantoor/ (17 februari 2026), "sjabloon-administraties aan te maken".
- snelstart: https://www.snelstart.nl/productnieuws/handige-tips-voor-kantoren-standaard-grootboekschema-aanleveren-aan-klanten, offices supply their own standard grootboekschema.
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/administratie-i-3041034, "Is sjabloon".

## Affected Projects

- [ ] Project: `shillinq`: the active administration per user, list scoping, an administration template and its follow-up sync.
- [ ] Project: `nextcloud-vue`: a runtime context value in page config (see Cross-Project Dependencies).

## Scope

### In Scope

- Storing the chosen administration per user and returning it from the context endpoint while the user is still a member.
- Scoping the index pages of administration-owned schemas to the active administration.
- `AdministrationTemplate`: a snapshot of an administration's chart of accounts and cost centres, saved by an office.
- Starting a new administration from an office template in the setup wizard, next to the shipped RGS templates.
- Administrations that follow a template receive the template's additions and changes.

### Out of Scope

- Daybooks. Shillinq has no daybook object; `Journals` lists `JournalEntry` postings, which are not master data.
- Deleting accounts through a sync. A sync adds and updates; a retired template account is retired in each follower only if it has no postings.

## Approach

The active administration is a Nextcloud user preference, validated against
membership on every read. Lists scope through a page-config context value.
Templates are records; the sync runs when a template changes. Details are
in design.md.

## New Dependencies

None.

## Impact

- `lib/Controller/AdministrationController.php`, `lib/Service/AdministrationContextService.php`.
- `src/manifest.json` index pages of administration-owned schemas.
- `lib/Settings/register.d/bookkeeping-multi-administratie.json`: the template schema and the follow link.
- `lib/Service/SettingsService.php`: seeding from an office template.

## Cross-Project Dependencies

- `nextcloud-vue`: `CnPageRenderer` resolves `@route.<param>` sentinels in page config (development, `CnPageRenderer.vue:1132`) and nothing else. Scoping a list to the active administration needs a host-provided context sentinel, for example `@context.activeAdministrationId`. That addition belongs to nextcloud-vue and is listed for its owner; until it lands, shillinq passes the filter from its own index wrapper.

## Risks

### Risk 1: A sync overwrites a client's deliberate deviation
**Severity:** Medium. **Mitigation:** an account edited in the follower is marked as deviating and is not overwritten; the sync lists what it skipped.

### Risk 2: A stored administration the user lost access to
**Severity:** Low. **Mitigation:** the stored id is checked against membership on every read and falls back to the first membership.

## Rollback Strategy

Stop reading the stored preference (context falls back to the first
membership, today's behaviour) and stop the sync. Templates stay as inert
records.

## Open Questions

None.
