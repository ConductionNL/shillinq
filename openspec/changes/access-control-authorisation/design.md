# Design: Access Control & Security — Shillinq

## Architecture Overview

This change layers the access control and security subsystem on top of the core and general infrastructure. All entities follow the OpenRegister thin-client pattern. The PHP backend introduces middleware (permission gate, field-security service, purchasing limit service) that intercepts all OCS API calls. Background jobs handle delegation expiry and access recertification. The Vue 2.7 + Pinia frontend handles all CRUD rendering via the standard `CnIndexPage` / `CnDetailPage` / `CnFormDialog` pattern.

```
Browser (Vue 2.7 + Pinia)
    │
    ├─ OpenRegister REST API  (Role, Team, User, AccessControl CRUD)
    │
    └─ Shillinq OCS API
            ├─ RoleController
            ├─ TeamController
            ├─ UserController
            ├─ AccessControlController  (audit log query)
            ├─ DelegationController     (AccessRight create/revoke)
            ├─ RecertificationController
            └─ ReportController         (access rights CSV)
                    │
                    └─ PHP Services
                            ├─ PermissionGateMiddleware
                            ├─ FieldSecurityService
                            ├─ PurchasingLimitService
                            ├─ DelegationService
                            ├─ RecertificationService
                            └─ AuditLogService
                                    │
                                    └─ OpenRegister ObjectService
```

## Data Model

### Role (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| name | string | Yes | — | e.g. Admin, Editor, Accountant, Viewer, Reports-only |
| description | string | No | — | Role description and responsibilities |
| level | integer | No | 0 | Hierarchy: 100=Admin, 80=Editor, 60=Accountant, 40=Viewer, 20=Reports-only |
| isActive | boolean | Yes | true | False = cannot be assigned |
| purchasingLimitAmount | number | No | — | Max purchase order amount for this role |
| purchasingLimitCategory | string | No | — | Category the limit applies to; null = all categories |

### Team (`schema:Organization`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| name | string | Yes | — | Team name |
| description | string | No | — | |
| createdAt | datetime | Yes | — | Team creation timestamp |

### User (`schema:Person`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| email | string | Yes | — | User email for notifications |
| username | string | Yes | — | Nextcloud username; primary reference key |
| displayName | string | Yes | — | Display name |
| isActive | boolean | Yes | true | False = all API calls return 403 |
| createdAt | datetime | Yes | — | Account creation timestamp |
| lastLogin | datetime | No | — | Last login timestamp |
| branch | string | No | — | Organisational unit / branch assignment |

### AccessControl (`schema:Event`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| action | string | Yes | — | create, read, update, delete, login, logout, permission-denied, delegation-created, delegation-revoked |
| resourceType | string | Yes | — | Schema name of accessed resource |
| resourceId | string | No | — | OpenRegister object ID |
| timestamp | datetime | Yes | — | ISO 8601 event time |
| result | string | Yes | — | success, denied, error |
| ipAddress | string | No | — | Remote IP of request |
| userAgent | string | No | — | HTTP User-Agent header |
| details | object | No | — | Additional context (e.g., blocked field names) |

Relations: → User (actor), → AccessRight (if delegation-related)

### AccessRight (additional entity)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| userId | string | Yes | — | Grantee User object ID |
| roleId | string | Yes | — | Delegated Role object ID |
| grantedBy | string | Yes | — | Admin User object ID |
| startDate | datetime | Yes | — | Delegation start |
| endDate | datetime | Yes | — | Delegation end; job revokes after this |
| isActive | boolean | Yes | true | Set false by DelegationExpiryJob |
| reason | string | No | — | Business justification |

### AccessRecertification (additional entity)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| name | string | Yes | — | Campaign name |
| cronExpression | string | Yes | — | Cron schedule, e.g. `0 9 1 * *` |
| isActive | boolean | Yes | true | |
| lastRunAt | datetime | No | — | |
| nextRunAt | datetime | No | — | Computed from cron |
| reviewDeadlineDays | integer | No | 14 | Days after notification before auto-suspend |

