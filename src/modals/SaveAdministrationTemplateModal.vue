<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SaveAdministrationTemplateModal: save an administration's chart of accounts
 as an office template (ledger-multi-administration 3.2, REQ-LMA-003).

 Asks the template's name and posts it to
 AdministrationTemplateController::saveAs. The server copies the structure
 only, never postings, relations or balances. A refusal is shown as the
 server words it.

 Opened by the Save as template header action on an administration's page
 (src/utils/administrationTemplateActions.js) through spawnDialog. Its own
 file for hydra gate-13.

 @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-an-office-saves-an-administration-as-a-template-req-lma-003
-->

<template>
	<NcDialog
		:name="t('shillinq', 'Save as template')"
		size="small"
		data-testid="save-template-modal"
		@closing="onClose">
		<div class="sat">
			<p>
				{{
					t(
						'shillinq',
						'Other administrations can start from this chart of accounts. Postings, relations and balances are not copied.',
					)
				}}
			</p>
			<NcTextField
				v-model="name"
				:label="t('shillinq', 'Template name')"
				data-testid="save-template-name" />
			<p
				v-if="error"
				class="sat__error"
				role="alert"
				data-testid="save-template-error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="onClose">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="submitting"
				data-testid="save-template-submit"
				@click="submit">
				{{ t('shillinq', 'Save template') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import { saveAdministrationAsTemplate } from '../utils/administrationTemplateActions.js'

export default {
	name: 'SaveAdministrationTemplateModal',

	components: { NcButton, NcDialog, NcTextField },

	props: {
		// The administration whose chart of accounts becomes the template.
		administrationId: {
			type: String,
			required: true,
		},

		// The administration's name, offered as the template's name.
		suggestedName: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			name: this.suggestedName,
			submitting: false,
			error: '',
		}
	},

	methods: {
		t,

		/**
		 * Save the template and close with it.
		 *
		 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-an-office-saves-an-administration-as-a-template-req-lma-003
		 */
		async submit() {
			this.error = ''
			const name = String(this.name || '').trim()
			if (name === '') {
				this.error = t('shillinq', 'Give the template a name.')
				return
			}
			this.submitting = true
			try {
				const template = await saveAdministrationAsTemplate(this.administrationId, name)
				showSuccess(t('shillinq', 'Template saved.'))
				this.$emit('close', template)
			} catch (error) {
				const data = error?.response?.data
				this.error = String(data?.error || data?.message || t('shillinq', 'The template could not be saved.'))
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close without saving.
		 *
		 * @spec exclude Dialog plumbing.
		 */
		onClose() {
			this.$emit('close', null)
		},
	},
}
</script>

<style scoped>
.sat {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.sat__error {
	color: var(--color-error-text);
}
</style>
