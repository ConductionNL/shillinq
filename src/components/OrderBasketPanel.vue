<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-9.3 -->
<template>
	<div class="basket-panel" :class="{ 'basket-panel--expanded': expanded }">
		<button class="basket-panel__toggle" @click="expanded = !expanded">
			<CartIcon :size="24" />
			<span v-if="itemCount > 0" class="basket-panel__badge">
				{{ itemCount }}
			</span>
			<span class="basket-panel__total">
				{{ formatPrice(basketTotal) }}
			</span>
		</button>

		<div v-if="expanded" class="basket-panel__dropdown">
			<h4 class="basket-panel__title">
				{{ t('shillinq', 'Your Basket') }}
			</h4>

			<div v-if="lines.length === 0" class="basket-panel__empty">
				{{ t('shillinq', 'No items in basket') }}
			</div>

			<ul v-else class="basket-panel__lines">
				<li v-for="line in lines" :key="line.id" class="basket-panel__line">
					<span class="basket-panel__line-name">{{ line.productName }}</span>
					<span class="basket-panel__line-qty">x{{ line.quantity }}</span>
					<span class="basket-panel__line-price">
						{{ formatPrice(line.quantity * line.unitPrice) }}
					</span>
				</li>
			</ul>

			<div class="basket-panel__footer">
				<strong>{{ t('shillinq', 'Total') }}: {{ formatPrice(basketTotal) }}</strong>
				<NcButton type="primary" :to="{ name: 'OrderBasket' }" @click="expanded = false">
					{{ t('shillinq', 'Go to basket') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import CartIcon from 'vue-material-design-icons/Cart.vue'

export default {
	name: 'OrderBasketPanel',
	components: {
		NcButton,
		CartIcon,
	},

	data() {
		return {
			expanded: false,
			lines: [],
			pollTimer: null,
		}
	},

	computed: {
		itemCount() {
			return this.lines.reduce((sum, line) => sum + (line.quantity || 0), 0)
		},
		basketTotal() {
			return this.lines.reduce(
				(sum, line) => sum + (line.quantity || 0) * (line.unitPrice || 0),
				0,
			)
		},
	},

	async created() {
		await this.loadBasket()
		this.pollTimer = setInterval(() => this.loadBasket(), 30000)
	},

	beforeDestroy() {
		if (this.pollTimer) {
			clearInterval(this.pollTimer)
		}
	},

	methods: {
		t(app, text) {
			return t(app, text)
		},

		formatPrice(price) {
			if (price == null) return '-'
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: 'EUR',
			}).format(price)
		},

		async loadBasket() {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/order-baskets/current')
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.lines = data.lines || []
				}
			} catch (error) {
				// Silently fail for the floating widget
			}
		},
	},
}
</script>

<style scoped>
.basket-panel {
	position: fixed;
	bottom: 24px;
	right: 24px;
	z-index: 1000;
}

.basket-panel__toggle {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 16px;
	background-color: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 24px;
	cursor: pointer;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
	font-size: 14px;
	font-weight: 600;
}

.basket-panel__toggle:hover {
	opacity: 0.9;
}

.basket-panel__badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 20px;
	height: 20px;
	padding: 0 6px;
	background-color: var(--color-error);
	color: #fff;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 700;
}

.basket-panel__total {
	font-size: 13px;
}

.basket-panel__dropdown {
	position: absolute;
	bottom: 56px;
	right: 0;
	width: 320px;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
	padding: 16px;
}

.basket-panel__title {
	margin: 0 0 12px;
	font-size: 16px;
	font-weight: 600;
}

.basket-panel__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 12px 0;
}

.basket-panel__lines {
	list-style: none;
	margin: 0;
	padding: 0;
	max-height: 200px;
	overflow-y: auto;
}

.basket-panel__line {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border-dark);
	font-size: 13px;
}

.basket-panel__line-name {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	margin-right: 8px;
}

.basket-panel__line-qty {
	color: var(--color-text-maxcontrast);
	margin-right: 8px;
}

.basket-panel__line-price {
	font-weight: 600;
	white-space: nowrap;
}

.basket-panel__footer {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-top: 12px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}
</style>
