# The payroll employee is a facet, not a second person

## Why

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so a bare `Employee` was answered for by humaniq's record as
readily as by this app's. A fleet audit on 2026-09-05 found eighteen slugs in
that state.

Most of them are different records that reached for the same word, and those
are simply renamed apart. This one is not. The two schemas share `bsn` and
`employeeNumber`: they are describing **the same person**, from payroll and
from HR.

Counting shared fields would have missed that. The two share 3 of 55, which
lands squarely in the "rename apart" band. What distinguishes it is *which*
fields are shared: a BSN identifies a person, and two apps carrying the same
BSN are not describing two people.

## What changes

The slug becomes `payrollEmployee`, which is what this record has always been:
contract type, tax classification, GL salary-expense account, placement agency.
It gains an `employee` uuid pointing at humaniq's `Employee`, which owns the
person.

A plain uuid and not a `$ref`, because humaniq's register is a different
register and ADR-062 rule 7 gives a cross-register target a plain string. Empty
when humaniq is absent, in which case this record stands alone.

This app already reads humaniq's `Employee` register-scoped for cost rates
(`HrmqCostRateAdapter`), so the direction of ownership is not new. The link is
now declared rather than implied.

## What this change does NOT do

`bsn` stays on both sides for now. It is special-category PII under ADR-005 and
holding one copy is strictly better, but removing it here would make humaniq a
hard runtime dependency of payroll: loonaangifte needs a BSN, and an install
without humaniq would have none. That needs its own decision and is not made
silently in a rename.
