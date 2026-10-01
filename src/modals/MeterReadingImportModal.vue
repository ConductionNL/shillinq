<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 MeterReadingImportModal: import a file of meter readings
 (sales-usage-billing REQ-USB-001).

 The bookkeeper picks a CSV file whose first line names the columns. Every
 valid row becomes an unrated reading in the active administration; every
 refused row is listed with its row number and the reason.

 Opened with spawnDialog from the "Import readings" header action on Meter
 readings (src/utils/usageBillingActions.js), so it lives in its own file
 (hydra gate-13).

 @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
-->

<template>
	<NcDialog
		:name="t('shillinq', 'Import readings')"
		size="normal"
		data-testid="meter-reading-import-modal"
		@closing="close">
		<div class="mri">
			<template v-if="!result">
				<p class="mri__hint">
					{{
						t(
							'shillinq',
							'Choose a CSV file. The first line names the columns: customerId, resourceType, quantity, periodStart and periodEnd, and optionally meterId, unit and ratePlanId.',
						)
					}}
				</p>
				<label class="mri__file">
					{{ t('shillinq', 'Readings file') }}
					<input
						type="file"
						accept=".csv,text/csv"
						data-testid="meter-reading-file"
						@change="onFile">
				</label>
				<p v-if="rows.length" data-testid="meter-reading-count">
					{{ t('shillinq', '{count} rows to import.', { count: rows.length }) }}
				</p>
			</template>

			<template v-else>
				<p data-testid="meter-reading-created">
					{{
						t('shillinq', '{count} readings imported. Rate them to see their amount.', {
							count: result.created.length,
						})
					}}
				</p>
				<template v-if="result.refused.length">
					<h3 class="mri__refused-title">
						{{ t('shillinq', 'Refused rows') }}
					</h3>
					<ul class="mri__refused" data-testid="meter-reading-refused">
						<li v-for="refusal in result.refused" :key="refusal.row">
							{{ t('shillinq', 'Row {row}: {reason}', refusal) }}
						</li>
					</ul>
				</template>
			</template>

			<p
				v-if="error"
				class="mri__error"
				role="alert"
				data-testid="meter-reading-error">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="close">
				{{ result ? t('shillinq', 'Close') : t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!result"
				variant="primary"
				:disabled="!canSubmit || submitting"
				data-testid="meter-reading-submit"
				@click="submit">
				{{ t('shillinq', 'Import') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog } from '@nextcloud/vue'
import { errorMessage } from '../utils/downPaymentApi.js'
import { importReadings, parseReadingsCsv } from '../utils/usageBilling.js'

export default {
	name: 'MeterReadingImportModal',

	components: { NcButton, NcDialog },

	emits: ['close'],

	data() {
		return {
			administrationId: '',
			rows: [],
			submitting: false,
			result: null,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether there is something to import.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.1
		 */
		canSubmit() {
			return this.administrationId !== '' && this.rows.length > 0
		},
	},

	/**
	 * Load the active administration.
	 *
	 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.1
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
			this.error = errorMessage(
				error,
				t('shillinq', 'The administration could not be loaded.'),
			)
		}
	},

	methods: {
		t,

		/**
		 * Read the chosen file into rows.
		 *
		 * @param {Event} event The change event of the file input.
		 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.1
		 */
		async onFile(event) {
			this.error = ''
			const file = event?.target?.files?.[0]
			if (!file) {
				this.rows = []
				return
			}
			this.rows = parseReadingsCsv(await file.text())
			if (this.rows.length === 0) {
				this.error = t(
					'shillinq',
					'The file has no readings below its header line.',
				)
			}
		},

		/**
		 * Import the rows.
		 *
		 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.1
		 */
		async submit() {
			this.error = ''
			this.submitting = true
			try {
				this.result = await importReadings(this.administrationId, this.rows)
			} catch (error) {
				this.error = errorMessage(
					error,
					t('shillinq', 'The readings could not be imported.'),
				)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close the dialog.
		 *
		 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.1
		 */
		close() {
			this.$emit('close', this.result)
		},
	},
}
</script>

<style scoped>
.mri {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.mri__hint {
	color: var(--color-text-maxcontrast);
}

.mri__file {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.mri__refused-title {
	font-size: 1em;
	margin: 0;
}

.mri__refused {
	margin: 0;
	padding-inline-start: 20px;
}

.mri__error {
	color: var(--color-error-text);
}
</style>
