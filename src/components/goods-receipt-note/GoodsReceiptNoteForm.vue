<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Goods Receipt Note capture form (slice 04 of bookkeeping-purchase-order-3way).
 Mobile-optimised: large touch targets, single-column layout, picker-style
 carriers + rejection reasons. Lets a magazijn-medewerker pick one or more
 purchase orders to receive against, enter per-line received / accepted /
 rejected quantities + rejection reason, capture carrier + delivery-note ref,
 and upload delivery-condition photos.

 The form does NOT decide anything server-authoritative — receivedBy / inspector
 identities are derived from the session on the server (ADR-005). On submit the
 GRN is created, every line is POSTed in sequence, and the form navigates to
 the GoodsReceiptNoteDetail view.

 @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
-->
<template>
	<div class="grn-form">
		<h2>{{ t('shillinq', 'Receive goods') }}</h2>

		<form @submit.prevent="onSubmit">
			<fieldset class="grn-form__header">
				<legend>{{ t('shillinq', 'Delivery') }}</legend>

				<NcSelect
					v-model="administrationId"
					:options="administrationOptions"
					:inputLabel="t('shillinq', 'Administration')"
					:reduce="(o) => o.value"
					data-testid="grn-form-administration" />

				<NcSelect
					v-model="selectedPoIds"
					multiple
					:options="poOptions"
					:inputLabel="t('shillinq', 'Purchase order(s)')"
					:reduce="(o) => o.value"
					data-testid="grn-form-pos" />

				<NcTextField
					v-model="carrier"
					:label="t('shillinq', 'Carrier (e.g. PostNL, DHL)')"
					data-testid="grn-form-carrier" />

				<NcTextField
					v-model="deliveryNoteReference"
					:label="t('shillinq', 'Delivery-note reference (pakbon)')"
					data-testid="grn-form-pakbon" />
			</fieldset>

			<fieldset class="grn-form__lines">
				<legend>{{ t('shillinq', 'Line items') }}</legend>
				<p v-if="availableLines.length === 0" class="grn-form__lines-empty">
					{{
						t(
							'shillinq',
							'Pick at least one purchase order to load lines.',
						)
					}}
				</p>
				<ul v-else class="grn-form__lines-list">
					<li
						v-for="line in availableLines"
						:key="line.id"
						class="grn-form__line"
						:data-testid="`grn-form-line-${line.id}`">
						<header class="grn-form__line-header">
							<strong>{{
								line.description
								|| line.productOrServiceCode
								|| line.id
							}}</strong>
							<span class="grn-form__line-ordered">
								{{ t('shillinq', 'Ordered') }}:
								{{ formatQty(line.quantityOrdered) }}
								{{ line.unitOfMeasure || '' }}
							</span>
						</header>

						<div class="grn-form__line-grid">
							<label class="grn-form__field">
								<span>{{ t('shillinq', 'Received') }}</span>
								<NcInputField
									v-model.number="
										lineState[line.id].quantityReceived
									"
									type="number"
									step="0.001"
									min="0"
									:label="t('shillinq', 'Received')" />
							</label>

							<label class="grn-form__field">
								<span>{{ t('shillinq', 'Accepted') }}</span>
								<NcInputField
									v-model.number="
										lineState[line.id].quantityAccepted
									"
									type="number"
									step="0.001"
									min="0"
									:label="t('shillinq', 'Accepted')" />
							</label>

							<label class="grn-form__field">
								<span>{{ t('shillinq', 'Rejected') }}</span>
								<NcInputField
									v-model.number="
										lineState[line.id].quantityRejected
									"
									type="number"
									step="0.001"
									min="0"
									:label="t('shillinq', 'Rejected')" />
							</label>

							<label
								v-if="lineState[line.id].quantityRejected > 0"
								class="grn-form__field grn-form__field--reason">
								<span>{{ t('shillinq', 'Rejection reason') }}</span>
								<NcSelect
									v-model="lineState[line.id].rejectionReason"
									:options="rejectionReasonOptions"
									:inputLabel="t('shillinq', 'Rejection reason')"
									:reduce="(o) => o.value" />
							</label>

							<label class="grn-form__field grn-form__field--batch">
								<span>{{ t('shillinq', 'Batch / lot') }}</span>
								<NcTextField
									v-model="lineState[line.id].batchReference"
									:label="
										t('shillinq', 'Batch reference (optional)')
									"
									:showTrailingButton="false" />
							</label>
						</div>
					</li>
				</ul>
			</fieldset>

			<fieldset class="grn-form__photos">
				<legend>{{ t('shillinq', 'Delivery photos') }}</legend>
				<input
					ref="photoInput"
					type="file"
					accept="image/*"
					multiple
					:aria-label="t('shillinq', 'Choose delivery photos to attach')"
					data-testid="grn-form-photo-input"
					@change="onPhotoSelected" />
				<ul
					v-if="photos.length > 0"
					class="grn-form__photo-list"
					data-testid="grn-form-photo-list">
					<li v-for="photo in photos" :key="photo.id">
						{{ photo.name }}
					</li>
				</ul>
				<p v-else class="grn-form__photo-empty">
					{{ t('shillinq', 'No photos attached yet.') }}
				</p>
			</fieldset>

			<div v-if="error" class="grn-form__error" data-testid="grn-form-error">
				{{ error }}
			</div>

			<div class="grn-form__actions">
				<NcButton
					variant="primary"
					type="submit"
					:disabled="submitting || !canSubmit"
					data-testid="grn-form-submit">
					{{
						submitting
							? t('shillinq', 'Saving…')
							: t('shillinq', 'Save goods receipt')
					}}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcInputField, NcSelect, NcTextField } from '@nextcloud/vue'

