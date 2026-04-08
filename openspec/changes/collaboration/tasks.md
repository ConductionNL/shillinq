# Tasks: collaboration

## 1. OpenRegister Schema Definitions

- [ ] 1.1 Add `Comment` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN `shillinq_register.json` is processed by OpenRegister
    - THEN schema `Comment` MUST be registered with properties: `content` (string, required), `author` (string, required), `targetType` (string, required), `targetId` (string, required), `timestamp` (datetime, required), `mentions` (array), `resolved` (boolean, default false), `resolvedBy` (string), `resolvedAt` (datetime), `editedAt` (datetime), `portalTokenId` (string)
    - AND `x-schema-org` annotation MUST be `schema:Comment`
    - AND `resolved` MUST default to `false`

- [ ] 1.2 Add `CollaborationRole` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-005`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is registered
    - THEN properties `targetType` (required), `targetId` (required), `principalType` (required, enum: user/group), `principalId` (required), `role` (required, enum: viewer/contributor/reviewer/approver), `grantedBy` (required), `grantedAt` (datetime, required), `expiresAt` (datetime) MUST exist
    - AND `x-schema-org` annotation MUST be `schema:Role`

- [ ] 1.3 Add `PresenceRecord` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-006`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is registered
    - THEN properties `userId` (string, required), `targetType` (string, required), `targetId` (string, required), `lastSeenAt` (datetime, required), `isEditing` (boolean, default false) MUST exist
    - AND `x-schema-org` annotation MUST be `schema:Thing`

## 2. Seed Data

- [ ] 2.1 Add `Comment` seed object to `lib/Repair/CreateDefaultConfiguration.php`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN the repair step runs on a fresh install
    - THEN 1 `Comment` object MUST be created with `content: "Please review the line items before approval."`, `author: "admin"`, `targetType: "Invoice"`, `targetId: "demo-invoice-001"`, `resolved: false`
    - AND idempotency check MUST use `content` + `targetId` combination
    - AND re-running MUST NOT create duplicates

- [ ] 2.2 Add `CollaborationRole` seed object to `lib/Repair/CreateDefaultConfiguration.php`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN a fresh install
    - THEN 1 `CollaborationRole` object MUST be seeded: `principalId: "admin"`, `targetType: "Invoice"`, `targetId: "demo-invoice-001"`, `role: "approver"`, `grantedBy: "admin"`
    - AND idempotency check MUST use `principalId` + `targetId` + `role` combination

## 3. Pinia Stores

- [ ] 3.1 Create `src/store/modules/comment.js`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-002`
  - **files**: `src/store/modules/comment.js`
  - **acceptance_criteria**:
    - GIVEN the store is initialized
    - THEN `useCommentStore` MUST be created via `createObjectStore('Comment')`
    - AND the store MUST be registered in `src/store/store.js`
    - AND the store MUST expose a `byTarget(targetType, targetId)` computed returning comments filtered by target

- [ ] 3.2 Create `src/store/modules/collaborationRole.js`
  - **files**: `src/store/modules/collaborationRole.js`
  - **acceptance_criteria**:
    - GIVEN the store is initialized
    - THEN `useCollaborationRoleStore` MUST be created via `createObjectStore('CollaborationRole')`
    - AND the store MUST expose a `rolesForTarget(targetType, targetId)` computed

- [ ] 3.3 Create `src/store/modules/presence.js`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-006`
  - **files**: `src/store/modules/presence.js`
  - **acceptance_criteria**:
    - GIVEN the store is initialized
    - THEN `usePresenceStore` MUST be created via `createObjectStore('PresenceRecord')`
    - AND the store MUST export `startHeartbeat(targetType, targetId)` and `stopHeartbeat()` functions
    - AND `startHeartbeat` MUST call `POST /api/v1/presence/ping` every 30 seconds

## 4. Comment Views

