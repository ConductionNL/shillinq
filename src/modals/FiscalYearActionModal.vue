<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 FiscalYearActionModal: run one record action for a fiscal year
 (tax-cbcr-and-pillar2-returns, tasks 6.1 to 6.3).

 Asks for the fiscal year, posts it to the action's endpoint for the open
 record, and says what came back: done, or the refusal the server gave.
 Generic: the header action names the endpoint, the title and the hint, so
 "Assess scope" and "Read figures from the books" share it.

 Opened by a declarative open-modal header action, so it lives in its own
 file (hydra gate-13).

 @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
-->

<template>
	<NcDialog
		v-if="open"
		:name="title"
		size="normal"
		data-testid="fiscal-year-action-modal"
		@closing="onClose">
		<div class="fya">
			<p v-if="hint" class="fya__hint">
				{{ hint }}
			</p>
			<NcTextField
				v-model="fiscalYear"
				type="number"
				:label="t('shillinq', 'Fiscal year')"
				data-testid="fiscal-year-action-year" />
			<p
				v-if="done"
				class="fya__done"
				role="status"
				data-testid="fiscal-year-action-done">
				{{ doneText }}
			</p>
			<p
				v-if="error"
				class="fya__error"
				role="alert"
				data-testid="fiscal-year-action-error">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="onClose">
				{{ done ? t('shillinq', 'Close') : t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!done"
				variant="primary"
				:disabled="!canSubmit || submitting"
				data-testid="fiscal-year-action-submit"
				@click="submit">
				{{ submitLabel || t('shillinq', 'Run') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'

export default {
	name: 'FiscalYearActionModal',

	components: { NcButton, NcDialog, NcTextField },

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		// The app endpoint, with :id for the record, e.g. /api/cbcr/entities/:id/assess-scope.
		endpoint: {
			type: String,
			required: true,
		},

		title: {
			type: String,
			required: true,
		},

		hint: {
			type: String,
			default: '',
		},

		submitLabel: {
			type: String,
			default: '',
		},

		// The page widget to refresh after a successful run.
		refreshWidget: {
			type: String,
			default: '',
		},

		objectId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			fiscalYear: String(new Date().getFullYear() - 1),
			submitting: false,
			done: null,
			error: '',
		}
	},

	computed: {
		/**
		 * The record's id: the prop, else the route's :id.
		 *
		 * @return {string}
		 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
		 */
		effectiveId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},

		/**
		 * Whether a plausible year and a record are given.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
		 */
		canSubmit() {
			const year = Number(this.fiscalYear)
			return this.effectiveId !== '' && Number.isInteger(year) && year >= 2000 && year <= 2100
		},

		/**
		 * The line after a successful run, naming what was skipped or not read.
		 *
		 * @return {string}
		 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.2
		 */
		doneText() {
			const parts = [t('shillinq', 'Done for {year}.', { year: this.fiscalYear })]
			const skipped = Array.isArray(this.done?.skipped) ? this.done.skipped : []
			if (skipped.length > 0) {
				parts.push(t('shillinq', 'Left as they were (reconciled or locked): {list}.', { list: skipped.join(', ') }))
			}
			const notRead = this.done?.notRead && typeof this.done.notRead === 'object' ? Object.keys(this.done.notRead) : []
			if (notRead.length > 0) {
				parts.push(t('shillinq', 'Not read: {list}.', { list: notRead.join(', ') }))
			}
			return parts.join(' ')
		},
	},

	methods: {
		t,

		/**
		 * Post the year to the endpoint.
		 *
		 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
		 */
		async submit() {
			this.error = ''
			this.submitting = true
			try {
				const url = generateUrl('/apps/shillinq' + this.endpoint.replace(':id', encodeURIComponent(this.effectiveId)))
				const response = await axios.post(url, { fiscalYear: Number(this.fiscalYear) })
				this.done = response?.data ?? {}
				if (this.refreshWidget !== '') {
					emit('cn:widget:refresh', { widget: this.refreshWidget })
				}
			} catch (error) {
				this.error = error?.response?.data?.error || t('shillinq', 'The action could not be completed.')
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close the dialog.
		 *
		 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
		 */
		onClose() {
			this.$emit('close', this.done)
		},
	},
}
</script>

<style scoped>
.fya {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.fya__hint {
	color: var(--color-text-maxcontrast);
}

.fya__error {
	color: var(--color-error-text);
}
</style>
