# Tasks: align-eupl-license-and-nc31-baseline

## 1. Correct the licence declaration
- [ ] 1.1 In `appinfo/info.xml`, change `<licence>agpl</licence>` to
      `<licence>eupl</licence>` (EUPL-1.2, matching the fleet token).
- [ ] 1.2 Confirm no other licence declaration needs touching — `LICENSE`,
      `composer.json`, `publiccode.yml`, `openspec/app-config.json`, and both
      info.xml description blocks already say EUPL-1.2.

## 2. Correct the Nextcloud compatibility floor
- [ ] 2.1 In `appinfo/info.xml`, change
      `<nextcloud min-version="28" max-version="34"/>` to
      `<nextcloud min-version="31" max-version="34"/>`.
- [ ] 2.2 Confirm the new floor matches CI `nextcloud-test-refs`
      (`stable31/32/33`) and `app-config.json` `cicd.nextcloudRefs`.

## 3. Verify
- [ ] 3.1 `xmllint --noout appinfo/info.xml` (well-formed after edits).
- [ ] 3.2 Install the app on a stable31 instance in CI and confirm it enables
      unchanged (no behavioural delta).
- [ ] 3.3 Grep the repo for any remaining `agpl` / `AGPL` licence token and any
      `min-version="28"` reference; confirm none remain.
