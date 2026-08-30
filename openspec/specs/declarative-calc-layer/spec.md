# declarative-calc-layer Specification

## Purpose
TBD - created by archiving change revive-declarative-calc-layer. Update Purpose after archive.
## Requirements
### Requirement: Calculations SHALL be expressed in the evaluator-executable JSON-AST dialect

Every shillinq calculation SHALL be authored in the JSON-AST dialect the OpenRegister
`CalculationEvaluator` can actually execute. Each `x-openregister-calculations` block declared
on a shillinq schema in `lib/Settings/shillinq_register.json` MUST define its `expression`
(or `formula`) as a JSON-AST tree using only operators the evaluator supports
(`prop`, `lit`, `concat`, `if`, `not`, `and`, `or`, `+`, `-`, `*`, `/`, `%`,
`eq`/`ne`/`lt`/`lte`/`gt`/`gte`, `now`, `diffDays`, `formatDate`, `dateDiff`, plus the
scalar functions `max`, `min`, `coalesce`, `abs`, `round`, `year`, `monthsElapsed` provided
by the `calc-engine-scalar-functions` dependency). Infix string-expression ternaries
(e.g. `"a = b ? x : y"`) SHALL NOT be used, because the evaluator does not parse them and
the field silently never computes. A calculation that requires a function outside this
vocabulary (e.g. `sha256`, `lookup` of another schema, cross-schema folding) is NOT a
per-object calculation and SHALL be relocated to `x-openregister-aggregations` or an
ADR-031 guard service instead.

#### Scenario: A string-ternary calc is rewritten to JSON-AST

- **GIVEN** a calc `RepaymentInstallment.isOverdue` declared as the string
  `"(@self.state = 'scheduled' OR @self.state = 'overdue') AND @self.dueDate < today()"`
- **WHEN** the register is rewritten per this change
- **THEN** the `expression` SHALL be a JSON-AST tree (`{"and":[{"or":[…]},{"lt":[{"prop":"dueDate"},{"now":[]}]}]}`)
- **AND** the calc SHALL use only operators in the supported evaluator vocabulary

#### Scenario: An evaluator-unsupported function disqualifies a per-object calc

- **GIVEN** a calc whose intent needs `lookup('ExchangeRate', …)` or `sha256(…)`
- **WHEN** the audit classifies it
- **THEN** it SHALL NOT be expressed as a per-object JSON-AST calc
- **AND** it SHALL be relocated to `x-openregister-aggregations` (if it is a sum/count/avg/min/max over a related schema) or to an ADR-031 guard service (if it is an external lookup or imperative document/hash generation)

### Requirement: Per-object derived fields SHALL declare materialise true

Every per-object derived field SHALL be marked to materialise so the on-save listener persists
it. Each per-object `x-openregister-calculations` block whose value must be persisted on save
MUST set `materialise: true`, because `CalculationOnSaveListener` skips any calc without it
and the derived field is never written. The materialised value SHALL be exposed on the
object after a save/seed of an object in the owning schema.

#### Scenario: A converted calc is materialised on save

- **GIVEN** a calc converted to JSON-AST that carries `materialise: true`
- **WHEN** an object of the owning schema is created or updated
- **THEN** `CalculationOnSaveListener` SHALL evaluate the calc and persist the result on the object
- **AND** a subsequent read of that object SHALL return the computed value

### Requirement: Cross-object aggregates SHALL be declared as aggregations

A cross-object aggregate SHALL be declared as an aggregation, not as a per-object calculation.
Any derived value that sums, counts, or averages over objects of ANOTHER schema (e.g. KOR
year-to-date revenue summing Invoices) MUST be declared as an `x-openregister-aggregations`
entry on the owning schema, because the per-object
evaluator cannot fold across other objects. The aggregation SHALL name the source
register-schema collection, the `sum`/`count`/`avg` metric and metric field, and any filter
required to scope the fold.

#### Scenario: KOR year-to-date revenue becomes an aggregation

- **GIVEN** `KorRegime.ytdRevenue` declared as the per-object expression `sum(Invoice.totalAmountExclVat)`
- **WHEN** the register is rewritten per this change
- **THEN** `ytdRevenue` SHALL be declared as an `x-openregister-aggregations` entry summing the `totalAmountExclVat` field over the Invoice schema collection within the relevant fiscal-year filter
- **AND** the per-object `x-openregister-calculations` string entry for `ytdRevenue` SHALL be removed

