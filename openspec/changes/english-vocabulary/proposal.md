# English vocabulary for shillinq — the remaining 11 domains

> Implements `hydra/openspec/changes/fleet-english-vocabulary`.
> The **commitment domain is already done** (PR #495) — this change is the rest.

## Why

An anchored scan found **42 Dutch-named schemas and 340 Dutch property names**
in shillinq, the largest concentration in the fleet. PR #495 converted the
commitment slice (`Verplichting`/`Verplichtingsregel`/`Verplichtingsmutatie`/
`Mandaat`/`Goedkeuringsstap` + TenderNed) end to end. That work is the template
and the evidence for the risks below.

## What changes

Remaining Dutch domains, each its own slice:

| slice | representative schemas |
|---|---|
| BBV / IV3 | `BbvTaakveld`, `Taakveld`, `Programmabegroting`, `Begrotingswijziging` |
| Voorzieningen | `Voorziening`, `ClaimsVoorzieningDetail`, `Garantievoorziening…` |
| Reserves | `Reserve` |
| Hours | `UrenRegistratie`, `UrenDagregistratie`, `UrenAlert`, `UrencriteriumYear` |
| Innovatiebox | `InnovatieboxAggregationService` + fields |
| DBA | `DBAOpdracht`, `DBAPortfolioRisico`, `DBARisicoflag` |
| ENSIA | `ENSIAJaarcyclus` and family |
| Rechtmatigheid | `Rechtmatigheidsbevinding`, `Rechtmatigheidstoets` |
| Incasso | `IncassoKostenBerekening` |
| Jaarrekening | `MaterieleVasteActiva`, `VpbBalansLink`, `BalanceSheet` fields |
| Budget (Group B) | `Budget` — blocked on the #485 two-vocabulary split |

### Internationalised (§2)

`btw*` → `vat*`, `kvkNummer` → `companyRegistrationNumber`, `bsn` →
`nationalIdentityNumber`, `rsin` → `legalEntityRegistrationNumber`,
`grootboek*` → `glAccount`/`generalLedger`, `boekjaar` → `fiscalYear`,
`kostenplaats` → `costCentre`, `bedrag` → `amount`, `factuur` → `invoice`.

### Statutory marker (§4)

`Taakveld`, `Rechtmatigheidsverantwoording`, BBV programme classifications and
the IV3 reporting schemas get English names plus `x-statutory-basis`
(`jurisdiction: NL`, `instrument: BBV / IV3 / Gemeentewet`).

`Woo` is explicitly **not** statutory-preserved — see fleet policy §4.

### Dutch → l10n (§3)

The commitment slice added 42 keys to `l10n/nl.json`. Same mechanism here.
⚠️ Editing a `title` detaches it from its Dutch — re-point keys, never
re-extract, and run `check-l10n` (it caught two orphaned keys in #495).

## Tasks

- [ ] Inventory per slice — real per-schema and per-file counts, not the scan estimate.
- [ ] Resolve `Budget` first: #485 says it carries two vocabularies
      (`boekjaar`/`geautoriseerd_bedrag` vs `budgetName`/`programmaStructure`)
      and needs a product decision before any rename.
- [ ] Rename schemas/properties/enums per slice, one fragment at a time.
- [ ] `x-statutory-basis` on the BBV/IV3/rechtmatigheid schemas.
- [ ] Rename classes/methods/**files** (`DoorsnijdingsVerbodValidator`,
      `InnovatieboxSbrExportService`, `BbvComplianceGuard`, …) and every register
      fragment that wires them **by class name**.
- [ ] Diff every filter/read key against the new schema.
- [ ] `l10n/nl.json` + `check-l10n`.
- [ ] Lower the `validate-seeds` BASELINE as renames fix unimportable seeds.
- [ ] Full suite + gates before each PR.

## Risks — all four observed for real in #495

- **Silent absence.** `BudgetBlocker` read `is_override` after the schema moved
  to `isOverride`: no error, the override check just evaluated false forever.
- **Shared field contract.** `Requisition` mirrors `Commitment`'s field names so
  `BudgetBlocker::canCommit` serves both; renaming one side alone made every
  requisition evaluate against a zero budget.
- **Wiring by class name.** Register fragments name lifecycle guards as strings;
  a renamed class that is not updated there stops guarding without failing.
- **Lower-cased comparisons.** `isCommitmentSchema()` compares a `strtolower()`
  value to a literal — a camelCase rename made it unsatisfiable, and PHPStan,
  not the tests, caught it.

## Non-goals

- The commitment domain (done in #495).
- External wire formats: TenderNed payload keys, SBR/XBRL and IV3 **export**
  field names stay Dutch at the adapter boundary.
