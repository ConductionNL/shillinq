# Design — Member 06: profile card + history UI

## Scope

The booking detail view markup: customer profile card + transaction
history + fallback states. Consumes the controller payload from member
05.

## Behaviour

- **Organization card**: legal name (bold), KvK, primary contact
  person, email (mailto), phone (tel), address, link to pipelinq
  Contact (new tab).
- **Individual card**: given + family name (bold), email (mailto),
  phone (tel), address.
- Missing optional fields are omitted entirely (no empty labels / no
  placeholders).
- **History section**: up to 5 most recent transactions; "Load more"
  pagination wired to the member-04 offset path; "No previous
  transactions" when empty; "History unavailable" when the controller
  passed the unavailable marker.
- **Read-only**: card fields are not editable; a hint says "Edit
  customer details in pipelinq".
- **Fallbacks**: "Customer not found" (with id) on 404; "Unable to
  load customer data" on error; "Customer not linked to pipelinq" when
  null.

## Decisions carried from the giant

- **D2** — card is read-only context, not an edit form.

## Theming (ADR-010)

- Use Nextcloud theme / existing booking styles; no hardcoded colors.
