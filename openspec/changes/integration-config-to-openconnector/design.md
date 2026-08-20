# Design: integration-config-to-openconnector

## 1. The derived 15-family slug table

Derived from `lib/Controller/ExternalAdaptersAdminController.php`'s
`ADAPTERS` const (lines 114–439) — the single source of truth the task brief
points at. Corrects the audit brief's "14" to the actual **15** (the `Cbs/`
directory holds two adapter interfaces, `CbsBestandenAdapterInterface` and
`CbsIv3AdapterInterface`, each a distinct family with its own registry entry,
`sourceSlug`, and admin page).

| # | family key | title | category | `sourceSlug` (declared) | feature flag | declared in `openconnector-sources.json`? |
|---|---|---|---|---|---|---|
| 1 | `digipoort-sbr` | Digipoort / SBR | regulatory | `digipoort-sbr` | `gov-sbr` | no |
| 2 | `salarisbureau` | Salarisbureau (payroll) | payroll | `salarisbureau` | `payroll-salarisbureau` | no |
| 3 | `rvo` | RvO (subsidy / WBSO) | regulatory | `rvo-aanvraag` | `gov-rvo` | no |
| 4 | `ib47` | Belastingdienst IB47 | regulatory | `ib47` | `gov-ib47` | no |
| 5 | `cbs-bestanden` | CBS Bestanden | regulatory | `cbs-bestanden` | `gov-cbs` | no |
| 6 | `cbs-iv3` | CBS Iv3 | regulatory | `cbs-iv3` | `gov-iv3` | no |
| 7 | `bzk-sisa` | BZK SiSa | regulatory | `bzk-sisa` | `gov-sisa` | **mismatched** — file has `bzk-sisa-upload-2026`, see §6 |
| 8 | `mollie` | Mollie Payments | payment | `mollie-payments` | `payments-mollie` | no |
| 9 | `bunq` | Bunq Bank Connector | bank | `bunq-bank` | `bank-bunq` | no |
| 10 | `kvk` | KvK Handelsregister | registry | `kvk-handelsregister` | `registry-kvk` | no |
| 11 | `uwv` | UWV Loonaangifte | payroll | `uwv-loonaangifte` | `gov-uwv` | no |
| 12 | `treasury-rates` | Treasury Rates (ECB / SDMX) | bank | `treasury-rates` | `treasury-rates` | no |
| 13 | `ccm-rule-engine` | CCM Rule Engine | regulatory | `ccm-rule-engine` | `ccm-external-engine` | no |
| 14 | `csrd-esrs-xbrl` | CSRD / ESRS XBRL | xbrl | `csrd-esrs-xbrl` | `csrd-xbrl` | no |
| 15 | `deposit-payment` | Deposit Payment lifecycle | payment | `deposit-payment` | `payments-deposit` | no |

`deposit-payment`'s own steps note it "delegates to mollie-payments under the
hood" — it is still a distinct declared slug in the controller and should get
its own stub entry; whether openconnector should model it as an alias/child
source of `mollie-payments` rather than a fully independent one is a
cross-repo question (§5), not something shillinq's declaration can resolve
unilaterally.

## 2. How `nav-reachability-gate` treats a page removed together with its route

`nav-reachability-gate` (`openspec/changes/nav-reachability-gate/`) is a
**proposed, not-yet-implemented** sibling change — verified at HEAD:
`tests/validate-nav-reachability.js` does not exist, and `check:nav-
reachability` is absent from `package.json`. This change does not depend on
it landing first, but is written to be safe under it regardless.

