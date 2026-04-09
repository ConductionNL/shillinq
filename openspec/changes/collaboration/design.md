# Design: Collaboration — Shillinq

**Status:** pr-created

## Architecture Overview

This change layers five collaboration capability groups on top of the core and access-control-authorisation infrastructure. All new entities follow the OpenRegister thin-client pattern: no custom database tables, all data via `ObjectService`. New PHP components handle mention resolution, presence tracking, email notification dispatch, and collaboration role enforcement; the Vue 2.7 + Pinia frontend handles all rendering.

```
Browser (Vue 2.7 + Pinia)
    │
    ├─ OpenRegister REST API  (Comment, CollaborationRole, PresenceRecord CRUD)
    │
    └─ Shillinq OCS API
            ├─ CommentController       (create, list, resolve, delete)
            ├─ CollaborationRoleController (assign, list, revoke)
            ├─ PresenceController      (ping heartbeat, list viewers)
            └─ DocumentEventController (manual trigger, event log)
                    │
                    └─ PHP Services
                            ├─ MentionService
                            ├─ CollaborationRoleService
                            ├─ PresenceService
                            └─ DocumentEventNotifier
```

## Data Model

### Comment (`schema:Comment`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| content | string | Yes | — | Comment text, max 4000 chars |
| author | string | Yes | — | Nextcloud userId of comment author |
| targetType | string | Yes | — | Entity type: Invoice / PurchaseOrder / Contract / NegotiationThread |
| targetId | string | Yes | — | OpenRegister object ID of the target |
| timestamp | datetime | Yes | — | Comment creation time (server-set) |
| mentions | array | No | [] | Array of mentioned Nextcloud userIds |
| resolved | boolean | No | false | Whether comment has been resolved |
| resolvedBy | string | No | — | userId who resolved the comment |
| resolvedAt | datetime | No | — | Timestamp of resolution |
| editedAt | datetime | No | — | Last edit timestamp |
| portalTokenId | string | No | — | Set when posted by a supplier via portal token |

### CollaborationRole (`schema:Role`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| targetType | string | Yes | — | Entity type the role applies to |
| targetId | string | Yes | — | OpenRegister object ID of the target |
| principalType | string | Yes | — | user / group |
| principalId | string | Yes | — | Nextcloud userId or groupId |
| role | string | Yes | — | viewer / contributor / reviewer / approver |
| grantedBy | string | Yes | — | userId who granted the role |
| grantedAt | datetime | Yes | — | Timestamp of grant |
| expiresAt | datetime | No | — | Optional expiry |

### PresenceRecord (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| userId | string | Yes | — | Nextcloud userId |
| targetType | string | Yes | — | Entity type being viewed |
| targetId | string | Yes | — | OpenRegister object ID |
| lastSeenAt | datetime | Yes | — | Last heartbeat timestamp |
| isEditing | boolean | No | false | Whether user has the edit form open |

## OpenRegister Register Updates

New schemas to add to `lib/Settings/shillinq_register.json`:

- `Comment`
- `CollaborationRole`
- `PresenceRecord`

## Backend Components

### `lib/Controller/CommentController.php`
OCS API controller. Routes:
- `GET /apps/shillinq/api/v1/comments` — list comments for a target (`?targetType=Invoice&targetId=xxx`)
- `POST /apps/shillinq/api/v1/comments` — create comment; triggers `MentionService`
- `PUT /apps/shillinq/api/v1/comments/{id}` — edit comment (author or admin only)
- `PATCH /apps/shillinq/api/v1/comments/{id}/resolve` — mark resolved
- `DELETE /apps/shillinq/api/v1/comments/{id}` — delete (DPO/admin or author within 5 minutes)

### `lib/Controller/CollaborationRoleController.php`
OCS API controller. Routes:
- `GET /apps/shillinq/api/v1/collaboration/roles` — list roles for a target
- `POST /apps/shillinq/api/v1/collaboration/roles` — assign role
- `DELETE /apps/shillinq/api/v1/collaboration/roles/{id}` — revoke role

### `lib/Controller/PresenceController.php`
OCS API controller. Routes:
- `POST /apps/shillinq/api/v1/presence/ping` — upsert `PresenceRecord` for current user + target
- `GET /apps/shillinq/api/v1/presence` — list active presence records (`?targetType=&targetId=&since=`)

### `lib/Service/MentionService.php`
Parses comment content for `@username` patterns, resolves each to a Nextcloud userId via `IUserManager`, validates the user exists, and dispatches a Nextcloud in-app notification (`INotificationManager`) with the comment excerpt and a link to the target document. Also sends email if Nextcloud mail is configured.

### `lib/Service/CollaborationRoleService.php`
Checks whether a given `principalId` holds a minimum role on a `(targetType, targetId)` pair. Role hierarchy: `viewer < contributor < reviewer < approver`. Used as a gate in `CommentController` (requires at least `contributor`) and in all write operations on collaboration objects.

### `lib/Service/PresenceService.php`
Upserts `PresenceRecord` objects using OpenRegister's update-by-filter (filter: `userId + targetType + targetId`). Prunes records with `lastSeenAt` older than 120 seconds from list responses to avoid stale presence display.

### `lib/Service/DocumentEventNotifier.php`
Dispatches notification events when internal Shillinq events fire (invoice approved, dispute opened, comment added, comment resolved). Notifies all users with `reviewer` or `approver` `CollaborationRole` on the target document. Uses Nextcloud's `IMailer` for email and `INotificationManager` for in-app notifications. Degrades gracefully if mail is not configured.

## Frontend Components

