<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-5.1
-->
<template>
	<div class="portal-token-detail">
		<header class="portal-token-detail__header">
			<h2>{{ token.description || t('shillinq', 'Portal Token') }}</h2>
			<NcButton
				v-if="token.isActive"
				type="error"
				@click="deactivate">
				{{ t('shillinq', 'Deactivate') }}
			</NcButton>
		</header>

		<div class="portal-token-detail__properties">
			<dl>
				<dt>{{ t('shillinq', 'Organisation') }}</dt>
				<dd>{{ token.organizationId }}</dd>
				<dt>{{ t('shillinq', 'Active') }}</dt>
				<dd>{{ token.isActive ? t('shillinq', 'Yes') : t('shillinq', 'No') }}</dd>
				<dt>{{ t('shillinq', 'Expires') }}</dt>
				<dd>{{ token.expiresAt || t('shillinq', 'Never') }}</dd>
				<dt>{{ t('shillinq', 'Last Used') }}</dt>
				<dd>{{ token.lastUsedAt || t('shillinq', 'Never') }}</dd>
				<dt>{{ t('shillinq', 'Permissions') }}</dt>
				<dd>{{ (token.permissions || []).join(', ') || '—' }}</dd>
			</dl>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { usePortalStore } from '../../store/modules/portal.js'

export default {
	name: 'PortalTokenDetail',
	components: {
		NcButton,
	},
	computed: {
		portalStore() {
			return usePortalStore()
		},
		token() {
			const id = this.$route.params.tokenId
			return this.portalStore.tokens.find((t) => t.id === id) || {}
		},
	},
	async created() {
		if (this.portalStore.tokens.length === 0) {
			await this.portalStore.fetchTokens()
		}
	},
	methods: {
		async deactivate() {
			try {
				const url = new URL(generateUrl('/apps/openregister/api/objects'), window.location.origin)
				url.searchParams.set('id', this.token.id)
				await fetch(url.toString(), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ isActive: false }),
				})
				await this.portalStore.fetchTokens()
			} catch (error) {
				console.error('Failed to deactivate token:', error)
			}
		},
	},
}
</script>

<style scoped>
.portal-token-detail {
	padding: 8px 4px 24px;
	max-width: 900px;
}

.portal-token-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.portal-token-detail__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.portal-token-detail__properties dl {
	display: grid;
	grid-template-columns: 160px 1fr;
	gap: 8px 16px;
}

.portal-token-detail__properties dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}
</style>
