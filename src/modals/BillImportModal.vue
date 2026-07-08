<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 BillImportModal — shillinq-bill-import-modal.

 A two-step NcDialog launched from the Financial overview dashboard's
 "Import bill" action — a declarative `config.headerActions[]` open-modal
 action (ADR-049 Phase-4) targeting this component's registry id — so the
 bookkeeper records a supplier invoice without losing the dashboard context.

 Step 1 (Upload): drag-and-drop or file-picker for a UBL/e-invoice XML or
 CSV (REQ-BIM-001). The upload POSTs to /api/v1/supplier-invoices/import,
 which ingests UBL/CSV deterministically (no OCR). A PDF is accepted by
 the picker but HONESTLY DEFERRED — there is no OCR engine bundled with
 this change, so selecting a PDF shows the deferral notice and the server
 returns HTTP 422 rather than fabricating an extraction (REQ-BIM-002).

 Step 2 (Review & confirm): the parsed values (supplier, invoice number,
 date, amount, VAT) are shown in an editable form; save is gated on
 supplier, invoiceNumber, invoiceDate and glAccount (REQ-BIM-003). A 409
 duplicate stays open with an inline warning (REQ-BIM-005). On success the
 modal closes and emits cn:widget:refresh for widget-open-creditors so the
 payables widget reloads without navigation (REQ-BIM-004).

 Modal isolation (hydra gate-13): this dialog lives in its own .vue file
 under src/modals/ and is registered as a kind:"modal" so the dashboard
 headerAction's open-modal `target` resolves it. The non-trivial logic
 lives in the sibling billImportModal.js so it is unit testable without the SFC.

 @spec openspec/changes/shillinq-bill-import-modal/specs/shillinq-bill-import-modal/spec.md
-->

