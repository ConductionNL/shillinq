---
status: proposed
source: specter
features: [role-based-access-control, field-level-security, multi-user-access, team-management, permission-levels, access-recertification, access-rights-reporting, purchasing-limits, temporary-delegation, audit-logging]
---

# Access Control & Security — Shillinq

## Summary

Implements comprehensive access control and security infrastructure for Shillinq: role-based access control (RBAC) with field-level permission settings, multi-user team management with invitation workflows, five-tier permission levels from Admin to Reports-only, per-buyer purchasing limits by category and department, time-limited access delegation, access recertification scheduling, and a full audit log for all access events. This change delivers the security foundation that all domain modules depend on to enforce least-privilege access for freelancers, SMBs, and corporate finance teams.

## Demand Evidence

Top features by market demand score driving this change:

- **Role-based access control with field-level security** (demand: 1144) — highest-demand security feature; finance teams must restrict sensitive fields (bank account numbers, supplier payment terms) to authorised roles only.
- **Security & Access Controls** (demand: 974) — general security posture; organisations require auditable access events and centralised permission management.
- **Role-based purchasing guidelines with per-buyer limits** (demand: 718) — procurement teams need per-user and per-category spend limits enforced at approval time to prevent policy violations.
- **Multi-user access with 5 permission levels** (demand: 691) — SMBs need graduated access from full Admin to read-only Reports-only without building custom roles from scratch.
- **Multi-user access with team member invitation** (demand: 660) — team-based onboarding must allow administrators to invite members with a pre-assigned role to reduce provisioning time.
- **Multi-user access with role-based permissions** (demand: 652) — general RBAC; users' capabilities must be determined by their assigned role, not individual permission grants.
- **Role-based access control with field-level permission settings** (demand: 597) — extends RBAC to individual schema properties; auditors must see amounts but not payment details.
- **Generate user access rights overview report** (demand: 573) — compliance teams need a printable access matrix showing which user holds which role and what they can access.
- **Schedule periodic access recertification** (demand: 363) — governance requirement; access rights must be reviewed and reconfirmed on a regular schedule to detect privilege creep.

Key stakeholder pain points addressed:

- **Group Controller**: currently cannot enforce per-department access boundaries; role-scoped access to organisational units resolves this directly.
- **Treasurer**: needs to audit who approved which payment and when; the AccessControl audit log with IP address and user-agent provides the full trail.
- **Customer**: external auditors and clients accessing the self-service portal require tightly scoped, read-only access — the internal-auditor role and time-limited delegation address this.

## What Shillinq Already Has

After the core and general changes:

- OpenRegister schemas for `Organization`, `AppSettings`, `Dashboard`, `DataJob`, `KpiWidget`, `AnalyticsReport`, `AutomationRule`, `ExpenseClaim`, `PortalToken`
- Entity list, detail, and form views using `CnIndexPage` / `CnDetailPage` / `CnFormDialog`
- Pinia stores via `createObjectStore`, sidebar navigation, breadcrumbs, notifications
- Nextcloud authentication (user identity is always the NC user)

### What Is Missing

- No Role schema or role-assignment UI
- No Team schema or team invitation workflow
- No field-level permission settings on any schema
- No per-buyer purchasing limit enforcement
- No time-limited access delegation
- No access recertification scheduling
- No AccessControl audit log schema or event capture
- No user access rights overview report
- No User schema (Shillinq users are NC users but have no Shillinq-specific profile object)

## Scope

### In Scope

