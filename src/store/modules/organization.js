// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore } from '@conduction/nextcloud-vue'

/**
 * Pinia store for Organization objects.
 *
 * @spec openspec/changes/core/tasks.md#task-3.1
 */
export const useOrganizationStore = createObjectStore('organization')
