# Design — Member 01: config schemas + seed

## Scope

This `kind: config` member declares the `BBVProgramme` and
`BudgetBBVMapping` registers, their relations, the demo seed data, and
the integration-test scaffold. It authors no aggregation, controller,
or UI logic — those land in later chain members.

## Declarative-vs-imperative decision (ADR-031 / ADR-024)

| Behaviour | Decision | Why |
|---|---|---|
| BBV programme master | Declarative (register schema) | Static metadata, no computation |
| Budget-to-programme allocation | Declarative (register + many-to-one relations) | Pure data |
| Seed data | `ConfigurationService::importFromApp()` | Idempotent install-time import |

Both registers are OpenRegister-managed schemas declared in
`lib/Settings/shillinq_register.json` — never raw `ALTER TABLE` or
app-local mapper tables (ADR-001, ADR-022). The relations to
`Administration`, `BBVProgramme`, and `Account` use OpenRegister
relation declarations.

## Decisions carried from the giant

- **D1** — `BBVProgramme` is the governance anchor: one record per
  fiscal-year policy programme code (e.g. "1.1.1"), admin-maintained,
  never hard-deleted (archive only).
- **D2** — `BudgetBBVMapping` is a many-to-one junction from a GL
  account to a BBV programme with an allocation percentage.

## Seed data

Demo/dev only — production waterboards configure their own programmes
and mappings via the admin UI.

**BBVProgramme** (fiscal year 2026):
- `1.1.1` — Core Administration
- `1.2.1` — HR & Payroll
- `2.3.2` — Water Quality Monitoring
- `2.4.1` — Infrastructure Maintenance
- `3.1.0` — Strategic Planning

**BudgetBBVMapping**:
- GL 4100 (Personnel) → 50% 1.1.1, 30% 1.2.1, 20% 2.4.1
- GL 5000 (Operations) → 25% 2.3.2, 75% 2.4.1

Re-import MUST NOT create duplicates (UUID / natural-key idempotency).

## Security (ADR-005)

- Both registers are admin-write, public-read per the giant's
  permission notes; OpenRegister RBAC enforces this (ADR-022, ADR-023).
- Seed import runs at install via a repair step (per the fleet
  `InitializeRegister` pattern); no anonymous write path.