1. **OpenRegister schema definitions** — `Role`, `Team`, `User`, and `AccessControl` schemas registered via `lib/Settings/shillinq_register.json`; all follow schema.org vocabulary annotations
2. **Role management** — CRUD views for `Role` objects with hierarchy level, `isActive` flag, and permission assignments; five built-in seed roles (Admin, Editor, Accountant, Viewer, Reports-only)
3. **Team management** — CRUD views for `Team` objects with member invitation (email-based), role assignment per team, and member list; team invitation dispatches a Nextcloud notification to the invitee
4. **User profile** — `User` view at `src/views/user/` showing Shillinq-specific user attributes, assigned roles, team memberships, branch assignment, and last login; maps to the Nextcloud user account for authentication
5. **Five permission levels** — built-in roles Admin (level 100), Editor (level 80), Accountant (level 60), Viewer (level 40), Reports-only (level 20) enforced in the PHP middleware for all OCS API endpoints
6. **Field-level security** — `Permission` objects linked to a `Role` define which schema properties are readable/writeable for that role; `FieldSecurityService` filters OpenRegister object responses to strip fields the requesting user's role cannot read
7. **Per-buyer purchasing limits** — `Role` objects include `purchasingLimitAmount` and `purchasingLimitCategory` properties; `PurchasingLimitService` checks the limit before any purchase order approval action completes
8. **Time-limited access delegation** — admin can create a `AccessRight` delegation record with `startDate`, `endDate`, and a delegated role; a background job (`DelegationExpiryJob`) revokes expired delegations automatically
9. **Access recertification scheduling** — `AccessRecertification` schema stores recertification campaigns; a background job sends recertification request notifications to role-owners on the configured schedule
10. **AccessControl audit log** — every login, logout, create, read, update, delete, and permission-denied event writes an `AccessControl` object with actor, resource, result, IP, and user-agent; the list view supports filtering by user, action type, result, and date range
11. **User access rights report** — `lib/Controller/ReportController.php` endpoint that generates a CSV/HTML access matrix listing every active user, their roles, teams, and effective permissions; accessible only to Admin-level users
12. **Seed data** — five built-in `Role` objects, one `Team` ("Administrators"), one `User` (admin), and three `AccessControl` sample events loaded via the repair step

### Out of Scope

- OAuth / SAML / SCIM integration — Nextcloud handles external identity providers
- Row-level security below organisational-unit scope — deferred
- Real-time WebSocket session invalidation — polling-based delegation expiry is sufficient for v1
- Password policy enforcement — delegated to Nextcloud
- Two-factor authentication — handled by Nextcloud

## Acceptance Criteria

1. GIVEN a new user account exists WHEN an admin assigns the `contract-manager` role THEN the user can access contract features but not system configuration
2. GIVEN an HR onboarding event arrives via the integration API WHEN it contains a valid employee ID and assigned role THEN a User object is provisioned with the mapped role and a Nextcloud notification is sent to the administrator
3. GIVEN a user is assigned to organisational unit 'Gemeente Amsterdam – Sociale Zaken' WHEN they navigate to the contract list THEN only contracts tagged with that organisational unit are visible
4. GIVEN a delegation is created with start and end dates WHEN the end date passes THEN the DelegationExpiryJob revokes the delegation and the substitute loses the delegated permissions automatically
5. GIVEN an internal-auditor role is assigned scoped to FY2024 contracts WHEN the auditor opens a contract THEN all edit, delete, and approval actions are disabled
6. GIVEN a buyer role has a purchasing limit of EUR 5 000 per category 'IT Equipment' WHEN they attempt to approve a purchase order for EUR 8 000 THEN the approval is blocked with a policy violation message
7. GIVEN an admin opens the user access rights report WHEN the report is generated THEN a CSV is downloaded listing every active user with their roles, teams, and effective permissions
8. GIVEN an access recertification campaign is scheduled monthly WHEN the background job fires THEN role-owners receive a Nextcloud notification with a link to review and confirm their team members' access
9. GIVEN any API call is made to a Shillinq OCS endpoint WHEN the call completes THEN an AccessControl event is written with action, resourceType, result, ipAddress, and timestamp
10. GIVEN a user with Viewer role attempts to read a field marked restricted for their role WHEN the response is assembled THEN that field is absent from the returned object

## Risks and Dependencies

- **Core and general changes must be merged first**: `Organization`, `AppSettings`, and `ApprovalWorkflow` entities must exist before purchasing limits and delegation can reference them.
- **Nextcloud user directory**: `User` objects reference NC user IDs; the `IUserManager` service must be available for user lookup during provisioning.
- **Field-level security performance**: Stripping fields server-side on every OpenRegister response adds latency; the `FieldSecurityService` must cache the role-permission matrix per request lifecycle.
- **DelegationExpiryJob timing**: Nextcloud background jobs run at most once per cron cycle (default 5 min); delegation expiry may be up to 5 minutes late — this is acceptable for v1.
- **AccessControl log volume**: High-frequency deployments may generate thousands of audit events per day; the list view must use server-side pagination and the schema must be designed for archival.
