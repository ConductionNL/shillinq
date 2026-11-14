<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SpreadOverPeriodsModal: spread a prepaid cost (AP) or deferred revenue (AR)
 from one invoice line over the periods of its service period
 (ledger-period-end REQ-LPE-004). Shows the releases before anything is
 booked; Spread creates the schedule, which posts one entry moving the
 amount from the result account to the balance account. The nightly close
 releases each period after it ends.

 Opened per line from SupplierInvoiceDetail, and from the "Spread over
 periods" header action on ARInvoiceDetail, where the line is chosen here.

 @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
-->

<template>
	<NcDialog
		v-if="open"
		:name="t('shillinq', 'Spread over periods')"
		size="normal"
		data-testid="spread-periods-modal"
		@closing="close">
		<div class="spread">
			<template v-if="!done">
				<NcSelect
					v-model="chosenLine"
					:options="lineOptions"
					:inputLabel="t('shillinq', 'Invoice line')"
					:clearable="false"
					data-testid="spread-periods-line" />
				<NcTextField
					v-model="form.resultAccount"
					:label="side === 'ar' ? t('shillinq', 'Revenue account') : t('shillinq', 'Cost account')"
					data-testid="spread-periods-result-account" />
				<NcTextField
					v-model="form.balanceAccount"
					:label="t('shillinq', 'Balance account')"
					data-testid="spread-periods-balance-account" />
				<NcTextField
					v-model="form.periodFrom"
					type="date"
					:label="t('shillinq', 'Service period from')"
					data-testid="spread-periods-from" />
				<NcTextField
					v-model="form.periodTo"
					type="date"
					:label="t('shillinq', 'Service period to')"
					data-testid="spread-periods-to" />

				<table
					v-if="preview"
					class="spread__releases"
					data-testid="spread-periods-releases">
					<caption>
						{{ t('shillinq', '{count} releases, EUR {total} in total', { count: preview.releases.length, total: euros(preview.totalCents) }) }}
					</caption>
					<thead>
						<tr>
							<th scope="col">{{ t('shillinq', 'Period') }}</th>
							<th scope="col">{{ t('shillinq', 'Days') }}</th>
							<th scope="col">{{ t('shillinq', 'Amount') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="release in preview.releases" :key="release.periodId">
							<td>{{ release.periodId }}</td>
							<td>{{ release.days }}</td>
							<td>EUR {{ euros(release.amountCents) }}</td>
						</tr>
					</tbody>
				</table>
			</template>
			<p v-else data-testid="spread-periods-done">
				{{ t('shillinq', 'The line is spread over {count} periods. The nightly close releases each period after it ends.', { count: preview.releases.length }) }}
			</p>
			<p
				v-if="error"
				class="spread__error"
				role="alert"
				data-testid="spread-periods-error">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="spread-periods-cancel" @click="close">
				{{ done ? t('shillinq', 'Close') : t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!done && !preview"
				variant="primary"
				:disabled="!complete || busy"
				data-testid="spread-periods-preview"
				@click="showPreview">
				{{ t('shillinq', 'Show releases') }}
			</NcButton>
			<NcButton
				v-if="!done && preview"
				variant="primary"
				:disabled="busy"
				data-testid="spread-periods-submit"
				@click="submit">
				{{ t('shillinq', 'Spread the line') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	createSchedule,
	euros,
	loadInvoice,
	previewSchedule,
	SIDES,
	spreadableLines,
} from '../utils/deferralApi.js'
import { errorMessage } from '../utils/downPaymentApi.js'

export default {
	name: 'SpreadOverPeriodsModal',

	components: { NcButton, NcDialog, NcSelect, NcTextField },

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		// ap (supplier invoice, prepaid cost) or ar (sales invoice, deferred revenue).
		side: {
			type: String,
			default: 'ap',
		},

		objectId: {
			type: String,
			default: '',
		},

		// The invoice when the caller already has it.
		invoice: {
			type: Object,
			default: null,
		},

		// The line key to preselect.
		lineKey: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		const side = SIDES[this.side] || SIDES.ap
		return {
			loaded: this.invoice,
			chosenLine: null,
			form: {
				resultAccount: side.resultAccount,
				balanceAccount: side.balanceAccount,
				periodFrom: '',
				periodTo: '',
			},

			preview: null,
			done: false,
			busy: false,
			error: '',
		}
	},

	computed: {
		lines() {
			return spreadableLines(this.side, this.loaded)
		},

		lineOptions() {
			return this.lines.map((line) => ({ ...line, label: `${line.label} · EUR ${euros(line.cents)}` }))
		},

		/**
		 * The request the form makes, without the preview.
		 *
		 * @return {object}
		 */
		body() {
			return {
				administrationId: String(this.loaded?.administrationId || ''),
				sourceReference: this.chosenLine?.reference || '',
				description: `${this.loaded?.invoiceNumber || ''} ${this.lines.find((line) => line.key === this.chosenLine?.key)?.label || ''}`.trim(),
				kind: (SIDES[this.side] || SIDES.ap).kind,
				resultAccount: this.form.resultAccount.trim(),
				balanceAccount: this.form.balanceAccount.trim(),
				totalCents: this.chosenLine?.cents || 0,
				periodFrom: this.form.periodFrom,
				periodTo: this.form.periodTo,
			}
		},

		complete() {
			const body = this.body
			return body.sourceReference !== '' && body.totalCents > 0 && body.resultAccount !== '' && body.balanceAccount !== '' && body.periodFrom !== '' && body.periodTo !== ''
		},
	},

	watch: {
		body: {
			deep: true,
			handler() {
				if (!this.done) {
					this.preview = null
				}
			},
		},

		chosenLine(line) {
			if (line?.account) {
				this.form.resultAccount = line.account
			}
		},
	},

	/**
	 * Load the invoice when it was not handed in, and preselect the line.
	 */
	async mounted() {
		try {
			if (!this.loaded) {
				this.loaded = await loadInvoice(this.side, String(this.objectId || this.$route?.params?.id || ''))
			}
			this.takeInvoice()
		} catch (err) {
			this.error = errorMessage(err, t('shillinq', 'The invoice could not be loaded.'))
		}
	},

	methods: {
		t,
		euros,

		/**
		 * Preselect the line and the invoice's own service period.
		 */
		takeInvoice() {
			this.chosenLine = this.lineOptions.find((line) => line.key === this.lineKey) || (this.lineOptions.length === 1 ? this.lineOptions[0] : null)
			if (this.chosenLine?.account) {
				this.form.resultAccount = this.chosenLine.account
			}
			if (this.loaded?.invoicingPeriodStart && this.loaded?.invoicingPeriodEnd) {
				this.form.periodFrom = String(this.loaded.invoicingPeriodStart).slice(0, 10)
				this.form.periodTo = String(this.loaded.invoicingPeriodEnd).slice(0, 10)
			}
		},

		async showPreview() {
			await this.run(async () => {
				this.preview = await previewSchedule(this.body)
			}, t('shillinq', 'The releases could not be worked out.'))
		},

		async submit() {
			await this.run(async () => {
				this.preview = await createSchedule(this.body)
				this.done = true
			}, t('shillinq', 'The invoice line could not be spread.'))
		},

		/**
		 * Run a call with the busy flag and its error message.
		 *
		 * @param {() => Promise<void>} call The call.
		 * @param {string} fallback The message when the server gives none.
		 * @return {Promise<void>}
		 */
		async run(call, fallback) {
			this.busy = true
			this.error = ''
			try {
				await call()
			} catch (err) {
				this.error = errorMessage(err, fallback)
			} finally {
				this.busy = false
			}
		},

		close() {
			this.$emit('close', this.done)
		},
	},
}
</script>

<style scoped>
.spread {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.spread__releases {
	width: 100%;
	border-collapse: collapse;
}

.spread__releases th,
.spread__releases td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.spread__releases caption {
	text-align: left;
	color: var(--color-text-maxcontrast);
}

.spread__error {
	color: var(--color-error);
}
</style>
