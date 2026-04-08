---
status: proposed
---

# Access Control & Security — Shillinq

## Purpose

Defines the functional requirements for Shillinq's role-based access control, team management, field-level security, per-buyer purchasing limits, time-limited delegation, access recertification, and audit logging. This spec establishes the security foundation that all domain modules depend on to enforce least-privilege access.

Stakeholders: Group Controller, Treasurer, Customer (external auditor persona).

User stories addressed: Assign role-based access to new user, Provision access from HR onboarding event, Restrict access to specific contracting units, Grant temporary access for substitute, Provision read-only access for auditor.

## Requirements

### REQ-AC-001: OpenRegister Schema Registration [must]

The app MUST register `Role`, `Team`, `User`, and `AccessControl` schemas in OpenRegister via `lib/Settings/shillinq_register.json`. Each schema MUST use a `schema.org` vocabulary annotation and include all properties from the data model.

**Scenarios:**

1. **GIVEN** Shillinq's repair step runs **WHEN** it completes **THEN** schemas `Role`, `Team`, `User`, and `AccessControl` exist in the `shillinq` OpenRegister register with all specified properties and types.

2. **GIVEN** the repair step has already run **WHEN** it runs again **THEN** existing schemas are updated (not duplicated) and no existing access data is lost.

3. **GIVEN** a `Role` is created without the required `name` property **WHEN** the request is submitted to OpenRegister **THEN** a 422 validation error is returned specifying the missing field.

4. **GIVEN** an `AccessControl` event object is created **WHEN** it is retrieved via the OpenRegister API **THEN** the `timestamp` field is returned as an ISO 8601 string and `result` is one of `["success","denied","error"]`.

### REQ-AC-002: Role Management with Five Permission Levels [must]

The app MUST provide a `Role` entity with a `level` hierarchy property and CRUD views. Five built-in roles MUST be seeded on install: Admin (100), Editor (80), Accountant (60), Viewer (40), Reports-only (20). All OCS API endpoints MUST enforce the requesting user's role level as a minimum permission gate.

**Scenarios:**

1. **GIVEN** a user with role level 40 (Viewer) calls the `POST /api/v1/invoices` endpoint **WHEN** the request is processed **THEN** the server returns 403 Forbidden because the Viewer role level is below the required minimum for write operations.

2. **GIVEN** an admin assigns the `contract-manager` role to a new user **WHEN** the user logs in and navigates to contracts **THEN** they can create, edit, and view contracts but cannot access system configuration pages (user story: Assign role-based access to new user).

3. **GIVEN** five built-in roles are seeded **WHEN** an admin views the Role list **THEN** all five roles appear with their correct level values and `isActive: true`.

4. **GIVEN** an admin deactivates the `Viewer` role by setting `isActive: false` **WHEN** any user currently assigned that role calls a Shillinq endpoint **THEN** the server returns 403 and a notification is sent to the admin to reassign the affected users.

5. **GIVEN** a role with `level: 80` (Editor) **WHEN** an admin attempts to delete it while users are assigned to it **THEN** the delete is blocked with a validation error listing the affected users.

### REQ-AC-003: Team Management with Member Invitation [must]

The app MUST provide a `Team` entity with member invite capability. Inviting a team member MUST dispatch a Nextcloud notification to the invitee and assign the team's default role. The team list MUST show member counts.

**Scenarios:**

1. **GIVEN** an admin creates a team "Finance Department" and invites user `j.doe@example.com` **WHEN** the invitation is sent **THEN** a Nextcloud notification is dispatched to `j.doe`, a `User` object is created (or updated) with the team association, and the team member count increments by one.

2. **GIVEN** a team has a default role of `Accountant` **WHEN** a new member is added to the team **THEN** the member's `Role` is set to `Accountant` unless overridden during the invitation flow.

3. **GIVEN** a team list is displayed **WHEN** it renders **THEN** columns show: team name, description, member count, creation date, and a "Manage Members" row action.

4. **GIVEN** a member is removed from a team **WHEN** they have no other team membership **THEN** their role defaults to `Viewer` and an admin receives a Nextcloud notification about the orphaned user.

### REQ-AC-004: User Profile with Role and Branch Assignment [must]

