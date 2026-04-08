// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore } from '@conduction/nextcloud-vue'

/**
 * RFQ store.
 *
 * @see openspec/changes/catalog-purchase-management/tasks.md#task-6.8
 */
export const useRFQStore = createObjectStore('rFQ')
