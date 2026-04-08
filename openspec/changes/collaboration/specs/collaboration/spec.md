---
status: proposed
---

# Collaboration — Shillinq

## Purpose

Defines functional requirements for Shillinq's collaboration features: a `Comment` schema with @mention support and resolution tracking, a `CollaborationRole` schema for per-document team permissions, a `PresenceRecord` schema for real-time viewer indicators, and a `DocumentEventNotifier` service for email and in-app notifications on document events. These capabilities build on the core and access-control-authorisation infrastructure and are consumed primarily by Supplier, Government Auditor, and Data Protection Officer stakeholders.

Stakeholders: Supplier, Government Auditor, Data Protection Officer.

User stories addressed: Resolve dispute and release invoice to payment, Reject application with formal motivation, Link logged complaints to performance review.

## Requirements

### REQ-COL-001: Comment Schema and Registration [must]

The app MUST register the `Comment` schema in OpenRegister via `lib/Settings/shillinq_register.json` using the `schema:Comment` vocabulary. The schema MUST include all properties from the data model with correct types and required flags.

**Scenarios:**

1. **GIVEN** Shillinq's repair step runs **WHEN** it completes **THEN** schema `Comment` exists in the `shillinq` register with properties: `content` (string, required), `author` (string, required), `targetType` (string, required), `targetId` (string, required), `timestamp` (datetime, required), `mentions` (array, optional), `resolved` (boolean, optional, default false).

2. **GIVEN** a `Comment` is created without `content` **WHEN** the request is submitted **THEN** a 422 validation error is returned specifying the missing field.

3. **GIVEN** a `Comment` is created without `author` **WHEN** the request is submitted **THEN** a 422 validation error is returned.

4. **GIVEN** a `Comment` is retrieved via the OpenRegister API **WHEN** `resolved` is not set **THEN** the field defaults to `false`.

### REQ-COL-002: Comment CRUD and Thread Display [must]

The app MUST provide OCS API endpoints for creating, listing, editing, resolving, and deleting comments. The frontend MUST display comment threads in the `DocumentCollaborationPanel` embedded in Invoice, PurchaseOrder, and Contract detail views.

**Scenarios:**

1. **GIVEN** a user with at least `contributor` role opens an invoice detail page **WHEN** the collaboration panel loads **THEN** all unresolved comments for that invoice are displayed chronologically with author avatar, relative timestamp, and content.

2. **GIVEN** a user posts a comment on an invoice **WHEN** the `POST /api/v1/comments` request succeeds **THEN** the comment appears at the bottom of the thread immediately without a page reload and the unresolved count badge in the sidebar increments.

3. **GIVEN** a comment author edits their own comment **WHEN** the edit is saved **THEN** the comment content is updated, `editedAt` is set to the current timestamp, and an "(edited)" label appears next to the timestamp.

4. **GIVEN** a user with `viewer` role attempts to post a comment **WHEN** the request reaches `CommentController` **THEN** a 403 Forbidden response is returned and the comment is not created.

5. **GIVEN** a comment is deleted by the author within 5 minutes of creation **WHEN** the delete succeeds **THEN** the comment is removed from the thread. Outside 5 minutes, deletion requires DPO or admin role.

6. **GIVEN** a DPO anonymises a comment **WHEN** the anonymise action is invoked **THEN** `content` is replaced with `[anonymised]`, `author` is replaced with `[anonymised]`, and `mentions` is cleared, while the object ID and timestamps are preserved for audit trail integrity.

### REQ-COL-003: @Mention Engine and Notifications [must]

The app MUST parse comment content for `@username` patterns, resolve usernames to Nextcloud userIds, and dispatch in-app and email notifications to mentioned users.

**Scenarios:**

1. **GIVEN** a user types `@` in the comment input **WHEN** at least one character follows **THEN** a dropdown of matching Nextcloud usernames is displayed, filtered by prefix match, showing display name and avatar.

