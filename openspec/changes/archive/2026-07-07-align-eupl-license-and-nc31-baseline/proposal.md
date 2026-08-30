# Change: align-eupl-license-and-nc31-baseline

## Why

Shillinq's published manifest tells two different stories about the same two
facts — its licence and the Nextcloud versions it supports — and both
mismatches are readiness-honesty defects a prospective operator or the
Nextcloud app store would catch on day one.

**Licence contradiction.** Every licence declaration in the repo says
**EUPL-1.2** *except one*:

- `LICENSE` — full text of the EUROPEAN UNION PUBLIC LICENCE v. 1.2
- `composer.json` — `"license": "EUPL-1.2"`
- `publiccode.yml` — `license: EUPL-1.2`
- `openspec/app-config.json` — `"license": "EUPL-1.2"`
- `appinfo/info.xml` description (both `en` and `nl`) — "Free and open source
  under the EUPL-1.2 license" / "Vrij en open source onder de EUPL-1.2-licentie"
- **`appinfo/info.xml` `<licence>` tag — `agpl`** ← the sole outlier

The machine-readable `<licence>` element is the one the Nextcloud app store and
tooling actually read, so the app currently *ships as AGPL* while advertising
EUPL-1.2 everywhere a human looks. Conduction policy is EUPL-1.2 for its apps,
and sibling apps already ship `<licence>eupl</licence>`.

**Untested compatibility claim.** `appinfo/info.xml` declares
`<nextcloud min-version="28" max-version="34"/>`, but nothing tests or supports
NC 28–30:

- CI (`.forgejo`/`.github` workflows) runs `nextcloud-test-refs:
  ["stable31", "stable32", "stable33"]`.
- `openspec/app-config.json` `cicd.nextcloudRefs` is `["stable31", "stable32"]`.

So `min-version="28"` is an unverified claim of three-major-versions of backward
compatibility that is never exercised. Conduction's EUPL apps baseline at
**NC ≥ 31**; the honest, tested floor is 31.

Neither fix changes app behaviour — they reconcile the manifest with what the
repo already is and what CI already proves.

## What Changes

- **ADDED** `REQ-Admin-006` to `app-administration` — the published manifest
  SHALL declare the licence and the supported Nextcloud version range
  truthfully and consistently with the repository's other licence and CI
  declarations.
- `appinfo/info.xml` — `<licence>agpl</licence>` → `<licence>eupl</licence>`
  (EUPL-1.2, matching `LICENSE`, `composer.json`, `publiccode.yml`,
  `app-config.json`, and the description text; matching sibling Conduction apps).
- `appinfo/info.xml` — `<nextcloud min-version="28" max-version="34"/>` →
  `<nextcloud min-version="31" max-version="34"/>` (the tested, supported floor;
  `max-version` unchanged).
- No code, schema, or UI change. Bumping `min-version` immutably cache-busts via
  the existing `<version>` bump discipline; no data migration.

## Impact

- Affected spec: `app-administration` (ADDED `REQ-Admin-006`).
- Affected file: `appinfo/info.xml` only (two attribute/element edits).
- Risk: none functional. The one externally-visible effect is correct: NC 28–30
  instances (never tested) can no longer install a build they were never
  verified against; NC 31–34 (the tested range) are unaffected.
- Out of scope: the stale `openspec/ROADMAP.md` change-directory links
  (`changes/scheduling`, `changes/add-invoice-pdf-export-with-ubl-peppol-support`
  point at directories that do not exist while the features ship under other
  specs) — a docs-only reconciliation with no spec delta, tracked separately.
