# Tasks: access-control-authorisation

## 1. OpenRegister Schema Definitions

- [x] 1.1 Add `role` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN `shillinq_register.json` is processed by OpenRegister
    - THEN schema `role` MUST be registered with properties: `name` (required), `description`, `level` (integer, default 0), `isActive` (required, boolean, default true), `purchasingLimitAmount`, `purchasingLimitCategory`
    - AND `x-schema-org` annotation MUST be `schema:Thing`

- [x] 1.2 Add `team` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is registered
    - THEN properties `name` (required), `description`, `createdAt` (required, date-time) MUST exist
    - AND `x-schema-org` MUST be `schema:Organization`

- [x] 1.3 Add `user` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is registered
    - THEN properties `email` (required, format: email), `username` (required), `displayName` (required), `isActive` (required, boolean, default true), `createdAt` (required, date-time), `lastLogin` (date-time), `branch` MUST exist
    - AND `x-schema-org` MUST be `schema:Person`

- [x] 1.4 Add `accessControl` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is registered
    - THEN properties `action` (required, enum), `resourceType` (required), `resourceId`, `timestamp` (required, date-time), `result` (required, enum), `ipAddress`, `userAgent`, `details` (object) MUST exist
    - AND `action` enum MUST be `["create","read","update","delete","login","logout","permission-denied","delegation-created","delegation-revoked"]`
    - AND `result` enum MUST be `["success","denied","error"]`
    - AND `x-schema-org` MUST be `schema:Event`

- [x] 1.5 Add `accessRight`, `accessRecertification`, and `permission` schemas to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-007, #REQ-AC-008, #REQ-AC-005`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN schemas are registered
    - THEN `accessRight` MUST have: `userId` (required), `roleId` (required), `grantedBy` (required), `startDate` (required, date-time), `endDate` (required, date-time), `isActive` (required, boolean, default true), `reason`
    - AND `accessRecertification` MUST have: `name` (required), `cronExpression` (required), `isActive` (required), `lastRunAt` (date-time), `nextRunAt` (date-time), `reviewDeadlineDays` (integer, default 14)
    - AND `permission` MUST have: `roleId` (required), `schemaName` (required), `fieldName` (required), `canRead` (required, boolean, default true), `canWrite` (required, boolean, default true)

## 2. Seed Data

- [x] 2.1 Add five built-in Role seed objects to `lib/Repair/CreateDefaultConfiguration.php`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-002`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN the repair step runs on a fresh install
    - THEN five Role objects MUST be created: Admin (100), Editor (80), Accountant (60), Viewer (40), Reports-only (20), each with `isActive: true`
    - AND idempotency check MUST use `name` as the unique key

- [x] 2.2 Add Team and AccessControl seed objects to repair step
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-003`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN a fresh install
    - THEN 1 Team "Administrators" MUST be created
    - AND 3 sample AccessControl events MUST be created (login, read, permission-denied) to populate the audit log demo view

## 3. PHP Middleware and Services

- [x] 3.1 Create `lib/Middleware/PermissionGateMiddleware.php`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-002`
  - **files**: `lib/Middleware/PermissionGateMiddleware.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN a Shillinq OCS request arrives
    - THEN the middleware MUST resolve the NC user to a `User` object and verify `isActive: true`
    - AND MUST compare the user's role level against the endpoint's `@RequiresRoleLevel` annotation
    - AND MUST call `AuditLogService::log()` for every request (success and denial)
    - AND MUST return 403 with a JSON error body for denied requests

- [x] 3.2 Create `lib/Service/AuditLogService.php`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-009`
  - **files**: `lib/Service/AuditLogService.php`
  - **acceptance_criteria**:
    - GIVEN `log()` is called with action, resourceType, resourceId, result
    - THEN an `AccessControl` object MUST be created in OpenRegister via `ObjectService`
    - AND `ipAddress` and `userAgent` MUST be extracted from the current `IRequest` instance
    - AND failures in this method MUST NOT propagate exceptions to the caller — log to NC system log only

- [x] 3.3 Create `lib/Service/FieldSecurityService.php`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-005`
  - **files**: `lib/Service/FieldSecurityService.php`
  - **acceptance_criteria**:
    - GIVEN `filterResponse($object, $schemaName, $userId)` is called
    - THEN all fields where the user's role has `canRead: false` MUST be removed from the returned array
    - AND `checkWritePermission($schemaName, $fieldName, $userId)` MUST return false for write-restricted fields
    - AND the role-permission matrix MUST be cached per request lifecycle using the DI container

