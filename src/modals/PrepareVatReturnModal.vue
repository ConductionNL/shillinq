<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 PrepareVatReturnModal: prepare the BTW return of a period from the books
 (tax-vat-return-from-books REQ-VBTW-004, task 2.3).

 The bookkeeper picks the period. The server sums the booked ledger lines of
 that period per return box and writes a draft return with its declarations
 and VAT lines. The draft is checked and submitted on its own page.

 Opened with spawnDialog from the "Prepare return" header action on BTW
 returns (src/utils/vatReturnActions.js), so it lives in its own file
 (hydra gate-13).

 @spec openspec/changes/tax-vat-return-from-books/specs/bookkeeping-vat-btw-filing/spec.md
-->

<template>
	<NcDialog
		:name="t('shillinq', 'Prepare return')"
		size="normal"
		data-testid="prepare-vat-return-modal"
		@closing="close">
		<div class="pvr">
			<template v-if="!result">
				<p class="pvr__hint">
					{{
						t(
							'shillinq',
							'The return adds up the booked ledger lines of the period per box. It is saved as a draft that you check and submit on its own page.',
						)
					}}
				</p>
				<NcSelect
					v-model="period"
					:options="periodOptions"
					:reduce="(option) => option.id"
					label="label"
					:clearable="false"
					:inputLabel="t('shillinq', 'Period kind')"
					data-testid="prepare-period-kind"
					@update:modelValue="resetPeriod" />
				<NcTextField
					v-model="periodYear"
					type="number"
					:label="t('shillinq', 'Year')"
					data-testid="prepare-year" />
				<NcTextField
					v-if="period !== 'year'"
					v-model="periodNumber"
					type="number"
					:label="periodNumberLabel"
					data-testid="prepare-number" />
				<NcSelect
					v-model="regime"
					:options="regimeOptions"
					:reduce="(option) => option.id"
					label="label"
					:clearable="false"
					:inputLabel="t('shillinq', 'Regime')"
					data-testid="prepare-regime" />
			</template>

			<p v-else data-testid="prepare-created">
				{{
					t(
						'shillinq',
						'Draft return {number}: BTW collected EUR {collected}, input tax EUR {paid}.',
						{
							number: result.returnNumber,
							collected: Number(result.totalVATCollected || 0).toFixed(
								2,
							),
							paid: Number(result.totalVATPaid || 0).toFixed(2),
						},
					)
				}}
				<a :href="returnUrl" data-testid="prepare-open-return">{{
					t('shillinq', 'Open the return')
				}}</a>
			</p>

			<p
				v-if="error"
				class="pvr__error"
				role="alert"
				data-testid="prepare-error">
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
				data-testid="prepare-submit"
				@click="submit">
				{{ t('shillinq', 'Prepare') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	lastFinishedPeriod,
	prepareErrorText,
	prepareVatReturn,
	vatReturnUrl,
} from '../utils/vatReturnApi.js'

export default {
	name: 'PrepareVatReturnModal',

	components: { NcButton, NcDialog, NcSelect, NcTextField },

	emits: ['close'],

	data() {
		const last = lastFinishedPeriod('quarter')
		return {
			administrationId: '',
			period: 'quarter',
			periodYear: String(last.periodYear),
			periodNumber: String(last.periodNumber),
			regime: 'standard',
			submitting: false,
			result: null,
			error: '',
		}
	},

	computed: {
		/**
		 * The period kinds.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
		 */
		periodOptions() {
			return [
				{ id: 'quarter', label: t('shillinq', 'Quarter') },
				{ id: 'month', label: t('shillinq', 'Month') },
				{ id: 'year', label: t('shillinq', 'Year') },
			]
		},

		/**
		 * The VAT regimes.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
		 */
		regimeOptions() {
			return [
				{ id: 'standard', label: t('shillinq', 'Standard') },
				{ id: 'kor', label: t('shillinq', 'Small business scheme (KOR)') },
				{ id: 'reverse-charge', label: t('shillinq', 'Reverse charge') },
			]
		},

		/**
		 * The label of the period number field.
		 *
		 * @return {string}
		 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
		 */
		periodNumberLabel() {
			return this.period === 'month'
				? t('shillinq', 'Month (1 to 12)')
				: t('shillinq', 'Quarter (1 to 4)')
		},

		/**
		 * Whether the form is complete.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
		 */
		canSubmit() {
			return (
				this.administrationId !== ''
				&& Number(this.periodYear) > 0
				&& (this.period === 'year' || Number(this.periodNumber) > 0)
			)
		},

		/**
		 * Link to the prepared return's page.
		 *
		 * @return {string}
		 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
		 */
		returnUrl() {
			return vatReturnUrl(this.result?.id || this.result?.['@self']?.id || '')
		},
	},

	/**
	 * Load the active administration.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
	 */
	async mounted() {
		try {
			const context = await axios.get(
				generateUrl('/apps/shillinq/api/administrations/context'),
			)
			this.administrationId = String(
				context.data?.activeAdministrationId || '',
			)
			if (this.administrationId === '') {
				this.error = t('shillinq', 'Choose an administration first.')
			}
		} catch {
			this.error = t('shillinq', 'The administration could not be loaded.')
		}
	},

	methods: {
		t,

		/**
		 * Put the period back on the last finished one of the chosen kind.
		 *
		 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
		 */
		resetPeriod() {
			const last = lastFinishedPeriod(this.period)
			this.periodYear = String(last.periodYear)
			this.periodNumber = String(last.periodNumber)
		},

		/**
		 * Prepare the return.
		 *
		 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
		 */
		async submit() {
			this.error = ''
			this.submitting = true
			try {
				this.result = await prepareVatReturn({
					administrationId: this.administrationId,
					period: this.period,
					periodYear: this.periodYear,
					periodNumber: this.periodNumber,
					regime: this.regime,
				})
			} catch (error) {
				this.error = prepareErrorText(error)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close the dialog.
		 *
		 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
		 */
		close() {
			this.$emit('close', this.result)
		},
	},
}
</script>

<style scoped>
.pvr {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.pvr__hint {
	color: var(--color-text-maxcontrast);
}

.pvr__error {
	color: var(--color-error-text);
}
</style>
