<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/core/tasks.md#task-8.2
-->
<template>
	<nav class="shillinq-breadcrumb" aria-label="Breadcrumb">
		<ol>
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
				<ChevronRight v-if="index < items.length - 1" :size="16" class="shillinq-breadcrumb__sep" />
			</li>
		</ol>
	</nav>
</template>

<script>
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'

export default {
	name: 'ShillinqBreadcrumb',
	components: {
		ChevronRight,
	},
	props: {
		items: {
			type: Array,
			required: true,
		},
	},
}
</script>

<style scoped>
.shillinq-breadcrumb ol {
	display: flex;
	align-items: center;
	list-style: none;
	margin: 0 0 12px;
	padding: 0;
	flex-wrap: wrap;
}

.shillinq-breadcrumb__item {
	display: flex;
	align-items: center;
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

.shillinq-breadcrumb__sep {
	margin: 0 4px;
	color: var(--color-text-maxcontrast);
}
</style>
