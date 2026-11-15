<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RecalculateInvestmentDeductionsModal: work out a year's investment deduction
 claims (tax-investment-deduction-claims REQ-IDC-002, REQ-IDC-003, task 2.1).

 The bookkeeper picks the year. The server sums the active investment assets
 of that year per scheme, replaces the year's draft claims and keeps every
 claim already used in a tax return. The dialog then shows the total per
 scheme.

 Opened with spawnDialog from the "Recalculate" header action on Investment
 deductions (src/utils/investmentDeductionActions.js), so it lives in its own
 file (hydra gate-13).

 @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md
-->

<template>
	<NcDialog
		:name="t('shillinq', 'Recalculate investment deductions')"
		size="normal"
		data-testid="recalculate-investment-deductions-modal"
		@closing="close">
		<div class="rid">
			<template v-if="!result">
				<p class="rid__hint">
					{{
						t(
							'shillinq',
							'The KIA, EIA and MIA claims of the year are worked out again from the active investment assets. Drafts are replaced; claims already used in a tax return are kept.',
						)
					}}
				</p>
				<NcTextField
					v-model="year"
					type="number"
					:label="t('shillinq', 'Year')"
					data-testid="recalculate-year" />
			</template>

			<template v-else>
				<p data-testid="recalculate-summary">
					{{
						t(
							'shillinq',
							'Year {year}. Claims calculated: {claims}. Kept from a tax return: {kept}.',
							{ year: result.year, claims: result.claims, kept: result.kept },
						)
					}}
				</p>
				<table class="rid__totals" data-testid="recalculate-totals">
					<caption>{{ t('shillinq', 'Total per scheme') }}</caption>
					<tbody>
						<tr v-for="row in totals" :key="row.scheme">
							<th scope="row">
								{{ row.scheme }}
							</th>
							<td>EUR {{ row.amount }}</td>
						</tr>
					</tbody>
				</table>
			</template>

			<p
				v-if="error"
				class="rid__error"
				role="alert"
				data-testid="recalculate-error">
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
				data-testid="recalculate-submit"
				@click="submit">
				{{ t('shillinq', 'Recalculate') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import {
	defaultYear,
	recalculateErrorText,
	recalculateYear,
	totalRows,
} from '../utils/investmentDeductionApi.js'

export default {
	name: 'RecalculateInvestmentDeductionsModal',

	components: { NcButton, NcDialog, NcTextField },

	emits: ['close'],

	data() {
		return {
			year: String(defaultYear()),
			submitting: false,
			result: null,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether the year is a whole number the server accepts.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/tax-investment-deduction-claims/tasks.md#task-2.1
		 */
		canSubmit() {
			const year = Number(this.year)
			return Number.isInteger(year) && year >= 2000 && year <= 2100
		},

		/**
		 * The result's total per scheme.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/tax-investment-deduction-claims/tasks.md#task-2.1
		 */
		totals() {
			return totalRows(this.result?.totals)
		},
	},

	methods: {
		t,

		/**
		 * Recalculate the year for the active administration.
		 *
		 * @spec openspec/changes/tax-investment-deduction-claims/tasks.md#task-2.1
		 */
		async submit() {
			this.error = ''
			this.submitting = true
			try {
				this.result = await recalculateYear(Number(this.year))
			} catch (error) {
				this.error = recalculateErrorText(error)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close the dialog.
		 *
		 * @spec openspec/changes/tax-investment-deduction-claims/tasks.md#task-2.1
		 */
		close() {
			this.$emit('close', this.result)
		},
	},
}
</script>

<style scoped>
.rid {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.rid__hint {
	color: var(--color-text-maxcontrast);
}

.rid__totals th {
	text-align: start;
	padding-inline-end: 24px;
}

.rid__totals caption {
	text-align: start;
	font-weight: bold;
}

.rid__error {
	color: var(--color-error-text);
}
</style>
