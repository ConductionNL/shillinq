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

 A buyer can draw the order against an active framework agreement of the
 supplier (REQ-PG-005): lines for products on the agreement show the agreed
 price, and the form shows what is left under the ceiling after this order.
 The server applies the agreed prices and the ceiling check itself; the form
 only previews them and sends frameworkAgreementId.

 @spec openspec/specs/bookkeeping-purchase-order-core/spec.md
 @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-002
 @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/specs/procurement-governance/spec.md (REQ-PG-005)
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

				<NcSelect
					v-model="frameworkAgreementId"
					:options="agreementOptions"
					:inputLabel="t('shillinq', 'Framework agreement (optional)')"
					:reduce="(o) => o.value"
					:disabled="agreementOptions.length === 0"
					data-testid="po-form-agreement" />

				<p
					v-if="agreementsError"
					class="po-form__agreement-error"
					data-testid="po-form-agreement-error">
					{{ agreementsError }}
				</p>

				<p
					v-if="ceilingLeft !== null"
					class="po-form__ceiling-left"
					data-testid="po-form-ceiling-left">
					{{ t('shillinq', 'Left under the ceiling after this order') }}:
					<strong>{{ formatMoney(ceilingLeft) }}</strong>
				</p>

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
								<span
									v-if="agreedPrice(line) !== null"
									class="po-form__agreed-price"
									:data-testid="`po-form-agreed-price-${idx}`">
									{{ formatMoney(agreedPrice(line)) }}
									<span class="po-form__agreed-price-note">{{
										t('shillinq', 'Agreed price')
									}}</span>
								</span>
								<NcInputField
									v-else
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
import { createdRecordId } from '../../utils/createdRecordId.js'

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
			frameworkAgreementId: '',
			agreements: [],
			agreementsError: '',
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

		/**
		 * The supplier's active agreements as picker options.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 */
		agreementOptions() {
			return this.agreements.map((agreement) => {
				const value = this.agreementKey(agreement)
				return {
					value,
					label: agreement.title ? `${agreement.title} (${value})` : value,
				}
			})
		},

		/**
		 * What is left under the picked agreement's ceiling once this order is
		 * drawn, in euro; null without an agreement.
		 *
		 * @return {number|null} The amount left.
		 */
		ceilingLeft() {
			const agreement = this.pickedAgreement()
			if (!agreement) {
				return null
			}
			const leftCents = (Number(agreement.ceilingAmount) || 0)
				- (Number(agreement.drawnAmount) || 0)
				- Math.round(this.total * 100)
			return leftCents / 100
		},
	},

	watch: {
		// The agreements on offer are those of the chosen supplier in the
		// chosen administration.
		administrationId() {
			this.loadAgreements()
		},

		supplierId() {
			this.loadAgreements()
		},

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
			const agreed = this.agreedPrice(line)
			const unit = agreed !== null ? agreed : Number(line.unitPrice) || 0
			return Math.round(qty * unit * 100) / 100
		},

		/**
		 * The identifier an agreement is picked and sent by.
		 *
		 * @param {object} agreement A FrameworkAgreement record.
		 * @return {string} Its agreement number, or its id.
		 */
		agreementKey(agreement) {
			return agreement.agreementNumber || agreement.id || ''
		},

		/**
		 * The picked agreement, if it is still on offer.
		 *
		 * @return {object|null} The FrameworkAgreement record.
		 */
		pickedAgreement() {
			if (!this.frameworkAgreementId) {
				return null
			}
			return this.agreements.find(
				(agreement) => this.agreementKey(agreement) === this.frameworkAgreementId,
			) || null
		},

		/**
		 * The agreed unit price of a line's product under the picked
		 * agreement, in euro; null when there is none.
		 *
		 * @param {object} line A form line.
		 * @return {number|null} The agreed price.
		 */
		agreedPrice(line) {
			const agreement = this.pickedAgreement()
			const code = String(line.productCode || '').trim()
			if (!agreement || code === '') {
				return null
			}
			const price = (agreement.agreedPrices || []).find(
				(entry) => entry && String(entry.productCode || '').trim() === code,
			)
			if (!price || !Number.isFinite(Number(price.unitAmount))) {
				return null
			}
			return Number(price.unitAmount) / 100
		},

		/**
		 * Load the active framework agreements of the chosen supplier, and
		 * drop a picked agreement that is no longer on offer.
		 *
		 * @return {Promise<void>}
		 */
		async loadAgreements() {
			this.agreementsError = ''
			if (!this.administrationId || !this.supplierId) {
				this.agreements = []
				this.frameworkAgreementId = ''
				return
			}
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/shillinq/FrameworkAgreement'),
					{
						params: {
							administrationId: this.administrationId,
							supplierId: this.supplierId,
							statusCode: 'active',
						},
					},
				)
				const rows = response.data?.results || []
				this.agreements = Array.isArray(rows) ? rows : []
			} catch {
				this.agreements = []
				this.agreementsError = this.t(
					'shillinq',
					'Failed to load framework agreements',
				)
			}
			if (!this.pickedAgreement()) {
				this.frameworkAgreementId = ''
			}
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
			this.error = this.submitBlocker()
			if (this.error) {
				return
			}

			this.submitting = true
			try {
				await this.createPurchaseOrder()
			} catch (e) {
				const message =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to create purchase order')
				this.error = message
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Why the order cannot be submitted yet: the first missing required
		 * field, or a total that is not positive; '' when it can.
		 *
		 * @return {string} The translated reason.
		 */
		submitBlocker() {
			if (!this.administrationId) {
				return this.t('shillinq', 'Administration is required')
			}
			if (!this.supplierId) {
				return this.t('shillinq', 'Supplier id is required')
			}
			if (!this.costCenter) {
				return this.t('shillinq', 'Cost center is required')
			}
			if (this.total <= 0) {
				return this.t(
					'shillinq',
					'Purchase order total must be positive',
				)
			}
			if (this.ceilingLeft !== null && this.ceilingLeft < 0) {
				return this.t(
					'shillinq',
					'This order exceeds the ceiling of the framework agreement',
				)
			}
			return ''
		},

		async createPurchaseOrder() {
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
					...(this.frameworkAgreementId
						? { frameworkAgreementId: this.frameworkAgreementId }
						: {}),
				},
			)
			const id = createdRecordId(response.data || {}, 'poNumber')
			if (id) {
				this.$router.push({
					name: 'PurchaseOrderDetail',
					params: { id },
				})
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

.po-form__agreed-price {
	display: inline-flex;
	flex-direction: column;
}

.po-form__agreed-price-note {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.po-form__agreement-error {
	color: var(--color-text-error, var(--color-error));
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
