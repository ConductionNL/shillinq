# A closed billable period becomes a draft invoice

## Why

Dossiq bills its SaaS tenants per month. Until now it POSTed a bare `tenant_id` to a `/invoices`
URL that shillinq does not have, so no tenant month ever became an invoice. Decision 174 (10 Oct
2026, Q-dossiq-L11-1) settles which customer a tenant bills under: a shillinq `CustomerMaster`
carries the dossiq tenant id as its external reference.

## What changes

- `CustomerMaster` gains `externalReference`: the id of this customer in a sibling app, such as
  a dossiq tenant id.
- Shillinq defines `BillablePeriodClosedEvent`, a generic event any app raises in the same process
  when a billable period of one of its accounts closes (ADR-041: the target app defines the typed
  event; decision 182: nothing in it is specific to one app or one kind of account). Dossiq is the
  first app to raise it, for a tenant's month.
- `BillablePeriodClosedListener` drafts a `BillableInvoice` for the customer that carries the
  reference. It goes through the path the time intake already uses: it writes the source rows
  (one `MeterReading` per line, priced by a flat `UsageRatePlan` found or created for that line's
  price) and calls the unchanged `InvoiceGenerationService::draftInvoice()` with the `usage`
  model. A `TimeIntakeBatch` row keyed `<sourceApp>:<reference>:<period>` makes a repeat answer
  with the same invoice.
- When no customer carries the reference, or more than one does, the listener refuses on the
  event, and the asking app records the refusal.

## Out of scope

- Posting the invoice. It stays a draft for the bookkeeper.
- Creating the customer. A bookkeeper sets the reference on an existing customer.

## Dependencies

- dossiq: `dossiq-delivers-nothing` phase 5 raises the event (paired dossiq PR).