2. **GIVEN** a user selects a username from the mention autocomplete **WHEN** the selection is confirmed **THEN** the `@username` token is inserted into the comment body and the userId is added to the `mentions` array on save.

3. **GIVEN** a comment containing `@alice` is saved **WHEN** `MentionService` processes the comment **THEN** the user `alice` receives a Nextcloud in-app notification with the comment excerpt (first 100 characters) and a link to the target document.

4. **GIVEN** Nextcloud outbound mail is configured **WHEN** a mention notification fires **THEN** `alice` also receives an email with the same content and a direct link to the document.

5. **GIVEN** `@nonexistentuser` is typed in a comment **WHEN** `MentionService` resolves the mention **THEN** no notification is sent and the userId is not added to `mentions`; the frontend shows a warning that the user was not found.

6. **GIVEN** a dispute comment is posted on an invoice with `@alice` **WHEN** the notification is sent **THEN** Alice can click the notification link and navigate directly to the invoice detail with the comment thread scrolled to the new comment (user story: Resolve dispute and release invoice to payment).

### REQ-COL-004: Comment Resolution Tracking [should]

The app MUST allow comments to be marked as resolved. Resolved comments MUST be visually collapsed in the panel and excluded from the unresolved count badge. All resolved comments MUST remain queryable for audit purposes.

**Scenarios:**

1. **GIVEN** a comment is unresolved **WHEN** the author or a user with `reviewer` or `approver` role clicks "Resolve" **THEN** `resolved` is set to `true`, `resolvedBy` is set to the acting userId, `resolvedAt` is set to the current timestamp, and the comment collapses in the thread.

2. **GIVEN** a comment is resolved **WHEN** the thread renders **THEN** the resolved comment is shown as a collapsed row with "Resolved by X on date" and a "Show" toggle; the main unresolved count badge does not include it.

3. **GIVEN** a Government Auditor exports the comment thread for an invoice **WHEN** the export runs **THEN** all comments including resolved ones are included in the audit export with `resolved`, `resolvedBy`, and `resolvedAt` fields (user story: Link logged complaints to performance review).

4. **GIVEN** a `viewer` role user attempts to resolve a comment **WHEN** the request reaches `CommentController` **THEN** a 403 Forbidden response is returned.

### REQ-COL-005: Team Collaboration Roles [must]

The app MUST provide a `CollaborationRole` schema to assign named roles (`viewer`, `contributor`, `reviewer`, `approver`) to Nextcloud users or groups for specific OpenRegister objects. Role checks MUST be enforced in all comment and collaboration operations.

**Scenarios:**

1. **GIVEN** an admin assigns `contributor` role to user `bob` for invoice `INV-2026-0042` **WHEN** the `CollaborationRole` object is created **THEN** `bob` can add comments but cannot resolve comments or manage roles on that invoice.

2. **GIVEN** user `carol` has `approver` role on a purchase order **WHEN** `carol` approves the purchase order **THEN** the approval is accepted and an `AuditTrail` entry records the approver's userId and role.

3. **GIVEN** a `CollaborationRole` has an `expiresAt` in the past **WHEN** `CollaborationRoleService` checks access **THEN** the role is treated as revoked and the user's request is denied with 403.

4. **GIVEN** an admin opens the team panel on a contract detail page **WHEN** the role list renders **THEN** all assigned `CollaborationRole` objects for that contract are shown with principal name, role badge, granted date, and expiry.

5. **GIVEN** no `CollaborationRole` exists for a user on a document **WHEN** the user attempts to comment **THEN** the system falls back to the global `AccessControl` rules; if the user has global `editor` access they are treated as `contributor`.

### REQ-COL-006: Presence Indicators [should]

The app MUST display real-time presence indicators showing which Nextcloud users are currently viewing or editing a document. Presence MUST be updated via a polling heartbeat every 30 seconds and pruned after 120 seconds of inactivity.