const REGISTER_SLUG = 'shillinq'

export default {
	name: 'GoodsReceiptNoteForm',
	components: {
		NcButton,
		NcTextField,
		NcInputField,
		NcSelect,
	},

	data() {
		return {
			administrationId: '',
			administrationOptions: [],
			selectedPoIds: [],
			poOptions: [],
			openPurchaseOrders: {},
			availableLines: [],
			lineState: {},
			carrier: '',
			deliveryNoteReference: '',
			photos: [],
			error: '',
			submitting: false,
		}
	},

	computed: {
		canSubmit() {
			if (!this.administrationId) {
				return false
			}
			if (this.selectedPoIds.length === 0) {
				return false
			}
			// At least one line must report a received quantity.
			return Object.values(this.lineState).some(
				(entry) => Number(entry.quantityReceived) > 0,
			)
		},

		rejectionReasonOptions() {
			return [
				{ value: 'schade', label: this.t('shillinq', 'Damage') },
				{
					value: 'verkeerd_product',
					label: this.t('shillinq', 'Wrong product'),
				},
				{ value: 'expired', label: this.t('shillinq', 'Expired') },
				{ value: 'niet_besteld', label: this.t('shillinq', 'Not ordered') },
				{
					value: 'short_shipped',
					label: this.t('shillinq', 'Short shipped'),
				},
				{ value: 'other', label: this.t('shillinq', 'Other') },
			]
		},
	},

	watch: {
		selectedPoIds: {
			handler() {
				this.refreshLines()
			},

			deep: true,
		},
	},

	async created() {
		await this.loadAdministrationContext()
		await this.loadOpenPurchaseOrders()
	},

	methods: {
		async loadAdministrationContext() {
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/administrations/context'),
				)
				const admins = response.data?.administrations || []
				this.administrationOptions = admins.map((a) => ({
					value: a.administrationId,
					label: a.name || a.administrationCode || a.administrationId,
				}))
				if (response.data?.activeAdministrationId) {
					this.administrationId = response.data.activeAdministrationId
				}
			} catch (e) {
				this.error = this.t(
					'shillinq',
					'Failed to load administration context',
				)
			}
		},

		/** @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md */
		async loadOpenPurchaseOrders() {
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/PurchaseOrder`,
					),
				)
				const records = response.data?.results || response.data || []
				const open = records.filter((row) => {
					const state = row.lifecycleState || ''
					return state !== 'draft' && state !== 'cancelled'
				})
				this.openPurchaseOrders = Object.fromEntries(
					open.map((row) => [row.id || row.poNumber, row]),
				)
				this.poOptions = open.map((row) => ({
					value: row.id || row.poNumber,
					label: row.poNumber || row.id,
				}))
			} catch (e) {
				this.openPurchaseOrders = {}
				this.poOptions = []
			}
		},

		/** @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md */
		async refreshLines() {
			if (this.selectedPoIds.length === 0) {
				this.availableLines = []
				this.lineState = {}
				return
			}

			const lines = []
			for (const poId of this.selectedPoIds) {
				try {
					const response = await axios.get(
						generateUrl(
							`/apps/openregister/api/objects/${REGISTER_SLUG}/PurchaseOrderLine`,
						),
						{ params: { filter: { poId } } },
					)
					const records = response.data?.results || response.data || []
					for (const line of records) {
						lines.push(line)
					}
				} catch (e) {
					// Skip silently — the lines table will show the empty state.
				}
			}

			this.availableLines = lines
			const nextState = {}
			for (const line of lines) {
				const existing = this.lineState[line.id]
				nextState[line.id] = existing || {
					quantityReceived: 0,
					quantityAccepted: 0,
					quantityRejected: 0,
					rejectionReason: '',
					batchReference: '',
				}
			}
			this.lineState = nextState
		},

		onPhotoSelected(event) {
			const files = Array.from(event.target.files || [])
			// File-id resolution is delegated to docudesk on submit; here we
			// stash a preview-friendly name so the operator sees the list.
			for (const file of files) {
				this.photos.push({
					id: `${file.name}-${file.size}`,
					name: file.name,
					file,
				})
			}
		},

		formatQty(qty) {
			return Number(qty || 0).toFixed(3)
		},

		async uploadPhotosToDocudesk() {
			if (this.photos.length === 0) {
				return []
			}
			const ids = []
			for (const photo of this.photos) {
				try {
					const form = new FormData()
					form.append('file', photo.file)
					form.append('administrationId', this.administrationId)
					const response = await axios.post(
						generateUrl('/apps/shillinq/api/docudesk/upload'),
						form,
						{ headers: { 'Content-Type': 'multipart/form-data' } },
					)
					const fileId = response.data?.fileId || response.data?.id
					if (fileId) {
						ids.push(String(fileId))
					}
				} catch (e) {
					// Silent — surfaced via the GRN photo array (empty on failure).
				}
			}
			return ids
		},

		async onSubmit() {
			this.error = ''
			if (!this.canSubmit) {
				this.error = this.t(
					'shillinq',
					'Pick at least one PO and one line with a received quantity',
				)
				return
			}

			this.submitting = true
			try {
				const photoIds = await this.uploadPhotosToDocudesk()
				const grnResponse = await axios.post(
					generateUrl('/apps/shillinq/api/goods-receipt-notes'),
					{
						administrationId: this.administrationId,
						poIds: this.selectedPoIds,
						carrier: this.carrier,
						deliveryNoteReference: this.deliveryNoteReference,
						photos: photoIds,
					},
				)
				const grn = grnResponse.data || {}
				const grnId =
					grn.id || (grn['@self'] && grn['@self'].id) || grn.grnNumber
				if (!grnId) {
					throw new Error('Missing GRN id in response')
				}

				for (const line of this.availableLines) {
					const state = this.lineState[line.id]
					if (!state || Number(state.quantityReceived) <= 0) {
						continue
					}
					await axios.post(
						generateUrl(
							`/apps/shillinq/api/goods-receipt-notes/${grnId}/lines`,
						),
						{
							administrationId: this.administrationId,
							poLineId: line.id,
							quantityReceived: state.quantityReceived,
							quantityAccepted:
								state.quantityAccepted || state.quantityReceived,
							quantityRejected: state.quantityRejected || 0,
							rejectionReason: state.rejectionReason,
							batchReference: state.batchReference,
						},
					)
				}

				this.$router.push({
					name: 'GoodsReceiptNoteDetail',
					params: { id: grnId },
				})
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to save goods receipt')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.grn-form {
	padding: 16px;
	max-width: 720px;
	margin: 0 auto;
}

.grn-form fieldset {
	margin-bottom: 16px;
	border: 1px solid var(--color-border, #ddd);
	padding: 12px;
}

.grn-form__lines-list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.grn-form__line {
	border-bottom: 1px solid var(--color-border, #ddd);
	padding: 12px 0;
}

.grn-form__line-header {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 8px;
}

.grn-form__line-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 12px;
}

.grn-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.grn-form__field--reason,
.grn-form__field--batch {
	grid-column: 1 / -1;
}

.grn-form__photo-list {
	list-style: none;
	padding: 0;
}

.grn-form__error {
	background: var(--color-error, #c33);
	color: #fff;
	padding: 8px;
	margin: 8px 0;
}

.grn-form__actions {
	margin-top: 16px;
}
</style>