### Permission (additional entity)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| roleId | string | Yes | — | Role object ID |
| schemaName | string | Yes | — | Target OpenRegister schema name |
| fieldName | string | Yes | — | Schema property name |
| canRead | boolean | Yes | true | |
| canWrite | boolean | Yes | true | |

## OpenRegister Register Definition

All schemas are added to `lib/Settings/shillinq_register.json` alongside the existing schemas.

```json
{
  "Role": {
    "x-schema-org": "schema:Thing",
    "required": ["name", "isActive"],
    "properties": {
      "name": { "type": "string" },
      "description": { "type": "string" },
      "level": { "type": "integer", "default": 0 },
      "isActive": { "type": "boolean", "default": true },
      "purchasingLimitAmount": { "type": "number" },
      "purchasingLimitCategory": { "type": "string" }
    }
  },
  "Team": {
    "x-schema-org": "schema:Organization",
    "required": ["name", "createdAt"],
    "properties": {
      "name": { "type": "string" },
      "description": { "type": "string" },
      "createdAt": { "type": "string", "format": "date-time" }
    }
  },
  "User": {
    "x-schema-org": "schema:Person",
    "required": ["email", "username", "displayName", "isActive", "createdAt"],
    "properties": {
      "email": { "type": "string", "format": "email" },
      "username": { "type": "string" },
      "displayName": { "type": "string" },
      "isActive": { "type": "boolean", "default": true },
      "createdAt": { "type": "string", "format": "date-time" },
      "lastLogin": { "type": "string", "format": "date-time" },
      "branch": { "type": "string" }
    }
  },
  "AccessControl": {
    "x-schema-org": "schema:Event",
    "required": ["action", "resourceType", "timestamp", "result"],
    "properties": {
      "action": {
        "type": "string",
        "enum": ["create","read","update","delete","login","logout","permission-denied","delegation-created","delegation-revoked"]
      },
      "resourceType": { "type": "string" },
      "resourceId": { "type": "string" },
      "timestamp": { "type": "string", "format": "date-time" },
      "result": { "type": "string", "enum": ["success","denied","error"] },
      "ipAddress": { "type": "string" },
      "userAgent": { "type": "string" },
      "details": { "type": "object" }
    }
  }
}
```

## Backend Components

### `lib/Middleware/PermissionGateMiddleware.php`
Implements `OCP\AppFramework\Middleware`. On every Shillinq OCS request:
1. Resolves the requesting Nextcloud user to a `User` object (cached).
2. Checks `isActive`; returns 403 if false.
3. Evaluates the required minimum role level for the endpoint (defined via route annotation `@RequiresRoleLevel(60)`).
4. Writes an `AccessControl` audit event via `AuditLogService` regardless of outcome.

### `lib/Service/FieldSecurityService.php`
- `filterResponse(array $object, string $schemaName, string $userId): array`
- Loads `Permission` objects for the user's role(s) from OpenRegister (cached per request in the DI container).
- Strips fields where `canRead: false` from the response array.
- Blocks writes on fields where `canWrite: false` — returns 422 with the restricted field name.

### `lib/Service/PurchasingLimitService.php`
- `checkLimit(string $userId, float $amount, string $category): bool`
- Resolves the user's effective roles (base + active delegations).
- Returns false (blocked) if any matching `purchasingLimitCategory` has `purchasingLimitAmount < amount`.
- Called from `PurchaseOrderController` before any approval action.

### `lib/Service/DelegationService.php`
- `createDelegation(string $userId, string $roleId, string $grantedBy, \DateTime $start, \DateTime $end, string $reason): AccessRight`
- Validates `end > start`; creates `AccessRight` object in OpenRegister.
- Dispatches Nextcloud notification to grantee and admin.

### `lib/Service/AuditLogService.php`
- `log(string $action, string $resourceType, ?string $resourceId, string $result, ?array $details = null): void`
- Creates `AccessControl` object via `ObjectService`. Fire-and-forget; errors are logged to Nextcloud log but do not interrupt the primary request.

