<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LedgerSealCheckModal: check the seal chain of the active administration
 (ledger-sealed-entries, REQ-LSE-002).

 The controller sees the chain intact over N entries, or the first
 transaction whose content or link no longer matches its seal. Only an admin
 or a controller of the administration may run it; the server refuses others
 and the dialog shows that sentence.

 Opened with spawnDialog from the header action on the general ledger page
 (src/utils/ledgerSealApi.js), so it lives in its own file (gate 13).

 @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md#requirement-a-controller-checks-the-seal-chain-req-lse-002
-->

<template>
	<NcDialog
		:name="t('shillinq', 'Check ledger seal')"
		size="normal"
		data-testid="ledger-seal-modal"
		@closing="close">
		<div class="lsc">
			<p v-if="checking" data-testid="ledger-seal-checking">
				{{ t('shillinq', 'Checking the ledger seal…') }}
			</p>
			<p
				v-else-if="result && result.intact"
				class="lsc__intact"
				role="status"
				data-testid="ledger-seal-intact">
				{{ result.message }}
			</p>
			<template v-else-if="result">
				<p class="lsc__broken" role="alert" data-testid="ledger-seal-broken">
					{{ result.message }}
				</p>
				<p v-if="reasonText" data-testid="ledger-seal-reason">
					{{ reasonText }}
				</p>
			</template>
			<p
				v-if="error"
				class="lsc__error"
				role="alert"
				data-testid="ledger-seal-error">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="close">
				{{ t('shillinq', 'Close') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="checking || !administrationId"
				data-testid="ledger-seal-recheck"
				@click="check">
				{{ t('shillinq', 'Check again') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog } from '@nextcloud/vue'
import { checkLedgerSeal, sealCheckError } from '../utils/ledgerSealApi.js'

export default {
	name: 'LedgerSealCheckModal',

	components: { NcButton, NcDialog },

	emits: ['close'],

	data() {
		return {
			administrationId: '',
			checking: false,
			result: null,
			error: '',
		}
	},

	computed: {
		/**
		 * What kind of break the check found, in words.
		 *
		 * @return {string} The sentence, or '' when the chain is intact.
		 */
		reasonText() {
			const reasons = {
				content: t('shillinq', 'Its content changed after it was posted.'),
				link: t('shillinq', 'Its link to the entry before it no longer matches.'),
				sequence: t('shillinq', 'An entry is missing before it.'),
			}
			return (this.result && reasons[this.result.reason]) || ''
		},
	},

	/**
	 * Read the active administration, then check its chain.
	 *
	 * @return {Promise<void>}
	 */
	async mounted() {
		try {
			const context = await axios.get(
				generateUrl('/apps/shillinq/api/administrations/context'),
			)
			this.administrationId = String(
				context.data?.activeAdministrationId || '',
			)
		} catch (error) {
			this.error = sealCheckError(
				error,
				t('shillinq', 'The administration could not be loaded.'),
			)
			return
		}
		await this.check()
	},

	methods: {
		t,

		/**
		 * Check the chain of the active administration.
		 *
		 * @return {Promise<void>}
		 */
		async check() {
			if (!this.administrationId) {
				this.error = t('shillinq', 'Choose an administration first.')
				return
			}
			this.checking = true
			this.error = ''
			this.result = null
			try {
				this.result = await checkLedgerSeal(this.administrationId)
			} catch (error) {
				this.error = sealCheckError(
					error,
					t('shillinq', 'The ledger seal could not be checked.'),
				)
			} finally {
				this.checking = false
			}
		},

		/**
		 * Close the dialog.
		 */
		close() {
			this.$emit('close', this.result)
		},
	},
}
</script>

<style scoped>
.lsc {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.lsc__intact {
	color: var(--color-success-text, var(--color-main-text));
}

.lsc__broken,
.lsc__error {
	color: var(--color-error);
}
</style>