**Scenarios:**

1. **GIVEN** two users are viewing the same purchase order **WHEN** both have sent a presence ping within the last 120 seconds **THEN** both user avatars appear in the `PresenceStrip` at the top of the collaboration panel.

2. **GIVEN** a user has the edit form open for an invoice **WHEN** their presence ping fires with `isEditing: true` **THEN** their avatar in the `PresenceStrip` shows an edit indicator (pencil icon overlay) to warn other viewers.

3. **GIVEN** a user closes the invoice detail tab **WHEN** 120 seconds elapse without a ping **THEN** their avatar is removed from the `PresenceStrip` in other users' views on the next poll.

4. **GIVEN** the `PresenceController` receives a ping **WHEN** a `PresenceRecord` already exists for `(userId, targetType, targetId)` **THEN** the existing record is upserted (not duplicated) and `lastSeenAt` is updated.

### REQ-COL-007: Document Event Notifications [should]

The app MUST dispatch in-app and email notifications when key document events occur: invoice approved, dispute opened, comment added, and comment resolved. Notifications MUST be sent to all users holding `reviewer` or `approver` `CollaborationRole` on the affected document.

**Scenarios:**

1. **GIVEN** an invoice is approved **WHEN** `DocumentEventNotifier` fires the `invoice.approved` event **THEN** all users with `reviewer` or `approver` role on that invoice receive a Nextcloud in-app notification and (if mail is configured) an email.

2. **GIVEN** a dispute comment is opened on an invoice **WHEN** the comment is saved with `targetType: NegotiationThread` **THEN** the internal team members with `reviewer` or `approver` roles receive a "Dispute opened" notification with a link to the invoice (user story: Resolve dispute and release invoice to payment).

3. **GIVEN** Nextcloud outbound mail is NOT configured **WHEN** a document event fires **THEN** in-app Nextcloud notifications are still sent and no mail error is raised.

4. **GIVEN** a grant application rejection comment is added **WHEN** the rejection comment is saved **THEN** all `approver` role users receive a notification linking to the rejection motivation (user story: Reject application with formal motivation).

### REQ-COL-008: Supply Chain Negotiation Thread [should]

The app MUST provide a "Negotiation" tab within the `DocumentCollaborationPanel` on `PurchaseOrder` and `Invoice` detail views where the supplier contact can participate via portal token authentication without a Nextcloud account.

**Scenarios:**

1. **GIVEN** a supplier has a valid `PortalToken` scoped to their organisation **WHEN** they open the negotiation tab on a purchase order **THEN** they can read all comments with `targetType: NegotiationThread` for that purchase order and post new comments.

2. **GIVEN** a supplier posts a negotiation comment **WHEN** the comment is saved **THEN** the `portalTokenId` field is set, `author` is set to the token's `organizationId`, and the internal team receives a "Supplier response" notification.

3. **GIVEN** a supplier's `PortalToken` is expired or inactive **WHEN** they attempt to post a negotiation comment **THEN** a 401 Unauthorized response is returned and no comment is created.

4. **GIVEN** the negotiation thread has reached a resolution **WHEN** an internal user marks the thread comment as resolved **THEN** the supplier sees the resolved state on their next portal reload and the dispute count on the Supplier stakeholder dashboard decrements.

### REQ-COL-009: Collaboration Seed Data [must]

The app MUST load seed data for `Comment` and `CollaborationRole` entities during the repair step. Seed loading MUST be idempotent.

**Scenarios:**

1. **GIVEN** a fresh Shillinq installation **WHEN** the repair step runs **THEN** at least 1 `Comment` and 1 `CollaborationRole` object are created with realistic demo data.

2. **GIVEN** the repair step has already run **WHEN** it runs again **THEN** no duplicate seed objects are created; the uniqueness check for `Comment` uses `content` + `targetId`, and for `CollaborationRole` uses `principalId` + `targetId` + `role`.