- [ ] 4.1 Implement `src/views/comment/CommentList.vue`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-002`
  - **files**: `src/views/comment/CommentList.vue`
  - **acceptance_criteria**:
    - GIVEN the admin comment list renders THEN `CnIndexPage` MUST list comments with columns: author, targetType, targetId, timestamp, resolved
    - AND `filtersFromSchema('Comment')` MUST generate filter chips including `targetType`, `resolved`, and `author`
    - AND clicking a row MUST navigate to `CommentDetail`

- [ ] 4.2 Implement `src/views/comment/CommentDetail.vue`
  - **files**: `src/views/comment/CommentDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the comment detail renders THEN `CnDetailPage` MUST show all properties including `mentions`, `resolved`, `resolvedBy`, `resolvedAt`
    - AND a DPO action button "Anonymise" MUST appear for users with the DPO role
    - AND clicking "Anonymise" MUST call the anonymise endpoint and refresh the view

## 5. CollaborationRole Views

- [ ] 5.1 Implement `src/views/collaborationRole/CollaborationRoleList.vue`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-005`
  - **files**: `src/views/collaborationRole/CollaborationRoleList.vue`
  - **acceptance_criteria**:
    - GIVEN the role list renders for a document THEN `CnIndexPage` MUST show: principalId, principalType, role (badge), grantedBy, grantedAt, expiresAt
    - AND expired roles (expiresAt in the past) MUST be shown with a warning style
    - AND an "Add Member" button MUST open `CollaborationRoleForm`

- [ ] 5.2 Implement `src/views/collaborationRole/CollaborationRoleForm.vue`
  - **files**: `src/views/collaborationRole/CollaborationRoleForm.vue`
  - **acceptance_criteria**:
    - GIVEN the form opens THEN fields MUST include: principalType (select: user/group), principalId (user search input), role (select: viewer/contributor/reviewer/approver), expiresAt (date picker, optional)
    - AND submitting MUST create a `CollaborationRole` object and notify the assigned user

## 6. Collaboration Panel Components

- [ ] 6.1 Implement `src/components/DocumentCollaborationPanel.vue`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-002`
  - **files**: `src/components/DocumentCollaborationPanel.vue`
  - **acceptance_criteria**:
    - GIVEN the panel mounts THEN it MUST accept `targetType` and `targetId` props
    - AND `startHeartbeat(targetType, targetId)` MUST be called on mount
    - AND `stopHeartbeat()` MUST be called on unmount
    - AND the panel MUST show two sections: "Comments" (with unresolved count badge) and "Team" (with role chips)
    - AND the panel MUST be embeddable in any `CnDetailPage` sidebar slot

- [ ] 6.2 Implement `src/components/CommentThread.vue`
  - **files**: `src/components/CommentThread.vue`
  - **acceptance_criteria**:
    - GIVEN unresolved comments exist THEN they MUST be listed chronologically with author avatar (NcAvatar), relative timestamp, and content
    - AND resolved comments MUST be shown as collapsed rows with "Resolved by X" label and a "Show" toggle
    - AND `@mention` tokens in content MUST be rendered as highlighted chips

- [ ] 6.3 Implement `src/components/CommentInput.vue` with `MentionAutocomplete.vue`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-003`
  - **files**: `src/components/CommentInput.vue`, `src/components/MentionAutocomplete.vue`
  - **acceptance_criteria**:
    - GIVEN the user types `@` followed by at least 1 character THEN `MentionAutocomplete` MUST appear with matching Nextcloud users
    - AND selecting a user MUST insert `@username` into the textarea and track the userId
    - AND pressing Ctrl+Enter MUST submit the comment
    - AND submitting with an `@nonexistentuser` MUST show a warning toast

- [ ] 6.4 Implement `src/components/PresenceStrip.vue`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-006`
  - **files**: `src/components/PresenceStrip.vue`
  - **acceptance_criteria**:
    - GIVEN the strip mounts THEN it MUST fetch presence records for `(targetType, targetId)` filtered to `lastSeenAt` within 120 seconds
    - AND each active user MUST be shown as an `NcAvatar` with a tooltip showing their display name
    - AND users with `isEditing: true` MUST have a pencil icon overlay on their avatar
    - AND the strip MUST re-fetch every 30 seconds

- [ ] 6.5 Implement `src/components/CollaborationRoleChip.vue`
  - **files**: `src/components/CollaborationRoleChip.vue`
  - **acceptance_criteria**:
    - GIVEN a `CollaborationRole` prop is passed THEN the chip MUST show `NcAvatar` + display name + role badge colour (blue: approver, green: reviewer, grey: contributor, light-grey: viewer)
    - AND an "X" remove button MUST appear for users with `approver` role on the document

## 7. Backend Services

