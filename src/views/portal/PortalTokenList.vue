<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-5.1
-->
<template>
	<div class="portal-token-list">
		<header class="portal-token-list__header">
			<h2>{{ t('shillinq', 'Portal Tokens') }}</h2>
			<NcButton type="primary" @click="showGenerateDialog = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('shillinq', 'Generate Token') }}
			</NcButton>
		</header>

		<NcLoadingIcon v-if="loading" />

		<table v-else class="portal-token-list__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Description') }}</th>
					<th>{{ t('shillinq', 'Organisation') }}</th>
					<th>{{ t('shillinq', 'Active') }}</th>
					<th>{{ t('shillinq', 'Expires') }}</th>
					<th>{{ t('shillinq', 'Last Used') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="token in tokens"
					:key="token.id"
					class="portal-token-list__row"
					@click="$router.push({ name: 'PortalTokenDetail', params: { tokenId: token.id } })">
					<td>{{ token.description || '—' }}</td>
					<td>{{ token.organizationId }}</td>
					<td>
						<span :class="token.isActive ? 'badge--active' : 'badge--inactive'">
							{{ token.isActive ? t('shillinq', 'Yes') : t('shillinq', 'No') }}
						</span>
					</td>
					<td>{{ token.expiresAt || t('shillinq', 'Never') }}</td>
					<td>{{ token.lastUsedAt || t('shillinq', 'Never') }}</td>
				</tr>
			</tbody>
		</table>

		<NcDialog
			v-if="showGenerateDialog"
			:name="t('shillinq', 'Generate Portal Token')"
			@close="closeGenerateDialog">
			<div v-if="generatedToken" class="portal-token-list__generated">
				<p><strong>{{ t('shillinq', 'Token generated successfully. Copy it now — it will not be shown again.') }}</strong></p>
				<div class="portal-token-list__token-display">
					<code>{{ generatedToken }}</code>
					<NcButton @click="copyToken">
						{{ t('shillinq', 'Copy') }}
					</NcButton>
				</div>
			</div>
			<div v-else class="portal-token-list__generate-form">
				<NcTextField
					:label="t('shillinq', 'Organisation ID')"
					:value.sync="newToken.organizationId" />
				<NcTextField
					:label="t('shillinq', 'Description')"
					:value.sync="newToken.description" />
				<NcButton type="primary" @click="generateToken">
					{{ t('shillinq', 'Generate') }}
				</NcButton>
			</div>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { usePortalStore } from '../../store/modules/portal.js'

export default {
	name: 'PortalTokenList',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcTextField,
		PlusIcon,
	},
	data() {
		return {
			showGenerateDialog: false,
			generatedToken: null,
			newToken: {
				organizationId: '',
				description: '',
			},
		}
	},
	computed: {
		portalStore() {
			return usePortalStore()
		},
		tokens() {
			return this.portalStore.tokens
		},
		loading() {
			return this.portalStore.tokenLoading
		},
	},
	created() {
		this.portalStore.fetchTokens()
	},
	methods: {
		async generateToken() {
			const result = await this.portalStore.generateToken(
				this.newToken.organizationId,
				this.newToken.description,
			)
			if (result) {
				this.generatedToken = result.rawToken
			}
		},
		closeGenerateDialog() {
			this.showGenerateDialog = false
			this.generatedToken = null
			this.newToken = { organizationId: '', description: '' }
		},
		copyToken() {
			navigator.clipboard.writeText(this.generatedToken)
		},
	},
}
</script>

<style scoped>
.portal-token-list {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.portal-token-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.portal-token-list__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.portal-token-list__table {
	width: 100%;
	border-collapse: collapse;
}

.portal-token-list__table th,
.portal-token-list__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.portal-token-list__row {
	cursor: pointer;
}

.portal-token-list__row:hover {
	background: var(--color-background-hover);
}

.badge--active {
	color: var(--color-success);
	font-weight: 600;
}

.badge--inactive {
	color: var(--color-text-maxcontrast);
}

.portal-token-list__generate-form,
.portal-token-list__generated {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.portal-token-list__token-display {
	display: flex;
	gap: 8px;
	align-items: center;
}

.portal-token-list__token-display code {
	background: var(--color-background-dark);
	padding: 8px 12px;
	border-radius: var(--border-radius);
	word-break: break-all;
	flex: 1;
}
</style>