### Directory Structure

```
src/
  views/
    comment/
      CommentList.vue           # CnIndexPage for admin comment search
      CommentDetail.vue         # CnDetailPage for a single comment (audit use)
    collaborationRole/
      CollaborationRoleList.vue # CnIndexPage — roles per document
      CollaborationRoleForm.vue # CnFormDialog — assign role
  components/
    DocumentCollaborationPanel.vue  # Sidebar panel: comments + roles + presence
    CommentThread.vue               # Scrollable comment list within panel
    CommentInput.vue                # Textarea with @mention autocomplete
    MentionAutocomplete.vue         # Dropdown for user search
    PresenceStrip.vue               # Avatar row for active viewers
    CollaborationRoleChip.vue       # Inline role badge
  store/
    modules/
      comment.js                # createObjectStore('Comment')
      collaborationRole.js      # createObjectStore('CollaborationRole')
      presence.js               # createObjectStore('PresenceRecord') + heartbeat timer
```

### DocumentCollaborationPanel Pattern

```vue
<!-- DocumentCollaborationPanel.vue — embed in any CnDetailPage sidebar slot -->
<template>
  <div class="collaboration-panel">
    <PresenceStrip :target-type="targetType" :target-id="targetId" />
    <NcAppNavigationItem name="Comments" :count="unresolvedCount">
      <CommentThread
        :target-type="targetType"
        :target-id="targetId"
        :comments="comments"
        @resolve="resolveComment"
      />
      <CommentInput
        :target-type="targetType"
        :target-id="targetId"
        @submit="addComment"
      />
    </NcAppNavigationItem>
    <NcAppNavigationItem name="Team">
      <CollaborationRoleChip
        v-for="role in roles"
        :key="role.id"
        :role="role"
      />
      <NcButton @click="openRoleDialog">Add Member</NcButton>
    </NcAppNavigationItem>
  </div>
</template>
```

### CommentInput with @Mention

```vue
<!-- CommentInput.vue -->
<template>
  <div class="comment-input">
    <NcRichContenteditable
      v-model="content"
      :auto-complete="autocompleteUsers"
      @keydown.enter.ctrl="submit"
    />
    <MentionAutocomplete
      v-if="mentionQuery"
      :query="mentionQuery"
      @select="insertMention"
    />
    <NcButton @click="submit">{{ t('shillinq', 'Send') }}</NcButton>
  </div>
</template>
```

### Presence Heartbeat Pattern

```js
// src/store/modules/presence.js
import { createObjectStore } from '@conduction/nextcloud-vue'

const usePresenceStore = createObjectStore('PresenceRecord')

// Heartbeat: called from DocumentCollaborationPanel mounted/unmounted
let heartbeatTimer = null

export function startHeartbeat(targetType, targetId) {
  heartbeatTimer = setInterval(async () => {
    await axios.post(generateUrl('/apps/shillinq/api/v1/presence/ping'), {
      targetType, targetId, isEditing: false,
    })
  }, 30000)
}

export function stopHeartbeat() {
  clearInterval(heartbeatTimer)
}
```

## Seed Data

Location: extend `lib/Repair/CreateDefaultConfiguration.php`.

```php
// Comment seed — demo comment on a demo invoice
$this->seedObject('Comment', 'content', 'Please review the line items before approval.', [
    'content'    => 'Please review the line items before approval.',
    'author'     => 'admin',
    'targetType' => 'Invoice',
    'targetId'   => 'demo-invoice-001',
    'timestamp'  => '2026-01-15T09:00:00Z',
    'mentions'   => [],
    'resolved'   => false,
]);

// CollaborationRole seed — demo reviewer role
$this->seedObject('CollaborationRole', 'principalId', 'admin', [
    'targetType'    => 'Invoice',
    'targetId'      => 'demo-invoice-001',
    'principalType' => 'user',
    'principalId'   => 'admin',
    'role'          => 'approver',
    'grantedBy'     => 'admin',
    'grantedAt'     => '2026-01-01T00:00:00Z',
]);
```

## Affected Files

**New PHP**
- `lib/Controller/CommentController.php`
- `lib/Controller/CollaborationRoleController.php`
- `lib/Controller/PresenceController.php`
- `lib/Service/MentionService.php`
- `lib/Service/CollaborationRoleService.php`
- `lib/Service/PresenceService.php`
- `lib/Service/DocumentEventNotifier.php`

**New Vue / JS**
- `src/views/comment/CommentList.vue`
- `src/views/comment/CommentDetail.vue`
- `src/views/collaborationRole/CollaborationRoleList.vue`
- `src/views/collaborationRole/CollaborationRoleForm.vue`
- `src/components/DocumentCollaborationPanel.vue`
- `src/components/CommentThread.vue`
- `src/components/CommentInput.vue`
- `src/components/MentionAutocomplete.vue`
- `src/components/PresenceStrip.vue`
- `src/components/CollaborationRoleChip.vue`
- `src/store/modules/comment.js`
- `src/store/modules/collaborationRole.js`
- `src/store/modules/presence.js`

**Modified**
- `lib/Settings/shillinq_register.json` — add 3 new schemas
- `lib/Repair/CreateDefaultConfiguration.php` — add seed objects
- `appinfo/routes.php` — add comment, role, presence routes
- `lib/AppInfo/Application.php` — register new services
- `src/navigation/MainMenu.vue` — add Collaboration section with unresolved comment badge
- `src/router/index.js` — add comment and role routes
- Invoice, PurchaseOrder, and Contract detail views — embed `DocumentCollaborationPanel`