Its own design (`nav-reachability-gate/design.md` §2) defines reachability
over the pair `{ pages, menu }` returned by `buildManifest()`. A page id can
only appear in the **orphan list** if it exists in `pages[]` but is
unreachable from `menu[]` (directly or via `indexRoute`/`detailRoute`/
`rowRoute`/`clickRoute`/`viewAllRoute`). **A page id that has been deleted
from `pages[]` entirely cannot appear in the orphan list — there is nothing
for the reachability walk to evaluate.** This change removes each of the 15
`ExternalAdapterDetail` page entries from `src/manifest.d/external-adapters-
w8.json`'s `pages[]` array in the same edit that removes their corresponding
`menu[].children[]` leaf — page and route disappear together, never leaving a
route referencing a page id that used to exist. This is the "page + route
removed together = no orphan" case the task brief asks to confirm, and it
holds under the gate's own stated algorithm without needing a `tests/nav-
reachability-baseline.json` exception entry.

The one page that survives, `ExternalAdaptersStatus` (repurposed as the
roster), keeps its existing page id and route (`/external-adapters`) —
renaming it was considered and rejected to avoid an unforced churn on the one
external reference point (`ExternalAdaptersAdminController`'s doc-comment,
the three existing e2e specs which are being rewritten anyway, and any
bookmark an operator may already have). The menu leaf itself changes shape
from a group-with-16-children to a single leaf node carrying `route`
directly (see §3) — still one `menu[]` node with a `route` matching a
surviving `pages[].id`, so REQ-NAVR-002's base case (a direct menu route)
continues to hold for it.

Today's actual mechanical backstop — gate 30 (`hydra-gate-effective-manifest-
crossref`, already enabled via `enable-hydra-gates: true`) — only checks ids
literally listed in `menu-layout.json#removals`. This change does not use
`removals[]` at all (the pages are deleted from the fragment directly, the
same shape `nav-reachability-gate/design.md` §2's "Why" section identifies as
gate 30's blind spot), so gate 30 has nothing to say about this change either
way; it is a true no-op both under gate 30 today and under `nav-reachability-
gate` once it exists.

## 3. Roster page design

### Manifest shape (before → after)

Before: `src/manifest.d/external-adapters-w8.json`, 10,922 bytes, one
`ExternalConnections` menu group with `children: [1 status leaf, 15 detail
leaves]`, and `pages: [1 status page, 15 detail pages]` (269 lines).

After: one menu leaf (no `children`), one page entry. A drafted replacement
fragment (id/route/title choices only — final component filename is the
implementer's call) sizes at **1,002 bytes**, for a **net reduction of
~9,920 bytes (~90.8%)** on this fragment. (See §7 for the exact byte
counting method — this is a real measurement against a drafted file, not a
percentage guess.)

```jsonc
{
  "$comment": "…",
  "menu": [
    {
      "id": "ExternalConnections",
      "label": "External Connections",
      "icon": "TransitConnectionVariant",
      "route": "ExternalConnectionsRoster",
      "order": 46
    }
  ],
  "pages": [
    {
      "id": "ExternalAdaptersStatus",
      "route": "/external-adapters",
      "type": "custom",
      "title": "External Connections",
      "component": "ExternalAdaptersStatus",
      "_note": "…"
    }
  ]
}
```

(Page id/route kept as today's `ExternalAdaptersStatus` / `/external-
adapters` per §2's churn-avoidance reasoning; the fragment's `$comment`
above uses the alternate `ExternalConnectionsRoster` naming purely to show
the byte count is naming-insensitive — either choice is close in size.)

### Component

`ExternalAdaptersStatus.vue` (or its replacement) renders one row per family
from `GET /api/admin/external-adapters` (`ExternalAdaptersAdminController
#index`), unchanged endpoint, extended response shape:

```jsonc
{
  "adapters": [
    {
      // ...every existing static field from ADAPTERS (id, title, category,
      // interface, logClass, specSlug, requirements, configKeys,
      // featureFlag, sourceSlug, description, steps) — unchanged...
      "dormant": true,               // unchanged — live isDormant() check
      "provisioning": {
        "status": "provisioned" | "declared-not-provisioned" | "unknown",
        "openconnectorObjectId": "…" ,   // present only when status === "provisioned"
        "deepLink": "/apps/openconnector/sources"
      }
    }
  ],
  "summary": { "total": 15, "dormant": 12, "live": 3 }
}
```

`ExternalAdaptersAdminController::resolveDormancy()` (lines 534–549) is the
existing precedent for a defensive per-family resolver: any `Throwable`
resolves to a safe default rather than crashing the whole roster. The new
`resolveProvisioning(sourceSlug)` follows the identical shape:

- Attempt: resolve OpenRegister's object service via the DI container (same
  `ContainerInterface` already injected) and query `register: 'openconnector',
  schema: 'source', slug: $sourceSlug` — a read-only existence + minimal-
  metadata check, never reading `configuration.headers`/credentials.
- On a resolved object → `status: 'provisioned'`.
- On "not found" (a normal, expected outcome for 14 of 15 families today) →
  `status: 'declared-not-provisioned'`.
- On any `Throwable` (openconnector not installed, OR service unavailable,
  DI resolution failure) → `status: 'unknown'`, logged at `warning` exactly
  like `resolveDormancy()` does, **not** surfaced as an error to the admin —
  the row still renders with the static declaration and the fallback deep
  link, per the task brief's explicit fallback instruction ("declared,
  provision in OpenConnector").

`deepLink` is `/apps/openconnector/sources` for every row today — no
per-slug deep-link exists in openconnector's admin UI (verified: no `/sources/
{slug}` route in `openconnector/appinfo/routes.php`, and no `route.query`
handling was found in openconnector's source-related Vue files). Whether to
add a slug-scoped query parameter to openconnector's Sources page is
cross-repo work (§5) — this design does not block on it; a well-formed,
if generic, deep link satisfies the roster requirement's "deep link to
openconnector's Source admin" as stated.

## 4. Why the generic OR object API, not a source-management REST endpoint

`openconnector/openspec/specs/source-management/spec.md` documents
`REQ-SRC-001` (dispatch a call) and `REQ-SRC-002` (`POST /api/sources/{id}/
test`) — neither is an existence/status check by slug. There is no
documented openconnector-owned "does slug X exist" endpoint. What *is*
documented and stable is that `Source` is an ordinary OpenRegister object:
`openconnector/lib/Settings/openconnector_register.json:70-71` declares
`schema.slug: "source"` under register `openconnector`, and every seeded
source fragment (e.g. `openconnector/lib/Settings/register.d/kvk-source
.json`) sets `@self.slug` as the human-facing stable identifier. Per
ADR-022's abstraction table ("Registers + schemas + objects — Versioned
typed entities with validation, queries, events") and its "how to consume"
guidance ("Use OR's PHP service via DI injection... Call OR's REST API from
the frontend via `@conduction/nextcloud-vue`"), resolving `register:
openconnector, schema: source, slug: <x>` through OR's own generic object
API/service is the sanctioned, already-documented mechanism — not something
this change invents. It satisfies the task brief's "query openconnector's
API if a documented endpoint exists" clause: the documented endpoint is OR's
generic object API, addressed at openconnector's `source` schema, not a
bespoke openconnector REST verb.

## 5. Cross-repo tasks handed back to the orchestrator

None of the following are in this change's scope (shillinq-side only, per
the task brief) and are not attempted here:

1. **Provision the 14 undeclared `Source` records in openconnector** for
   real (currently only `bzk-sisa-upload-2026`/`bzk-sisa` exists, and its own
   slug is in question — see §6). This is genuinely an operator/openconnector-
   admin task, not a spec-and-code one, but the openconnector team should be
   aware 14 more slugs are about to be declared as expected by shillinq.
2. **Resolve the `bzk-sisa` vs `bzk-sisa-upload-2026` slug mismatch** against
   whatever is actually live in a running openconnector instance (§6) —
   needs a person with access to a real instance, not something derivable
   from either repo's source alone.
3. **Confirm or add a slug-scoped deep-link query parameter** on
   openconnector's generic Sources admin page (no evidence one exists today —
   §3). Without it, the roster's "provision in OpenConnector" links land on
   the plain list, not a pre-filtered view of the named source.
4. **Confirm the `deposit-payment` slug's relationship to `mollie-payments`**
   (§1) — should openconnector model it as a distinct source, or should
   shillinq's `deposit-payment` adapter be repointed at the `mollie-payments`
   slug directly? Either answer is a legitimate design; this change declares
   the slug as-is per the controller and defers the decision.
5. **No change requested to any of `openconnector/openspec/specs/{psd2-ais-
   bank-feed-connector, live-payment-providers, corporate-card-feed, webhook-
   signing, source-management, http-call-engine, job-scheduling}/spec.md`** —
   they were read for citation accuracy only (`proposal.md`, `spec.md`
   Notes) and none of their requirements need to change for this wave.

## 6. Open questions

1. **The `bzk-sisa` / `bzk-sisa-upload-2026` slug mismatch (§1, §5.2)** is
   the one finding in this change that looks like it could be "just fix the
   typo," and is deliberately NOT resolved here. `openconnector-sources.json`
   is a *shillinq-local declaration* of what it expects to exist — renaming
   its one existing entry to match the controller could silently break a
   reference an operator has already wired up in a live openconnector
   instance under the current slug. Renaming the controller's `sourceSlug`
   instead has the same risk in the other direction. Needs a person who can
   check what is actually provisioned before either side is edited.
2. **The ADR-067 rule 2 "optionalIntegrations / roadmap" declaration
   mechanism (REQ-ICO-005)**: is the existing ADR-022 `optionalIntegrations`
   manifest field + `CapabilityProviderInterface` pattern the intended
   vehicle, or is ADR-067 describing something narrower and app-local?
   ADR-022's mechanism resolves *registry-typed* capabilities
   (`workflow-engine`, `signing-provider`, `pdf-export`, `ocr-engine`,
   `geocoding`) at runtime against installed providers; these 15 families are
   point-to-point external APIs with no such registry today. Building a full
   `CapabilityProviderInterface` for each of 15 dormant families seems like
   more than ADR-067's one-sentence rule intends, but a static declaration
   with no runtime resolution is a lighter reading of the same sentence. This
   change specs the requirement (a declaration must exist) but leaves the
   mechanism choice to whoever implements it, ideally after a short check-in
   with whoever owns ADR-067/ADR-022.
3. **Whether `ExternalAdaptersAdminController#show` should be removed.** With
   no page deep-linking to a single family any more, `#show` (lines 506–519)
   has no browser caller left. It may still be worth keeping as a stable,
   documented per-family JSON endpoint (e.g. for a future CLI/monitoring
   consumer) — this is a judgment call for the implementer, not specced as a
   hard requirement either way in `spec.md`.
4. **Whether `deposit-payment` needs its own roster row at all**, given its
   own description says it delegates to `mollie-payments` "under the hood"
   (§1) — kept as its own row here because the controller declares it as a
   distinct family; revisit if §5.4 resolves it as an alias.

## 7. Byte-count method (for reproducibility)

The 10,922-byte "before" figure is `wc -c src/manifest.d/external-adapters-
w8.json` against the file at HEAD. The 1,002-byte "after" figure is `wc -c`
against a drafted replacement fragment with the same `$comment`-discipline
and one page/one menu-leaf shape shown in §3 — not a percentage estimate.
The implementer's actual final fragment will differ slightly by comment
wording and page `_note` length; the order of magnitude (roughly 90%
reduction, ~9–10 KB freed on this one fragment file) will hold regardless.

The implementer's actual final fragment is 1,514 bytes (`wc -c` against
`src/manifest.d/external-adapters-w8.json` post-implementation) — a net
reduction of 9,408 bytes (~86.1%) on this fragment, close to but slightly
above the 1,002-byte draft estimate (a richer `$comment` + page `_note`
than the drafted shape), exactly as this section predicted. Fleet-wide
`check-manifest-budget` totals: 1,123,373B before → 1,113,965B after
(budget 1,126,300B) — this change alone moved the app from 2,927B of
budget headroom to 12,335B.

## 8. Resolution evidence (apply phase, live-verified 2026-08-20)

### 8.1 §6.1 — the `bzk-sisa` / `bzk-sisa-upload-2026` slug mismatch

Per the orchestrator's binding ruling, the live openconnector instance on
the shared dev box (`http://localhost:8080`, admin:admin) was queried via
OpenRegister's generic object API — the same abstraction REQ-ICO-003 uses
at runtime — for every source object in `register: openconnector, schema:
source`:

```
GET /apps/openregister/api/objects/openconnector/source?_limit=100&_page={1,2,3}
```

Result: **262 source objects total** (`total: 262`, confirmed via
`_limit=1`), all 262 slugs collected across three pages. Neither `bzk-sisa`
nor `bzk-sisa-upload-2026` appears anywhere in that set (`grep`-equivalent
match over every `results[].@self.slug` — zero hits for either string, and
zero hits for `"sisa"`/`"bzk"` as a substring of any slug or `name`). None
of the other 14 families' declared `sourceSlug` values
(`digipoort-sbr`, `salarisbureau`, `rvo-aanvraag`, `ib47`, `cbs-bestanden`,
`cbs-iv3`, `mollie-payments`, `bunq-bank`, `kvk-handelsregister`,
`uwv-loonaangifte`, `treasury-rates`, `ccm-rule-engine`, `csrd-esrs-xbrl`,
`deposit-payment`) are provisioned either — the only near-miss is a
pre-existing, unrelated `kvk` slug (not `kvk-handelsregister`).

Per the ruling ("if neither exists, the JSON's existing
`bzk-sisa-upload-2026` wins"): `lib/Settings/openconnector-sources.json`
keeps its pre-existing `bzk-sisa-upload-2026` entry slug UNCHANGED —
neither renamed to match the controller's `bzk-sisa` nor otherwise
touched — and the entry's `description` now states this mismatch and its
evidence inline (grep `design.md §6.1` in that file). The controller's
`ExternalAdaptersAdminController::ADAPTERS['bzk-sisa']['sourceSlug']` is
similarly left as `'bzk-sisa'`, unrenamed, per REQ-ICO-001's explicit
"MUST NOT be silently renamed... without the verification §6.1 describes"
clause — the verification has now been performed and documents a
still-open mismatch, not a resolution in either direction. `docs/
features.json`'s ADR-067 declaration (§8.2) enumerates this family by the
controller's `bzk-sisa` value, per REQ-ICO-005's scenario wording ("all 15
`sourceSlug` values from `ExternalAdaptersAdminController::ADAPTERS`"),
which is a distinct, deliberately-not-reconciled use of the two
spellings — see §5.2 for the still-open cross-repo resolution task.

A live-browser consequence: with 0/15 families provisioned today, the
`provisioned` REQ-ICO-003 scenario has no real fixture on this box. The
e2e coverage for that scenario (`tests/e2e/workflows/
external-adapters-admin.spec.ts`) therefore drives all three
`resolveProvisioning()` states via a mocked `GET /api/admin/
external-adapters` response rather than live data — the frontend contract
under test is identical either way; only the origin of the JSON differs.

### 8.2 §6.2 — the ADR-067 rule 2 declaration mechanism

Per the orchestrator's binding ruling: `tests/schemas/
app-manifest-v2.schema.json` was checked for an `optionalIntegrations`-
style key. The root schema declares `"additionalProperties": false` (line
21) and its full enumerated property list (`$schema`, `version`,
`openbuildEditable`, `dependencies`, `setup`, `walkthrough`, `nav`,
`runtime`, `menu`, `pages`, `adminSettings`, `credentials`, `schedules`,
`observability`, `deepLinks`, `pageTemplates`, `pageInstances`, `sets`,
`mcp`) contains no `optionalIntegrations` key and no narrower app-local
equivalent — inventing one would fail `node tests/validate-manifest.js`'s
Ajv pass exactly as the ruling anticipated.

Per the ruling's fallback, the ADR-018 features/roadmap declaration
(`docs/features.json`, surfaced by the existing `FeaturesRoadmap` manifest
page — `src/manifest.json` id `FeaturesRoadmap`, route `/features-roadmap`)
is the mechanism. Rather than adding 15 separate customer-facing roadmap
cards (poor product-page hygiene for what is internal integration
plumbing, not a marketable feature), one consolidated entry — slug
`external-connections-integration-config`, `status: "soon"` — carries a
new `integrations[]` array enumerating all 15 families by their
controller-declared `sourceSlug`, each with its own `status:
"unimplemented"`. This satisfies REQ-ICO-005's scenario ("all 15
`sourceSlug` values ... appear, each marked as an unimplemented/roadmap
integration") without a schema change: `docs/features.json` carries no
`additionalProperties: false` constraint anywhere in this repo (no
`features.schema.json` was found), so the new `integrations[]` field and
the entry's own `_note` are additive. `openspec/features.overlay.json`
(pre-existing near-duplicate content of `docs/features.json`, no
generation script binds them so both are hand-maintained) was updated
identically to avoid the two files drifting.

**Correction (post-merge CI failure, `quality / Features Check`):** the
"no generation script binds them" premise above was wrong.
`ConductionNL/.github`'s shared `quality.yml` runs `.conduction-shared/
scripts/extract-features.py --app-root . --check` on every PR, which
regenerates `docs/features.json` from `openspec/features.overlay.json`
and hard-fails if the committed file differs byte-for-byte from that
output. The generator's `normalize_overlay_entry()` only carries a fixed
field set through (`slug`, `title`, `summary`, `status`, `docsUrl`,
`providedBy`, `title_nl`, `summary_nl`) — it silently drops any unknown
key, including this entry's `_note` and `integrations[]`. Committing
those two fields into `docs/features.json` (a generated artifact) made it
permanently stale against its own generator. Fix: `docs/features.json`'s
`external-connections-integration-config` entry had `_note` and
`integrations[]` removed so it matches `extract-features.py`'s real
output; `openspec/features.overlay.json` keeps both fields unchanged,
since it is the hand-authored *source* the generator reads (extra keys
there are harmlessly ignored, not diffed). The overlay file remains the
machine-readable declaration that satisfies REQ-ICO-005 (all 15
`sourceSlug` values, each marked `unimplemented`) — the generated public
`docs/features.json` just no longer tries to carry that engineering
metadata past the generator.

### 8.3 §6.3 — whether `ExternalAdaptersAdminController#show` should be removed

Resolved by the orchestrator's binding ruling, not left to implementer
judgment: **removed**. `appinfo/routes.php`'s `externalAdaptersAdmin#show`
route entry and the controller's `show()` method are both deleted — with
no page deep-linking to a single family any more, it was dead code with no
browser caller (confirmed: no remaining `/api/admin/external-adapters/
{id}` caller anywhere in `src/`).

### 8.4 §6.4 — whether `deposit-payment` needs its own roster row

Resolved by the orchestrator's binding ruling: **yes, unchanged** —
`deposit-payment` keeps its own roster row (family #15 in §1's table),
annotated as delegating to Mollie under the hood (both the controller's
existing `description`/`steps` text and this change's new
`lib/Settings/openconnector-sources.json` `deposit-payment` entry state
this explicitly). §5.4's cross-repo question (should openconnector model
it as an alias of `mollie-payments` instead) remains open and unresolved
by this change, as originally scoped.
