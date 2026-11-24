# Design: platform-required-fields

Read at shillinq development `79f438f33` and openregister development on
2026-09-27.

## Context

- **Shipped requirements.** Each schema's `required` list lives in `lib/Settings/shillinq_register.json` (for example lines 26, 297, 396) and in `lib/Settings/register.d/*.json`. Forms take their fields from `src/manifest.json` `config.fields` (for example lines 1255, 1410).
- **Pre-save veto.** OpenRegister's `ObjectCreatingEvent` and `ObjectUpdatingEvent` implement `StoppableEventInterface` and carry `setErrors()` (openregister `lib/Event/ObjectCreatingEvent.php`). shillinq already vetoes saves this way: `lib/AppInfo/Application.php:671` registers `FeeScheduleValidationListener` on both events.
- **Settings pages.** Shillinq's settings foldout (`src/menu-layout.json`) hosts admin pages such as `DunningKlantOverrides`.

## Goals / Non-Goals

**Goals**
- An administrator adds required fields per administration; saves missing them are refused with the field names; forms show them.

**Non-Goals**
- Loosening shipped requirements, adding fields.

## Decisions

### D1. Requirements are records per administration

New schema `FieldRequirement`: `administrationId`, `schema` (a shillinq
schema slug), `field` (a property of that schema), `reason`,
`lifecycleState` (`active`, `retired`). A requirement on a field that is
not a property of the schema is refused on save.

### D2. One listener enforces all of them

`lib/Listener/FieldRequirementListener.php` on both pre-save events: for an
object of a shillinq schema with an `administrationId`, it loads the active
requirements for that administration and schema (cached per request) and,
when a required field is empty, stops propagation with errors naming each
missing field and its reason. Writes carrying the system flag that
shillinq's services set are skipped.

As built (2026-10-01): shillinq has no system flag on its writes, so the
listener tells a person's save from a system write by the request
(`ObjectApiRequest`): only a POST, PUT or PATCH on
`/apps/openregister/api/objects/{register}/{schema}[/{id}]` naming this
object's schema and uuid is checked. Writes by listeners, jobs and
shillinq's own controllers pass. A `FieldRequirement` itself is checked on
every write: its schema must exist, be kept per administration, have the
field, and not already always require it.

Alternative considered: rewriting the schema's `required` list per
administration. Rejected: a schema is shared by every administration in the
register.

### D3. The settings page lists form fields

`RequiredFields` settings page: pick a schema, see the fields its forms use
(from the manifest `config.fields` of that schema's pages) with the shipped
required ones shown locked, and toggle the others with a reason.

As built (2026-10-01): hydra gate 69 refuses a new `type:custom` page, so the
page is an index of the administration's `FieldRequirement` records (create
one with record type, field and reason; retire it to switch it off) and a
detail page per requirement whose table lists every field of the record type
as Always, In this administration or No, read from
`GET /api/field-requirements/{id}/fields`. The list shows the schema's
writable properties rather than a page's `config.fields`, because most
forms are built from the schema.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Which fields are required | Declarative: `FieldRequirement` records | Configuration as data. |
| Refusing a save | Imperative, a pre-save listener (ADR-031 exception: validation across records) | Reads configuration records at write time. |

## Seed Data

Gemeente Voorbeeld: `SupplierInvoice.costCenterCode` required, reason
"Elke inkoopfactuur wordt op een kostenplaats verantwoord"; Adviesbureau Van
Dijk: `CustomerMaster.kvkNumber` required, reason "Elke zakelijke klant
staat in het Handelsregister" (`email` is already always required on
CustomerMaster, so a requirement on it would be refused). The supplier
invoice field is `costCenter`.

## Risks / Trade-offs

- [A required field on a schema whose forms do not show it] → the settings page offers only fields a form shows.

## Migration Plan

None.

## Open Questions

None.
