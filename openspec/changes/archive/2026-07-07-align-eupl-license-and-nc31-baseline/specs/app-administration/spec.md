# Spec: app-administration (delta)

## ADDED Requirements

### Requirement: REQ-Admin-006 The published manifest SHALL declare licence and supported Nextcloud versions truthfully

The published `appinfo/info.xml` manifest SHALL declare a `<licence>` value and a `<nextcloud min-version>` floor consistent with the repository's other licence declarations and with the Nextcloud versions the app is actually tested against. `appinfo/info.xml` is the machine-readable manifest the Nextcloud app store and tooling consume.

The `<licence>` element SHALL denote **EUPL-1.2** (the `eupl` app-store token),
matching the `LICENSE` file, `composer.json` `license`, `publiccode.yml`
`license`, `openspec/app-config.json` `license`, and the `en`/`nl` description
text. It SHALL NOT declare `agpl` while every other declaration says EUPL-1.2.

The `<nextcloud min-version>` SHALL be the lowest Nextcloud major the app is
tested against in CI (`nextcloud-test-refs` / `cicd.nextcloudRefs`), currently
`31`. It SHALL NOT claim support for Nextcloud majors (28, 29, 30) that CI never
exercises. `max-version` is unchanged (`34`).

#### Scenario: Licence element agrees with every other licence declaration

- **WHEN** the `<licence>` value in `appinfo/info.xml` is compared with the
  `LICENSE` file, `composer.json`, `publiccode.yml`, `app-config.json`, and the
  info.xml description text
- **THEN** all six denote EUPL-1.2 (the info.xml `<licence>` is `eupl`, not
  `agpl`)
- @e2e exclude static manifest metadata, not browser-observable — verified by inspection of appinfo/info.xml

#### Scenario: Declared minimum Nextcloud version matches the tested floor

- **WHEN** `<nextcloud min-version>` in `appinfo/info.xml` is compared with the
  CI `nextcloud-test-refs` and `openspec/app-config.json` `cicd.nextcloudRefs`
- **THEN** `min-version` is `31` — the lowest Nextcloud major CI actually tests —
  and no version below the tested floor is claimed as supported
- @e2e exclude static manifest metadata, not browser-observable — verified by inspection of appinfo/info.xml and CI config

#### Scenario: No behavioural change accompanies the manifest correction

- **WHEN** the app is installed on a Nextcloud instance within the tested range
  (31–34) after the manifest correction
- **THEN** the app installs, enables, and runs exactly as before — the change is
  metadata-only, with no schema, route, service, or UI difference
- @e2e exclude install-time metadata behaviour, covered by CI install on stable31/32/33
