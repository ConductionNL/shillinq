<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 UnmatchedClassifyDialog: classify selected unmatched items with one reason.

 The unmatched items bulk actions used to declare an api-call to
 "/api/reconciliations/:reconId/matches/bulk-resolve": a URL outside the
 shillinq app route, with a `:reconId` token nothing fills and no reason in
 the body the endpoint requires. This dialog asks for the reason, groups the
 selected matches by their reconciliation and posts each group to
 /apps/shillinq/api/reconciliations/{reconId}/matches/bulk-resolve.

 Opened with spawnDialog from src/utils/bankMatchActions.js (hydra gate-13).

 @spec openspec/changes/banking-manual-match/tasks.md#task-1.1
-->

<template>
	<NcDialog
		:name="title"
		size="normal"
		data-testid="unmatched-classify-dialog"
		@closing="close">
		<p>
			{{
				t('shillinq', '{count} items selected.', {
					count: selectedIds.length,
				})
			}}
		</p>
		<NcTextField
			v-model="reason"
			:label="t('shillinq', 'Reason')"
			data-testid="unmatched-classify-reason" />
		<p
			v-if="error"
			class="ucd__error"
			role="alert"
			data-testid="unmatched-classify-error">
			{{ error }}
		</p>

		<template #actions>
			<NcButton @click="close">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="reason.trim() === '' || submitting"
				data-testid="unmatched-classify-confirm"
				@click="confirm">
				{{ t('shillinq', 'Classify') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import { classifyUnmatchedItems } from '../utils/bankMatchApi.js'

export default {
	name: 'UnmatchedClassifyDialog',
	components: {
		NcButton,
		NcDialog,
		NcTextField,
	},

	props: {
		/** ReconciliationMatch ids from the selection. */
		selectedIds: {
			type: Array,
			required: true,
		},

		/** `timing`, `pending` or `adjustment`. */
		resolutionStatus: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],
	data() {
		return {
			reason: '',
			error: '',
			submitting: false,
		}
	},

	computed: {
		/**
		 * The dialog title for the chosen classification.
		 *
		 * @spec openspec/changes/banking-manual-match/tasks.md#task-1.1
		 */
		title() {
			return (
				{
					timing: t('shillinq', 'Classify as timing'),
					pending: t('shillinq', 'Classify as pending'),
					adjustment: t('shillinq', 'Classify as adjustment'),
				}[this.resolutionStatus] || t('shillinq', 'Classify')
			)
		},
	},

	methods: {
		t,

		/**
		 * Classify the selection with the reason and report the totals.
		 *
		 * @spec openspec/changes/banking-manual-match/tasks.md#task-1.1
		 */
		async confirm() {
			this.submitting = true
			this.error = ''
			try {
				const result = await classifyUnmatchedItems(
					this.selectedIds,
					this.resolutionStatus,
					this.reason.trim(),
				)
				if (result.failed > 0) {
					showError(
						t(
							'shillinq',
							'{failed} of {total} items could not be classified.',
							{
								failed: result.failed,
								total: this.selectedIds.length,
							},
						),
					)
				} else {
					showSuccess(
						t('shillinq', '{count} items classified.', {
							count: result.applied,
						}),
					)
				}
				emit('cn:page:refresh', {})
				this.$emit('close', result)
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('shillinq', 'The items could not be classified.')
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close without classifying.
		 *
		 * @spec openspec/changes/banking-manual-match/tasks.md#task-1.1
		 */
		close() {
			this.$emit('close', null)
		},
	},
}
</script>

<style scoped>
.ucd__error {
	color: var(--color-error-text);
}
</style>
