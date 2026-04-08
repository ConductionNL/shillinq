// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import Dashboard from '../views/Dashboard.vue'
import AdminRoot from '../views/settings/AdminRoot.vue'
import ProductCategoryIndex from '../views/productCategory/ProductCategoryIndex.vue'
import ProductCategoryDetail from '../views/productCategory/ProductCategoryDetail.vue'
import ProductIndex from '../views/product/ProductIndex.vue'
import ProductDetail from '../views/product/ProductDetail.vue'
import CatalogIndex from '../views/catalog/CatalogIndex.vue'
import CatalogDetail from '../views/catalog/CatalogDetail.vue'
import CatalogSearch from '../views/catalog/CatalogSearch.vue'
import OrderBasketView from '../views/orderBasket/OrderBasketView.vue'
import OrderBasketHistory from '../views/orderBasket/OrderBasketHistory.vue'
import PurchaseOrderIndex from '../views/purchaseOrder/PurchaseOrderIndex.vue'
import PurchaseOrderDetail from '../views/purchaseOrder/PurchaseOrderDetail.vue'
import GoodsReceiptIndex from '../views/goodsReceipt/GoodsReceiptIndex.vue'
import GoodsReceiptDetail from '../views/goodsReceipt/GoodsReceiptDetail.vue'
import GoodsReceiptForm from '../views/goodsReceipt/GoodsReceiptForm.vue'
import RFQIndex from '../views/rFQ/RFQIndex.vue'
import RFQDetail from '../views/rFQ/RFQDetail.vue'

Vue.use(Router)

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/shillinq'),
	routes: [
		{
			path: '/',
			name: 'Dashboard',
			component: Dashboard,
			meta: { breadcrumb: 'Dashboard' },
		},
		{
			path: '/settings',
			name: 'Settings',
			component: AdminRoot,
			meta: { breadcrumb: 'Settings' },
		},
		{
			path: '/categories',
			name: 'ProductCategoryIndex',
			component: ProductCategoryIndex,
			meta: { breadcrumb: 'Categories' },
		},
		{
			path: '/categories/:categoryId',
			name: 'ProductCategoryDetail',
			component: ProductCategoryDetail,
			meta: { breadcrumb: 'Category Detail' },
		},
		{
			path: '/products',
			name: 'ProductIndex',
			component: ProductIndex,
			meta: { breadcrumb: 'Products' },
		},
		{
			path: '/products/:productId',
			name: 'ProductDetail',
			component: ProductDetail,
			meta: { breadcrumb: 'Product Detail' },
		},
		{
			path: '/catalogs',
			name: 'CatalogIndex',
			component: CatalogIndex,
			meta: { breadcrumb: 'Catalogs' },
		},
		{
			path: '/catalogs/:catalogId',
			name: 'CatalogDetail',
			component: CatalogDetail,
			meta: { breadcrumb: 'Catalog Detail' },
		},
		{
			path: '/catalog-search',
			name: 'CatalogSearch',
			component: CatalogSearch,
			meta: { breadcrumb: 'Catalog Search' },
		},
		{
			path: '/basket',
			name: 'OrderBasketView',
			component: OrderBasketView,
			meta: { breadcrumb: 'My Basket' },
		},
		{
			path: '/orders',
			name: 'OrderBasketHistory',
			component: OrderBasketHistory,
			meta: { breadcrumb: 'My Orders' },
		},
		{
			path: '/purchase-orders',
			name: 'PurchaseOrderIndex',
			component: PurchaseOrderIndex,
			meta: { breadcrumb: 'Purchase Orders' },
		},
		{
			path: '/purchase-orders/:purchaseOrderId',
			name: 'PurchaseOrderDetail',
			component: PurchaseOrderDetail,
			meta: { breadcrumb: 'Purchase Order Detail' },
		},
		{
			path: '/goods-receipts',
			name: 'GoodsReceiptIndex',
			component: GoodsReceiptIndex,
			meta: { breadcrumb: 'Goods Receipts' },
		},
		{
			path: '/goods-receipts/:goodsReceiptId',
			name: 'GoodsReceiptDetail',
			component: GoodsReceiptDetail,
			meta: { breadcrumb: 'Goods Receipt Detail' },
		},
		{
			path: '/goods-receipts/new/:purchaseOrderId',
			name: 'GoodsReceiptForm',
			component: GoodsReceiptForm,
			meta: { breadcrumb: 'New Goods Receipt' },
		},
		{
			path: '/rfqs',
			name: 'RFQIndex',
			component: RFQIndex,
			meta: { breadcrumb: 'RFQ' },
		},
		{
			path: '/rfqs/:rfqId',
			name: 'RFQDetail',
			component: RFQDetail,
			meta: { breadcrumb: 'RFQ Detail' },
		},
		{ path: '*', redirect: '/' },
	],
})
