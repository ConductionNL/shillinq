<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  Quick-access header buttons on the Financial overview dashboard.
  Wired as the Dashboard page actionsComponent in manifest.json.

  The "Create invoice" button launches the InvoiceQuickDraftModal
  (shillinq-invoice-quick-draft) instead of navigating to the AR index,
  so the bookkeeper drafts a one-line invoice without losing the
  dashboard context. The modal lives in its own file under src/modals/
  (hydra gate-13 modal isolation) and is hosted here because this
  component owns the launch button.
-->
<template>
	<div class="financial-dashboard-actions">
		<NcButton @click="goTo('SupplierInvoices')">
			<template #icon>
				<InboxArrowDown :size="20" />
			</template>
			{{ t('shillinq', 'Import bill') }}
		</NcButton>
		<NcButton type="primary" data-testid="fda-create-invoice" @click="showInvoiceQuickDraftModal = true">
			<template #icon>
				<FileDocumentPlus :size="20" />
			</template>
			{{ t('shillinq', 'Create invoice') }}
		</NcButton>
		<NcButton data-testid="fda-import-bank" @click="showBankStatementWizard = true">
			<template #icon>
				<BankTransferIn :size="20" />
			</template>
			{{ t('shillinq', 'Import bank') }}
		</NcButton>

		<InvoiceQuickDraftModal
			:open="showInvoiceQuickDraftModal"
			@close="showInvoiceQuickDraftModal = false" />
		<BankStatementWizard
			:open="showBankStatementWizard"
			@close="showBankStatementWizard = false" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import InboxArrowDown from 'vue-material-design-icons/InboxArrowDown.vue'
import FileDocumentPlus from 'vue-material-design-icons/FileDocumentPlus.vue'
import BankTransferIn from 'vue-material-design-icons/BankTransferIn.vue'
import InvoiceQuickDraftModal from '../../../modals/InvoiceQuickDraftModal.vue'
import BankStatementWizard from '../../../modals/BankStatementWizard.vue'

export default {
	name: 'FinancialDashboardActions',
	components: {
		NcButton,
		InboxArrowDown,
		FileDocumentPlus,
		BankTransferIn,
		InvoiceQuickDraftModal,
		BankStatementWizard,
	},
	data() {
		return {
			showInvoiceQuickDraftModal: false,
			showBankStatementWizard: false,
		}
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
