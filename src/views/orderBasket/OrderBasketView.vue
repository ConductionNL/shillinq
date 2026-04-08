<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-9.1 -->
<template>
	<div class="order-basket">
		<header class="order-basket__header">
			<h2>{{ t('shillinq', 'Order Basket') }}</h2>
		</header>

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcEmptyContent
			v-else-if="lines.length === 0"
			:name="t('shillinq', 'Your basket is empty')"
			:description="t('shillinq', 'Search the catalog to add items.')">
			<template #icon>
				<CartOutlineIcon :size="64" />
			</template>
			<template #action>
				<NcButton type="primary" :to="{ name: 'CatalogSearch' }">
					{{ t('shillinq', 'Search Catalog') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- Budget warning -->
			<div v-if="budgetWarning" class="order-basket__warning">
				<AlertIcon :size="20" />
				{{ budgetWarning }}
			</div>

			<!-- Approval message -->
			<div v-if="approvalMessage" class="order-basket__approval">
				<CheckCircleIcon :size="20" />
				{{ approvalMessage }}
			</div>

			<table class="order-basket__table">
				<thead>
					<tr>
						<th>{{ t('shillinq', 'Product') }}</th>
						<th>{{ t('shillinq', 'Quantity') }}</th>
						<th>{{ t('shillinq', 'Unit Price') }}</th>
						<th>{{ t('shillinq', 'Line Total') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="line in lines" :key="line.id">
						<td>{{ line.productName }}</td>
						<td>
							<NcTextField
								v-model.number="line.quantity"
								type="number"
								min="1"
								class="order-basket__qty"
								:label="t('shillinq', 'Quantity')"
								@input="onQuantityChange(line)" />
						</td>
						<td>{{ formatPrice(line.unitPrice, line.currency) }}</td>
						<td class="order-basket__line-total">
							{{ formatPrice(lineTotal(line), line.currency) }}
						</td>
						<td>
							<NcButton
								type="tertiary"
								:aria-label="t('shillinq', 'Remove')"
								@click="removeLine(line)">
								<template #icon>
									<DeleteIcon :size="20" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
				<tfoot>
					<tr>
						<td colspan="3" class="order-basket__total-label">
							<strong>{{ t('shillinq', 'Total') }}</strong>
						</td>
						<td class="order-basket__total-value">
							<strong>{{ formatPrice(basketTotal, defaultCurrency) }}</strong>
						</td>
						<td />
					</tr>
				</tfoot>
			</table>

			<div class="order-basket__footer">
				<div class="order-basket__cost-centre">
					<label for="cost-centre">{{ t('shillinq', 'Cost Centre') }}</label>
					<NcSelect
						id="cost-centre"
						v-model="selectedCostCentre"
						:options="costCentres"
						:placeholder="t('shillinq', 'Select cost centre')"
						label="name"
						track-by="id" />
				</div>

				<NcButton
					type="primary"
					:disabled="submitting || !selectedCostCentre"
					@click="submitBasket">
					<template v-if="submitting" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					<template v-else #icon>
						<SendIcon :size="20" />
					</template>
					{{ t('shillinq', 'Submit Order') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import CartOutlineIcon from 'vue-material-design-icons/CartOutline.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'

export default {
	name: 'OrderBasketView',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		AlertIcon,
		CartOutlineIcon,
		CheckCircleIcon,
		DeleteIcon,
		SendIcon,
	},

	data() {
		return {
			loading: false,
			submitting: false,
			basketId: null,
			lines: [],
			costCentres: [],
			selectedCostCentre: null,
			budgetWarning: null,
			approvalMessage: null,
			defaultCurrency: 'EUR',
		}
	},

	computed: {
		basketTotal() {
			return this.lines.reduce((sum, line) => sum + this.lineTotal(line), 0)
		},
	},

	async created() {
		await Promise.all([
			this.loadBasket(),
			this.loadCostCentres(),
		])
	},

	methods: {
		t(app, text, params) {
			return t(app, text, params)
		},

		lineTotal(line) {
			return (line.quantity || 0) * (line.unitPrice || 0)
		},

		formatPrice(price, currency) {
			if (price == null) return '-'
			const cur = currency || 'EUR'
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: cur,
			}).format(price)
		},

		onQuantityChange(line) {
			if (line.quantity < 1) {
				line.quantity = 1
			}
		},

		async loadBasket() {
			this.loading = true
			try {
				const url = generateUrl('/apps/shillinq/api/v1/order-baskets/current')
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.basketId = data.id
					this.lines = data.lines || []
				}
			} catch (error) {
				console.error('Failed to load basket:', error)
			} finally {
				this.loading = false
			}
		},

		async loadCostCentres() {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/cost-centres')
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.costCentres = data.results || data
				}
			} catch (error) {
				console.error('Failed to load cost centres:', error)
			}
		},

		async removeLine(line) {
			try {
				const url = generateUrl(
					`/apps/shillinq/api/v1/order-baskets/${this.basketId}/lines/${line.id}`,
				)
				const response = await fetch(url, {
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.lines = this.lines.filter((l) => l.id !== line.id)
				}
			} catch (error) {
				console.error('Failed to remove line:', error)
			}
		},

		async submitBasket() {
			if (!this.basketId || !this.selectedCostCentre) return

			this.submitting = true
			this.budgetWarning = null
			this.approvalMessage = null

			try {
				const url = generateUrl(
					`/apps/shillinq/api/v1/order-baskets/${this.basketId}/submit`,
				)
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						costCentreId: this.selectedCostCentre.id,
						lines: this.lines.map((l) => ({
							id: l.id,
							quantity: l.quantity,
						})),
					}),
				})

				const data = await response.json()

				if (response.ok) {
					if (data.budgetWarning) {
						this.budgetWarning = data.budgetWarning
					}
					if (data.requiresApproval) {
						this.approvalMessage = t(
							'shillinq',
							'Your order has been sent for approval.',
						)
					} else {
						this.approvalMessage = t(
							'shillinq',
							'Your order has been submitted successfully.',
						)
					}
					await this.loadBasket()
				}
			} catch (error) {
				console.error('Failed to submit basket:', error)
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.order-basket {
	padding: 8px 16px 24px;
	max-width: 1200px;
}

.order-basket__header h2 {
	margin: 0 0 16px;
	font-size: 22px;
	font-weight: 600;
}

.order-basket__warning {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 16px;
	background-color: var(--color-warning-hover);
	border-radius: var(--border-radius-large);
	color: var(--color-warning-text);
	margin-bottom: 12px;
}

.order-basket__approval {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 16px;
	background-color: var(--color-success-hover);
	border-radius: var(--border-radius-large);
	color: var(--color-success-text);
	margin-bottom: 12px;
}

.order-basket__table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 16px;
}

.order-basket__table th,
.order-basket__table td {
	padding: 10px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.order-basket__qty {
	width: 80px;
}

.order-basket__line-total {
	font-weight: 600;
}

.order-basket__total-label {
	text-align: right;
	padding-right: 12px;
}

.order-basket__total-value {
	font-size: 16px;
}

.order-basket__footer {
	display: flex;
	justify-content: space-between;
	align-items: flex-end;
	gap: 16px;
}

.order-basket__cost-centre {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 250px;
}

.order-basket__cost-centre label {
	font-weight: 600;
	font-size: 14px;
}
</style>
