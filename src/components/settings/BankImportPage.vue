<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  Import bank statements — a Configuratie (settings) page that hosts the
  BankStatementWizard. Moved here from the Financial overview dashboard
  actions so importing bank statements lives with the other setup tasks
  rather than as a prominent dashboard button. The wizard modal lives in
  its own file under src/modals/ (hydra gate-13 modal isolation).
-->
<template>
	<div class="bank-import-page" data-testid="bank-import-page">
		<header class="bank-import-page__header">
			<h2>{{ t('shillinq', 'Import bank statements') }}</h2>
			<p class="bank-import-page__hint">
				{{
					t(
						'shillinq',
						'Upload a bank statement (CAMT.053 / MT940 / CSV) to import its transactions and reconcile them against your invoices and bills.',
					)
				}}
			</p>
		</header>

		<NcButton
			variant="primary"
			data-testid="bank-import-launch"
			@click="showBankStatementWizard = true">
			<template #icon>
				<BankTransferIn :size="20" />
			</template>
			{{ t('shillinq', 'Import bank statement') }}
		</NcButton>

		<BankStatementWizard
			:open="showBankStatementWizard"
			@close="showBankStatementWizard = false" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import BankTransferIn from 'vue-material-design-icons/BankTransferIn.vue'
import BankStatementWizard from '../../modals/BankStatementWizard.vue'

export default {
	name: 'BankImportPage',
	components: {
		NcButton,
		BankTransferIn,
		BankStatementWizard,
	},

	data() {
		return {
			showBankStatementWizard: false,
		}
	},

	methods: { t },
}
</script>

<style scoped>
.bank-import-page {
	padding: 1rem;
	display: flex;
	flex-direction: column;
	gap: 1rem;
	align-items: flex-start;
}

.bank-import-page__hint {
	color: var(--color-text-maxcontrast);
	margin: 0.25rem 0 0 0;
	max-width: 44rem;
}
</style>
