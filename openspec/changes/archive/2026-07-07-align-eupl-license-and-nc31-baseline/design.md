# Design: align-eupl-license-and-nc31-baseline

## Context

This is a manifest-integrity correction, not a feature. Two facts in
`appinfo/info.xml` disagree with the rest of the repository:

| Fact | info.xml `<licence>` / `min-version` | Everywhere else |
|---|---|---|
| Licence | `agpl` | EUPL-1.2 (`LICENSE`, `composer.json`, `publiccode.yml`, `app-config.json`, description) |
| NC floor | `min-version="28"` | CI tests `stable31/32/33`; `app-config.cicd.nextcloudRefs = [stable31, stable32]` |

## Decisions

### D1 — Licence token: `eupl`

The Nextcloud app-store `<licence>` element uses short tokens (`agpl`, `mit`,
`eupl`, …). Sibling Conduction apps already ship `<licence>eupl</licence>`, so
`eupl` is the fleet-consistent token for EUPL-1.2. The SPDX identifier
`EUPL-1.2` stays where SPDX belongs (`composer.json`, `publiccode.yml`). No
change to the `LICENSE` file (already the correct full EUPL-1.2 text).

### D2 — Nextcloud floor: 31, not 28

Set `min-version="31"` — the lowest major CI actually installs against. This is
the *honest* floor: claiming 28 asserts three extra majors of backward
compatibility that are never tested and that the app's dependencies (declarative
notifications with `web-push` + `actions`, the ADR-031/048/051 engine surfaces
shillinq consumes) do not target. `max-version="34"` is unchanged.

### D3 — Why this is not "just docs"

The `<licence>` element is the one a downstream consumer's tooling reads to
decide redistribution terms; shipping `agpl` while the `LICENSE` file is EUPL-1.2
is a licensing-integrity defect, not cosmetics. Likewise `min-version` is a hard
install gate, so an untested `28` is a false promise to NC 28–30 operators.

## Non-goals

- No change to the `LICENSE` file, `composer.json`, `publiccode.yml`, or
  `app-config.json` — they are already correct.
- No `openspec/ROADMAP.md` reconciliation (the phantom `changes/scheduling` and
  `changes/add-invoice-pdf-export-with-ubl-peppol-support` links). That is a
  docs-only fix with no spec delta and is out of scope here.
- No `<version>` bump decision beyond the normal release discipline (a manifest
  edit rides the next version bump for immutable cache-busting).

## Risks

Only one externally-visible effect, and it is intended: NC 28–30 instances can
no longer install a build never verified against them. The tested range (31–34)
is unaffected.
