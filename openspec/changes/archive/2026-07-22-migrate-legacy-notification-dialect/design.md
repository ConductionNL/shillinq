# Design: migrate-legacy-notification-dialect

## Declarative-vs-imperative decision (ADR-031)

All three ADR-031 extension classes touched by this change stay on the
declarative side:

| Behaviour | Extension | Why declarative fits |
|---|---|---|
| Notify finance officer / grant owner on submit/approve/finalize/overdue | `x-openregister-notifications` | Exactly the canonical dialect's `transition` + `scheduled` trigger types; no cross-schema join, no non-idempotent side effect beyond what the engine already handles (dedupe, channel fan-out, audit). |
| "Report is overdue" | `x-openregister-calculations` | Pure function of `status` + a date field + a 90-day constant — the textbook `isOverdue` example ADR-031 already names for ActionItem. |
| Role → recipient resolution with a fallback | `x-openregister-notifications recipients[].kind=expression` | The canonical dialect explicitly supports `{"kind": "expression", "resolver": "<DI tag / FQCN>"}` for exactly this case — a short, single-purpose PHP class the lifecycle engine calls, not a service that replaces the engine. |

The one exception acknowledged and documented here (ADR-031 exception #2,
"spans schemas… joins… engine can't model"): the legacy `resolver` +
`fallback` pair encodes **two-level role fallback** (try the primary NC
group; if it resolves to zero members, notify the fallback group instead).
The canonical dialect's `recipients[]` array does not have native
"try-this-then-that" semantics — every entry in `recipients[]` is notified
unconditionally, which would silently change behaviour from "notify one
group" to "notify both groups always." Rather than accept that behaviour
change on 48 rules, this change introduces `RoleFallbackResolver` (an
`expression` resolver) that internally implements the try-primary-else-
fallback logic in PHP, called once per rule via a single `recipients[]`
entry. This keeps the *external* dialect canonical (one `expression`
resolver, no bespoke rule-level fallback field) while preserving the
original fallback intent.

## RoleFallbackResolver

```php
final class RoleFallbackResolver implements RecipientResolverInterface
{
    // role => [primary NC group id, fallback NC group id]
    private const ROLE_GROUPS = [
        'finance-officer'          => ['shillinq-finance-officers', 'shillinq-finance'],
        'subsidie-coordinator'     => ['shillinq-subsidie-coordinators', 'shillinq-finance'],
        'administration-treasurer' => ['shillinq-treasurers', 'shillinq-finance'],
        // … remaining role names collected from the 19 files (tasks.md #2).
    ];

    public function resolve(string $role, ObjectEntity $object): array
    {
        [$primary, $fallback] = self::ROLE_GROUPS[$role] ?? [null, null];
        $uids = $primary !== null ? $this->groupManager->get($primary)?->getUsers() ?? [] : [];
        if ($uids === []) {
            $uids = $fallback !== null ? $this->groupManager->get($fallback)?->getUsers() ?? [] : [];
        }
        return array_map(static fn ($u) => $u->getUID(), $uids);
    }
}
```

Registered once in `lib/AppInfo/Application.php` under the DI tag/FQCN
referenced by every migrated rule's `resolver` string
(`OCA\Shillinq\Notification\RoleFallbackResolver::<role>` — the `::<role>`
suffix is parsed by the resolver itself off the `resolver` string, matching
the shape OR's `expression` recipient kind already expects: a single
resolvable string, not a structured object). The NC group ids in
`ROLE_GROUPS` are placeholders — tasks.md requires an audit against actual
group ids used elsewhere in shillinq's admin settings before merge (do not
invent group ids that don't exist in the deployed instance).

## Overdue rule replaces the imperative job exactly

`OverdueVerantwoordingJob::isOverdue()` (lines 103-128) is already a pure
function taking `(array $verantwoording, DateTimeImmutable $now, ?string
$awardDate)` — it has no I/O and was clearly written to be portable. The
new `x-openregister-calculations.isOverdue` block ports the same three
rules (non-final status in `[draft, submitted, approved]`; reference date =
explicit award date or `reportingPeriod` start; `now - reference > 90
days`) as calculation expressions. The `onOverdue` notification rule then
uses `trigger.type: scheduled` + `intervalSec: 86400` (matching the job's
existing 24h cadence) + `filter: {isOverdue: true}`, recipient
`{"kind":"field","field":"approverUserId"}` (the job already resolves the
same field, `record['approverUserId']`, line 187) — a direct 1:1 port, no
behaviour change for the "who gets notified when" question. The job's
"skip when `approverUserId` is empty" behaviour (line 188-192) is the
default behaviour of the `field` recipient kind (an empty/non-uid field
value resolves to zero recipients, per ADR-031's `recipients[].kind=field`
definition — "the field value is treated as a Nextcloud uid… the field MUST
hold a real uid").

## Breaking change note

Deleting `lib/Notification/Notifier.php` means the NC notification's
subject key changes from shillinq's own `subsidie_verantwoording_overdue`
prepare() (custom text + custom link
`#/subsidies/accountability-reports`) to whatever `AnnotationNotifier`'s
canonical rendering produces from the new rule's `subject`/`message`
locale maps + `actions[]`. Tasks.md requires a grep across `src/` for any
hardcoded match on `subsidie_verantwoording_overdue` before deleting the
old notifier (none found during this review, but a fresh grep at
implementation time is cheaper than trusting this document).