- [ ] 7.1 Implement `lib/Service/MentionService.php`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-003`
  - **files**: `lib/Service/MentionService.php`
  - **acceptance_criteria**:
    - GIVEN `processMentions(string $content, string $targetType, string $targetId)` is called
    - THEN all `@username` patterns MUST be extracted via regex
    - AND each username MUST be resolved using `IUserManager::get()`; non-existent users are skipped
    - AND for each resolved user, `INotificationManager` MUST dispatch a notification with app `shillinq`, subject `comment_mention`, and object link to the target document
    - AND if `IMailer` is available and the user has email set, an email notification MUST be sent

- [ ] 7.2 Implement `lib/Service/CollaborationRoleService.php`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-005`
  - **files**: `lib/Service/CollaborationRoleService.php`
  - **acceptance_criteria**:
    - GIVEN `checkRole(string $userId, string $targetType, string $targetId, string $minimumRole)` is called
    - THEN the service MUST fetch all `CollaborationRole` objects for the target, filter to matching `principalId` (user) or group membership, check `expiresAt`, and return `true` only if the user's highest role meets or exceeds the minimum
    - AND role hierarchy MUST be: viewer(1) < contributor(2) < reviewer(3) < approver(4)
    - AND if no `CollaborationRole` exists, the service MUST fall back to `AccessControl` global permissions

- [ ] 7.3 Implement `lib/Service/PresenceService.php`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-006`
  - **files**: `lib/Service/PresenceService.php`
  - **acceptance_criteria**:
    - GIVEN `ping(string $userId, string $targetType, string $targetId, bool $isEditing)` is called
    - THEN the service MUST upsert the `PresenceRecord` using OpenRegister's filter-update (filter: userId + targetType + targetId) setting `lastSeenAt` to current timestamp
    - AND `getActiveViewers(string $targetType, string $targetId)` MUST return only records with `lastSeenAt` within 120 seconds

- [ ] 7.4 Implement `lib/Service/DocumentEventNotifier.php`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-007`
  - **files**: `lib/Service/DocumentEventNotifier.php`
  - **acceptance_criteria**:
    - GIVEN `notify(string $eventType, string $targetType, string $targetId, array $context)` is called
    - THEN all `CollaborationRole` objects with `role` in `["reviewer","approver"]` for the target MUST be fetched
    - AND each principal user MUST receive an `INotificationManager` notification with the event type and link
    - AND if `IMailer` is configured, an email MUST also be dispatched; if not configured, only in-app notifications are sent and no exception is thrown

## 8. Backend Controllers and Routes

- [ ] 8.1 Implement `lib/Controller/CommentController.php`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-002`
  - **files**: `lib/Controller/CommentController.php`
  - **acceptance_criteria**:
    - GIVEN `GET /api/v1/comments?targetType=Invoice&targetId=xxx` is called THEN all `Comment` objects for the target MUST be returned sorted by `timestamp` ascending
    - AND `POST /api/v1/comments` MUST check `CollaborationRoleService::checkRole(..., 'contributor')` before creating; return 403 if fails
    - AND after create, `MentionService::processMentions()` MUST be called
    - AND `PATCH /api/v1/comments/{id}/resolve` MUST check for `reviewer` role minimum; set `resolved`, `resolvedBy`, `resolvedAt`
    - AND `DELETE /api/v1/comments/{id}` MUST allow deletion only by author within 5 minutes or by DPO/admin

- [ ] 8.2 Implement `lib/Controller/CollaborationRoleController.php`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-005`
  - **files**: `lib/Controller/CollaborationRoleController.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/collaboration/roles` is called THEN the requester MUST hold `approver` role on the target; otherwise 403
    - AND a successful role grant MUST notify the grantee via `INotificationManager`
    - AND `DELETE /api/v1/collaboration/roles/{id}` MUST also require `approver` role

- [ ] 8.3 Implement `lib/Controller/PresenceController.php`
  - **spec_ref**: `specs/collaboration/spec.md#REQ-COL-006`
  - **files**: `lib/Controller/PresenceController.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/presence/ping` with body `{ targetType, targetId, isEditing }` THEN `PresenceService::ping()` MUST be called and return 200
    - AND `GET /api/v1/presence?targetType=Invoice&targetId=xxx` MUST return only records within the 120-second activity window

