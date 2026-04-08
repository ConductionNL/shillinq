// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore } from '@conduction/nextcloud-vue'

/**
 * Goods receipt store.
 *
 * @see openspec/changes/catalog-purchase-management/tasks.md#task-6.7
 */
export const useGoodsReceiptStore = createObjectStore('goodsReceipt')
