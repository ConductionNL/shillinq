# Design: ledger-journal-line-dimensions

Read at shillinq development `8bd50b5b` and humaniq development `50acc7b` on 28 September 2026.

## Context

- `JournalEntry` (`lib/Settings/register.d/add-shillinq-bookkeeping-foundation.json`): `lines` is an array of `{accountNumber, side, amount, description}`, `minItems` 2; "Each row materialises into a GLLine on post".
- `GLLine` (`lib/Settings/shillinq_register.json`): `costCenterCode` ("FK to AnalyticalDimension.code (dimensionType=cost-center)"), `costCarrierCode` (dimensionType `cost-object`), `projectCode`, `dimensions`.
- `AnalyticalDimension` (`lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json`): `code`, `dimensionType` (`cost-center`, `cost-object`, `custom`, `project`), `lifecycleState` (`active`, `blocked`, `archived`), `administrationId`, `startDate`, `endDate`.
- `lib/Lifecycle/JournalEntryGuard.php:94` `canPost()` reads the embedded lines (`resolveLines()`, `:189`) and refuses fewer than two lines, a negative amount, an unknown side or an imbalance, failing closed.
- The open change `ledger-posting-path` (D2) registers `materialise-gl-transaction`, which builds one `GLLine` per source line with a small mapper per source schema: "`JournalEntry.lines` already carries account, side and amount".
- Humaniq writes the journal as a draft `JournalEntry` from `lib/Service/PayrollGLPostService.php` (`buildLines()`, amounts in euros after the cent arithmetic) and, with `payroll-cost-allocation`, adds `costCenterCode` and `projectCode` on the debit lines.

## Decisions

### D1. Three optional line properties with the GLLine names

`costCenterCode`, `costCarrierCode` and `projectCode`, each an optional string with the same description as on `GLLine`. Using the ledger line's names means the mapper copies them without translation, and humaniq's payload needs no renaming.

### D2. The JournalEntry mapper copies them

In the `materialise-gl-transaction` handler, the `JournalEntry` mapper copies the three codes from each source line onto its `GLLine` when present, and leaves them out when absent. No other mapper changes.

### D3. The post guard checks the codes

`canPost()` gains one delegated check after the balance check: for every line with a code, an `AnalyticalDimension` with that `code`, the matching `dimensionType` (`cost-center`, `cost-object`, `project`), `lifecycleState` `active`, the entry's `administrationId`, and a validity window around the entry date must exist. The dimensions are read once per entry, not per line. A failed check refuses the transition with "Line 3: cost centre KP-900 does not exist or is not active in this administration", so humaniq's run records the refusal as its `failed` outcome and a bookkeeper sees the line.

### D4. The line editor shows the codes

`JournalDetail` (`src/manifest.json`) shows the three codes as line columns with a picker over active dimensions of the right type.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Line properties | Declarative: schema | Properties. |
| Copy onto the ledger line | Imperative, in the posting handler's mapper | The handler exists for exactly this. |
| Code check | Imperative, one delegated check in the existing guard | A cross-schema lookup the schema cannot express. |

## Risks

- Humaniq's cost-centre codes differ from shillinq's dimension codes: the post is refused with the line and code, which is the behaviour humaniq's design counts on.
- Journal entries already stored without codes: nothing changes for them.