- [ ] 8.4 Register all new routes in `appinfo/routes.php`
  - **files**: `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN the app boots THEN OCS routes MUST be registered for: `CommentController`, `CollaborationRoleController`, `PresenceController`
    - AND all routes MUST require an active Nextcloud session (standard OCS auth)

## 9. Navigation Updates

- [ ] 9.1 Embed `DocumentCollaborationPanel` in Invoice, PurchaseOrder, and Contract detail views
  - **files**: Invoice detail view, PurchaseOrder detail view, Contract detail view
  - **acceptance_criteria**:
    - GIVEN any of the three entity detail views renders THEN `DocumentCollaborationPanel` MUST be mounted in the sidebar slot with the correct `targetType` and `targetId` props
    - AND the collaboration panel MUST not appear for users without any `CollaborationRole` on the document (panel hidden, not error)

- [ ] 9.2 Update `src/navigation/MainMenu.vue` to add Collaboration section
  - **files**: `src/navigation/MainMenu.vue`
  - **acceptance_criteria**:
    - GIVEN the sidebar renders THEN a "Collaboration" section MUST appear with links to Comments (admin) and Team Roles
    - AND the Comments link MUST show a badge with the count of all unresolved comments accessible to the current user

- [ ] 9.3 Update `src/router/index.js` with new routes
  - **files**: `src/router/index.js`
  - **acceptance_criteria**:
    - GIVEN the router is configured THEN routes MUST be registered for: `#/comments`, `#/comments/:commentId`, `#/collaboration/roles`, `#/collaboration/roles/:roleId`
    - AND each route MUST include breadcrumb meta matching the component hierarchy

## 10. i18n

- [ ] 10.1 Add English translations for all new UI strings
  - **files**: `l10n/en.json`
  - **acceptance_criteria**:
    - GIVEN the app renders in English THEN all new labels (Send, Resolve, Anonymise, Add Member, Viewer, Contributor, Reviewer, Approver) MUST be translated via `t('shillinq', '...')` calls
    - AND no hardcoded English strings MUST appear outside translation calls

- [ ] 10.2 Add Dutch translations
  - **files**: `l10n/nl.json`
  - **acceptance_criteria**:
    - GIVEN the Nextcloud instance language is Dutch THEN all new UI strings MUST render in Dutch
    - AND translation keys MUST match `en.json`

## 11. Unit Tests

- [ ] 11.1 Add unit tests for `MentionService.php`
  - **files**: `tests/Unit/Service/MentionServiceTest.php`
  - **acceptance_criteria**:
    - GIVEN content `"Please review @alice and cc @nonexistent"` THEN `processMentions()` MUST send notification to `alice` only
    - AND `IUserManager::get('nonexistent')` returning null MUST result in no notification and no exception
    - AND notification subject MUST be `comment_mention`

- [ ] 11.2 Add unit tests for `CollaborationRoleService.php`
  - **files**: `tests/Unit/Service/CollaborationRoleServiceTest.php`
  - **acceptance_criteria**:
    - GIVEN a user with role `contributor` THEN `checkRole(..., 'contributor')` MUST return `true`
    - AND `checkRole(..., 'reviewer')` MUST return `false`
    - AND an expired role (`expiresAt` in the past) MUST return `false` regardless of role level
    - AND no role at all MUST fall back to `AccessControl` check

- [ ] 11.3 Add unit tests for `PresenceService.php`
  - **files**: `tests/Unit/Service/PresenceServiceTest.php`
  - **acceptance_criteria**:
    - GIVEN a ping is sent for `(alice, Invoice, 001)` THEN a `PresenceRecord` is upserted with `lastSeenAt` equal to now
    - GIVEN a second ping from the same user THEN the record is updated, not duplicated
    - GIVEN `getActiveViewers()` is called THEN records older than 120 seconds MUST not be returned

- [ ] 11.4 Add unit tests for `DocumentEventNotifier.php`
  - **files**: `tests/Unit/Service/DocumentEventNotifierTest.php`
  - **acceptance_criteria**:
    - GIVEN 2 `reviewer` roles and 1 `approver` role on an invoice THEN notifying `invoice.approved` MUST dispatch 3 notifications
    - GIVEN `IMailer` is not available THEN no exception MUST be thrown and in-app notifications MUST still be sent