### `lib/BackgroundJob/DelegationExpiryJob.php`
Extends `OC\BackgroundJob\TimedJob` (runs every 5 minutes). Queries all `AccessRight` objects where `isActive: true` and `endDate < now`. Sets each to `isActive: false`, writes an audit event, and dispatches notifications.

### `lib/BackgroundJob/RecertificationNotificationJob.php`
Extends `OC\BackgroundJob\TimedJob` (runs hourly). Evaluates active `AccessRecertification` campaigns against their `cronExpression`. If due, dispatches review notifications to role-owners. Updates `lastRunAt` and `nextRunAt`.

### `lib/Controller/ReportController.php`
OCS controller. Route: `GET /apps/shillinq/api/v1/reports/access-rights?format=csv|html`. Requires role level 100 (Admin). Queries all active `User` objects, joins their roles and teams via OpenRegister relations, and streams the result.

### `lib/Controller/DelegationController.php`
- `POST /api/v1/delegations` — create delegation
- `DELETE /api/v1/delegations/{id}` — manual revoke

### `lib/Controller/RecertificationController.php`
- `GET /api/v1/recertifications` — list campaigns
- `POST /api/v1/recertifications/{id}/review` — submit review decisions (confirm/revoke array)

## Frontend Components

### Directory Structure

```
src/
  views/
    role/
      RoleIndex.vue           # CnIndexPage list
      RoleDetail.vue          # CnDetailPage detail
    team/
      TeamIndex.vue           # CnIndexPage list
      TeamDetail.vue          # CnDetailPage with Members tab
      TeamInviteDialog.vue    # Invite member form
    user/
      UserIndex.vue           # CnIndexPage list
      UserDetail.vue          # CnDetailPage with Roles/Delegations/History tabs
    accessControl/
      AccessControlIndex.vue  # CnIndexPage audit log (read-only)
      AccessControlDetail.vue # CnDetailPage event detail
    delegation/
      DelegationDialog.vue    # Create/revoke delegation form
    recertification/
      RecertificationIndex.vue
      RecertificationReview.vue   # Confirm/revoke members per campaign
    report/
      AccessRightsReport.vue  # Access matrix table + CSV export
  store/
    modules/
      role.js                 # createObjectStore('role')
      team.js                 # createObjectStore('team')
      user.js                 # createObjectStore('user')
      accessControl.js        # createObjectStore('accessControl')
      delegation.js           # createObjectStore('accessRight')
      recertification.js      # createObjectStore('accessRecertification')
```

### Store Pattern

```js
// src/store/modules/role.js
import { createObjectStore } from '@conduction/nextcloud-vue'
export const useRoleStore = createObjectStore('role', {
  register: 'shillinq',
  schema: 'role',
})
```

All six stores follow the same pattern.

### Key View Patterns

**RoleIndex.vue** — `CnIndexPage` with `columnsFromSchema('role')`, `filtersFromSchema('role')`. Row actions: View, Edit, Deactivate (not Delete if users assigned). "Add Role" button opens `CnFormDialog` with `fieldsFromSchema('role')`.

**TeamDetail.vue** — `CnDetailPage` with two tabs: Details and Members. Members tab lists associated `User` objects with role badge and an "Invite Member" button that opens `TeamInviteDialog.vue`.

**UserDetail.vue** — `CnDetailPage` with tabs: Profile, Roles & Permissions, Delegations, Access History. The Access History tab shows the last 50 `AccessControl` events for this user, filtered by `userId`.

**AccessControlIndex.vue** — read-only `CnIndexPage`. No create/edit/delete actions exposed. Filters: user, action, result, date range. Pagination 50 per page.

**RecertificationReview.vue** — custom page (not `CnDetailPage`) showing a table of team members with Confirm / Revoke toggle buttons per row. Submits decisions in a single batch call to `RecertificationController`.

