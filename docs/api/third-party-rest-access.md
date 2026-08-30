# Third-Party REST Access via OpenConnector

**Spec:** [`consume-platform-abstractions`](../../openspec/changes/archive/2026-07-14-consume-platform-abstractions/specs/consume-platform-abstractions/spec.md).
**Owner of the gateway:** `openconnector` — shillinq ships **no** API controller for this. This page documents the real, sanctioned path for a third party (e.g. an accounting-software integrator) to read shillinq's OpenRegister objects, and corrects a documentation mismatch the audit found: OpenRegister's self-generated OpenAPI spec (OAS) describes a **different** set of paths and a **different** auth scheme than the one third parties actually use.

## The two REST surfaces — do not confuse them

shillinq's registers (`shillinq`) are OpenRegister objects. OpenRegister exposes **two independent HTTP surfaces** over the same underlying data, with different paths, different auth, and different intended callers:

| | OpenRegister's own object API | OpenConnector's endpoint gateway |
|---|---|---|
| Path shape | `/index.php/apps/openregister/api/objects/{register}/{schema}[/{id}]` | `/index.php/apps/openconnector/api/endpoint/{configured-path}` |
| Auth | Nextcloud session cookie, or NC-group-scoped OAuth2/Basic | Per-`Endpoint` `Rule` (JWT, apiKey via a Rule-local key map — see caveat below) |
| Self-documenting OAS | Yes — `GET /index.php/apps/openregister/api/objects/{register}/oas` (`OasController::generate()`) | No dedicated OAS generator today |
| Intended caller | A Nextcloud-authenticated user / another Conduction app in-session | An external, non-Nextcloud third party |

**The mismatch the audit flagged is real**: if you hand a third-party integrator the OAS document OpenRegister generates for shillinq's register, the paths and the `securitySchemes` it describes (NC-group OAuth2 / Basic) are **not** the ones that third party will actually use. The sanctioned path for an external caller is the OpenConnector gateway (right column), which has no self-generated OAS at all today. Until OpenConnector grows one (tracked as an OpenConnector follow-up — not built here, see "Not built here" below), **this page is the source of truth** for the actual path/auth shape.

## How the gateway works (already shipped, unmodified by this change)

`openconnector/lib/Service/EndpointService.php:364` dispatches any request whose configured `Endpoint.targetType === 'register/schema'` to `handleSchemaRequest()` (`:1052`), which resolves the target register+schema and forwards to OpenRegister's generic object mapper — fully generic, works for any register/schema pair including shillinq's, with zero new PHP. Example candidate schemas: `ARInvoice` (customer receivables) and `APInvoice` (supplier payables) in the `shillinq` register.

```
Third party
   │  GET /index.php/apps/openconnector/api/endpoint/{configured-path}
   │  Authorization: <per-Rule auth — see below>
   ▼
EndpointService::handleSchemaRequest()
   │  targetType: "register/schema", targetId: "{numericRegisterId}/{numericSchemaId}"
   ▼
OpenRegister generic object mapper — register=shillinq, schema=ARInvoice|APInvoice|...
```

### Endpoint (what gets configured, and where it actually lives)

An `Endpoint` is a plain OpenRegister object (register `openconnector`, schema `endpoint` — not a bespoke entity; `lib/Db/Endpoint.php` was removed in favour of a generic `ObjectEntity`). Required fields: `name`, `endpoint` (path pattern), `method`. For this use case: `targetType: "register/schema"`, `targetId: "{registerId}/{schemaId}"` (numeric IDs at the storage layer — a `"shillinq/ARInvoice"` slug pair only resolves automatically when authored as an openconnector **Configuration bundle** and imported through `EndpointHandler::import()`, which does the slug→ID lookup).

### Consumer + auth (the part that needs care)

