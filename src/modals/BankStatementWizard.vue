<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 BankStatementWizard — shillinq-bank-statement-wizard.

 A 3-step NcDialog launched from the Financial overview dashboard's "Import
 bank" action (FinancialDashboardActions.vue). It guides a first-time user
 through (1) file-format selection + upload, (2) mapping the statement IBAN to
 a GL account (skipped for remembered IBANs), and (3) an import summary that
 POSTs the file to /api/v1/bank-statements/import and offers a single hop to
 the reconciliation page to review matches.

 Modal isolation (hydra gate-13): this dialog lives in its own .vue file under
 src/modals/ and is imported by FinancialDashboardActions.vue. Its pure logic
 (format options, IBAN memory, payload + breadcrumb helpers) lives in the
 sibling bankStatementWizard.js so it can be unit-tested without a DOM mount.

 Honesty: auto-matching is the reconciliation engine's job, so the summary
 reports matchedCount = 0 / unmatchedCount = transactionCount as returned by
 the import endpoint — no fabricated match counts. The "Connect via PSD2" link
 is discoverability only; the real PSD2 connection is owned by the
 bank-connectors capability under Settings.

 @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
-->

<template>
	<NcDialog
		v-if="open"
		:name="t('shillinq', 'Import bank statement')"
		size="normal"
		data-testid="bank-statement-wizard"
		@closing="onClose">
		<div class="bsw">
			<!-- Step 1: format selection + file upload -->
			<div v-if="step === 1" class="bsw__step" data-testid="bsw-step-1">
				<p class="bsw__heading">
					{{ t('shillinq', 'How does your bank export statements?') }}
				</p>
				<NcSelect
					:modelValue="selectedFormatOption"
					:options="formatSelectOptions"
					:inputLabel="t('shillinq', 'Statement format')"
					:clearable="false"
					label="display"
					trackBy="value"
					data-testid="bsw-format"
					@update:modelValue="onFormatSelected" />

				<p
					v-if="form.format"
					class="bsw__hint"
					data-testid="bsw-format-hint">
					{{ formatInstructions }}
				</p>

				<div v-if="form.format" class="bsw__field">
					<label class="bsw__label" for="bsw-file">{{
						t('shillinq', 'Statement file')
					}}</label>
					<input
						id="bsw-file"
						ref="file"
						type="file"
						:accept="acceptFor(form.format)"
						class="bsw__input"
						data-testid="bsw-file"
						@change="onFileChosen" />
				</div>

				<p class="bsw__psd2">
					{{
						t(
							'shillinq',
							'Or connect your bank directly and skip manual uploads:',
						)
					}}
					<a
						href="#"
						class="bsw__link"
						data-testid="bsw-psd2"
						@click.prevent="goToBankConnections">
						{{ t('shillinq', 'Connect via PSD2') }}
					</a>
				</p>
			</div>

			<!-- Step 2: account mapping -->
			<div v-else-if="step === 2" class="bsw__step" data-testid="bsw-step-2">
				<p class="bsw__heading">
					{{ t('shillinq', 'Map to Shillinq account') }}
				</p>
				<dl class="bsw__meta">
					<dt>{{ t('shillinq', 'Statement IBAN') }}</dt>
					<dd data-testid="bsw-iban">
						{{ statementIban || t('shillinq', 'Unknown') }}
					</dd>
					<dt>{{ t('shillinq', 'Statement name') }}</dt>
					<dd>{{ statementName || '—' }}</dd>
				</dl>
				<GlAccountPicker
					v-model="form.glAccountId"
					data-testid="bsw-gl-account" />
			</div>

			<!-- Step 3: import summary -->
			<div v-else class="bsw__step" data-testid="bsw-step-3">
				<p v-if="importing" class="bsw__heading" data-testid="bsw-importing">
					{{
						t('shillinq', 'Importing {count} transactions', {
							count: result ? result.transactionCount : '…',
						})
					}}
				</p>
				<template v-else-if="result">
					<p class="bsw__heading" data-testid="bsw-summary">
						{{
							t('shillinq', 'Importing {count} transactions', {
								count: result.transactionCount,
							})
						}}
					</p>
					<ul class="bsw__counts">
						<li data-testid="bsw-matched">
							{{ result.matchedCount }}
							{{ t('shillinq', 'automatically matched') }}
						</li>
						<li data-testid="bsw-unmatched">
							{{ result.unmatchedCount }}
							{{ t('shillinq', 'unmatched — require manual review') }}
						</li>
					</ul>
				</template>
				<p v-if="error" class="bsw__error" data-testid="bsw-error">
					{{ error }}
				</p>
			</div>
		</div>

		<template #actions>
			<NcButton data-testid="bsw-cancel" @click="onClose">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="step === 1"
				variant="primary"
				:disabled="!canLeaveStep1"
				data-testid="bsw-next-1"
				@click="advanceFromStep1">
				{{ t('shillinq', 'Next') }}
			</NcButton>
			<NcButton
				v-else-if="step === 2"
				variant="primary"
				:disabled="!form.glAccountId"
				data-testid="bsw-next-2"
				@click="advanceFromStep2">
				{{ t('shillinq', 'Next') }}
			</NcButton>
			<NcButton
				v-else-if="step === 3 && result"
				variant="primary"
				:disabled="importing"
				data-testid="bsw-review"
				@click="reviewMatches">
				{{ t('shillinq', 'Import and review matches') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import GlAccountPicker from '../components/BudgetBBVMapping/GlAccountPicker.vue'
import {
	buildImportPayload,
	formatOptions,
	loadIbanMapping,
	saveIbanMapping,
	setReturnBreadcrumb,
} from './bankStatementWizard.js'

export default {
	name: 'BankStatementWizard',
	components: { NcDialog, NcButton, NcSelect, GlAccountPicker },
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'imported'],
	data() {
		return {
			step: 1,
			importing: false,
			error: '',
			result: null,
			statementIban: '',
			statementName: '',
			form: {
				format: '',
				contents: '',
				fileName: '',
				glAccountId: '',
			},
		}
	},

	computed: {
		/** @spec openspec/specs/shillinq-bank-statement-wizard/spec.md */
		formatSelectOptions() {
			return [
				{ value: 'camt053', display: t('shillinq', 'CAMT.053 XML') },
				{ value: 'mt940', display: t('shillinq', 'MT940') },
				{ value: 'csv', display: t('shillinq', 'CSV') },
			]
		},

		/** @spec openspec/specs/shillinq-bank-statement-wizard/spec.md */
		selectedFormatOption() {
			return (
				this.formatSelectOptions.find((o) => o.value === this.form.format)
				|| null
			)
		},

		/** @spec openspec/specs/shillinq-bank-statement-wizard/spec.md */
		formatInstructions() {
			const map = {
				camt053: t(
					'shillinq',
					'Most Dutch banks (ING, Rabobank, ABN AMRO, SNS). Export from your bank: Downloads → Account overview → Format: CAMT.053 → Date range: last 30 days.',
				),

				mt940: t(
					'shillinq',
					'Older SWIFT format (Triodos, some ING accounts). Export the MT940 / .STA file from your bank portal.',
				),

				csv: t(
					'shillinq',
					'Custom export with a header row (valueDate, amount, currency, remittanceInfo, counterpartyName, counterpartyIban).',
				),
			}
			return map[this.form.format] || ''
		},

		/** @spec openspec/specs/shillinq-bank-statement-wizard/spec.md */
		canLeaveStep1() {
			return Boolean(this.form.format) && Boolean(this.form.contents)
		},
	},

	watch: {
		/**
		 * @param next
		 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
		 */
		open(next) {
			if (next === true) {
				this.reset()
			}
		},
	},

	methods: {
		t,
		/** @spec openspec/specs/shillinq-bank-statement-wizard/spec.md */
		reset() {
			this.step = 1
			this.importing = false
			this.error = ''
			this.result = null
			this.statementIban = ''
			this.statementName = ''
			this.form = { format: '', contents: '', fileName: '', glAccountId: '' }
		},

		/**
		 * @param format
		 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
		 */
		acceptFor(format) {
			const opt = formatOptions().find((o) => o.value === format)
			return opt ? opt.accept : ''
		},

		/**
		 * @param option
		 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
		 */
		onFormatSelected(option) {
			this.form.format = option ? String(option.value) : ''
		},

		/**
		 * @param event
		 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
		 */
		onFileChosen(event) {
			const file = event?.target?.files?.[0]
			if (!file) {
				this.form.contents = ''
				this.form.fileName = ''
				return
			}
			this.form.fileName = file.name
			const reader = new FileReader()
			reader.onload = () => {
				this.form.contents = String(reader.result || '')
				this.extractStatementMeta()
			}
			reader.readAsText(file)
		},

		/** @spec openspec/specs/shillinq-bank-statement-wizard/spec.md */
		extractStatementMeta() {
			// Lightweight pre-parse: pull the first IBAN-shaped token so the
			// account-mapping step can show it and consult the IBAN memory.
			const m = String(this.form.contents).match(
				/\b([A-Z]{2}\d{2}[A-Z0-9]{10,30})\b/,
			)
			this.statementIban = m ? m[1] : ''
		},

		/** @spec openspec/specs/shillinq-bank-statement-wizard/spec.md */
		advanceFromStep1() {
			if (!this.canLeaveStep1) return
			// REQ-BSW-006: skip the mapping step for a remembered IBAN.
			const remembered = loadIbanMapping(this.statementIban)
			if (remembered) {
				this.form.glAccountId = remembered
				this.step = 3
				this.runImport()
				return
			}
			this.step = 2
		},

		/** @spec openspec/specs/shillinq-bank-statement-wizard/spec.md */
		advanceFromStep2() {
			if (!this.form.glAccountId) return
			this.step = 3
			this.runImport()
		},

		/** @spec openspec/specs/shillinq-bank-statement-wizard/spec.md */
		async runImport() {
			this.importing = true
			this.error = ''
			try {
				const response = await axios.post(
					generateUrl('/apps/shillinq/api/v1/bank-statements/import'),
					buildImportPayload(this.form),
				)
				this.result = response.data || {}
				// Remember the IBAN → GL account mapping for next time.
				if (this.statementIban && this.form.glAccountId) {
					saveIbanMapping(this.statementIban, this.form.glAccountId)
				}
				this.$emit('imported', this.result)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| t('shillinq', 'Failed to import the bank statement.')
				showError(this.error)
			} finally {
				this.importing = false
			}
		},

		/** @spec openspec/specs/shillinq-bank-statement-wizard/spec.md */
		reviewMatches() {
			if (!this.result) return
			// REQ-BSW-005: the only navigation away from the dashboard.
			setReturnBreadcrumb(this.result.statementId)
			this.refreshDashboardWidgets()
			this.$emit('close')
			this.$router.push({ name: 'BankReconciliation' })
		},

		/** @spec openspec/specs/shillinq-bank-statement-wizard/spec.md */
		refreshDashboardWidgets() {
			// REQ-BSW-005: reload the payables + receivables widgets.
			emit('cn:widget:refresh', { widget: 'widget-open-debtors' })
			emit('cn:widget:refresh', { widget: 'widget-open-creditors' })
		},

		/** @spec openspec/specs/shillinq-bank-statement-wizard/spec.md */
		goToBankConnections() {
			this.$emit('close')
			window.location.href = generateUrl('/settings/admin/shillinq')
		},

		/** @spec openspec/specs/shillinq-bank-statement-wizard/spec.md */
		onClose() {
			if (this.importing) return
			// If an import completed but the user closes instead of reviewing,
			// still refresh the dashboard widgets (REQ-BSW-005).
			if (this.result) {
				this.refreshDashboardWidgets()
			}
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.bsw {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 2px 8px;
	min-width: min(520px, 80vw);
}

.bsw__step {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.bsw__heading {
	font-weight: 600;
	margin: 0;
}

.bsw__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
	font-size: 0.92em;
}

.bsw__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.bsw__label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.bsw__input {
	box-sizing: border-box;
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-family: inherit;
}

.bsw__psd2 {
	margin: 0;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
}

.bsw__link {
	color: var(--color-primary-element);
	font-weight: 600;
}

.bsw__meta {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin: 0;
}

.bsw__meta dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.bsw__meta dd {
	margin: 0;
}

.bsw__counts {
	margin: 0;
	padding-left: 18px;
}

.bsw__error {
	color: var(--color-error);
	margin: 0;
}
</style>
