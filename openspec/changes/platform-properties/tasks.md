# Tasks: platform-properties

## 1. Tags
- [ ] 1.1 Add `@spec openspec/specs/platform-properties/spec.md#REQ-PLAT-001` and `#REQ-PLAT-002` to the class docblock of `lib/AppInfo/Application.php`, and `#REQ-PLAT-001` to the class docblock of each controller with a `#[PublicPage]` method (V1)
- [ ] 1.2 Add an XML comment `<!-- @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-005 -->` above `<licence>` in `appinfo/info.xml` (V1)

## 2. A test that keeps the properties true
- [ ] 2.1 `tests/Unit/AppInfo/PlatformPropertiesTest.php`: reads `appinfo/info.xml` and `LICENSE`; asserts `<licence>EUPL-1.2</licence>`, that `LICENSE` is the EUPL-1.2 text, and that `openregister` is the only dependency `src/manifest.json` marks required (REQ-PLAT-005) (V1)
- [ ] 2.2 Same test: parses `appinfo/routes.php` and the controllers, asserts every routed method carries a Nextcloud access attribute, and that every `#[PublicPage]` method is on an allow-list in the test with the token or signature check it relies on (REQ-PLAT-001, REQ-PLAT-003) (V1)
- [ ] 2.3 Same test: asserts no file under `lib/` starts a session or sets a login cookie of its own (`setcookie`, `session_start`), and that `password_hash` appears only in the token hashing of `lib/Service/WidgetAuthService.php` and `lib/Util/TokenValidator.php` (share and widget tokens, not user passwords) (REQ-PLAT-001, REQ-PLAT-002) (V1)
- [ ] 2.4 Same test: asserts `l10n/nl.json` and `l10n/en.json` exist and parse (REQ-PLAT-004) (V1)

## 3. Matrix
- [ ] 3.1 When the tasks are done, archive this change and repoint the five rows in `openspec/parity/capabilities.json` from `built.change` to `built.spec: openspec/specs/platform-properties`
