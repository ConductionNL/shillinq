## ADDED Requirements

### Requirement: A granted app raises a request without a user (REQ-SOPR-010)

Shillinq SHALL let an administrator grant `payment.request` to an app in the
app config key `paymentActionApps` (a JSON object from action to app ids).
The leaf SHALL offer `createAsApp(appId, register, schema, objectId,
payload)` beside `create`. It SHALL raise the same request as `create` when
the app is named for `payment.request` and is enabled, with or without a
signed-in user, and SHALL record `requestedBy` as `app:<appId>`. It SHALL
refuse with a 403 and write nothing for an app that is not named, an app
named only for another action, an app that is not enabled, and an id that is
not an app id. No app SHALL carry an action by default. `create` SHALL stay
as REQ-SOPR-003 has it: no payload key SHALL make it act for an app.

#### Scenario: larpinq raises the fee for a promoted registration

- GIVEN `paymentActionApps` is `{"payment.request": ["larpinq"]}` and larpinq is enabled
- AND nobody is signed in (larpinq's daily job moves a waitlisted registration up)
- WHEN larpinq calls `createAsApp('larpinq', ...)` on the registration with an amount
- THEN one `pending` request exists with the registration as subject and `requestedBy` `app:larpinq`
- @e2e exclude a background caller has no browser; covered by PHPUnit on `PaymentRequestLeafProvider::createAsApp()`

#### Scenario: An app without the grant is refused

- GIVEN `paymentActionApps` names only dossiq for `payment.request`
- WHEN larpinq calls `createAsApp('larpinq', ...)`
- THEN the leaf answers 403 and no request exists
- @e2e exclude authorization guard; covered by PHPUnit on `PaymentRequestLeafProvider::createAsApp()`

#### Scenario: A request cannot claim an app

- GIVEN larpinq carries the grant
- WHEN an anonymous caller posts to the leaf's create route with `callerApp: larpinq` in the body
- THEN the leaf answers 403 and no request exists
- @e2e exclude authorization guard; covered by PHPUnit on `PaymentRequestLeafProvider::create()`
