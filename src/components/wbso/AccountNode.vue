<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Recursive Account tree node used by ChartOfAccountsView (REQ-WBSO-006).
 Each node displays the account number, name, type, and status, plus an
 expand/collapse toggle when it has children.

 @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-20
-->
<template>
	<li
		role="treeitem"
		class="wbso-account-node"
		:aria-expanded="hasChildren ? expanded : null">
		<div class="wbso-account-node__row" :style="{ paddingLeft: indent }">
			<button
				v-if="hasChildren"
				type="button"
				class="wbso-account-node__toggle"
				:aria-label="
					expanded ? t('shillinq', 'Collapse') : t('shillinq', 'Expand')
				"
				@click="expanded = !expanded">
				{{ expanded ? '▾' : '▸' }}
			</button>
			<span v-else class="wbso-account-node__leaf-bullet">•</span>
			<span class="wbso-account-node__number">{{
				account.accountNumber
			}}</span>
			<span class="wbso-account-node__name">{{ account.name }}</span>
			<span class="wbso-account-node__type">{{
				translateType(account.accountType)
			}}</span>
			<span class="wbso-account-node__status" :data-status="account.status">{{
				translateStatus(account.status)
			}}</span>
		</div>
		<ul
			v-if="hasChildren && expanded"
			class="wbso-account-node__children"
			role="group">
			<AccountNode
				v-for="child in account.children"
				:key="child.accountNumber"
				:account="child"
				:depth="depth + 1" />
		</ul>
	</li>
</template>

<script>
export default {
	name: 'AccountNode',

	props: {
		account: {
			type: Object,
			required: true,
		},

		depth: {
			type: Number,
			default: 0,
		},
	},

	data() {
		return {
			expanded: true,
		}
	},

	computed: {
		hasChildren() {
			return (
				Array.isArray(this.account.children)
				&& this.account.children.length > 0
			)
		},

		indent() {
			return `${this.depth * 1.25}rem`
		},
	},

	methods: {
		/**
		 * Translate an accountType slug into the user-facing label.
		 *
		 * @param {string} type One of assets|liabilities|equity|revenue|expenses.
		 * @return {string} Localised label.
		 */
		translateType(type) {
			const map = {
				assets: t('shillinq', 'Assets'),
				liabilities: t('shillinq', 'Liabilities'),
				equity: t('shillinq', 'Equity'),
				revenue: t('shillinq', 'Revenue'),
				expenses: t('shillinq', 'Expenses'),
			}
			return map[type] || type
		},

		/**
		 * Translate a status slug.
		 *
		 * @param {string} status One of active|blocked|archived.
		 * @return {string} Localised label.
		 */
		translateStatus(status) {
			const map = {
				active: t('shillinq', 'Active'),
				blocked: t('shillinq', 'Blocked'),
				archived: t('shillinq', 'Archived'),
			}
			return map[status] || status
		},
	},
}
</script>

<style scoped>
.wbso-account-node {
	list-style: none;
}

.wbso-account-node__row {
	display: flex;
	gap: 0.5rem;
	padding: 0.25rem 0;
	align-items: center;
}

.wbso-account-node__toggle,
.wbso-account-node__leaf-bullet {
	width: 1.25rem;
	text-align: center;
}

.wbso-account-node__toggle {
	background: none;
	border: 0;
	cursor: pointer;
}

.wbso-account-node__number {
	font-weight: bold;
	min-width: 4rem;
}

.wbso-account-node__type {
	color: var(--color-text-maxcontrast);
}

.wbso-account-node__status[data-status='blocked'],
.wbso-account-node__status[data-status='archived'] {
	color: var(--color-error);
}

.wbso-account-node__children {
	padding-left: 0;
}
</style>