- [x] 3.4 Create `lib/Service/PurchasingLimitService.php`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-006`
  - **files**: `lib/Service/PurchasingLimitService.php`
  - **acceptance_criteria**:
    - GIVEN `checkLimit($userId, $amount, $category)` is called
    - THEN MUST resolve all active roles (base + active delegations) for the user
    - AND MUST return false if any applicable role has `purchasingLimitAmount < $amount` for the matching category
    - AND if `purchasingLimitCategory` is null on a role, the limit applies to ALL categories

- [x] 3.5 Create `lib/Service/DelegationService.php`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-007`
  - **files**: `lib/Service/DelegationService.php`
  - **acceptance_criteria**:
    - GIVEN `createDelegation()` is called with valid start/end dates
    - THEN an `AccessRight` object MUST be created with `isActive: true`
    - AND a Nextcloud notification MUST be dispatched to both the grantee and the admin
    - AND MUST reject with an exception if `endDate <= startDate`

- [x] 3.6 Create `lib/Service/RecertificationService.php`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-008`
  - **files**: `lib/Service/RecertificationService.php`
  - **acceptance_criteria**:
    - GIVEN an `AccessRecertification` campaign is due (cron expression matches current time)
    - THEN `dispatchReviewNotifications()` MUST send a Nextcloud notification to each role-owner
    - AND `processReviewDecisions($campaignId, $decisions)` MUST set `isActive: false` on revoked users and write `AccessControl` events

## 4. Background Jobs

- [x] 4.1 Create `lib/BackgroundJob/DelegationExpiryJob.php`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-007`
  - **files**: `lib/BackgroundJob/DelegationExpiryJob.php`
  - **acceptance_criteria**:
    - GIVEN the job runs
    - THEN all `AccessRight` objects where `isActive: true` AND `endDate < now()` MUST be set to `isActive: false`
    - AND an `AccessControl` event with `action: "delegation-revoked"` MUST be written for each
    - AND Nextcloud notifications MUST be sent to grantee and granting admin

- [x] 4.2 Create `lib/BackgroundJob/RecertificationNotificationJob.php`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-008`
  - **files**: `lib/BackgroundJob/RecertificationNotificationJob.php`
  - **acceptance_criteria**:
    - GIVEN the job runs hourly
    - THEN active campaigns whose `cronExpression` is due MUST trigger `RecertificationService::dispatchReviewNotifications()`
    - AND `lastRunAt` and `nextRunAt` on the campaign MUST be updated after dispatch
    - AND campaigns with `isActive: false` MUST be skipped

## 5. OCS API Controllers

- [x] 5.1 Create `lib/Controller/RoleController.php`
  - **files**: `lib/Controller/RoleController.php`
  - **acceptance_criteria**:
    - Routes: `GET|POST /api/v1/roles`, `GET|PUT|DELETE /api/v1/roles/{id}`
    - GIVEN a DELETE request for a role with assigned users
    - THEN return 422 with list of affected usernames
    - AND all write endpoints MUST require role level ≥ 100 (Admin)

- [x] 5.2 Create `lib/Controller/TeamController.php`
  - **files**: `lib/Controller/TeamController.php`
  - **acceptance_criteria**:
    - Routes: `GET|POST /api/v1/teams`, `GET|PUT|DELETE /api/v1/teams/{id}`
    - AND `POST /api/v1/teams/{id}/invite` — accepts `{ email, roleId }`, calls `DelegationService` to provision user

- [x] 5.3 Create `lib/Controller/UserController.php`
  - **files**: `lib/Controller/UserController.php`
  - **acceptance_criteria**:
    - Routes: `GET /api/v1/users`, `GET|PUT /api/v1/users/{id}`
    - AND `POST /api/v1/users/provision` — accepts HR onboarding payload `{ employeeId, roleName }`, creates/updates User object

- [x] 5.4 Create `lib/Controller/AccessControlController.php`
  - **files**: `lib/Controller/AccessControlController.php`
  - **acceptance_criteria**:
    - Routes: `GET /api/v1/access-log`, `GET /api/v1/access-log/{id}`
    - All endpoints are read-only — no POST/PUT/DELETE
    - MUST require role level ≥ 60 (Accountant) to read; full list requires ≥ 100 (Admin)

- [x] 5.5 Create `lib/Controller/DelegationController.php` and `lib/Controller/RecertificationController.php`
  - **files**: `lib/Controller/DelegationController.php`, `lib/Controller/RecertificationController.php`
  - **acceptance_criteria**:
    - `DelegationController`: `POST /api/v1/delegations`, `DELETE /api/v1/delegations/{id}`
    - `RecertificationController`: `GET|POST /api/v1/recertifications`, `POST /api/v1/recertifications/{id}/review`

- [x] 5.6 Create `lib/Controller/ReportController.php`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-010`
  - **files**: `lib/Controller/ReportController.php`
  - **acceptance_criteria**:
    - Route: `GET /api/v1/reports/access-rights?format=csv|html`
    - MUST require role level 100 (Admin)
    - GIVEN `format=csv` THEN stream a CSV with headers: username, displayName, roles, teams, lastLogin, branch, delegationsActive
    - GIVEN `format=html` THEN return JSON array for the Vue report table

