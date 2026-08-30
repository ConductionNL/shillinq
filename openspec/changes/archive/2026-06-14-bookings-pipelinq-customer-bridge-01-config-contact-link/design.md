# Design — Member 01: config + contact link

## Scope

This `kind: config` member declares the connection configuration, the
booking↔contact link, the admin settings UI, and the integration-test
scaffold. It authors no HTTP adapter logic — that lands in member 02.

## Declarative-vs-imperative decision

Per ADR-031/ADR-001, the booking record is an OpenRegister-managed
schema. The optional `pipelinqContactId` link is therefore declared as
a schema field in `lib/Settings/shillinq_register.json` and surfaced
through `src/manifest.json` (ADR-024) — **not** via a raw
`ALTER TABLE oc_bookings`. The giant's task to "extend booking entity
with optional pipelinqContactId" is realised declaratively here.

The connection config (`pipelinq_api_endpoint`,
`pipelinq_api_token`) is stored via Nextcloud `IConfig` / the secrets
store. The token MUST be stored in the secrets store (not plaintext)
per ADR-005; the endpoint URL is plaintext app config.

## Decisions carried from the giant

- **D1** — pipelinq Contact lookup is by `externalId` (KvK for orgs,
  opaque pipelinq id for individuals), not email/phone. The
  `pipelinqContactId` field stores that `externalId`.
- **Auth model** — token in secrets store, masked on reload.

## Seed data

No persistent seed data — Contact data is maintained in pipelinq and
read on demand. The integration-test scaffold provides a pipelinq API
**mock server** returning canned Contact + klantbeeld + timeline
responses; example shapes:

```json
{
  "externalId": "org-kvk-12345678",
  "type": "organization",
  "legalName": "Bakkerij de Zon B.V.",
  "kvkNumber": "12345678",
  "email": "info@bakkerij-de-zon.nl",
  "phone": "+31 6 12345678",
  "address": "Zonneplein 10, 1234 AB Amsterdam"
}
```

## Security (ADR-005)

- API token stored in the secrets store, never logged, masked in the
  settings UI on reload.
- Settings routes are admin-only (Nextcloud SecurityMiddleware default
  for controllers without `#[NoAdminRequired]`).
