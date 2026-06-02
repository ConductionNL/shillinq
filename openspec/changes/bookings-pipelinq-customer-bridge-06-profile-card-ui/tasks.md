# Tasks — Member 06: profile card + history UI

Sourced from the giant's Phase 2 (booking detail view template + read-path render tests).

## Profile card

- [ ] Render customer profile card from injected Contact data
- [ ] Organization name (bold) or person name (given + family)
- [ ] Email (mailto: link)
- [ ] Phone (tel: link, if present)
- [ ] KvK number (if organization)
- [ ] Address (formatted)
- [ ] Link to pipelinq Contact detail (opens in new tab)
- [ ] Omit missing optional fields (no empty labels)
- [ ] Read-only affordance ("Edit customer details in pipelinq")

## Transaction history

- [ ] List of 5 most recent transactions (from klantbeeld)
- [ ] Pagination controls for "Load more"
- [ ] Fallback messages: not found / unavailable / empty / not linked
- [ ] CSS styling using Nextcloud theme / existing booking styles

## Tests

- [ ] Test template rendering with valid Contact data
- [ ] Test template rendering with invalid / missing Contact data
- [ ] Test history rendering with up to 5 entries and "Load more"
