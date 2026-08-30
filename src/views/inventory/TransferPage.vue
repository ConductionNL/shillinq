<!--
  Transfer Page wrapper. @spec openspec/changes/inventory-mobile-scanner/tasks.md#T3.2
-->
<template>
	<div class="transfer-page">
		<header class="transfer-page__header">
			<router-link to="/inventory/mobile" class="transfer-page__back">
				← {{ t('shillinq', 'Back') }}
			</router-link>
			<SyncStatusBadge />
		</header>
		<TransferOp />
	</div>
</template>

<script>
import SyncStatusBadge from '../../components/inventory/SyncStatusBadge.vue'
import TransferOp from '../../components/inventory/TransferOp.vue'
import { useInventoryMobileScannerStore } from '../../store/modules/inventoryMobileScanner.js'

export default {
	name: 'TransferPage',
	components: { TransferOp, SyncStatusBadge },
	async mounted() {
		await useInventoryMobileScannerStore().bootstrap()
	},
}
</script>

<style scoped>
.transfer-page {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	padding: var(--default-grid-baseline, 4px);
}

.transfer-page__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.transfer-page__back {
	text-decoration: none;
}
</style>
