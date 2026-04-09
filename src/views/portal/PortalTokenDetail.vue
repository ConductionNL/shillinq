<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/general/tasks.md#task-5.1
-->
<template>
	<div class="portal-token-detail">
		<div class="portal-token-detail__header">
			<h2>{{ token.description || t('shillinq', 'Portal Token') }}</h2>
			<NcButton
				v-if="token.isActive"
				type="error"
				@click="deactivate">
				{{ t('shillinq', 'Deactivate') }}
			</NcButton>
		</div>

		<div class="portal-token-detail__properties">
			<dl>
				<dt>{{ t('shillinq', 'Organization ID') }}</dt>
				<dd>{{ token.organizationId }}</dd>
				<dt>{{ t('shillinq', 'Active') }}</dt>
				<dd>{{ token.isActive ? t('shillinq', 'Yes') : t('shillinq', 'No') }}</dd>
				<dt>{{ t('shillinq', 'Expires') }}</dt>
				<dd>{{ token.expiresAt || t('shillinq', 'Never') }}</dd>
				<dt>{{ t('shillinq', 'Last Used') }}</dt>
				<dd>{{ token.lastUsedAt || t('shillinq', 'Never') }}</dd>
				<dt>{{ t('shillinq', 'Permissions') }}</dt>
				<dd>{{ (token.permissions || []).join(', ') || t('shillinq', 'None') }}</dd>
			</dl>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { usePortalStore } from '../../store/modules/portal.js'

export default {
	name: 'PortalTokenDetail',
	components: {
		NcButton,
	},
	data() {
		return {
			portalStore: usePortalStore(),
		}
	},
	computed: {
		token() {
			const tokenId = this.$route.params.tokenId
			return this.portalStore.portalTokens.find((t) => t.id === tokenId) || {}
		},
	},
	mounted() {
		if (this.portalStore.portalTokens.length === 0) {
			this.portalStore.fetchTokens()
		}
	},
	methods: {
		async deactivate() {
			const objectStore = (await import('../../store/modules/object.js')).useObjectStore()
			console.info('Deactivating token:', this.token.id)
			this.portalStore.fetchTokens()
		},
	},
}
</script>

<style scoped>
.portal-token-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.portal-token-detail__properties dl {
	display: grid;
	grid-template-columns: 150px 1fr;
	gap: 8px;
}

.portal-token-detail__properties dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}
</style>
