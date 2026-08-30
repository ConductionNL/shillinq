<!--
  Receive Page wrapper. Bootstraps the inventory scanner store and renders
  the Receive operation component.

  @spec openspec/changes/inventory-mobile-scanner/tasks.md#T3.1
-->
<template>
	<div class="receive-page">
		<header class="receive-page__header">
			<router-link to="/inventory/mobile" class="receive-page__back">
				← {{ t('shillinq', 'Back') }}
			</router-link>
			<SyncStatusBadge />
		</header>
		<ReceiveOp />
	</div>
</template>

<script>
import ReceiveOp from '../../components/inventory/ReceiveOp.vue'
import SyncStatusBadge from '../../components/inventory/SyncStatusBadge.vue'
import { useInventoryMobileScannerStore } from '../../store/modules/inventoryMobileScanner.js'

export default {
	name: 'ReceivePage',
	components: { ReceiveOp, SyncStatusBadge },
	async mounted() {
		await useInventoryMobileScannerStore().bootstrap()
	},
}
</script>

<style scoped>
.receive-page {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	padding: var(--default-grid-baseline, 4px);
}

.receive-page__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.receive-page__back {
	text-decoration: none;
}
</style>
