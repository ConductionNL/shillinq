---
status: proposed
source: specter
features: [team-collaboration-roles, comment-mentions, real-time-collaboration, notification-service, supply-chain-collaboration]
---

# Collaboration — Shillinq

## Summary

Implements collaboration features for Shillinq: a team collaboration system with member roles and project-level permissions, a comment and @mention engine on financial documents, real-time collaboration status indicators, a notification service for mentions and document events, and supply chain collaboration tooling for supplier-facing dispute resolution and negotiation. These capabilities address the highest-demand collaboration features identified in the Specter intelligence model and build on the core and access-control-authorisation infrastructure changes.

## Demand Evidence

Top features by market demand score:

- **team-collaboration-roles** (demand: 301) — highest-demand collaboration feature; finance teams and procurement managers need role-based access at the document and project level so that different team members can edit, review, or read financial records without escalating to system-wide admin permissions.
- **various apps for email** (demand: 140) — users need email-based notifications for document events (invoice approved, purchase order raised, dispute opened) triggered from within Shillinq without leaving the Nextcloud environment.
- **Live Chat (Community)** (demand: 59) — internal teams and supplier contacts need a lightweight threaded comment channel per financial document to replace back-channel email threads.
- **Real-time Collaboration** (demand: 46) — concurrent editing indicators and presence markers so team members know who is viewing or editing a document before overwriting each other's changes.
- **Supply chain collaboration** (demand: 24) — procurement teams and suppliers need an in-system negotiation thread attached to purchase orders and invoices to track amendments, counter-offers, and dispute resolutions without email.

Features with demand score below 10 and priority `could` (@Mention Collaboration, Collaboration Workspace, Expensify Chat, SRM collaboration portal, Notificatieservice, InSite portaal) are addressed selectively where they overlap with higher-demand items. Lower-priority standalone items are deferred.

Key stakeholder pain points addressed:

- **Supplier**: unclear invoice status communication and late-payment disputes resolved via the in-document comment/negotiation thread.
- **Government Auditor**: fragmented audit trail across email and system — structured `Comment` objects with timestamps and author IDs provide a complete, queryable record for audit.
- **Data Protection Officer**: financial documents contain personal data; comment content is subject to GDPR minimisation — comments store user IDs (not full names) and can be deleted/anonymised by the DPO.

## What Shillinq Already Has

After the core and access-control-authorisation changes:

- OpenRegister schemas for `Organization`, `AppSettings`, `AccessControl`
- Nextcloud notification integration
- Entity list, detail, and form views using `CnIndexPage` / `CnDetailPage` / `CnFormDialog`
- Global search, admin settings, sidebar navigation
- User preference support

### What Is Missing

- No per-document comment thread with @mention support
- No team member role assignment at project/entity level beyond global access control
- No presence or real-time editing indicators
- No email notification dispatch for document events
- No supplier-facing negotiation thread on purchase orders and invoices
- No comment resolution tracking or audit trail integration

## Scope

### In Scope

1. **Comment Schema and CRUD** — OpenRegister `Comment` schema (`schema:Comment`) with content, author, targetType, targetId, timestamp, mentions (array of userIds), and resolved (boolean). Full CRUD via OpenRegister REST. Views at `src/views/comment/`.

2. **@Mention Engine** — when a comment body contains `@username`, the username is resolved to a Nextcloud userId, added to the `mentions` array, and the mentioned user receives a Nextcloud notification (`lib/Service/MentionService.php`). The frontend `CommentInput.vue` component renders a mention autocomplete dropdown.

3. **Comment Resolution** — comments can be marked resolved by the author or any user with `edit` permission on the target document. Resolved comments are visually collapsed and excluded from the default view but remain queryable for audit.

4. **Team Collaboration Roles** — a `CollaborationRole` OpenRegister schema assigns a named role (viewer / contributor / reviewer / approver) to a Nextcloud user or group for a specific OpenRegister object. Roles are enforced by `CollaborationRoleService.php` checked before comment creation, edit, and resolution. Views at `src/views/collaborationRole/`.

