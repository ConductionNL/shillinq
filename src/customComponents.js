// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for shillinq's manifest-driven app shell.
//
// EMPTY ON PURPOSE. Every shillinq page is a declarative manifest page
// type — `dashboard` and `settings` today, `index` / `detail` once the
// business-administration domain pages land. An entry here means a page
// (or sidebar tab / settings section) that does NOT fit a built-in type;
// adding one requires an explicit justification in the design doc of the
// change that introduces it. Deleting entries is the right direction
// (ADR-024).
//
// Resolution order at runtime (first hit wins):
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components
//
// See @conduction/nextcloud-vue → docs/migrating-to-manifest.md and
// openspec/changes/shillinq-manifest-tier4/design.md.

export default {}
