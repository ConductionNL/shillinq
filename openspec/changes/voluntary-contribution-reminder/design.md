# Design: voluntary-contribution-reminder

## Architecture Overview

```
tickInvoice ──> executeStage ──> VoluntaryContributionPolicy.isVoluntary?
                     │                 yes: VoluntaryReminderTemplate.render(invoice)
                     │                      -> templateId, renderedSubject, renderedBody
                     │                 declined: refuse
                     └──> DunningRun (saved)

portaliq ──(X-Portal-Subject, {invoiceId})──> PortalContributionDeclineController
            └─> ContributionDeclineService
                  PortalSubjectResolver (opaque id, customerMasterId from portalAccount)
                  ARInvoice owned + voluntary + issued|overdue
                  -> lifecycleState declined, contribution.declinedAt
                  -> pending PaymentRequest -> voided
```

## Decisions

### D1: The template text lives in the docudesk template registry

Every dunning template is an entry in `lib/Settings/docudesk-templates.json`,
referenced by `templateId` from the ladder and the run. The voluntary reminder is
two more entries there. `VoluntaryReminderTemplate` reads the entry and fills its
merge fields, so the run carries the rendered text as evidence (the run already
has `renderedSubject` and `renderedBody`). Alternative: strings in `l10n/*.json`.
Rejected: the template would then exist twice, once as a registry entry docudesk
renders and once as catalogue strings.

### D2: The selection happens in executeStage only

`executeStage()` is the one place every route to a run passes (the tick, the HTTP
endpoint). It already applies the voluntary policy there. Putting the template
override there too means no caller can send the generic stage letter for a
voluntary contribution, whatever it passes.

### D3: The language rides on the invoice

The raise already takes a `language` (default `nl`) for the invoice note. It now
also writes `contribution.language`, so the reminder follows the note. `en*`
picks English; everything else picks Dutch, the default of the raise.

### D4: A decline is a lifecycle state, not a write-off

A write-off books bad-debt expense and needs the `ar-controller` role. A refused
voluntary contribution is not bad debt: the parent had the right to refuse. A new
state `declined` closes the invoice without a posting. The raise posts no GL
transaction, so there is nothing to reverse.

### D5: The transitions are guarded, the portal save is plain

The lifecycle declares `decline` and `decline-overdue` so a bookkeeper can record
a refusal that arrived by mail or phone. `VoluntaryDeclineGuard` limits both to a
voluntary contribution (ADR-031 exception: a lifecycle guard). The portal receiver
saves the state plainly, like the reconciliation does for `paid`, after checking
the same policy.

### D6: One ownership chain for pay and decline

The pay receiver resolves the guardian's `customerMasterId` from their own portal
account and rejects a non-opaque target. `PortalSubjectResolver` takes those two
private methods out of `PortalPaymentSessionService` so the decline uses the same
code, not a copy of the security boundary. The decline then reads the invoice with
`find()` by uuid: a `findAll()` filter on `id` addresses a JSON property and
matches nothing in OpenRegister.

### D7: Idempotent and no existence oracle

A second decline of an invoice the guardian already declined answers 200 without
a write, so a double click is harmless. Every other refusal is one 403 body.

## API Design

See `contract.md`: `POST /apps/shillinq/api/portal/contributions/decline`,
`{invoiceId}` in, `{status: declined}` out; 401, 403, 502.

## Declarative-vs-imperative decision

| Behaviour | Path | Why |
|---|---|---|
| `declined` state and its transitions | declarative, `x-openregister-lifecycle` overlay in `school-contributions.json` | state graph |
| "only a voluntary contribution" on the transition | imperative guard `VoluntaryDeclineGuard` | ADR-031 lifecycle guard exception |
| not overdue | declarative, `x-openregister-calculations` overlay | derived field |
| template choice and rendering | imperative, `VoluntaryReminderTemplate` | document generation exception |
| portal decline | imperative receiver | external integration (portaliq forward), ADR-046 |

## Nextcloud Integration

- Controllers: `PortalContributionDeclineController` (`#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit]`).
- Services: `ContributionDeclineService`, `VoluntaryReminderTemplate`, `PortalSubjectResolver`; OpenRegister `ObjectService` fetched lazily from the container.
- Events: OpenRegister's `ObjectUpdatedEvent` fires on the saves; nothing new.

## Security Considerations

The assertion is the only credential; the audience is gated before any read; the
owner comes from the guardian's own portal account, never from the body; the
target is an opaque id. A foreign, compulsory or closed invoice answers the same
403. Portal reads and writes bypass NC RBAC like the pay receiver
(`_rbac: false`), because portal subjects have no Nextcloud account; the ownership
check is the boundary (ADR-005). The lifecycle guard stops a bookkeeper declining
a compulsory invoice.

## File Structure

```
lib/
  Controller/PortalContributionDeclineController.php   (new)
  Lifecycle/VoluntaryDeclineGuard.php                   (new)
  Portal/PortalSubjectResolver.php                      (new)
  Portal/PortalContributionProvider.php                 (decline action)
  Service/ContributionDeclineService.php                (new)
  Service/ContributionInvoiceBuilder.php                (language)
  Service/DunningRunService.php                         (template, declined)
  Service/Dunning/VoluntaryContributionPolicy.php       (language, declined)
  Service/Dunning/VoluntaryReminderTemplate.php         (new)
  Service/Payment/PortalPaymentSessionService.php       (uses the resolver)
  Settings/docudesk-templates.json                      (two templates)
  Settings/register.d/school-contributions.json         (ARInvoice 0.15.0)
appinfo/routes.php                                      (one route)
```

## Seed Data

No new schema. The existing seed invoices in `school-contributions.json`
(`@self: {register: shillinq, schema: ARInvoice}`) gain `contribution.language:
nl`. One more seed, `ar-invoice-ctb-2026-ouderbijdrage-2`, shows a declined
voluntary parental contribution with `declinedAt` set, so the state is visible on
a fresh install.

## Risks / Trade-offs

- [The template exists only in en and nl] → other languages fall back to Dutch, the raise's default.
- [A pending request is voided while the guardian has a checkout open] → the capture then lands in `captured_unapplied` (the invoice is not settleable), the existing exception path with a refund.

## Migration Plan

No Nextcloud migration class. The register import adds the fields and the state.
Existing contribution invoices have no language and get Dutch.