A `Consumer` (register `openconnector`, schema `consumer`) declares `authorizationType` (`none | apiKey | jwt | basic | oauth2`), `rateLimit {requestsPerWindow, windowSeconds}`, and `quota {limit, period}`. **Important, code-verified caveat**: as shipped today, an `Endpoint` enforces authentication through a separate `rule` object (schema `rule`, `type: "authentication"`) attached to `Endpoint.rules[]` — an Endpoint with an empty `rules[]` has **no authentication at all**. For the `apiKey` rule type, the key→user map lives on the **Rule** itself, not on the Consumer; setting `Consumer.authorizationType: "apiKey"` currently has **no enforcement effect** anywhere in the codebase. The **JWT** path is the one genuinely wired end-to-end to a Consumer record (issuer-name resolution, public key/algorithm, and rate-limit/quota keyed on the resolved consumer). Until OpenConnector wires `apiKey` auth to the Consumer record (tracked as a follow-up), **JWT is the auth type to plan an integration around** if per-consumer control (rate limit, quota, revocation) actually matters.

## Not built here — this is the openconnector-repo boundary

This change does **not** create an `Endpoint`, `Consumer`, or `Rule` object for shillinq's registers, for three independent reasons, each sufficient on its own:

1. **`Endpoint` is only declaratively importable as an openconnector "Configuration bundle"** (`openconnector/configurations/*/`, processed by `ConfigurationService` + `EndpointHandler`) — a folder that lives in the **openconnector repository**, not shillinq's. There is no shillinq-side config surface (no `register.d`-equivalent) that openconnector's importer reads from a sibling app.
2. **`Consumer` has no import handler at all** (`ConfigurationService`'s handler registry covers `endpoint | synchronization | mapping | job | source | rule` — not `consumer`). Creating one requires an imperative call to `POST /apps/openregister/api/objects/openconnector/consumer` (UI form or a script) — not a drop-in JSON file, and automating it from shillinq's install would mean writing new PHP in openconnector or a new Repair step, which is explicitly out of scope for a config-only change.
3. **The `apiKey` auth path is not actually wired to `Consumer.authorizationType`** (see caveat above) — creating a Consumer with `authorizationType: apiKey` today would not enforce anything, so doing so would ship a control that looks configured but is not, which is worse than not building it.

Per this change's own discipline (a smaller, correct change beats a bigger boundary violation), these are reported as **openconnector follow-ups** rather than forced through:

- Wire `apiKey` (and `basic`/`oauth2`) enforcement to `Consumer.authorizationType`/`authorizationConfiguration`, matching what REQ-CON-001 already documents.
- Add a `ConsumerHandler` to `ConfigurationService` so `Consumer` becomes as declarative/importable as `Endpoint`.
- Add an OAS generator for the `/apps/openconnector/api/endpoint/{path}` surface (or an OpenRegister OAS option for a custom `servers[]` + `apiKey`/`jwt` security scheme) so third parties get accurate self-service documentation instead of this hand-written page.

## Reference: what an ops engineer would configure (illustrative, not committed)

Once the openconnector-side gaps above are resolved, exposing `shillinq`'s `ARInvoice` register through the gateway looks like this Configuration-bundle shape (illustrative — placeholders only, **not** a file shipped by this change):

```json
{
  "endpoint": {
    "name": "shillinq-ar-invoices",
    "description": "Read-only AR invoice feed for accounting-software integrators.",
    "endpoint": "shillinq/ar-invoices",
    "method": "GET",
    "targetType": "register/schema",
    "targetId": "shillinq/ARInvoice",
    "rules": ["shillinq-ar-invoices-auth"]
  },
  "rule": {
    "slug": "shillinq-ar-invoices-auth",
    "type": "authentication",
    "configuration": {
      "authentication": {
        "type": "jwt",
        "issuer": "YOUR_CONSUMER_NAME_HERE"
      }
    }
  },
  "consumer": {
    "name": "YOUR_CONSUMER_NAME_HERE",
    "authorizationType": "jwt",
    "authorizationConfiguration": {
      "publicKey": "YOUR_JWT_PUBLIC_KEY_HERE",
      "algorithm": "RS256"
    },
    "rateLimit": { "requestsPerWindow": 100, "windowSeconds": 60 },
    "quota": { "limit": 10000, "period": "day" }
  }
}
```