## 6. Pinia Stores

- [x] 6.1 Create `src/store/modules/role.js`
  - **files**: `src/store/modules/role.js`
  - **acceptance_criteria**:
    - `useRoleStore` MUST be created via `createObjectStore('role')`
    - MUST be registered in `src/store/store.js`

- [x] 6.2 Create `src/store/modules/team.js`
  - **files**: `src/store/modules/team.js`
  - **acceptance_criteria**:
    - `useTeamStore` MUST be created via `createObjectStore('team')`

- [x] 6.3 Create `src/store/modules/user.js`
  - **files**: `src/store/modules/user.js`
  - **acceptance_criteria**:
    - `useUserStore` MUST be created via `createObjectStore('user')`

- [x] 6.4 Create `src/store/modules/accessControl.js`
  - **files**: `src/store/modules/accessControl.js`
  - **acceptance_criteria**:
    - `useAccessControlStore` MUST be created via `createObjectStore('accessControl')`

- [x] 6.5 Create `src/store/modules/delegation.js` and `src/store/modules/recertification.js`
  - **files**: `src/store/modules/delegation.js`, `src/store/modules/recertification.js`
  - **acceptance_criteria**:
    - `useDelegationStore` via `createObjectStore('accessRight')`
    - `useRecertificationStore` via `createObjectStore('accessRecertification')`

- [x] 6.6 Register all six new stores in `src/store/store.js`
  - **files**: `src/store/store.js`
  - **acceptance_criteria**:
    - GIVEN `initializeStores()` is called THEN all six stores MUST be initialised and returned

## 7. Role Views

- [x] 7.1 Create `src/views/role/RoleIndex.vue`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-002`
  - **files**: `src/views/role/RoleIndex.vue`
  - **acceptance_criteria**:
    - GIVEN the Role list renders
    - THEN `CnIndexPage` MUST show columns: name, level, description, isActive (badge)
    - AND row actions: View, Edit, Deactivate (replaces Delete if users assigned)
    - AND "Add Role" button opens `CnFormDialog` with `fieldsFromSchema('role')`

- [x] 7.2 Create `src/views/role/RoleDetail.vue`
  - **files**: `src/views/role/RoleDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a Role detail renders
    - THEN `CnDetailPage` MUST show tabs: Details, Permissions (field-level settings), Members
    - AND breadcrumb: Shillinq > Security > Roles > {name}

## 8. Team Views