The app MUST provide a `User` entity view showing Shillinq-specific attributes: assigned roles, team memberships, branch assignment, active delegations, and last login. The `User` object MUST reference the Nextcloud user by `username`.

**Scenarios:**

1. **GIVEN** an HR onboarding event is received via the integration API with a valid `employeeId` and `roleName` **WHEN** the event is processed **THEN** a `User` object is created with `isActive: true`, the mapped role, and a confirmation notification is sent to the admin (user story: Provision access from HR onboarding event).

2. **GIVEN** a user is assigned to branch `Gemeente Amsterdam – Sociale Zaken` **WHEN** they navigate to the contracts list **THEN** the list is automatically pre-filtered to show only contracts tagged with that organisational unit (user story: Restrict access to specific contracting units).

3. **GIVEN** an admin views the User detail page **WHEN** it loads **THEN** sections show: Profile, Assigned Roles, Team Memberships, Active Delegations, and Access History (last 10 AccessControl events for the user).

4. **GIVEN** a user's `isActive` is set to `false` **WHEN** that user makes any Shillinq API call **THEN** the server returns 403 Forbidden immediately without processing the request.

### REQ-AC-005: Field-Level Security [must]

The app MUST support `Permission` objects that define per-schema, per-property read/write capability for a given `Role`. The `FieldSecurityService` MUST strip restricted fields from OpenRegister responses before they reach the client.

**Scenarios:**

1. **GIVEN** a `Permission` object defines `bankAccountNumber` as write-restricted for the `Viewer` role on the `Supplier` schema **WHEN** a Viewer retrieves a Supplier object **THEN** the `bankAccountNumber` field is absent from the response body.

2. **GIVEN** a `Permission` object defines `totalAmount` as read-restricted for the `Reports-only` role **WHEN** a Reports-only user calls the invoice list endpoint **THEN** none of the returned invoice objects contain `totalAmount`.

3. **GIVEN** an Admin role has no field restrictions **WHEN** an Admin retrieves any object **THEN** all fields are present in the response, including those restricted for lower-level roles.

4. **GIVEN** the `FieldSecurityService` processes a response **WHEN** the role-permission matrix is evaluated **THEN** the matrix is cached per request lifecycle using the Nextcloud DI container to avoid redundant OpenRegister queries.

5. **GIVEN** a field-level permission is updated by an admin **WHEN** the change is saved **THEN** the permission cache is invalidated and the new restriction takes effect on the next API call.

### REQ-AC-006: Per-Buyer Purchasing Limits [must]

The app MUST enforce per-buyer purchasing limits defined on `Role` objects (`purchasingLimitAmount`, `purchasingLimitCategory`). Approval of any purchase order exceeding the buyer's limit for the given category MUST be blocked with a policy violation response.

**Scenarios:**

1. **GIVEN** a buyer role has `purchasingLimitAmount: 5000` and `purchasingLimitCategory: "IT Equipment"` **WHEN** the buyer attempts to approve a purchase order for EUR 8 000 in category "IT Equipment" **THEN** the approval endpoint returns 422 with message "Purchase amount 8 000 exceeds your authorised limit of 5 000 for category IT Equipment".

2. **GIVEN** a buyer role limit is EUR 5 000 for "IT Equipment" **WHEN** the buyer approves a purchase order for EUR 3 000 in "Office Supplies" **THEN** the approval succeeds because the category does not match the limit restriction.

3. **GIVEN** a buyer has been granted a temporary role elevation via delegation **WHEN** the elevated limit applies **THEN** the `PurchasingLimitService` uses the highest applicable limit across active roles and delegations.

4. **GIVEN** an admin sets `purchasingLimitAmount: 0` on a role **WHEN** any user with that role attempts to approve any purchase order **THEN** all approvals are blocked regardless of amount.

### REQ-AC-007: Time-Limited Access Delegation [must]

The app MUST allow admins to create a time-limited delegation granting a substitute user additional permissions for a defined period. An `AccessRight` delegation record stores the period and delegated role. A background job (`DelegationExpiryJob`) MUST automatically revoke expired delegations.

**Scenarios:**

1. **GIVEN** a contract manager is on leave **WHEN** an admin creates a delegation for substitute user with `startDate: 2026-05-01` and `endDate: 2026-05-15` **THEN** the substitute gains the delegated role's permissions from 2026-05-01 and loses them automatically after 2026-05-15 (user story: Grant temporary access for substitute).

