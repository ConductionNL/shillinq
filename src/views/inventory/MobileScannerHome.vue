<!--
  Mobile Scanner Home

  Top-level operation selector for the warehouse PWA. Boots the
  inventoryMobileScanner store on mount and surfaces the four operations
  + sync status badge per T3.5.

  @spec openspec/changes/inventory-mobile-scanner/tasks.md#T3.5
-->
<template>
	<section class="mobile-scanner-home">
		<header class="mobile-scanner-home__header">
			<h1>{{ t('shillinq', 'Inventory Mobile Scanner') }}</h1>
			<SyncStatusBadge />
		</header>

		<p class="mobile-scanner-home__intro">
			{{
				t(
					'shillinq',
					'Select an operation to begin. All operations work offline.',
				)
			}}
		</p>

		<nav
			class="mobile-scanner-home__grid"
			:aria-label="t('shillinq', 'Warehouse operations')">
			<router-link
				to="/inventory/mobile/receive"
				class="mobile-scanner-home__tile">
				<strong>{{ t('shillinq', 'Receive') }}</strong>
				<span>{{ t('shillinq', 'Goods inbound') }}</span>
			</router-link>
			<router-link
				to="/inventory/mobile/transfer"
				class="mobile-scanner-home__tile">
				<strong>{{ t('shillinq', 'Transfer') }}</strong>
				<span>{{ t('shillinq', 'Move between locations') }}</span>
			</router-link>
			<router-link
				to="/inventory/mobile/pick"
				class="mobile-scanner-home__tile">
				<strong>{{ t('shillinq', 'Pick') }}</strong>
				<span>{{ t('shillinq', 'Fulfil an order line') }}</span>
			</router-link>
			<router-link
				to="/inventory/mobile/count"
				class="mobile-scanner-home__tile">
				<strong>{{ t('shillinq', 'Count') }}</strong>
				<span>{{ t('shillinq', 'Physical count + variance') }}</span>
			</router-link>
		</nav>
	</section>
</template>

<script>
import SyncStatusBadge from '../../components/inventory/SyncStatusBadge.vue'
import { registerInventoryServiceWorker } from '../../composables/useServiceWorker.js'
import { useInventoryMobileScannerStore } from '../../store/modules/inventoryMobileScanner.js'

export default {
	name: 'MobileScannerHome',
	components: { SyncStatusBadge },
	async mounted() {
		const store = useInventoryMobileScannerStore()
		try {
			await store.bootstrap()
		} catch (e) {
			// Surface failure via badge state; non-fatal for the home view.
		}
		// Register the service worker once we know the PWA shell is on screen.
		// Non-blocking — failure to register downgrades to "online-only".
		registerInventoryServiceWorker().catch(() => null)
	},
}
</script>

<style scoped>
.mobile-scanner-home {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	padding: var(--default-grid-baseline, 4px);
}

.mobile-scanner-home__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.mobile-scanner-home__intro {
	color: var(--color-text-maxcontrast);
}

.mobile-scanner-home__grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: var(--default-grid-baseline, 4px);
}

.mobile-scanner-home__tile {
	display: flex;
	flex-direction: column;
	padding: 16px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
	text-decoration: none;
	color: inherit;
}

.mobile-scanner-home__tile strong {
	font-size: 1.2em;
}
</style>
