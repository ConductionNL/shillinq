# Design: public-sector-reserves-and-interest

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

- **Reserve, twice.** `lib/Settings/shillinq_register.json` declares `Reserve` with `kind`, `purpose`, `programme`, `balanceYearStartCents`, `balanceYearEndCents`, `plafondCents`, `floorCents`, `termEnd`, `councilResolutionInstitution` and `rentetoerekening`. `register.d/bookkeeping-programmabegroting.json` declares `Reserve` with `budgetId`, `type`, `designatedPurpose`, `openingBalance`, `toevoegingen`, `onttrekkingen`, `closingBalance`. They merge. Neither has a lifecycle, a calculation or a page.
- **Investering** (`register.d/bookkeeping-programmabegroting.json`): `description`, `gross`, `depreciationTerm`, `firstDepreciationYear`, `coverage`, `capitalChargesSchedule`, `programmeId`. The fragment's `_meta` describes the kapitaallasten schedule. No page, no code.
- **Taakvelden.** `BbvTaakveld` is the fleet's single taakveld catalogue (hydra ADR-107 decision 1). Taakveld 0.5 is Treasury, where the charged interest is credited.
- **Posting.** Journal entries post through `ledger-posting-path`.

## Goals / Non-Goals

**Goals**
- Reserve mutations per year with council resolution, and balances that follow from them.
- A multi-year overview of reserves, realised and planned.
- The yearly interest charge to taakvelden and to reserves, posted.

**Non-Goals**
- Provisions, the rente-schema calculation.

## Decisions

### D1. Mutations are records, balances are calculations

New schema `ReserveMutation`: `administrationId`, `reserveId`, `year`,
`kind` (`addition`, `withdrawal`), `amountCents`, `programme`,
`councilResolution`, `status` (`planned`, `realised`), `journalEntryId`.
`Reserve` gains `x-openregister-aggregations` summing realised additions and
withdrawals per year; the overview derives each year's opening from the
previous closing, starting from `balanceYearStartCents` of the first year.
Realising a mutation posts a journal entry between the reserve's balance
account and the programme's result account.

### D2. The overview is one page over mutations

`ReserveMultiYearOverview` lists reserves against years (last realised year
and the four meerjarenraming years), each cell opening, additions,
withdrawals and closing, planned figures marked as planned. It flags a
closing balance under `floorCents` or over `plafondCents`.

### D3. The interest run is yearly and explicit

New schema `InterestAllocationRun`: `administrationId`, `year`,
`omslagrentePercentage`, `lines` (investment, taakveld, book value at 1
January, interest), `reserveLines` (reserve, balance, interest), `state`
(`draft`, `posted`), `journalEntryId`. "Calculate" fills the lines:
book value at 1 January of each investment (from its depreciation state)
times the percentage, charged to its programme's taakveld; for each reserve
with `rentetoerekening`, its 1 January balance times the percentage added
to the reserve. "Post" writes one journal entry: debit each taakveld's
interest cost, credit taakveld 0.5 Treasury, and the reserve additions.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Reserve balances per year | Declarative: `x-openregister-aggregations` on `Reserve` | Sums over mutations. |
| Run states | Declarative: `x-openregister-lifecycle` on `InterestAllocationRun` | Draft to posted. |
| Calculating and posting interest | Imperative, a service behind the run's actions (ADR-031 exception: domain calculation over several schemas) | Reads investments, depreciation and reserves for a date. |

## Seed Data

Gemeente Voorbeeld 2026, omslagrente 1.2 percent. Investment "Sporthal De
Wielewaal", book value EUR 4,500,000 on 1 January, taakveld 5.2
Sportaccommodaties: interest EUR 54,000. Reserve "Algemene reserve", no
interest; reserve "Reserve onderhoud sportaccommodaties" with
rentetoerekening, balance EUR 800,000: interest addition EUR 9,600. A
realised withdrawal of EUR 250,000 from the maintenance reserve by council
resolution 2026-088, and planned additions of EUR 150,000 a year for 2027 to
2030.

## Risks / Trade-offs

- [Investments without depreciation data] → the run lists them with a missing book value and excludes them until entered.

## Migration Plan

None; existing reserve balance fields remain as the starting point.

## Open Questions

None.

## As built (2026-09-29)

- The records live in their own fragment, `lib/Settings/register.d/public-sector-reserves-and-interest.json`.
- Amounts on `ReserveMutation` and `InterestAllocationRun` are euros, like `Investering.gross` and journal lines. `Reserve` starts from `openingBalance` in `openingBalanceYear` (falling back to `balanceYearStartCents`).
- `InterestAllocationRun` states are draft, calculated and posted: Calculate (draft to calculated), Reopen (back to draft) and Post (calculated to posted), each executed by `InterestAllocationAction`.
- The run names its interest cost account and Treasury account; a reserve names its balance account and result account. The journal debits the cost account per task field and credits Treasury (task field 0.5); a reserve's interest debits its result account and credits its reserve account, and is recorded as a realised addition naming the run.
- The multi-year overview is a dashboard page over `ReserveOverviewController::overview` (hydra gate 69 refuses a new custom page), so the opening-to-closing chain is tested in PHPUnit.
- Book value on 1 January is gross less the stored capital charges of earlier years, or the straight-line schedule from `KapitaallastenCalculator` when none is stored.
