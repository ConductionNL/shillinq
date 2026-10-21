<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 One warehouse scanner operation (receive, transfer, pick or count), chosen
 by the page config's `operation`. Scan or type the code, fill in the
 quantity, and the operation is queued offline and synced later.

 @spec openspec/specs/inventory-mobile-scanner/spec.md
-->
<template>
	<div class="scan-op" :data-testid="`scan-op-${operation}`">
		<header class="scan-op__header">
			<router-link :to="{ name: 'MobileScannerHome' }" class="scan-op__back">
				{{ t('shillinq', 'Back') }}
			</router-link>
			<SyncStatusBadge />
		</header>

		<p v-if="!config" role="alert">
			{{ t('shillinq', 'This scanner operation does not exist.') }}
		</p>

		<section v-else class="scan-op__body">
			<h1>{{ t('shillinq', config.title) }}</h1>

			<form class="scan-op__form" @submit.prevent="confirm">
				<label>
					<span>{{ t('shillinq', config.locationLabel) }}</span>
					<select v-model="form.location" required :aria-label="t('shillinq', config.locationAria)">
						<option value="">
							{{ config.toLocation ? t('shillinq', 'Select source') : t('shillinq', 'Select a location') }}
						</option>
						<option v-for="loc in store.locations" :key="loc.code" :value="loc.code">
							{{ loc.name }} ({{ loc.code }})
						</option>
					</select>
				</label>

				<label>
					<span>{{ t('shillinq', 'SKU / barcode') }}</span>
					<div class="scan-op__sku-row">
						<input
v-model="form.sku"
							type="text"
							required
							:placeholder="t('shillinq', 'Tap scan or type SKU')"
							:aria-label="t('shillinq', 'Stock keeping unit')">
						<button type="button" data-testid="scan-op-scan" @click="scanning = true">
							{{ t('shillinq', 'Scan') }}
						</button>
					</div>
				</label>

				<label v-if="config.toLocation">
					<span>{{ t('shillinq', 'To location') }}</span>
					<select v-model="form.toLocation" required :aria-label="t('shillinq', 'Destination location')">
						<option value="">
							{{ t('shillinq', 'Select destination') }}
						</option>
						<option v-for="loc in destinations" :key="loc.code" :value="loc.code">
							{{ loc.name }} ({{ loc.code }})
						</option>
					</select>
				</label>

				<label v-if="config.orderLine">
					<span>{{ t('shillinq', 'Order line id (optional)') }}</span>
					<input v-model="form.orderLineId" type="text" :aria-label="t('shillinq', 'Order line id')">
				</label>

				<label>
					<span>{{ t('shillinq', config.quantityLabel) }}</span>
					<input
v-model.number="form[config.quantityField]"
						type="number"
						:min="config.quantityMin"
						step="0.01"
						required
						:aria-label="t('shillinq', config.quantityAria)">
				</label>

				<div v-if="config.variance && systemQuantity !== null" class="scan-op__variance" role="status">
					<span>{{ t('shillinq', 'System qty: {sys}', { sys: systemQuantity }) }}</span>
					<span>{{ t('shillinq', 'Variance: {variance}', { variance }) }}</span>
				</div>

				<label v-if="config.reconcile" class="scan-op__check">
					<input v-model="form.reconcile" type="checkbox">
					{{ t('shillinq', 'Update the stock to the physical count') }}
				</label>

				<div v-if="error" class="scan-op__error" role="alert">
					{{ error }}
				</div>
				<div v-if="warning" class="scan-op__warning" role="alert">
					{{ warning }}
				</div>
				<div v-if="successMessage" class="scan-op__success" role="status">
					{{ successMessage }}
				</div>

				<div class="scan-op__actions">
					<button type="submit" :disabled="submitting">
						{{ t('shillinq', config.submitLabel) }}
					</button>
				</div>
			</form>

			<CnCameraCapture
v-if="scanning"
				mode="barcode"
				@scan="onScan"
				@close="scanning = false" />
		</section>
	</div>
</template>

