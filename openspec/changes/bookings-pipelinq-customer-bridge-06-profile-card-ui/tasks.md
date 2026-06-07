# Tasks — Member 06: profile card + history UI

Sourced from the giant's Phase 2 (booking detail view template + read-path render tests).

## Profile card

- [x] Render customer profile card from injected Contact data
- [x] Organization name (bold) or person name (given + family)
- [x] Email (mailto: link)
- [x] Phone (tel: link, if present)
- [x] KvK number (if organization)
- [x] Address (formatted)
- [x] Link to pipelinq Contact detail (opens in new tab)
- [x] Omit missing optional fields (no empty labels)
- [x] Read-only affordance ("Edit customer details in pipelinq")

## Transaction history

- [x] List of 5 most recent transactions (from klantbeeld)
- [x] Pagination controls for "Load more"
- [x] Fallback messages: not found / unavailable / empty / not linked
- [x] CSS styling using Nextcloud theme / existing booking styles

## Tests

- [x] Test template rendering with valid Contact data
- [x] Test template rendering with invalid / missing Contact data
- [x] Test history rendering with up to 5 entries and "Load more"
