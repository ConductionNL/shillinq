// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { useObjectStore } from '@conduction/nextcloud-vue'
import { useSettingsStore } from './modules/settings.js'
import { useProductCategoryStore } from './modules/productCategory.js'
import { useProductStore } from './modules/product.js'
import { useCatalogStore } from './modules/catalog.js'
import { useCatalogItemStore } from './modules/catalogItem.js'
import { useOrderBasketStore } from './modules/orderBasket.js'
import { usePurchaseOrderStore } from './modules/purchaseOrder.js'
import { useGoodsReceiptStore } from './modules/goodsReceipt.js'
import { useRfqStore } from './modules/rfq.js'
import { useSupplierQuoteStore } from './modules/supplierQuote.js'
import { useSupplierStore } from './modules/supplier.js'

export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	await settingsStore.fetchSettings()

	return { settingsStore, objectStore }
}

export {
	useObjectStore,
	useSettingsStore,
	useProductCategoryStore,
	useProductStore,
	useCatalogStore,
	useCatalogItemStore,
	useOrderBasketStore,
	usePurchaseOrderStore,
	useGoodsReceiptStore,
	useRfqStore,
	useSupplierQuoteStore,
	useSupplierStore,
}