<script>
import { CnCameraCapture } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import SyncStatusBadge from '../../components/inventory/SyncStatusBadge.vue'
import { readStockQuantity } from '../../composables/useInventoryDb.js'
import { useInventoryMobileScannerStore } from '../../store/modules/inventoryMobileScanner.js'
import {
	emptyScanForm,
	nextScanForm,
	scanOperation,
	scanPayload,
	scanSuccess,
	stockShortage,
	validateScan,
} from './scanOperations.js'

/** Refuse a second confirm within this many milliseconds (REQ-SYNC-002). */
const RESUBMIT_GUARD_MS = 5000

export default {
	name: 'ScanOperation',

	components: { CnCameraCapture, SyncStatusBadge },

	props: {
		/** receive, transfer, pick or count. */
		operation: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			form: emptyScanForm(),
			systemQuantity: null,
			scanning: false,
			submitting: false,
			error: '',
			warning: '',
			successMessage: '',
			lastSubmittedAt: 0,
		}
	},

	computed: {
		config() {
			return scanOperation(this.operation)
		},

		store() {
			return useInventoryMobileScannerStore()
		},

		destinations() {
			return this.store.locations.filter((loc) => loc.code !== this.form.location)
		},

		variance() {
			if (this.systemQuantity === null || this.form.physicalQuantity === null || this.form.physicalQuantity === '') {
				return null
			}
			return Number(this.form.physicalQuantity) - Number(this.systemQuantity)
		},
	},

	watch: {
		'form.sku': function() {
			this.readSystemQuantity()
		},

		'form.location': function() {
			this.readSystemQuantity()
		},
	},

	async mounted() {
		await this.store.bootstrap()
	},

	methods: {
		t,

		onScan(value) {
			this.form.sku = value
			this.scanning = false
		},

		async stockAt(sku, location) {
			if (!this.store.db) {
				return null
			}
			try {
				return await readStockQuantity(this.store.db, sku, location)
			} catch {
				return null
			}
		},

		async readSystemQuantity() {
			if (!this.config || !this.config.variance || !this.form.sku || !this.form.location) {
				this.systemQuantity = null
				return
			}
			this.systemQuantity = (await this.stockAt(this.form.sku, this.form.location)) ?? 0
		},

		async confirm() {
			this.error = ''
			this.warning = ''
			this.successMessage = ''

			const invalid = validateScan(this.operation, this.form)
			if (invalid) {
				this.error = invalid
				return
			}

			if (this.config.stockCheck) {
				const onHand = await this.stockAt(this.form.sku, this.form.location)
				const shortage = onHand === null ? '' : stockShortage(this.operation, this.form, onHand)
				if (shortage) {
					this[this.config.stockCheck] = shortage
					return
				}
			}

			const now = Date.now()
			if (now - this.lastSubmittedAt < RESUBMIT_GUARD_MS) {
				this.error = t('shillinq', 'Already submitted; waiting for server ACK.')
				return
			}
			this.lastSubmittedAt = now

			this.submitting = true
			try {
				await this.store.submitOperation(scanPayload(this.operation, this.form))
				this.successMessage = scanSuccess(this.operation, this.form, this.variance)
				this.form = nextScanForm(this.form)
				this.systemQuantity = null
			} catch (e) {
				this.error = e?.message || t('shillinq', this.config.failure)
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.scan-op,
.scan-op__body {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	padding: var(--default-grid-baseline, 4px);
}

.scan-op__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.scan-op__back {
	text-decoration: none;
}

.scan-op__form label {
	display: flex;
	flex-direction: column;
	margin-bottom: var(--default-grid-baseline, 4px);
}

.scan-op__form .scan-op__check {
	flex-direction: row;
	align-items: center;
	gap: var(--default-grid-baseline, 4px);
}

.scan-op__sku-row {
	display: flex;
	gap: var(--default-grid-baseline, 4px);
}

.scan-op__sku-row input {
	flex: 1;
}

.scan-op__variance {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	color: var(--color-text-maxcontrast);
}

.scan-op__error {
	color: var(--color-error-text);
}

.scan-op__warning {
	color: var(--color-warning-text);
}

.scan-op__success {
	color: var(--color-success-text);
}

.scan-op__actions {
	display: flex;
	justify-content: flex-end;
}
</style>
