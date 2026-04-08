<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<nav class="shillinq-breadcrumb" aria-label="breadcrumb">
		<ol class="shillinq-breadcrumb__list">
			<li v-for="(item, index) in items"
				:key="index"
				class="shillinq-breadcrumb__item">
				<router-link v-if="item.route && index < items.length - 1"
					:to="item.route"
					class="shillinq-breadcrumb__link">
					{{ item.label }}
				</router-link>
				<span v-else class="shillinq-breadcrumb__current">
					{{ item.label }}
				</span>
				<ChevronRightIcon v-if="index < items.length - 1"
					:size="16"
					class="shillinq-breadcrumb__separator" />
			</li>
		</ol>
	</nav>
</template>

<script>
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'

export default {
	name: 'Breadcrumb',
	components: {
		ChevronRightIcon,
	},
	props: {
		items: {
			type: Array,
			default: () => [],
		},
	},
}
</script>

<style scoped>
.shillinq-breadcrumb__list {
	display: flex;
	align-items: center;
	list-style: none;
	margin: 0 0 12px;
	padding: 0;
	gap: 4px;
}

.shillinq-breadcrumb__item {
	display: flex;
	align-items: center;
	gap: 4px;
}

.shillinq-breadcrumb__link {
	color: var(--color-primary);
	text-decoration: none;
}

.shillinq-breadcrumb__link:hover {
	text-decoration: underline;
}

.shillinq-breadcrumb__current {
	color: var(--color-text-maxcontrast);
}

.shillinq-breadcrumb__separator {
	color: var(--color-text-maxcontrast);
}
</style>
