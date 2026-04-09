<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@see openspec/changes/general/tasks.md#task-5.1
-->
<template>
	<div class="portal-token-list">
		<div class="portal-token-list__header">
			<h2>{{ t('shillinq', 'Portal Tokens') }}</h2>
			<NcButton
				type="primary"
				@click="showGenerateDialog = true">
				{{ t('shillinq', 'Generate Token') }}
			</NcButton>
		</div>

		<table class="portal-token-list__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Description') }}</th>
					<th>{{ t('shillinq', 'Organization') }}</th>
					<th>{{ t('shillinq', 'Active') }}</th>
					<th>{{ t('shillinq', 'Expires') }}</th>
					<th>{{ t('shillinq', 'Last Used') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="token in portalStore.portalTokens"
					:key="token.id"
					@click="openToken(token)">
					<td>{{ token.description || t('shillinq', 'No description') }}</td>
					<td>{{ token.organizationId }}</td>
					<td>
						<span :class="token.isActive ? 'status--active' : 'status--inactive'">
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
			@close="showGenerateDialog = false">
			<div class="generate-form">
				<label>{{ t('shillinq', 'Organization ID') }}</label>
				<input
					v-model="newToken.organizationId"
					type="text">
				<label>{{ t('shillinq', 'Description') }}</label>
				<input
					v-model="newToken.description"
					type="text">
				<NcButton
					type="primary"
					@click="generateToken">
					{{ t('shillinq', 'Generate') }}
				</NcButton>
			</div>
		</NcDialog>

		<NcDialog
			v-if="generatedToken"
			:name="t('shillinq', 'Token Generated')"
			@close="generatedToken = null">
			<div class="token-display">
				<p>{{ t('shillinq', 'Copy this token now. It will not be shown again.') }}</p>
				<div class="token-display__value">
					<code>{{ generatedToken }}</code>
					<NcButton @click="copyToken">
						{{ t('shillinq', 'Copy') }}
					</NcButton>
				</div>
			</div>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { usePortalStore } from '../../store/modules/portal.js'

export default {
	name: 'PortalTokenList',
	components: {
		NcButton,
		NcDialog,
	},
	data() {
		return {
			portalStore: usePortalStore(),
			showGenerateDialog: false,
			generatedToken: null,
			newToken: {
				organizationId: '',
				description: '',
			},
		}
	},
	mounted() {
		this.portalStore.fetchTokens()
	},
	methods: {
		openToken(token) {
			this.$router.push({ name: 'PortalTokenDetail', params: { tokenId: token.id } })
		},
		async generateToken() {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/portal/tokens')
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(this.newToken),
				})
				if (response.ok) {
					const data = await response.json()
					this.generatedToken = data.raw
					this.showGenerateDialog = false
					this.portalStore.fetchTokens()
				}
			} catch (error) {
				console.error('Failed to generate token:', error)
			}
		},
		copyToken() {
			navigator.clipboard.writeText(this.generatedToken)
		},
	},
}
</script>

<style scoped>
.portal-token-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
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

.portal-token-list__table tr:hover {
	background: var(--color-background-hover);
	cursor: pointer;
}

.status--active {
	color: var(--color-success);
}

.status--inactive {
	color: var(--color-error);
}

.generate-form,
.token-display {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.generate-form label {
	font-weight: bold;
}

.token-display__value {
	display: flex;
	align-items: center;
	gap: 8px;
}

.token-display__value code {
	flex: 1;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	word-break: break-all;
}
</style>
