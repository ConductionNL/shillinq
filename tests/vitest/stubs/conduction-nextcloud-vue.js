/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Minimal @conduction/nextcloud-vue stub for the offline Vitest suite. The
 * migrated record-list views (migrate-list-views-to-cndatatable) import the
 * shared CnDataTable universal-list-widget. The unit tests exercise each
 * view's `columns` / `methods` logic, never the real component's template, so
 * the stub only needs to be a valid (empty) Vue component option object.
 */

export const CnDataTable = { name: 'CnDataTable', render: () => null }
