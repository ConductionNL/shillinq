// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore } from '@conduction/nextcloud-vue'

/**
 * Pinia store for AppSettings objects.
 *
 * @spec openspec/changes/core/tasks.md#task-3.2
 */
export const useAppSettingsStore = createObjectStore('appSettings')