5. **Document Collaboration Panel** — a reusable `DocumentCollaborationPanel.vue` sidebar component embeddable in any `CnDetailPage`. It shows the active comment thread, @mention input, member role list, and presence indicators. Used initially on Invoice, PurchaseOrder, and Contract detail views.

6. **Presence Indicators** — `PresenceRecord` schema stores `userId`, `targetType`, `targetId`, `lastSeenAt`, and `isEditing`. A `PresenceController.php` heartbeat endpoint (`POST /api/v1/presence/ping`) updates the record every 30 seconds from the frontend. The panel shows avatars for users currently viewing the document.

7. **Email Notification Dispatch** — `DocumentEventNotifier.php` service listens on internal Shillinq events (invoice approved, dispute opened, comment added) and sends Nextcloud mail notifications to relevant team members. Notification templates stored in `lib/Notification/`.

8. **Supply Chain Negotiation Thread** — on `PurchaseOrder` and `Invoice` detail views, a dedicated "Negotiation" tab within the collaboration panel allows the supplier contact (identified by `Organization.contactEmail`) to participate in a comment thread scoped to `targetType: NegotiationThread` without a full Nextcloud account, using the existing portal token mechanism.

9. **Seed data** — demo `Comment`, `CollaborationRole`, and `PresenceRecord` objects loaded via the repair step (ADR-016).

### Out of Scope

- WebSocket-based live cursors or operational transform — presence polling is sufficient for v1
- Full in-browser chat room unrelated to a document — comment threads are document-scoped
- SCIM-based external user provisioning for supplier negotiation — portal tokens suffice
- Email inbox parsing / inbound mail routing — outbound notifications only
- Lower-demand standalone features: InSite portaal, Expensify Chat, standalone Collaboration Workspace

## Acceptance Criteria

1. GIVEN a user opens an invoice detail page WHEN the collaboration panel loads THEN all comments for that invoice are displayed chronologically with author avatar, timestamp, and content
2. GIVEN a user types `@` in the comment input WHEN at least one character follows THEN a dropdown of matching Nextcloud usernames appears and selecting one inserts the mention and adds the userId to the `mentions` array
3. GIVEN a comment containing `@alice` is saved WHEN the save completes THEN the user `alice` receives a Nextcloud notification linking to the document
4. GIVEN a comment is marked resolved WHEN the resolved flag is set THEN it is visually collapsed in the panel and excluded from the unresolved count badge
5. GIVEN a `CollaborationRole` of `viewer` is assigned to a user for a specific invoice WHEN that user attempts to add a comment THEN a 403 response is returned and the comment input is disabled
6. GIVEN two users are viewing the same purchase order WHEN both have pinged the presence endpoint within 60 seconds THEN both avatars appear in the presence indicator strip
7. GIVEN an invoice is approved WHEN the `DocumentEventNotifier` fires THEN all team members with `reviewer` or `approver` roles on that invoice receive an email notification via Nextcloud's mail system
8. GIVEN a supplier has a valid portal token and is linked to a purchase order WHEN they open the negotiation tab THEN they can post comments visible to the internal team on that purchase order only

## Risks and Dependencies

- **Core and access-control-authorisation prerequisite**: `Organization`, `AppSettings`, and `AccessControl` schemas must exist before `CollaborationRole` references them.
- **Portal token mechanism**: Supplier negotiation thread reuses `PortalToken` from the general change — that change must be merged first or deployed together.
- **Nextcloud mail configuration**: Email notifications require Nextcloud's outbound mail to be configured by the instance admin; the feature degrades gracefully (Nextcloud in-app notification only) if mail is not configured.
- **Presence polling load**: 30-second heartbeat from all active users could generate significant DB writes at scale — `PresenceRecord` upserts use OpenRegister's update-by-filter to avoid row explosion.
- **GDPR comment deletion**: DPO must be able to anonymise comment `content` and `author` fields without deleting the object (to preserve audit trail structure). An anonymise action is included in the DPO admin panel.
