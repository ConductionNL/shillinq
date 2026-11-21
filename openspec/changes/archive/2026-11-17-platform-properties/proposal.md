---
kind: code
---

# Proposal: platform-properties

## Summary

Five capability rows describe how shillinq behaves as an app rather than a page in it: sign-in with two-factor authentication, sign-in through the organisation's single sign-on, a REST API for other software, the interface in several languages, and hosting on the customer's own server. All five are built, by Nextcloud, OpenRegister or shillinq itself, but no requirement says them. Nothing can cite them and nothing stops a later change from breaking one. This change writes them down as a `platform-properties` spec, names who provides each property, adds the `@spec` tags to the code that carries it, and adds a test that keeps them true.

## The rows this covers

Source: shillinq `openspec/parity/capabilities.json`.

- **plt-2fa** Protect sign-in with two-factor authentication (built, owner nextcloud/server).
- **plt-sso** Sign in through your organisation's own single sign-on (built, owner nextcloud/server).
- **plt-api** Connect other software through a documented REST API (built, owner openregister).
- **plt-languages** Use the interface in Dutch, English and other languages (built, owner shillinq: `l10n/` ships 26 catalogues).
- **plt-self-host** Run it on your own servers (built, owner shillinq: `appinfo/info.xml`, EUPL-1.2).

## What changes

1. A new main capability `platform-properties` with one requirement per property (REQ-PLAT-001 to REQ-PLAT-005), each naming its provider and how shillinq inherits it.
2. `@spec` tags on the files that carry each property.
3. A unit test over `appinfo/info.xml`, `LICENSE`, `appinfo/routes.php` and `l10n/` that keeps the properties true.

## Screens

None. These are properties of the app; see `screens.md`.

## Out of scope

- The Nextcloud login, its two-factor providers and the `user_saml` and `user_oidc` apps themselves (Nextcloud owns them).
- The OpenRegister objects API itself (OpenRegister owns it).
- Translating the catalogues (Transifex and the translation flow own that).
