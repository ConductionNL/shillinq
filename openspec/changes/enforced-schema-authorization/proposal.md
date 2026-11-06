# Enforced schema authorization for the remaining shillinq domains

## Why

OpenRegister enforces a schema's `authorization` block and nothing else. Shillinq declared its access
rules under `x-openregister-rbac` (and transition roles under `x-rbac-role`), which no OpenRegister code
reads and which the importer drops, so these schemas were readable and writable by any logged-in user
through `/apps/openregister/api/objects/shillinq/...`. Payroll, bank, payment and ledger data were open.
The administration, bookings and sales schemas were converted first (#2057, #2058, #2059); this change
covers every other schema.

## What changes

- Every remaining schema carries an `authorization` block, and the dead `x-openregister-rbac` and
  `x-rbac-role` keys are removed. Role `x` becomes the Nextcloud group `shillinq_x`, `administrator`
  becomes `shillinq_admin`, and an action no group holds is left out, which means Nextcloud admins only.
- A schema that declared no rule gets one written from its domain's intent.
- A transition that named a role (`x-rbac-role`) now names it in the transition's `authorization`, with
  the role assigned to its group in the schema's `roles` map.
- Server-side state guards replace browser-only checks on payment runs, payment blocks, purchase order
  and supplier invoice approval, three-way-match exception acceptance and report schedules.

## Not in this change

Per-administration isolation. OpenRegister's match grammar cannot look up AdministrationMembership, so a
role holder in one administration still reaches another administration's rows of the same schema.
