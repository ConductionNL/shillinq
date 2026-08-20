<!--
  Pick Page wrapper. @spec openspec/changes/inventory-mobile-scanner/tasks.md#T3.3
-->
<template>
	<div class="pick-page">
		<header class="pick-page__header">
			<router-link to="/inventory/mobile" class="pick-page__back">
				← {{ t('shillinq', 'Back') }}
			</router-link>
			<SyncStatusBadge />
		</header>
		<PickOp />
	</div>
</template>

<script>
import PickOp from '../../components/inventory/PickOp.vue'
import SyncStatusBadge from '../../components/inventory/SyncStatusBadge.vue'
import { useInventoryMobileScannerStore } from '../../store/modules/inventoryMobileScanner.js'

export default {
	name: 'PickPage',
	components: { PickOp, SyncStatusBadge },
	async mounted() {
		await useInventoryMobileScannerStore().bootstrap()
	},
}
</script>

<style scoped>
.pick-page {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	padding: var(--default-grid-baseline, 4px);
}

.pick-page__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.pick-page__back {
	text-decoration: none;
}
</style>
