<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Chart-of-Accounts tree view (REQ-WBSO-006). Renders the hierarchical RGS
 chart-of-accounts for the active administration with expand/collapse,
 detail navigation, and an "Add Account" affordance for users with write
 permission. Reads from /api/wbso-sno/accounts/hierarchy via the
 WbsoAccountApiController.

 @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-20
-->
<template>
	<NcAppContent>
		<div class="wbso-chart-of-accounts">
			<header class="wbso-chart-of-accounts__header">
				<h2>{{ t('shillinq', 'Chart of Accounts') }}</h2>
				<NcButton v-if="canCreate" variant="primary" @click="onAddAccount">
					{{ t('shillinq', 'Add Account') }}
				</NcButton>
			</header>

			<NcEmptyContent
				v-if="!loading && roots.length === 0"
				:name="t('shillinq', 'No accounts yet')"
				:description="
					t(
						'shillinq',
						'Create the first account in the chart-of-accounts to start bookkeeping.',
					)
				" />

			<div v-else-if="loading" class="wbso-chart-of-accounts__loading">
				{{ t('shillinq', 'Loading…') }}
			</div>

			<ul v-else class="wbso-chart-of-accounts__tree" role="tree">
				<AccountNode
					v-for="account in roots"
					:key="account.accountNumber"
					:account="account"
					:depth="0" />
			</ul>

			<p
				v-if="errorMessage"
				class="wbso-chart-of-accounts__error"
				role="alert">
				{{ errorMessage }}
			</p>
		</div>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { NcAppContent, NcButton, NcEmptyContent } from '@nextcloud/vue'
import AccountNode from '../../components/wbso/AccountNode.vue'

export default {
	name: 'ChartOfAccountsView',

	components: {
		NcAppContent,
		NcButton,
		NcEmptyContent,
		AccountNode,
	},

	data() {
		return {
			roots: [],
			loading: true,
			errorMessage: '',
			canCreate: false,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the hierarchical chart-of-accounts from the API.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.errorMessage = ''
			try {
				const url = generateOcsUrl('apps/shillinq/api/v1/accounts/hierarchy')
				const { data } = await axios.get(url)
				this.roots = data?.ocs?.data?.tree ?? data?.tree ?? []
				this.canCreate =
					data?.ocs?.data?.canCreate ?? data?.canCreate ?? false
			} catch (error) {
				this.errorMessage = t(
					'shillinq',
					'Failed to load Chart of Accounts.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Navigate the user toward the create-account form. Until a dedicated
		 * detail modal exists, deep-linking through the manifest custom
		 * component keeps the affordance discoverable without inlining a
		 * modal in the parent view (ADR-024 isolation rule).
		 *
		 * @return {void}
		 */
		onAddAccount() {
			this.$emit('add-account')
		},
	},
}
</script>

<style scoped>
.wbso-chart-of-accounts {
	padding: 1rem;
}

.wbso-chart-of-accounts__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1rem;
}

.wbso-chart-of-accounts__tree {
	list-style: none;
	padding-left: 0;
}

.wbso-chart-of-accounts__loading,
.wbso-chart-of-accounts__error {
	padding: 0.5rem 0;
}

.wbso-chart-of-accounts__error {
	color: var(--color-error);
}
</style>