- [x] 8.1 Create `src/views/team/TeamIndex.vue`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-003`
  - **files**: `src/views/team/TeamIndex.vue`
  - **acceptance_criteria**:
    - GIVEN the Team list renders
    - THEN `CnIndexPage` MUST show columns: name, description, memberCount, createdAt
    - AND row action "Manage Members" navigates to team detail

- [x] 8.2 Create `src/views/team/TeamDetail.vue` and `src/views/team/TeamInviteDialog.vue`
  - **files**: `src/views/team/TeamDetail.vue`, `src/views/team/TeamInviteDialog.vue`
  - **acceptance_criteria**:
    - GIVEN a Team detail renders
    - THEN tabs: Details and Members MUST be shown
    - AND Members tab MUST list `User` objects with role badge and "Remove" action
    - AND "Invite Member" button opens `TeamInviteDialog` with email field and role dropdown

## 9. User Views

- [x] 9.1 Create `src/views/user/UserIndex.vue`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-004`
  - **files**: `src/views/user/UserIndex.vue`
  - **acceptance_criteria**:
    - GIVEN the User list renders
    - THEN `CnIndexPage` MUST show columns: displayName, email, roles, teams, lastLogin, isActive (badge)
    - AND inactive users MUST be visually distinguished (greyed-out row)

- [x] 9.2 Create `src/views/user/UserDetail.vue`
  - **files**: `src/views/user/UserDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a User detail renders
    - THEN tabs: Profile, Roles & Permissions, Delegations, Access History MUST be shown
    - AND Access History tab MUST display the last 50 AccessControl events for this user
    - AND "Grant Delegation" button on Delegations tab opens `DelegationDialog`

## 10. AccessControl Audit Log Views

- [x] 10.1 Create `src/views/accessControl/AccessControlIndex.vue`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-009`
  - **files**: `src/views/accessControl/AccessControlIndex.vue`
  - **acceptance_criteria**:
    - GIVEN the Access Log list renders
    - THEN `CnIndexPage` MUST show columns: timestamp, action, resourceType, resourceId, result (color-coded), ipAddress
    - AND NO create/edit/delete row actions MUST be present
    - AND filters MUST include: action, result, date range (from `filtersFromSchema`)

- [x] 10.2 Create `src/views/accessControl/AccessControlDetail.vue`
  - **files**: `src/views/accessControl/AccessControlDetail.vue`
  - **acceptance_criteria**:
    - GIVEN an audit event detail renders
    - THEN `CnDetailPage` MUST show all fields including `details` rendered as formatted JSON
    - AND NO edit or delete actions MUST be exposed

## 11. Delegation and Recertification Views

- [x] 11.1 Create `src/views/delegation/DelegationDialog.vue`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-007`
  - **files**: `src/views/delegation/DelegationDialog.vue`
  - **acceptance_criteria**:
    - GIVEN the dialog opens
    - THEN fields: grantee user (searchable dropdown), role, startDate, endDate, reason MUST be shown
    - AND saving with `endDate <= startDate` MUST show validation error "End date must be after start date"
    - AND successful save MUST call `DelegationController::create` and refresh the parent view

- [x] 11.2 Create `src/views/recertification/RecertificationIndex.vue` and `RecertificationReview.vue`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-008`
  - **files**: `src/views/recertification/RecertificationIndex.vue`, `src/views/recertification/RecertificationReview.vue`
  - **acceptance_criteria**:
    - GIVEN the recertification list renders
    - THEN `CnIndexPage` shows: name, cronExpression, lastRunAt, nextRunAt, isActive
    - GIVEN a review campaign is opened
    - THEN `RecertificationReview` MUST show a table of members with Confirm / Revoke buttons per row
    - AND submitting MUST send a batch decision payload to `RecertificationController`

## 12. Access Rights Report View

- [x] 12.1 Create `src/views/report/AccessRightsReport.vue`
  - **spec_ref**: `specs/access-control-authorisation/spec.md#REQ-AC-010`
  - **files**: `src/views/report/AccessRightsReport.vue`
  - **acceptance_criteria**:
    - GIVEN the report renders
    - THEN a table lists all active users with columns: username, displayName, roles, teams, lastLogin, branch, delegationsActive
    - AND users with `lastLogin` > 90 days ago MUST display an `NcBadge` "Inactive"
    - AND "Export CSV" MUST trigger `GET /api/v1/reports/access-rights?format=csv`
    - AND the page MUST be visible only to Admin-level users (non-admins see a 403 placeholder)