**AccessRightsReport.vue** — table rendered from `ReportController` response. "Export CSV" button calls `GET /api/v1/reports/access-rights?format=csv`. Rows with `lastLogin` > 90 days ago display an `NcBadge` with text "Inactive".

## Seed Data

Location: `lib/Repair/CreateDefaultConfiguration.php` — called from the existing repair step.

```php
// Five built-in roles — keyed on 'name' for idempotency
$roles = [
  ['name' => 'Admin',        'level' => 100, 'description' => 'Full system access'],
  ['name' => 'Editor',       'level' => 80,  'description' => 'Create and edit all entities'],
  ['name' => 'Accountant',   'level' => 60,  'description' => 'Financial data read/write'],
  ['name' => 'Viewer',       'level' => 40,  'description' => 'Read-only access to all entities'],
  ['name' => 'Reports-only', 'level' => 20,  'description' => 'Export and view reports only'],
];
foreach ($roles as $role) {
    $this->seedObject('role', 'name', $role['name'], array_merge($role, ['isActive' => true]));
}

// Default admin team
$this->seedObject('team', 'name', 'Administrators', [
    'name'        => 'Administrators',
    'description' => 'System administrators with full access',
    'createdAt'   => '2026-01-01T00:00:00Z',
]);

// Sample AccessControl events
$this->seedObject('accessControl', 'resourceId', 'seed-login-001', [
    'action'       => 'login',
    'resourceType' => 'session',
    'resourceId'   => 'seed-login-001',
    'timestamp'    => '2026-01-01T09:00:00Z',
    'result'       => 'success',
    'ipAddress'    => '127.0.0.1',
]);
```

## Affected Files

**PHP — New**
- `lib/Middleware/PermissionGateMiddleware.php`
- `lib/Service/FieldSecurityService.php`
- `lib/Service/PurchasingLimitService.php`
- `lib/Service/DelegationService.php`
- `lib/Service/AuditLogService.php`
- `lib/Service/RecertificationService.php`
- `lib/Controller/RoleController.php`
- `lib/Controller/TeamController.php`
- `lib/Controller/UserController.php`
- `lib/Controller/AccessControlController.php`
- `lib/Controller/DelegationController.php`
- `lib/Controller/RecertificationController.php`
- `lib/Controller/ReportController.php`
- `lib/BackgroundJob/DelegationExpiryJob.php`
- `lib/BackgroundJob/RecertificationNotificationJob.php`

**PHP — Modified**
- `lib/Settings/shillinq_register.json` — add Role, Team, User, AccessControl, AccessRight, AccessRecertification, Permission schemas
- `lib/Repair/CreateDefaultConfiguration.php` — add seed data
- `appinfo/routes.php` — register new routes
- `lib/AppInfo/Application.php` — register middleware, background jobs, controllers

**Vue / JS — New**
- `src/views/role/RoleIndex.vue`
- `src/views/role/RoleDetail.vue`
- `src/views/team/TeamIndex.vue`
- `src/views/team/TeamDetail.vue`
- `src/views/team/TeamInviteDialog.vue`
- `src/views/user/UserIndex.vue`
- `src/views/user/UserDetail.vue`
- `src/views/accessControl/AccessControlIndex.vue`
- `src/views/accessControl/AccessControlDetail.vue`
- `src/views/delegation/DelegationDialog.vue`
- `src/views/recertification/RecertificationIndex.vue`
- `src/views/recertification/RecertificationReview.vue`
- `src/views/report/AccessRightsReport.vue`
- `src/store/modules/role.js`
- `src/store/modules/team.js`
- `src/store/modules/user.js`
- `src/store/modules/accessControl.js`
- `src/store/modules/delegation.js`
- `src/store/modules/recertification.js`

**Vue / JS — Modified**
- `src/navigation/MainMenu.vue` — add Security section with Role, Team, Users, Access Log, Reports nav items
- `src/store/store.js` — register six new stores
- `src/router/index.js` — add routes for all new views
