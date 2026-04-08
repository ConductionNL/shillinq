// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore } from '@conduction/nextcloud-vue'

/**
 * Order basket store.
 *
 * @see openspec/changes/catalog-purchase-management/tasks.md#task-6.5
 */
export const useOrderBasketStore = createObjectStore('orderBasket', {
	plugins: [
		(store) => {
			store.getOpenBasketCount = () => {
				return store.objectList.filter((item) => item.status === 'open').length
			}
		},
	],
})