## 13. Navigation Update

- [x] 13.1 Update `src/navigation/MainMenu.vue` to add Security section
  - **files**: `src/navigation/MainMenu.vue`
  - **acceptance_criteria**:
    - GIVEN the sidebar renders
    - THEN a "Security" section MUST appear with items: Roles, Teams, Users, Access Log, Recertification, Reports
    - AND "Access Log" badge MUST show count of `denied` events in the last 24 hours

## 14. Router Update

- [x] 14.1 Add all new routes to `src/router/index.js`
  - **files**: `src/router/index.js`
  - **acceptance_criteria**:
    - Routes MUST be registered: `#/roles`, `#/roles/:id`, `#/teams`, `#/teams/:id`, `#/users`, `#/users/:id`, `#/access-log`, `#/access-log/:id`, `#/delegations`, `#/recertifications`, `#/recertifications/:id/review`, `#/reports/access-rights`
    - AND route meta MUST include breadcrumb arrays for all new routes

## 15. i18n

- [x] 15.1 Add English translations in `l10n/en.json` for all access control UI strings
  - **files**: `l10n/en.json`
  - **acceptance_criteria**:
    - All new labels, actions, error messages, notification subjects MUST be in `t('shillinq', '...')` calls
    - Keys: `Role`, `Team`, `User`, `Access Log`, `Delegation`, `Recertification`, `Access Rights Report`, `Deactivate`, `Invite Member`, `Grant Delegation`, `Confirm Access`, `Revoke Access`, `Export CSV`, `Inactive account`, `Purchase limit exceeded`, etc.

- [x] 15.2 Add Dutch translations in `l10n/nl.json`
  - **files**: `l10n/nl.json`
  - **acceptance_criteria**:
    - All keys from `en.json` MUST have Dutch equivalents
    - AND translation keys MUST match those in `en.json`

## 16. Unit Tests

- [x] 16.1 Add unit tests for `PermissionGateMiddleware.php`
  - **files**: `tests/Unit/Middleware/PermissionGateMiddlewareTest.php`
  - **acceptance_criteria**:
    - GIVEN a user with role level 40 accessing a level-60 endpoint THEN 403 is returned
    - GIVEN an inactive user THEN 403 is returned regardless of role
    - GIVEN a valid user at the required level THEN the request proceeds and an audit event is written

- [x] 16.2 Add unit tests for `FieldSecurityService.php`
  - **files**: `tests/Unit/Service/FieldSecurityServiceTest.php`
  - **acceptance_criteria**:
    - GIVEN a role with `canRead: false` for `bankAccountNumber` THEN that field is absent from `filterResponse` output
    - GIVEN an Admin role THEN no fields are stripped
    - GIVEN the permission matrix is loaded twice in one request THEN OpenRegister is queried only once (cache hit)

- [x] 16.3 Add unit tests for `PurchasingLimitService.php`
  - **files**: `tests/Unit/Service/PurchasingLimitServiceTest.php`
  - **acceptance_criteria**:
    - GIVEN a role limit of 5000 for "IT Equipment" and amount 8000 THEN `checkLimit` returns false
    - GIVEN a role limit of 5000 for "IT Equipment" and amount 3000 in "Office Supplies" THEN returns true
    - GIVEN an active delegation with higher limit THEN the higher limit applies

- [x] 16.4 Add unit tests for `DelegationExpiryJob.php`
  - **files**: `tests/Unit/BackgroundJob/DelegationExpiryJobTest.php`
  - **acceptance_criteria**:
    - GIVEN an `AccessRight` with `endDate` in the past THEN it is set to `isActive: false`
    - GIVEN an `AccessRight` with `endDate` in the future THEN it is unchanged
    - GIVEN expiry occurs THEN an `AccessControl` event with `action: "delegation-revoked"` is written
