# platform-properties Specification (delta)

## Purpose

How shillinq behaves as an app, independent of any page: how users sign in, how other software reaches its data, which languages it speaks and where it runs. Each requirement names who provides the property and how shillinq inherits it. Covers rows plt-2fa, plt-sso, plt-api, plt-languages and plt-self-host.

## ADDED Requirements

### Requirement: Sign-in, including two-factor authentication, is the Nextcloud login (REQ-PLAT-001)

Provider: Nextcloud server. shillinq SHALL have no login, password store or session of its own. Every shillinq page and every route in `appinfo/routes.php` SHALL require an authenticated Nextcloud session, except the pages listed as public, which SHALL rely on a share token or signature instead of an account. The two-factor providers an administrator enables or enforces in Nextcloud SHALL therefore apply before any shillinq page opens, and shillinq SHALL NOT offer a way around them.

#### Scenario: Two-factor authentication is enforced
- GIVEN an administrator enforces two-factor authentication in Nextcloud
- WHEN a user who has not passed the second factor opens a shillinq page
- THEN Nextcloud asks for the second factor first, and the shillinq page opens only after it succeeds

#### Scenario: A route without an access attribute
- GIVEN a new controller method routed in `appinfo/routes.php` without a Nextcloud access attribute
- WHEN the platform properties test runs
- THEN it fails and names the method

### Requirement: Single sign-on works through the Nextcloud login (REQ-PLAT-002)

Provider: Nextcloud server with the `user_saml` or `user_oidc` app. shillinq SHALL identify a user only by the Nextcloud user id and SHALL read the user's name, e-mail and groups from Nextcloud. A user who signs in through the organisation's SAML or OpenID Connect provider SHALL therefore reach shillinq with the same rights as any other Nextcloud user in the same groups, without a separate shillinq account.

#### Scenario: A user signs in through the organisation's identity provider
- GIVEN Nextcloud is connected to the organisation's OpenID Connect provider and a user is in the group that may use shillinq
- WHEN the user signs in through that provider and opens shillinq
- THEN shillinq opens with the user's Nextcloud name and the rights of the user's groups, and no shillinq login appears

### Requirement: Other software reaches shillinq's records through a REST API (REQ-PLAT-003)

Provider: OpenRegister. Every shillinq record SHALL be an OpenRegister object, readable and writable through OpenRegister's objects API (`/index.php/apps/openregister/api/objects/shillinq/<schema>`) under the caller's Nextcloud account and with the same permissions the caller has on screen. shillinq's own endpoints in `appinfo/routes.php` SHALL follow REQ-PLAT-001. shillinq SHALL NOT keep financial records outside OpenRegister where the API cannot reach them.

#### Scenario: An external system reads sales invoices
- GIVEN an app password for a Nextcloud user with read access to sales invoices
- WHEN an external system calls the OpenRegister objects API for the shillinq sales invoice schema with that app password
- THEN it receives the invoices that user may see, and no others

#### Scenario: A caller without rights
- GIVEN a Nextcloud user without access to shillinq
- WHEN that user calls the objects API for a shillinq schema
- THEN the call is refused and no record is returned

### Requirement: The interface follows the user's Nextcloud language (REQ-PLAT-004)

Provider: shillinq, on the Nextcloud localisation framework. Every user-facing string in shillinq SHALL go through the Nextcloud translation functions (`t()` / `n()` in the frontend, `IL10N` in PHP) with an English source key. `l10n/` SHALL ship at least the `nl` and `en` catalogues. shillinq SHALL show each page in the language the user chose in Nextcloud and SHALL fall back to the English source text for a string a catalogue lacks.

#### Scenario: A Dutch-speaking user
- GIVEN a user whose Nextcloud language is Dutch
- WHEN the user opens the shillinq dashboard
- THEN the menu, headings and buttons are in Dutch

#### Scenario: A catalogue is missing
- GIVEN the `nl` catalogue is removed from `l10n/`
- WHEN the platform properties test runs
- THEN it fails and names the missing catalogue

### Requirement: shillinq runs on the customer's own Nextcloud (REQ-PLAT-005)

Provider: shillinq. shillinq SHALL install from the Nextcloud app store or by hand on a Nextcloud server the customer operates, with OpenRegister as its only required app dependency. It SHALL NOT need a service run by Conduction or another vendor to start, store data or serve its pages; external connections (banks, Digipoort, Peppol, KvK, payment providers) SHALL be optional and configured by the customer. `LICENSE` and `appinfo/info.xml` SHALL both declare the EUPL-1.2.

#### Scenario: An installation without access to vendor services
- GIVEN a Nextcloud server with OpenRegister and no route to any Conduction service
- WHEN an administrator installs and enables shillinq
- THEN the app enables, its pages load, and a sales invoice can be created and read

#### Scenario: The licence is declared consistently
- GIVEN the repository at any release tag
- WHEN `LICENSE` and `appinfo/info.xml` are read
- THEN both name the EUPL-1.2
