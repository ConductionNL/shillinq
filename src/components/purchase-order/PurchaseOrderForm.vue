<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Purchase Order creation form (slice 02 of bookkeeping-purchase-order-3way).
 Lets an Inkoper enter PO header fields (supplier, cost center, project code,
 currency, notes), add line items (productCode, quantity, unitPrice, vatRate,
 glAccount) and previews the server-side approval chain by amount threshold.

 The form does NOT decide who needs to approve — every chain entry is fetched
 from /api/purchase-orders/approval-chain (server-authoritative). On submit it
 POSTs to /api/purchase-orders and navigates to PurchaseOrderDetail.

 Slice 03 (bookkeeping-purchase-order-3way-03-peppol-transmission) surfaces
 a notice that once approved the PO can be sent via Peppol or PDF+email from
 the detail view (the actual transmission control lives on the detail view
 because the action requires a persisted PO with a complete approval chain).

 @spec openspec/changes/bookkeeping-purchase-order-3way-02-purchase-order-core/tasks.md
 @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
-->
<template>
	<div class="po-form">
		<h2>{{ t('shillinq', 'Create Purchase Order') }}</h2>

		<form @submit.prevent="onSubmit">
			<fieldset class="po-form__header">
				<legend>{{ t('shillinq', 'Header') }}</legend>

				<NcSelect
					v-model="administrationId"
					:options="administrationOptions"
					:inputLabel="t('shillinq', 'Administration')"
					:reduce="(o) => o.value"
					data-testid="po-form-administration" />

				<NcTextField
					v-model="supplierId"
					:label="t('shillinq', 'Supplier id')"
					data-testid="po-form-supplier" />

				<NcTextField
					v-model="costCenter"
					:label="t('shillinq', 'Cost center')"
					data-testid="po-form-cost-center" />

				<NcTextField
					v-model="projectCode"
					:label="t('shillinq', 'Project code (optional)')"
					data-testid="po-form-project" />

				<NcTextField
					v-model="currency"
					:label="t('shillinq', 'Currency')"
					data-testid="po-form-currency" />

				<NcTextArea
					v-model="notes"
					:label="t('shillinq', 'Notes (optional)')"
					data-testid="po-form-notes" />
			</fieldset>

			<fieldset class="po-form__lines">
				<legend>{{ t('shillinq', 'Line items') }}</legend>
				<table class="po-form__lines-table">
					<thead>
						<tr>
							<th scope="col">{{ t('shillinq', 'Product code') }}</th>
							<th scope="col">{{ t('shillinq', 'Quantity') }}</th>
							<th scope="col">{{ t('shillinq', 'Unit price') }}</th>
							<th scope="col">{{ t('shillinq', 'VAT rate') }}</th>
							<th scope="col">{{ t('shillinq', 'GL account') }}</th>
							<th scope="col">{{ t('shillinq', 'Line total') }}</th>
							<th scope="col">
								<span class="po-form__sr-only">{{
									t('shillinq', 'Actions')
								}}</span>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="(line, idx) in lines"
							:key="idx"
							:data-testid="`po-form-line-${idx}`">
							<td>
								<NcTextField
									v-model="line.productCode"
									:label="t('shillinq', 'Product code')"
									:showTrailingButton="false" />
							</td>
							<td>
								<NcInputField
									v-model.number="line.quantity"
									type="number"
									step="0.01"
									:label="t('shillinq', 'Quantity')" />
							</td>
							<td>
								<NcInputField
									v-model.number="line.unitPrice"
									type="number"
									step="0.01"
									:label="t('shillinq', 'Unit price')" />
							</td>
							<td>
								<NcInputField
									v-model.number="line.vatRate"
									type="number"
									step="0.01"
									:label="t('shillinq', 'VAT rate (fraction)')" />
							</td>
							<td>
								<NcTextField
									v-model="line.glAccount"
									:label="t('shillinq', 'GL account')"
									:showTrailingButton="false" />
							</td>
							<td>{{ formatMoney(lineTotal(line)) }}</td>
							<td>
								<NcButton
									variant="tertiary"
									:aria-label="t('shillinq', 'Remove line')"
									@click="removeLine(idx)">
									{{ t('shillinq', 'Remove') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
				<NcButton
					variant="secondary"
					data-testid="po-form-add-line"
					@click="addLine">
					{{ t('shillinq', 'Add line') }}
				</NcButton>
			</fieldset>

			<fieldset class="po-form__chain">
				<legend>
					{{ t('shillinq', 'Approval chain (server-determined)') }}
				</legend>
				<p class="po-form__total" data-testid="po-form-total">
					{{ t('shillinq', 'Total') }}:
					<strong>{{ formatMoney(total) }}</strong>
				</p>
				<ul
					v-if="approvalChain.length > 0"
					class="po-form__chain-list"
					data-testid="po-form-chain">
					<li v-for="entry in approvalChain" :key="entry.role">
						<span class="po-form__chain-order">#{{ entry.order }}</span>
						<span class="po-form__chain-role">{{
							roleLabel(entry.role)
						}}</span>
						<span
							class="po-form__chain-status po-form__chain-status--pending"
							>{{ t('shillinq', 'pending') }}</span
						>
					</li>
				</ul>
				<p v-else class="po-form__chain-empty">
					{{ t('shillinq', 'No approvers required yet — add lines.') }}
				</p>
			</fieldset>

			<div v-if="error" class="po-form__error" data-testid="po-form-error">
				{{ error }}
			</div>

			<p
				class="po-form__transmission-hint"
				data-testid="po-form-transmission-hint">
				{{
					t(
						'shillinq',
						'Once the approval chain is complete you can send this PO via Peppol or PDF+email from the detail view.',
					)
				}}
			</p>

			<div class="po-form__actions">
				<NcButton
					variant="primary"
					type="submit"
					:disabled="submitting"
					data-testid="po-form-submit">
					{{
						submitting
							? t('shillinq', 'Creating…')
							: t('shillinq', 'Create purchase order')
					}}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcInputField,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'

export default {
	name: 'PurchaseOrderForm',
	components: {
		NcButton,
		NcTextField,
		NcTextArea,
		NcInputField,
		NcSelect,
	},

	data() {
		return {
			administrationId: '',
			supplierId: '',
			costCenter: '',
			projectCode: '',
			currency: 'EUR',
			notes: '',
			lines: [this.emptyLine()],
			approvalChain: [],
			administrationOptions: [],
			error: '',
			submitting: false,
		}
	},

	computed: {
		/**
		 * PO total in euro, summed from line totals using integer cents.
		 *
		 * @return {number} The total amount as a float (multipleOf 0.01).
		 */
		total() {
			let cents = 0
			for (const line of this.lines) {
				cents += Math.round(this.lineTotal(line) * 100)
			}
			return cents / 100
		},
	},

	watch: {
		// Refresh the chain preview whenever the total changes, debounced by Vue's
		// reactivity batching. Server-authoritative — never compute locally.
		total: {
			immediate: true,
			handler(newTotal) {
				this.refreshApprovalChain(newTotal)
			},
		},
	},

	async created() {
		await this.loadAdministrationContext()
	},

	methods: {
		emptyLine() {
			return {
				productCode: '',
				quantity: 1,
				unitPrice: 0,
				vatRate: 0.21,
				glAccount: '',
			}
		},

		addLine() {
			this.lines.push(this.emptyLine())
		},

		removeLine(idx) {
			this.lines.splice(idx, 1)
			if (this.lines.length === 0) {
				this.lines.push(this.emptyLine())
			}
		},

		lineTotal(line) {
			const qty = Number(line.quantity) || 0
			const unit = Number(line.unitPrice) || 0
			return Math.round(qty * unit * 100) / 100
		},

		formatMoney(amount) {
			const symbol = this.currency || 'EUR'
			return `${symbol} ${Number(amount || 0).toFixed(2)}`
		},

		roleLabel(role) {
			const labels = {
				teamleider: this.t('shillinq', 'Team lead'),
				facility_manager: this.t('shillinq', 'Facility Manager'),
				procurement_manager: this.t('shillinq', 'Procurement Manager'),
			}
			return labels[role] || role
		},

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
				// Surface as an inline error rather than crashing the form.
				this.error = this.t(
					'shillinq',
					'Failed to load administration context',
				)
			}
		},

		async refreshApprovalChain(amount) {
			if (!amount || amount <= 0) {
				this.approvalChain = []
				return
			}
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/purchase-orders/approval-chain'),
					{ params: { amount } },
				)
				this.approvalChain = response.data?.chain || []
			} catch (e) {
				this.approvalChain = []
			}
		},

		async onSubmit() {
			this.error = ''
			if (!this.administrationId) {
				this.error = this.t('shillinq', 'Administration is required')
				return
			}
			if (!this.supplierId) {
				this.error = this.t('shillinq', 'Supplier id is required')
				return
			}
			if (!this.costCenter) {
				this.error = this.t('shillinq', 'Cost center is required')
				return
			}
			if (this.total <= 0) {
				this.error = this.t(
					'shillinq',
					'Purchase order total must be positive',
				)
				return
			}

			this.submitting = true
			try {
				const response = await axios.post(
					generateUrl('/apps/shillinq/api/purchase-orders'),
					{
						administrationId: this.administrationId,
						supplierId: this.supplierId,
						costCenter: this.costCenter,
						projectCode: this.projectCode,
						currency: this.currency,
						notes: this.notes,
						lines: this.lines,
					},
				)
				const po = response.data || {}
				const id = po.id || (po['@self'] && po['@self'].id) || po.poNumber
				if (id) {
					this.$router.push({
						name: 'PurchaseOrderDetail',
						params: { id },
					})
				}
			} catch (e) {
				const message =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to create purchase order')
				this.error = message
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.po-form {
	padding: 16px;
}

/*
 * Accessible name for the line-actions column. The header is not shown, but
 * a screen reader still announces a column header for every cell, so an
 * empty <th> leaves the remove button's column unnamed. Defined locally
 * rather than relying on a server-global utility class.
 */
.po-form__sr-only {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
}

.po-form fieldset {
	margin-bottom: 16px;
	border: 1px solid var(--color-border, #ddd);
	padding: 12px;
}

.po-form__lines-table {
	width: 100%;
	border-collapse: collapse;
}

.po-form__lines-table th,
.po-form__lines-table td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border-dark, #ccc);
	text-align: left;
}

.po-form__chain-list {
	list-style: none;
	padding: 0;
}

.po-form__chain-list li {
	display: flex;
	gap: 12px;
	padding: 4px 0;
}

.po-form__chain-order {
	font-weight: bold;
}

.po-form__chain-status--pending {
	color: var(--color-warning, #c93);
}

.po-form__error {
	background: var(--color-error, #c33);
	color: #fff;
	padding: 8px;
	margin: 8px 0;
}

.po-form__actions {
	margin-top: 16px;
}
</style>