<template>
	<NcDialog
		v-if="open"
		:name="t('shillinq', 'Import bill')"
		size="normal"
		data-testid="bill-import-modal"
		@closing="onClose">
		<div class="bim">
			<!-- Step 1 — Upload -->
			<div v-if="step === 'upload'" class="bim__step" data-testid="bim-upload-step">
				<p class="bim__intro">
					{{ t('shillinq', 'Upload supplier invoice') }}
				</p>
				<div
					class="bim__dropzone"
					data-testid="bim-dropzone"
					@dragover.prevent
					@drop.prevent="onDrop">
					<span>{{ t('shillinq', 'Drag and drop a UBL XML or CSV file') }}</span>
					<input
						ref="fileInput"
						type="file"
						accept=".xml,.ubl,.csv,.pdf,application/xml,text/csv,application/pdf"
						class="bim__file"
						data-testid="bim-file-input"
						@change="onFileSelected">
				</div>

				<p class="bim__hint" data-testid="bim-pdf-hint">
					{{ t('shillinq', 'PDF OCR extraction is not yet available. Please upload a UBL/e-invoice XML or CSV.') }}
				</p>

				<p v-if="pdfDeferred" class="bim__deferred" data-testid="bim-pdf-deferred">
					{{ t('shillinq', 'PDF OCR extraction is not yet available. Please upload a UBL/e-invoice XML or CSV.') }}
				</p>

				<p v-if="error" class="bim__error" data-testid="bim-upload-error">
					{{ error }}
				</p>
			</div>

			<!-- Step 2 — Review & confirm -->
			<div v-else class="bim__step" data-testid="bim-review-step">
				<p class="bim__intro">
					{{ t('shillinq', 'Review and confirm') }}
				</p>

				<div class="bim__field">
					<label class="bim__label" for="bim-supplier">{{ t('shillinq', 'Supplier') }}</label>
					<input
						id="bim-supplier"
						v-model="form.supplier"
						type="text"
						class="bim__input"
						data-testid="bim-supplier">
				</div>

				<div class="bim__field">
					<label class="bim__label" for="bim-invoice-number">{{ t('shillinq', 'Invoice number') }}</label>
					<input
						id="bim-invoice-number"
						v-model="form.invoiceNumber"
						type="text"
						class="bim__input"
						data-testid="bim-invoice-number">
				</div>

				<div class="bim__row">
					<div class="bim__field">
						<label class="bim__label" for="bim-invoice-date">{{ t('shillinq', 'Invoice date') }}</label>
						<input
							id="bim-invoice-date"
							v-model="form.invoiceDate"
							type="date"
							class="bim__input"
							data-testid="bim-invoice-date">
					</div>
					<div class="bim__field">
						<label class="bim__label" for="bim-amount">{{ t('shillinq', 'Amount') }}</label>
						<input
							id="bim-amount"
							v-model.number="form.amount"
							type="number"
							step="0.01"
							class="bim__input"
							data-testid="bim-amount">
					</div>
					<div class="bim__field">
						<label class="bim__label" for="bim-vat-amount">{{ t('shillinq', 'VAT amount') }}</label>
						<input
							id="bim-vat-amount"
							v-model.number="form.vatAmount"
							type="number"
							step="0.01"
							class="bim__input"
							data-testid="bim-vat-amount">
					</div>
				</div>

				<div class="bim__field">
					<GlAccountPicker
						v-model="form.glAccount"
						data-testid="bim-gl-account" />
				</div>

				<p v-if="error" class="bim__error" data-testid="bim-review-error">
					{{ error }}
				</p>
			</div>
		</div>

		<template #actions>
			<NcButton
				:disabled="busy"
				data-testid="bim-cancel"
				@click="onClose">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="step === 'review'"
				type="primary"
				:disabled="!canSave || busy"
				data-testid="bim-save"
				@click="onSave">
				{{ busy ? t('shillinq', 'Saving…') : t('shillinq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import GlAccountPicker from '../components/BudgetBBVMapping/GlAccountPicker.vue'
import {
	detectFormat,
	isDeferredPdf,
	buildImportFormData,
	reviewFormFromRecord,
	canSaveReview,
	importErrorMessage,
	refreshEventPayload,
	PDF_DEFERRAL_MESSAGE,
} from './billImportModal.js'

const REGISTER_SLUG = 'shillinq'
const SUPPLIER_INVOICE_SCHEMA = 'SupplierInvoice'

export default {
	name: 'BillImportModal',
	components: { NcDialog, NcButton, GlAccountPicker },
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['close', 'imported'],
	data() {
		return {
			step: 'upload',
			busy: false,
			error: '',
			pdfDeferred: false,
			importedRecord: null,
			form: this.emptyForm(),
		}
	},
	computed: {
		/** @spec openspec/changes/shillinq-bill-import-modal/specs/shillinq-bill-import-modal/spec.md */
		canSave() {
			return canSaveReview(this.form)
		},
	},
	watch: {
		/** @spec openspec/changes/shillinq-bill-import-modal/specs/shillinq-bill-import-modal/spec.md */
		open(next) {
			if (next === true) {
				this.reset()
			}
		},
	},
	methods: {
		t,
		/** @spec openspec/changes/shillinq-bill-import-modal/specs/shillinq-bill-import-modal/spec.md */
		emptyForm() {
			return {
				supplier: '',
				invoiceNumber: '',
				invoiceDate: '',
				amount: 0,
				vatAmount: 0,
				glAccount: '',
			}
		},
		/** @spec openspec/changes/shillinq-bill-import-modal/specs/shillinq-bill-import-modal/spec.md */
		reset() {
			this.step = 'upload'
			this.busy = false
			this.error = ''
			this.pdfDeferred = false
			this.importedRecord = null
			this.form = this.emptyForm()
		},
		/** @spec openspec/changes/shillinq-bill-import-modal/specs/shillinq-bill-import-modal/spec.md */
		onDrop(event) {
			const file = event?.dataTransfer?.files?.[0]
			if (file) this.handleFile(file)
		},
		/** @spec openspec/changes/shillinq-bill-import-modal/specs/shillinq-bill-import-modal/spec.md */
		onFileSelected(event) {
			const file = event?.target?.files?.[0]
			if (file) this.handleFile(file)
		},
		/** @spec openspec/changes/shillinq-bill-import-modal/specs/shillinq-bill-import-modal/spec.md */
		async handleFile(file) {
			this.error = ''
			this.pdfDeferred = false
			const format = detectFormat(file.name)

			// Honest PDF-OCR deferral (REQ-BIM-002) — never POST a fake
			// extraction; tell the user OCR is unavailable.
			if (isDeferredPdf(format)) {
				this.pdfDeferred = true
				this.error = t('shillinq', PDF_DEFERRAL_MESSAGE)
				return
			}

			if (format !== 'ubl' && format !== 'csv') {
				this.error = t('shillinq', 'Unsupported file. Upload a UBL/e-invoice XML or CSV.')
				return
			}

			this.busy = true
			try {
				const body = buildImportFormData(file, format)
				const response = await axios.post(
					generateUrl('/apps/shillinq/api/v1/supplier-invoices/import'),
					body,
				)
				const data = response.data ?? {}
				const record = data.record ?? (Array.isArray(data.records) ? data.records[0] : null) ?? {}
				this.importedRecord = record
				this.form = reviewFormFromRecord(record)
				this.step = 'review'
			} catch (e) {
				if (e?.response?.status === 422 && e?.response?.data?.deferred === 'pdf-ocr') {
					this.pdfDeferred = true
				}
				this.error = importErrorMessage(e)
				showError(this.error)
			} finally {
				this.busy = false
			}
		},
		/** @spec openspec/changes/shillinq-bill-import-modal/specs/shillinq-bill-import-modal/spec.md */
		async onSave() {
			if (!this.canSave || this.busy) return
			this.busy = true
			this.error = ''
			try {
				const record = { ...(this.importedRecord || {}) }
				record.supplierId = this.form.supplier
				record.invoiceNumber = this.form.invoiceNumber
				record.invoiceDate = this.form.invoiceDate
				record.glAccount = this.form.glAccount
				record.totalInclVat = Math.round((Number(this.form.amount) || 0) * 100)
				record.totalVat = Math.round((Number(this.form.vatAmount) || 0) * 100)
				record.statusCode = record.statusCode || 'received'

				const response = await axios.post(
					generateUrl(`/apps/openregister/api/objects/${REGISTER_SLUG}/${SUPPLIER_INVOICE_SCHEMA}`),
					record,
				)
				const created = response.data?.object ?? response.data ?? {}
				showSuccess(t('shillinq', 'Bill imported.'))
				// REQ-BIM-004: refresh the payables widget without navigation.
				emit('cn:widget:refresh', refreshEventPayload())
				this.$emit('imported', { id: created.id ?? created.uuid ?? '' })
				this.$emit('close')
			} catch (e) {
				this.error = importErrorMessage(e)
				showError(this.error)
			} finally {
				this.busy = false
			}
		},
		/** @spec openspec/changes/shillinq-bill-import-modal/specs/shillinq-bill-import-modal/spec.md */
		onClose() {
			if (this.busy) return
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.bim {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 2px 8px;
	min-width: min(520px, 80vw);
}

.bim__step {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.bim__intro {
	font-weight: 600;
	margin: 0;
}

.bim__dropzone {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: center;
	justify-content: center;
	border: 2px dashed var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.bim__file {
	cursor: pointer;
}

.bim__hint {
	margin: 0;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.bim__deferred {
	margin: 0;
	padding: 8px 10px;
	border-radius: var(--border-radius);
	background: var(--color-warning, #fae9b8);
	color: var(--color-main-text);
}

.bim__row {
	display: flex;
	gap: 12px;
}

.bim__field {
	display: flex;
	flex-direction: column;
	flex: 1;
	gap: 4px;
}

.bim__label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.bim__input {
	box-sizing: border-box;
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-family: inherit;
}

.bim__error {
	color: var(--color-error);
	margin: 0;
}
</style>