2. **GIVEN** a delegation has `endDate` in the past **WHEN** `DelegationExpiryJob` runs **THEN** the `AccessRight` record is marked `isActive: false`, the substitute's effective permissions revert, and both the substitute and the admin receive a Nextcloud notification.

3. **GIVEN** a user has an active delegation **WHEN** they make an API call within the delegation period **THEN** the `FieldSecurityService` and permission gate use the union of their base role and the delegated role.

4. **GIVEN** an admin attempts to create a delegation where `endDate` is before `startDate` **WHEN** the form is submitted **THEN** a validation error "End date must be after start date" is returned and no record is created.

### REQ-AC-008: Access Recertification Scheduling [must]

The app MUST provide an `AccessRecertification` schema for scheduling periodic access reviews. A background job MUST send recertification request notifications to role-owners on the configured schedule. Role-owners MUST be able to confirm or revoke access for each team member.

**Scenarios:**

1. **GIVEN** an `AccessRecertification` campaign is scheduled with `cronExpression: "0 9 1 * *"` (monthly) **WHEN** the background job fires on the 1st of the month **THEN** all role-owners receive a Nextcloud notification with a link to the recertification review page.

2. **GIVEN** a role-owner opens the recertification review page **WHEN** it loads **THEN** a list of their team members with current roles and last-login dates is displayed; each row has "Confirm" and "Revoke" actions.

3. **GIVEN** a role-owner clicks "Revoke" for a team member **WHEN** the action is confirmed **THEN** the `User` object's `isActive` is set to `false`, an `AccessControl` event is written, and the team member receives a Nextcloud notification.

4. **GIVEN** a recertification campaign deadline passes with unreviewed members **WHEN** the follow-up job runs **THEN** unreviewed users with `lastLogin` older than 90 days have their access automatically suspended and the admin is notified.

### REQ-AC-009: AccessControl Audit Log [must]

The app MUST write an `AccessControl` object for every security-relevant event: login, logout, create, read, update, delete, permission-denied, delegation-created, delegation-revoked. The audit log MUST be queryable by user, action type, result, and date range with server-side pagination.

**Scenarios:**

1. **GIVEN** any OCS API call is made **WHEN** the call completes (success or denial) **THEN** an `AccessControl` event is written with `action`, `resourceType`, `resourceId`, `result`, `ipAddress`, `userAgent`, and `timestamp`.

2. **GIVEN** an admin opens the Access Log list view **WHEN** they filter by `result: "denied"` and a date range **THEN** only denied access events within that range are returned, paginated 50 per page.

3. **GIVEN** an `AccessControl` record is written **WHEN** an admin attempts to delete it **THEN** the deletion is rejected with 403 — audit records are immutable.

4. **GIVEN** a user logs in to Nextcloud and opens Shillinq **WHEN** the app initialises **THEN** a login event with `action: "login"` and `result: "success"` is written to the audit log.

5. **GIVEN** a permission-denied event is logged **WHEN** the same user is denied three times within 60 seconds **THEN** an admin alert notification is dispatched for potential brute-force detection.

### REQ-AC-010: User Access Rights Report [must]

The app MUST provide an access rights overview report accessible only to Admin-level users. The report MUST list every active user with their roles, teams, effective field-level permissions, and last login. It MUST be exportable as CSV.

**Scenarios:**

1. **GIVEN** an admin navigates to Reports > Access Rights **WHEN** the page loads **THEN** a table lists all active users with columns: username, displayName, roles, teams, lastLogin, branch, delegationsActive.

2. **GIVEN** an admin clicks "Export CSV" **WHEN** the export runs **THEN** a CSV file is downloaded where each row is a user and columns match the on-screen table (user story: Generate user access rights overview report).

3. **GIVEN** a non-Admin user attempts to access the `/api/v1/reports/access-rights` endpoint **WHEN** the request is evaluated **THEN** the server returns 403 Forbidden.

4. **GIVEN** the report is viewed **WHEN** a user's `lastLogin` is more than 90 days ago **THEN** that row is highlighted in the UI with an "Inactive account" warning badge.
