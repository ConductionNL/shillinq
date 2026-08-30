# Tasks — Shillinq config & governance surfaces into Settings

## Phase 0: Deduplication Check (ADR-012)

- [ ] Confirm this change adds **no** schema, register fragment, page, route, or
      PHP — it only re-homes existing leaves. Grep `lib/Settings/register.d/` and
      `src/manifest.d/` to confirm no fragment is edited by this change.
- [ ] Confirm the 13 target leaves already exist as pages with routes:
      cluster A — `AllocationRules`, `BookkeepingAuditTrail`,
      `BookkeepingChangeHistory`, `BookkeepingActivityFeed`,
      `BookkeepingDestructionReport`, `BookkeepingComplianceExport`,
      `Bewaartermijnen`; cluster B — `ConfirmationTemplates`,
      `ReminderTemplates`, `CancellationTemplates`, `CountTemplates`,
      `NotificationTriggers`, `NotificationMonitor`, `SmsReminderChannels`.
- [ ] Confirm a Settings surface already exists and will be **extended**, not
      duplicated: the `type:"settings"` page (id `Settings`, route `/settings`)
      in `src/manifest.json` and the `section:"settings"` nav entry (id
      `Settings`). No second Settings page/anchor is created.
- [ ] Confirm `BookkeepingSigningTrail` is owned by `shillinq-delegate-signing`
      and is **NOT** in scope here (assert it is absent from this change's
      `removals` additions and from both spec sections).
- [ ] Confirm no overlap with the in-flight IA `relocations` pass: none of the
      13 leaves currently appear in `menu-layout.json` `relocations` (they are
      not group moves; they become `removals`). Re-check before editing.

## Phase 1: Cluster A — Governance & Compliance settings (GOVSET)

- [ ] In `src/menu-layout.json` `removals`, add the 6 cluster-A leaf ids:
      `AllocationRules`, `BookkeepingAuditTrail`, `BookkeepingChangeHistory`,
      `BookkeepingActivityFeed`, `BookkeepingDestructionReport`,
      `BookkeepingComplianceExport`. Add `Bewaartermijnen` (root `Administratie`
      group). Do NOT add `BookkeepingSigningTrail`.
- [ ] In `src/manifest.json`, extend the `Settings` (`type:"settings"`) page's
      `config.sections[]` with a new section
      `{ "id": "governance-compliance", "title": "Governance & Compliance", … }`
      whose sub-nav links the 7 relocated routes (`AllocationRules`,
      `BookkeepingAuditTrail`, `BookkeepingChangeHistory`,
      `BookkeepingActivityFeed`, `BookkeepingDestructionReport`,
      `BookkeepingComplianceExport`, `Bewaartermijnen`).
- [ ] Confirm the five log/export leaves and `Bewaartermijnen` remain
      **read-only** in their existing pages (no write control added by this
      change). `AllocationRules` stays editable config (ADR-031 metadata).
- [ ] Confirm each route still resolves directly (deep link) after the menu
      removal.

## Phase 2: Cluster B — Templates & Notifications settings (TMPLSET)

- [ ] In `src/menu-layout.json` `removals`, add the 7 cluster-B leaf ids:
      `ConfirmationTemplates`, `ReminderTemplates`, `CancellationTemplates`,
      `CountTemplates`, `NotificationTriggers`, `NotificationMonitor`,
      `SmsReminderChannels`.
- [ ] In `src/manifest.json`, extend the `Settings` page `config.sections[]`
      with a section
      `{ "id": "templates-notifications", "title": "Templates & Notifications", … }`
      whose sub-nav links the 7 relocated routes.
- [ ] Confirm the source `manifest.d` fragments
      (`bookings-email-templates.json`, `inventory-cycle-count.json`,
      `bookings-notification-triggers.json`,
      `bookings-sms-reminder-channel.json`) are **unchanged** — they still
      declare the pages; only the menu home moves via `menu-layout.json`.
- [ ] Confirm `NotificationMonitor` stays a read-only delivery view; the
      template / trigger / SMS-channel leaves stay editable config.

## Phase 3: IA consistency

- [ ] Verify the Bookkeeping group shrinks by 6 leaves and the root
      `Administratie` group loses `Bewaartermijnen`; the bookings/inventory
      groups lose their 7 template/notification leaves.
- [ ] Verify ordering within each Settings section is stable and labelled
      from the existing page labels (no relabel; D4).
- [ ] Verify no leaf was both relocated (cluster IA pass) AND removed here
      (no double-home).

## Phase 4: Verification

- [ ] Rebuild the front-end bundle; confirm `applyMenuRelocations` in `main.js`
      consumes the new `removals` and the leaves drop from the transactional
      tree. (Bundle has no `?v=` cache-buster — verify via fetch no-store /
      hard reload.)
- [ ] Navigate Settings → "Governance & Compliance" → each of the 7 pages and
      Settings → "Templates & Notifications" → each of the 7 pages; all 14
      links resolve to the existing page.
- [ ] Deep-link each relocated route directly; all resolve (pages stay
      routable).
- [ ] Assert `BookkeepingSigningTrail` is still in the Bookkeeping group
      (untouched — owned by `shillinq-delegate-signing`).
- [ ] Run `cd shillinq && openspec validate shillinq-config-to-settings`.
- [ ] Update any e2e spec that navigated to a relocated leaf via the old menu to
      navigate via the Settings sub-nav (route-based specs are unaffected).
