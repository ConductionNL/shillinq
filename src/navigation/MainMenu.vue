<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/catalog-purchase-management/tasks.md#task-13.1
-->
<template>
	<NcAppNavigation>
		<template #list>
			<NcAppNavigationItem
				:name="t('shillinq', 'Dashboard')"
				:to="{ name: 'Dashboard' }"
				:exact="true">
				<template #icon>
					<HomeIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationCaption :name="t('shillinq', 'Catalog')" />

			<NcAppNavigationItem
				:name="t('shillinq', 'Catalog Search')"
				:to="{ name: 'CatalogSearch' }">
				<template #icon>
					<MagnifyIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'My Basket')"
				:to="{ name: 'OrderBasketView' }">
				<template #icon>
					<CartOutlineIcon :size="20" />
				</template>
				<template v-if="openBasketCount > 0" #counter>
					<NcCounterBubble type="highlighted">
						{{ openBasketCount }}
					</NcCounterBubble>
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'My Orders')"
				:to="{ name: 'OrderBasketHistory' }">
				<template #icon>
					<ClipboardListOutlineIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'Catalogs')"
				:to="{ name: 'CatalogIndex' }">
				<template #icon>
					<BookOpenPageVariantOutlineIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'Products')"
				:to="{ name: 'ProductIndex' }">
				<template #icon>
					<PackageVariantClosedIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'Categories')"
				:to="{ name: 'ProductCategoryIndex' }">
				<template #icon>
					<FolderOutlineIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationCaption :name="t('shillinq', 'Procurement')" />

			<NcAppNavigationItem
				:name="t('shillinq', 'Purchase Orders')"
				:to="{ name: 'PurchaseOrderIndex' }">
				<template #icon>
					<ClipboardTextOutlineIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'Goods Receipts')"
				:to="{ name: 'GoodsReceiptIndex' }">
				<template #icon>
					<TruckDeliveryOutlineIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'RFQ')"
				:to="{ name: 'RFQIndex' }">
				<template #icon>
					<FileDocumentEditOutlineIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'Documentation')"
				@click="openLink('https://conduction.nl', '_blank')">
				<template #icon>
					<BookOpenVariantOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<NcAppNavigationItem
				:name="t('shillinq', 'Settings')"
				:to="{ name: 'Settings' }">
				<template #icon>
					<CogIcon :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationCaption, NcAppNavigationItem, NcCounterBubble } from '@nextcloud/vue'
import BookOpenPageVariantOutlineIcon from 'vue-material-design-icons/BookOpenPageVariantOutline.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import CartOutlineIcon from 'vue-material-design-icons/CartOutline.vue'
import ClipboardListOutlineIcon from 'vue-material-design-icons/ClipboardListOutline.vue'
import ClipboardTextOutlineIcon from 'vue-material-design-icons/ClipboardTextOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import FileDocumentEditOutlineIcon from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import PackageVariantClosedIcon from 'vue-material-design-icons/PackageVariantClosed.vue'
import TruckDeliveryOutlineIcon from 'vue-material-design-icons/TruckDeliveryOutline.vue'
import { useOrderBasketStore } from '../store/modules/orderBasket.js'

export default {
	name: 'MainMenu',
	components: {
		BookOpenPageVariantOutlineIcon,
		BookOpenVariantOutline,
		CartOutlineIcon,
		ClipboardListOutlineIcon,
		ClipboardTextOutlineIcon,
		CogIcon,
		FileDocumentEditOutlineIcon,
		FolderOutlineIcon,
		HomeIcon,
		MagnifyIcon,
		NcAppNavigation,
		NcAppNavigationCaption,
		NcAppNavigationItem,
		NcCounterBubble,
		PackageVariantClosedIcon,
		TruckDeliveryOutlineIcon,
	},
	computed: {
		openBasketCount() {
			const store = useOrderBasketStore()
			return store.getOpenBasketCount
		},
	},
	methods: {
		openLink(url, target = '_blank') {
			window.open(url, target)
		},
	},
}
</script>
