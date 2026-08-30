# Design — Member 04: klantbeeld read path

## Scope

`getKlantbeeld($externalId, $limit)` with pagination. Consumes the
member-02 transport and the member-03 Contact path.

## Behaviour

- GET `/api/v1/contacts/{externalId}/klantbeeld` with `limit` (default
  5, max 100) and `offset` query params, 3s timeout via the member-02
  client.
- Parse the `transactions` array into objects: date, description,
  amount, currency, status.
- Empty `transactions` is a valid result ("no previous transactions"),
  not an error.
- Klantbeeld 5xx while Contact succeeded → return an "unavailable"
  marker so the UI can show the profile but hide/replace history.
- No local cache: transactions are immutable; cache only within the
  request/session per the giant's note.

## Decisions carried from the giant

- **D6** — klantbeeld is read-only/immutable; no persistent cache TTL.
