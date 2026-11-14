<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ProposeDirectDebitModal: draft the direct debit batches for a collection
 date (receivables-sepa-direct-debit REQ-SDD-011).

 The bookkeeper picks the collection date. The server writes a draft batch
 per scheme and sequence type with one collection per open direct debit
 invoice whose customer has an active mandate, and names every invoice it
 left out with the reason. Each batch is generated and downloaded on its own
 page.

 Opened with spawnDialog from the "Propose direct debit" header action on
 Direct debit batches (src/utils/directDebitActions.js), so it lives in its
 own file (hydra gate-13).

 @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-a-batch-is-proposed-from-open-invoices-with-a-mandate-req-sdd-011
-->

<template>
	<NcDialog
		:name="t('shillinq', 'Propose direct debit')"
		size="normal"
		data-testid="propose-direct-debit-modal"
		@closing="close">
		<div class="pdd">
			<template v-if="!result">
				<p class="pdd__hint">
					{{
						t(
							'shillinq',
							'Every open invoice paid by direct debit whose customer has an active mandate goes on a draft batch for the date you choose.',
						)
					}}
				</p>
				<NcTextField
					v-model="collectionDate"
					type="date"
					:label="t('shillinq', 'Collection date')"
					data-testid="propose-dd-date" />
			</template>

			<template v-else>
				<template v-if="result.batches.length">
					<p data-testid="propose-dd-created">
						{{
							n(
								'shillinq',
								'{count} draft batch, total EUR {total}.',
								'{count} draft batches, total EUR {total}.',
								result.batches.length,
								{ count: result.batches.length, total: total.toFixed(2) },
							)
						}}
					</p>
					<ul class="pdd__batches" data-testid="propose-dd-batches">
						<li v-for="batch in result.batches" :key="batch.id">
							<a :href="batchUrl(batch)">{{ batchLabel(batch) }}</a>
						</li>
					</ul>
				</template>
				<p v-else data-testid="propose-dd-nothing">
					{{ t('shillinq', 'No invoice can be collected on that date.') }}
				</p>
				<template v-if="result.skipped && result.skipped.length">
					<h3 class="pdd__skipped-title">
						{{ t('shillinq', 'Left out') }}
					</h3>
					<ul class="pdd__skipped" data-testid="propose-dd-skipped">
						<li v-for="skip in result.skipped" :key="skip.invoiceId">
							{{ skip.invoiceNumber }}: {{ reasonText(skip) }}
						</li>
					</ul>
				</template>
			</template>

			<p
				v-if="error"
				class="pdd__error"
				role="alert"
				data-testid="propose-dd-error">
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
				data-testid="propose-dd-submit"
				@click="submit">
				{{ t('shillinq', 'Propose') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import {
	proposalTotal,
	proposeDirectDebitBatches,
	skipReasonText,
} from '../utils/directDebitApi.js'
import { errorMessage } from '../utils/downPaymentApi.js'

export default {
	name: 'ProposeDirectDebitModal',

	components: { NcButton, NcDialog, NcTextField },

	emits: ['close'],

	data() {
		return {
			administrationId: '',
			collectionDate: new Date().toISOString().slice(0, 10),
			submitting: false,
			result: null,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether the form is complete.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
		 */
		canSubmit() {
			return this.administrationId !== '' && this.collectionDate !== ''
		},

		/**
		 * The total of the proposed batches.
		 *
		 * @return {number}
		 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
		 */
		total() {
			return proposalTotal(this.result?.batches)
		},
	},

	/**
	 * Load the active administration.
	 *
	 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
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
		n,

		/**
		 * The sentence for a left-out invoice.
		 *
		 * @param {object} skip The entry.
		 * @return {string}
		 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
		 */
		reasonText(skip) {
			return skipReasonText(skip)
		},

		/**
		 * Link to a proposed batch's page.
		 *
		 * @param {object} batch The batch.
		 * @return {string}
		 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
		 */
		batchUrl(batch) {
			return generateUrl(
				'/apps/shillinq/bookkeeping/direct-debit-batches/'
					+ encodeURIComponent(String(batch?.id || '')),
			)
		},

		/**
		 * A batch's line: scheme, sequence type, count and total.
		 *
		 * @param {object} batch The batch.
		 * @return {string}
		 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
		 */
		batchLabel(batch) {
			return n(
				'shillinq',
				'{scheme} {sequenceType}: {count} collection, EUR {total}',
				'{scheme} {sequenceType}: {count} collections, EUR {total}',
				Number(batch?.collectionCount || 0),
				{
					scheme: batch?.scheme,
					sequenceType: batch?.sequenceType,
					count: Number(batch?.collectionCount || 0),
					total: Number(batch?.controlSum || 0).toFixed(2),
				},
			)
		},

		/**
		 * Ask for the draft batches.
		 *
		 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
		 */
		async submit() {
			this.error = ''
			this.submitting = true
			try {
				this.result = await proposeDirectDebitBatches({
					administrationId: this.administrationId,
					collectionDate: this.collectionDate,
				})
			} catch (error) {
				this.error = errorMessage(
					error,
					t('shillinq', 'The direct debit could not be proposed.'),
				)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close the dialog.
		 *
		 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
		 */
		close() {
			this.$emit('close', this.result)
		},
	},
}
</script>

<style scoped>
.pdd {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.pdd__hint {
	color: var(--color-text-maxcontrast);
}

.pdd__skipped-title {
	font-size: 1em;
	margin: 0;
}

.pdd__batches,
.pdd__skipped {
	margin: 0;
	padding-inline-start: 20px;
}

.pdd__error {
	color: var(--color-error-text);
}
</style>
