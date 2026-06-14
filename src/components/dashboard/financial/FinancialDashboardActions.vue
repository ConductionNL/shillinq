<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  Quick-access header buttons on the Financial overview dashboard.
  Wired as the Dashboard page actionsComponent in manifest.json.
-->
<template>
	<div class="financial-dashboard-actions">
		<NcButton @click="goTo('SupplierInvoices')">
			<template #icon>
				<InboxArrowDown :size="20" />
			</template>
			{{ t('shillinq', 'Import bill') }}
		</NcButton>
		<NcButton type="primary" @click="goTo('AccountsReceivable')">
			<template #icon>
				<FileDocumentPlus :size="20" />
			</template>
			{{ t('shillinq', 'Create invoice') }}
		</NcButton>
		<NcButton @click="goTo('BankReconciliation')">
			<template #icon>
				<BankTransferIn :size="20" />
			</template>
			{{ t('shillinq', 'Import bank') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import InboxArrowDown from 'vue-material-design-icons/InboxArrowDown.vue'
import FileDocumentPlus from 'vue-material-design-icons/FileDocumentPlus.vue'
import BankTransferIn from 'vue-material-design-icons/BankTransferIn.vue'

export default {
	name: 'FinancialDashboardActions',
	components: {
		NcButton,
		InboxArrowDown,
		FileDocumentPlus,
		BankTransferIn,
	},
	methods: {
		goTo(routeName) {
			this.$router.push({ name: routeName })
		},
	},
}
</script>

<style scoped>
.financial-dashboard-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}
</style>
