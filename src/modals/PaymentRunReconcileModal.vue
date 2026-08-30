<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 PaymentRunReconcileModal — payment-run-sepa-export (REQ-SEPA-007).

 The "Reconcile / import statement" dialog launched from the PaymentRun detail
 page's actions. The operator uploads a CAMT.053 (ISO 20022 bank-to-customer
 account statement) file for the exported run; the upload POSTs to
 /api/v1/payment-runs/{id}/reconcile, which parses the statement, matches its
 booked entries to the run's payment lines (EndToEndId primary; amount +
 creditor IBAN fallback) and — on a FULL match — sets reconciledAt and drives
 exported → reconciled. A PARTIAL match leaves the run exported and returns a
 mismatch note shown inline.

 Modal isolation (hydra gate-13): this dialog lives in its own .vue file under
 src/modals/. It is launched declaratively from the PaymentRunDetail page's
 `config.headerActions[]` (ADR-049 Phase-4) via a type:"open-modal" action
 targeting this component's registry id ("PaymentRunReconcileModal").

 @spec openspec/specs/payment-run-sepa-export/spec.md
-->

<template>
	<NcDialog
		v-if="open"
		:name="t('shillinq', 'Reconcile / import statement')"
		size="normal"
		data-testid="payment-run-reconcile-modal"
		@closing="onClose">
		<div class="prr">
			<p class="prr__intro">
				{{
					t(
						'shillinq',
						"Import a CAMT.053 bank statement for this payment run. Its booked entries are matched to the run's payment lines; on a full match the run is reconciled.",
					)
				}}
			</p>

			<input
				ref="fileInput"
				type="file"
				accept=".xml,application/xml,text/xml"
				:aria-label="t('shillinq', 'Choose a CAMT.053 bank statement file')"
				data-testid="payment-run-reconcile-file"
				@change="onFileSelected" />

			<p v-if="fileName" class="prr__filename">
				{{ t('shillinq', 'Selected') }}: {{ fileName }}
			</p>

			<div v-if="result" class="prr__result" :class="resultClass">
				<p v-if="result.result === 'full'">
					{{ t('shillinq', 'Reconciled — all lines matched.') }}
				</p>
				<p v-else>
					{{ result.mismatchNote }}
				</p>
			</div>
		</div>

		<template #actions>
			<NcButton @click="onClose">
				{{ t('shillinq', 'Close') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!fileContents || submitting"
				data-testid="payment-run-reconcile-submit"
				@click="submit">
				{{ t('shillinq', 'Reconcile') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'PaymentRunReconcileModal',
	components: {
		NcDialog,
		NcButton,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		// Optional explicit run id. When launched declaratively from the
		// PaymentRunDetail page's `config.headerActions[]` open-modal action
		// (ADR-049 Phase-4), the modal is hosted at CnAppRoot level — outside
		// the detail page's object-context provide — so no `@objectId` prop is
		// interpolated. In that case `runId` falls back to the route's `:id`
		// param (the modal is always mounted within the app router).
		paymentRunId: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'reconciled'],
	data() {
		return {
			fileName: '',
			fileContents: '',
			submitting: false,
			result: null,
		}
	},

	computed: {
		/** Effective run id: explicit prop wins, else the current route's :id. */
		runId() {
			return String(this.paymentRunId || this.$route?.params?.id || '')
		},

		resultClass() {
			if (!this.result) {
				return ''
			}
			return this.result.result === 'full'
				? 'prr__result--ok'
				: 'prr__result--warn'
		},
	},

	methods: {
		t,
		onFileSelected(event) {
			const file = event.target.files && event.target.files[0]
			if (!file) {
				this.fileName = ''
				this.fileContents = ''
				return
			}
			this.fileName = file.name
			const reader = new FileReader()
			reader.onload = () => {
				this.fileContents = String(reader.result || '')
			}
			reader.readAsText(file)
		},

		async submit() {
			if (!this.fileContents || this.submitting) {
				return
			}
			this.submitting = true
			this.result = null
			try {
				const response = await axios.post(
					generateUrl(
						`/apps/shillinq/api/v1/payment-runs/${this.runId}/reconcile`,
					),
					{ contents: this.fileContents },
				)
				this.result = response.data
				if (this.result.result === 'full') {
					showSuccess(t('shillinq', 'Payment run reconciled.'))
					emit('cn:widget:refresh', { widget: 'PaymentRunDetail' })
					this.$emit('reconciled', this.result)
				} else {
					showError(
						t('shillinq', 'Partial match — the run stays exported.'),
					)
				}
			} catch (error) {
				const message =
					error.response
					&& error.response.data
					&& error.response.data.error
						? error.response.data.error
						: t('shillinq', 'Could not reconcile the payment run.')
				showError(message)
			} finally {
				this.submitting = false
			}
		},

		onClose() {
			this.fileName = ''
			this.fileContents = ''
			this.result = null
			if (this.$refs.fileInput) {
				this.$refs.fileInput.value = ''
			}
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.prr {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 360px;
}

.prr__filename {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.prr__result {
	border-radius: var(--border-radius);
	padding: 8px 12px;
}

.prr__result--ok {
	background-color: var(--color-success, #2d7d46);
	color: #fff;
}

.prr__result--warn {
	background-color: var(--color-warning, #c7a008);
	color: #000;
}
</style>
