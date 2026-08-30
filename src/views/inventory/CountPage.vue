<!--
  Count Page wrapper. @spec openspec/changes/inventory-mobile-scanner/tasks.md#T3.4
-->
<template>
	<div class="count-page">
		<header class="count-page__header">
			<router-link to="/inventory/mobile" class="count-page__back">
				← {{ t('shillinq', 'Back') }}
			</router-link>
			<SyncStatusBadge />
		</header>
		<CountOp />
	</div>
</template>

<script>
import CountOp from '../../components/inventory/CountOp.vue'
import SyncStatusBadge from '../../components/inventory/SyncStatusBadge.vue'
import { useInventoryMobileScannerStore } from '../../store/modules/inventoryMobileScanner.js'

export default {
	name: 'CountPage',
	components: { CountOp, SyncStatusBadge },
	async mounted() {
		await useInventoryMobileScannerStore().bootstrap()
	},
}
</script>

<style scoped>
.count-page {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	padding: var(--default-grid-baseline, 4px);
}

.count-page__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.count-page__back {
	text-decoration: none;
}
</style>
