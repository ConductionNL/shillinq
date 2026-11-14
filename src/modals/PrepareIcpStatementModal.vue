<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 PrepareIcpStatementModal: prepare the ICP statement of a period from the
 books (tax-icp-and-reverse-charge REQ-ICR-002, task 2.3).

 The bookkeeper picks a quarter or a month. The server adds up the posted
 intra-EU supplies of that period per buyer VAT number and supply type,
 writes them as the period's draft statement and compares the total with box
 3b of the VAT return. The dialog then shows the lines and that comparison.
 A filed statement is never replaced; the server refuses and the dialog says
 to file a correction.

 Opened with spawnDialog from the "Prepare statement" header action on the
 ICP page (src/utils/icpStatementActions.js), so it lives in its own file
 (hydra gate-13).

 @spec openspec/changes/tax-icp-and-reverse-charge/specs/bookkeeping-icp-opgaaf/spec.md
-->

<template>
	<NcDialog
		:name="t('shillinq', 'Prepare ICP statement')"
		size="normal"
		data-testid="prepare-icp-modal"
		@closing="close">
		<div class="picp">
			<template v-if="!result">
				<p class="picp__hint">
					{{
						t(
							'shillinq',
							'The statement adds up the posted intra-EU supplies of the period per buyer VAT number and supply type. It is saved as a draft and compared with box 3b of the VAT return.',
						)
					}}
				</p>
				<NcSelect
					v-model="kind"
					:options="kindOptions"
					:reduce="(option) => option.id"
					label="label"
					:clearable="false"
					:inputLabel="t('shillinq', 'Period kind')"
					data-testid="prepare-icp-kind"
					@update:modelValue="resetPeriod" />
				<NcTextField
					v-model="year"
					type="number"
					:label="t('shillinq', 'Year')"
					data-testid="prepare-icp-year" />
				<NcTextField
					v-model="number"
					type="number"
					:label="numberLabel"
					data-testid="prepare-icp-number" />
			</template>

			<template v-else>
				<p data-testid="prepare-icp-created">
					{{
						t('shillinq', 'Draft statement {period}: {count} lines, total EUR {total}.', {
							period: result.statement.period,
							count: lines.length,
							total: Number(result.statement.total || 0).toFixed(2),
						})
					}}
				</p>
				<table v-if="lines.length > 0" class="picp__lines" data-testid="prepare-icp-lines">
					<thead>
						<tr>
							<th scope="col">
								{{ t('shillinq', 'Buyer VAT number') }}
							</th>
							<th scope="col">
								{{ t('shillinq', 'Supply type') }}
							</th>
							<th scope="col" class="picp__amount">
								{{ t('shillinq', 'Amount excl. VAT') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="line in lines" :key="line.buyerVatId + line.supplyType">
							<td>{{ line.buyerVatId }}</td>
							<td>{{ supplyTypeLabel(line.supplyType) }}</td>
							<td class="picp__amount">
								{{ Number(line.amountExclVat || 0).toFixed(2) }}
							</td>
						</tr>
					</tbody>
				</table>
				<p
					class="picp__reconciliation"
					:class="'picp__reconciliation--' + reconciliation.kind"
					role="status"
					data-testid="prepare-icp-reconciliation">
					{{ reconciliation.text }}
				</p>
			</template>

			<p
				v-if="error"
				class="picp__error"
				role="alert"
				data-testid="prepare-icp-error">
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
				data-testid="prepare-icp-submit"
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
	icpErrorText,
	icpPeriod,
	lastFinishedIcpPeriod,
	prepareIcpStatement,
	reconciliationMessage,
} from '../utils/icpStatementApi.js'

export default {
	name: 'PrepareIcpStatementModal',

	components: { NcButton, NcDialog, NcSelect, NcTextField },

	emits: ['close'],

	data() {
		const last = lastFinishedIcpPeriod('quarter')
		return {
			administrationId: '',
			kind: 'quarter',
			year: String(last.year),
			number: String(last.number),
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
		 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
		 */
		kindOptions() {
			return [
				{ id: 'quarter', label: t('shillinq', 'Quarter') },
				{ id: 'month', label: t('shillinq', 'Month') },
			]
		},

		/**
		 * The label of the period number field.
		 *
		 * @return {string}
		 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
		 */
		numberLabel() {
			return this.kind === 'month'
				? t('shillinq', 'Month (1 to 12)')
				: t('shillinq', 'Quarter (1 to 4)')
		},

		/**
		 * The chosen period, or '' when it is incomplete.
		 *
		 * @return {string}
		 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
		 */
		period() {
			return icpPeriod(this.kind, this.year, this.number)
		},

		/**
		 * Whether the form is complete.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
		 */
		canSubmit() {
			return this.administrationId !== '' && this.period !== ''
		},

		/**
		 * The prepared lines.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
		 */
		lines() {
			return Array.isArray(this.result?.statement?.lines)
				? this.result.statement.lines
				: []
		},

		/**
		 * The comparison with box 3b, in words.
		 *
		 * @return {{kind: string, text: string}}
		 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
		 */
		reconciliation() {
			return reconciliationMessage(this.result?.reconciliation)
		},
	},

	/**
	 * Load the active administration.
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
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
		 * The name of a supply type.
		 *
		 * @param {string} code L, S or T.
		 * @return {string}
		 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
		 */
		supplyTypeLabel(code) {
			const labels = {
				L: t('shillinq', 'Goods'),
				S: t('shillinq', 'Services'),
				T: t('shillinq', 'Triangulation'),
			}
			return labels[code] || code
		},

		/**
		 * Put the period back on the last finished one of the chosen kind.
		 *
		 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
		 */
		resetPeriod() {
			const last = lastFinishedIcpPeriod(this.kind)
			this.year = String(last.year)
			this.number = String(last.number)
		},

		/**
		 * Prepare the statement.
		 *
		 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
		 */
		async submit() {
			this.error = ''
			this.submitting = true
			try {
				this.result = await prepareIcpStatement({
					administrationId: this.administrationId,
					period: this.period,
				})
			} catch (error) {
				this.error = icpErrorText(error)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close the dialog.
		 *
		 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
		 */
		close() {
			this.$emit('close', this.result)
		},
	},
}
</script>

<style scoped>
.picp {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.picp__hint {
	color: var(--color-text-maxcontrast);
}

.picp__lines {
	width: 100%;
	border-collapse: collapse;
}

.picp__lines th,
.picp__lines td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.picp__amount {
	text-align: end;
}

.picp__lines th.picp__amount,
.picp__lines td.picp__amount {
	text-align: end;
}

.picp__reconciliation--success {
	color: var(--color-success-text);
}

.picp__reconciliation--warning {
	color: var(--color-warning-text);
}

.picp__reconciliation--error,
.picp__error {
	color: var(--color-error-text);
}
</style>
