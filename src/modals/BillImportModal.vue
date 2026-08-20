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

 receipt-extraction-consume (REQ-RXC-002 / REQ-RXC-004 / REQ-RXC-005): when
 opened without a file (Step 1), any uncommitted docudesk extraction drafts
 (`extractionStatus: pending-review`, created asynchronously by
 ExtractionCompletedListener from the nl.conduction.docudesk.extraction.completed
 event) are listed above the dropzone; picking one jumps straight to Step 2
 with every field pre-filled AND its per-field confidence shown
 (FieldConfidenceBadge), sub-threshold fields flagged for review. Editing a
 field before Save records it as human-corrected server-side
 (ExtractionPrefillService::recordCorrection, never silently discarding the
 original extracted value/confidence). A "Request extraction" action
 (re-)asks docudesk for a fresh extraction via the ExtractionRequestController
 proxy; the resulting event round-trips back through REQ-RXC-001. Confidence
 never bypasses the explicit Save click (REQ-RXC-006) — it only changes
 whether the badges + review copy are shown.

 @spec openspec/specs/shillinq-bill-import-modal/spec.md
 @spec openspec/specs/receipt-extraction-consume/spec.md
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
			<div
				v-if="step === 'upload'"
				class="bim__step"
				data-testid="bim-upload-step">
				<p class="bim__intro">
					{{ t('shillinq', 'Upload supplier invoice') }}
				</p>
				<div
					class="bim__dropzone"
					data-testid="bim-dropzone"
					@dragover.prevent
					@drop.prevent="onDrop">
					<span>{{
						t('shillinq', 'Drag and drop a UBL XML or CSV file')
					}}</span>
					<input
						ref="fileInput"
						type="file"
						accept=".xml,.ubl,.csv,.pdf,application/xml,text/csv,application/pdf"
						class="bim__file"
						:aria-label="
							t(
								'shillinq',
								'Choose a UBL XML, CSV or PDF bill to import',
							)
						"
						data-testid="bim-file-input"
						@change="onFileSelected" />
				</div>

				<p class="bim__hint" data-testid="bim-pdf-hint">
					{{
						t(
							'shillinq',
							'PDF OCR extraction is not yet available. Please upload a UBL/e-invoice XML or CSV.',
						)
					}}
				</p>

				<p
					v-if="pdfDeferred"
					class="bim__deferred"
					data-testid="bim-pdf-deferred">
					{{
						t(
							'shillinq',
							'PDF OCR extraction is not yet available. Please upload a UBL/e-invoice XML or CSV.',
						)
					}}
				</p>

				<p v-if="error" class="bim__error" data-testid="bim-upload-error">
					{{ error }}
				</p>

				<!-- receipt-extraction-consume (REQ-RXC-001/002) — pending
				     extraction drafts awaiting operator review. -->
				<div
					v-if="pendingDrafts.length > 0"
					class="bim__pending"
					data-testid="bim-pending-drafts">
					<p class="bim__intro">
						{{ t('shillinq', 'Extracted fields') }}
					</p>
					<ul class="bim__pending-list">
						<li
							v-for="draft in pendingDrafts"
							:key="draft.id"
							class="bim__pending-item"
							:data-testid="`bim-pending-${draft.id}`">
							<button
								type="button"
								class="bim__pending-button"
								@click="openDraft(draft.id)">
								<span>{{
									draft.label
									|| t('shillinq', '(no invoice number)')
								}}</span>
								<FieldConfidenceBadge
									:confidence="draft.overallConfidence" />
							</button>
						</li>
					</ul>
				</div>
			</div>

			<!-- Step 2 — Review & confirm -->
			<div v-else class="bim__step" data-testid="bim-review-step">
				<p class="bim__intro">
					{{ t('shillinq', 'Review and confirm') }}
				</p>

				<p
					v-if="isDraftReview"
					class="bim__hint"
					data-testid="bim-review-hint">
					{{
						requiresReview
							? t(
									'shillinq',
									'Some fields have low confidence — please review before confirming.',
								)
							: t(
									'shillinq',
									'Extraction confidence is high. Review and confirm.',
								)
					}}
				</p>

				<div class="bim__field">
					<label class="bim__label" for="bim-supplier">{{
						t('shillinq', 'Supplier')
					}}</label>
					<div class="bim__field-row">
						<input
							id="bim-supplier"
							v-model="form.supplier"
							type="text"
							class="bim__input"
							data-testid="bim-supplier" />
						<FieldConfidenceBadge
							v-if="isDraftReview"
							field="supplierId"
							:confidence="confidenceFor('supplierId')"
							:corrected="isCorrected('supplierId')" />
					</div>
				</div>

				<div class="bim__field">
					<label class="bim__label" for="bim-invoice-number">{{
						t('shillinq', 'Invoice number')
					}}</label>
					<div class="bim__field-row">
						<input
							id="bim-invoice-number"
							v-model="form.invoiceNumber"
							type="text"
							class="bim__input"
							data-testid="bim-invoice-number" />
						<FieldConfidenceBadge
							v-if="isDraftReview"
							field="invoiceNumber"
							:confidence="confidenceFor('invoiceNumber')"
							:corrected="isCorrected('invoiceNumber')" />
					</div>
				</div>

				<div class="bim__row">
					<div class="bim__field">
						<label class="bim__label" for="bim-invoice-date">{{
							t('shillinq', 'Invoice date')
						}}</label>
						<div class="bim__field-row">
							<input
								id="bim-invoice-date"
								v-model="form.invoiceDate"
								type="date"
								class="bim__input"
								data-testid="bim-invoice-date" />
							<FieldConfidenceBadge
								v-if="isDraftReview"
								field="invoiceDate"
								:confidence="confidenceFor('invoiceDate')"
								:corrected="isCorrected('invoiceDate')" />
						</div>
					</div>
					<div class="bim__field">
						<label class="bim__label" for="bim-amount">{{
							t('shillinq', 'Amount')
						}}</label>
						<div class="bim__field-row">
							<input
								id="bim-amount"
								v-model.number="form.amount"
								type="number"
								step="0.01"
								class="bim__input"
								data-testid="bim-amount" />
							<FieldConfidenceBadge
								v-if="isDraftReview"
								field="totalInclVat"
								:confidence="confidenceFor('totalInclVat')"
								:corrected="isCorrected('totalInclVat')" />
						</div>
					</div>
					<div class="bim__field">
						<label class="bim__label" for="bim-vat-amount">{{
							t('shillinq', 'VAT amount')
						}}</label>
						<input
							id="bim-vat-amount"
							v-model.number="form.vatAmount"
							type="number"
							step="0.01"
							class="bim__input"
							data-testid="bim-vat-amount" />
					</div>
				</div>

				<div class="bim__field">
					<div class="bim__field-row">
						<GlAccountPicker
							v-model="form.glAccount"
							data-testid="bim-gl-account" />
						<FieldConfidenceBadge
							v-if="isDraftReview"
							field="glAccount"
							:confidence="confidenceFor('glAccount')"
							:corrected="isCorrected('glAccount')" />
					</div>
				</div>

				<!-- gl-account-suggestion-consume (REQ-GAC-003/004) — docudesk's
				     suggested booking account, reusing FieldConfidenceBadge; the
				     operator must still click "Use suggestion" AND Save — never
				     auto-filled/auto-booked (REQ-GAC-004/REQ-RXC-006). -->
				<div
					v-if="glSuggestion"
					class="bim__suggestion"
					data-testid="bim-gl-suggestion">
					<div class="bim__field-row">
						<FieldConfidenceBadge
							field="suggestedGlAccount"
							:confidence="glSuggestion.confidence" />
						<span class="bim__suggestion-text">
							{{
								t('shillinq', 'Suggested: {code} {label}', {
									code: glSuggestion.code,
									label: glSuggestion.label,
								})
							}}
						</span>
						<NcButton
							variant="tertiary"
							:disabled="busy"
							data-testid="bim-use-suggestion"
							@click="onUseSuggestion">
							{{ t('shillinq', 'Use suggestion') }}
						</NcButton>
					</div>
					<p
						class="bim__suggestion-rationale"
						data-testid="bim-gl-suggestion-rationale">
						{{ glSuggestion.rationale }}
					</p>
				</div>

				<NcButton
					v-if="isDraftReview && canRerequest"
					variant="secondary"
					:disabled="busy"
					data-testid="bim-rerequest"
					@click="onRerequest">
					{{ t('shillinq', 'Request extraction') }}
				</NcButton>

				<p v-if="error" class="bim__error" data-testid="bim-review-error">
					{{ error }}
				</p>
			</div>
		</div>

		<template #actions>
			<NcButton :disabled="busy" data-testid="bim-cancel" @click="onClose">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="step === 'review'"
				variant="primary"
				:disabled="!canSave || busy"
				data-testid="bim-save"
				@click="onSave">
				{{ busy ? t('shillinq', 'Saving…') : t('shillinq', 'Save') }}
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
import GlAccountPicker from '../components/BudgetBBVMapping/GlAccountPicker.vue'
import FieldConfidenceBadge from '../components/FieldConfidenceBadge.vue'
import {
	buildImportFormData,
	canSaveReview,
	confidenceForField,
	detectFormat,
	glAccountSuggestionSummary,
	hasKnownExtractionId,
	importErrorMessage,
	isDeferredPdf,
	isExtractionDraft,
	isFieldCorrected,
	PDF_DEFERRAL_MESSAGE,
	pendingDraftSummary,
	refreshEventPayload,
	requiresExplicitReview,
	reviewFormFromRecord,
} from './billImportModal.js'

const REGISTER_SLUG = 'shillinq'
const SUPPLIER_INVOICE_SCHEMA = 'SupplierInvoice'

export default {
	name: 'BillImportModal',
	components: { NcDialog, NcButton, GlAccountPicker, FieldConfidenceBadge },
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
			pendingDraftRows: [],
			glSuggestion: null,
		}
	},

	computed: {
		/** @spec openspec/specs/shillinq-bill-import-modal/spec.md */
		canSave() {
			return canSaveReview(this.form)
		},

		/** @spec openspec/specs/receipt-extraction-consume/spec.md */
		isDraftReview() {
			return isExtractionDraft(this.importedRecord)
		},

		/** @spec openspec/specs/receipt-extraction-consume/spec.md */
		requiresReview() {
			return requiresExplicitReview(this.importedRecord)
		},

		/** @spec openspec/specs/receipt-extraction-consume/spec.md */
		canRerequest() {
			return !!this.importedRecord?.sourceDocumentUri
		},

		/** @spec openspec/specs/receipt-extraction-consume/spec.md */
		pendingDrafts() {
			return this.pendingDraftRows.map(pendingDraftSummary)
		},
	},

	watch: {
		/**
		 * @param next
		 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
		 */
		open(next) {
			if (next === true) {
				this.reset()
				this.loadPendingDrafts()
			}
		},
	},

	methods: {
		t,
		/** @spec openspec/specs/shillinq-bill-import-modal/spec.md */
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

		/** @spec openspec/specs/shillinq-bill-import-modal/spec.md */
		reset() {
			this.step = 'upload'
			this.busy = false
			this.error = ''
			this.pdfDeferred = false
			this.importedRecord = null
			this.form = this.emptyForm()
			this.pendingDraftRows = []
			this.glSuggestion = null
		},

		/**
		 * @param field
		 * @spec openspec/specs/receipt-extraction-consume/spec.md
		 */
		confidenceFor(field) {
			return confidenceForField(this.importedRecord, field)
		},

		/**
		 * @param field
		 * @spec openspec/specs/receipt-extraction-consume/spec.md
		 */
		isCorrected(field) {
			return isFieldCorrected(this.importedRecord, field)
		},

		/**
		 * Load pending SupplierInvoice extraction drafts (REQ-RXC-001/002) so
		 * the operator can jump straight to the confidence-scored review step
		 * instead of only being able to start a fresh UBL/CSV upload.
		 *
		 * @spec openspec/specs/receipt-extraction-consume/spec.md
		 */
		async loadPendingDrafts() {
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/${SUPPLIER_INVOICE_SCHEMA}`,
					),
					{ params: { extractionStatus: 'pending-review' } },
				)
				const rows =
					response.data?.results
					?? response.data?.objects
					?? response.data
					?? []
				this.pendingDraftRows = Array.isArray(rows) ? rows : []
			} catch (e) {
				// Non-blocking — the upload path still works without the list.
				this.pendingDraftRows = []
			}
		},

		/**
		 * Open an extraction draft directly into the review step, pre-filled
		 * with per-field confidence (REQ-RXC-002), then requests a GL-account
		 * suggestion for it (gl-account-suggestion-consume, REQ-GAC-003).
		 *
		 * @spec openspec/specs/receipt-extraction-consume/spec.md
		 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
		 * @param {string} id The draft's OR object id.
		 */
		async openDraft(id) {
			this.error = ''
			this.busy = true
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/${SUPPLIER_INVOICE_SCHEMA}/${id}`,
					),
				)
				const record = response.data?.object ?? response.data ?? {}
				this.importedRecord = record
				this.form = reviewFormFromRecord(record)
				this.step = 'review'
				this.fetchGlSuggestion()
			} catch (e) {
				this.error = importErrorMessage(e)
				showError(this.error)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Request a GL-account suggestion for the currently-reviewed draft
		 * (gl-account-suggestion-consume, REQ-GAC-003) via the shillinq proxy.
		 * Degrades gracefully when the draft has no known docudesk extraction
		 * id or docudesk returns no suggestion (REQ-GAC-006); the operator's
		 * plain manual booking is unaffected either way.
		 *
		 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
		 */
		async fetchGlSuggestion() {
			this.glSuggestion = null
			if (!hasKnownExtractionId(this.importedRecord)) {
				return
			}

			try {
				const response = await axios.post(
					generateUrl(
						`/apps/shillinq/api/v1/extraction/drafts/${this.importedRecord.id}/suggest-account?schema=${SUPPLIER_INVOICE_SCHEMA}`,
					),
				)
				this.glSuggestion = glAccountSuggestionSummary(response.data)
			} catch (e) {
				this.glSuggestion = null
			}
		},

		/**
		 * Fill the GL-account picker with the suggested code — the operator
		 * still must click Save to commit anything (REQ-GAC-004).
		 *
		 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
		 */
		onUseSuggestion() {
			if (!this.glSuggestion) return
			this.form.glAccount = this.glSuggestion.code
		},

		/**
		 * (Re-)request docudesk extraction for the currently reviewed draft
		 * (REQ-RXC-005) via the shillinq proxy endpoint.
		 *
		 * @spec openspec/specs/receipt-extraction-consume/spec.md
		 */
		async onRerequest() {
			if (this.busy || !this.canRerequest) return
			this.busy = true
			this.error = ''
			try {
				await axios.post(
					generateUrl('/apps/shillinq/api/v1/extraction/request'),
					{
						documentUri: this.importedRecord.sourceDocumentUri,
						docType: 'supplier-invoice',
						id: this.importedRecord.id ?? '',
					},
				)
				showSuccess(
					t(
						'shillinq',
						'Extraction requested. The draft will update once docudesk responds.',
					),
				)
			} catch (e) {
				this.error = importErrorMessage(e)
				showError(this.error)
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param event
		 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
		 */
		onDrop(event) {
			const file = event?.dataTransfer?.files?.[0]
			if (file) this.handleFile(file)
		},

		/**
		 * @param event
		 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
		 */
		onFileSelected(event) {
			const file = event?.target?.files?.[0]
			if (file) this.handleFile(file)
		},

		/**
		 * @param file
		 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
		 */
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
				this.error = t(
					'shillinq',
					'Unsupported file. Upload a UBL/e-invoice XML or CSV.',
				)
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
				const record =
					data.record
					?? (Array.isArray(data.records) ? data.records[0] : null)
					?? {}
				this.importedRecord = record
				this.form = reviewFormFromRecord(record)
				this.step = 'review'
			} catch (e) {
				if (
					e?.response?.status === 422
					&& e?.response?.data?.deferred === 'pdf-ocr'
				) {
					this.pdfDeferred = true
				}
				this.error = importErrorMessage(e)
				showError(this.error)
			} finally {
				this.busy = false
			}
		},

		/**
		 * REQ-BIM-003/004 (manual UBL/CSV import) OR, for an extraction draft
		 * (REQ-RXC-004), commits any operator corrections through the
		 * ExtractionRequestController confirm proxy — which records
		 * `humanCorrected` provenance server-side — instead of creating a
		 * second SupplierInvoice record.
		 *
		 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
		 * @spec openspec/specs/receipt-extraction-consume/spec.md
		 */
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
				record.totalInclVat = Math.round(
					(Number(this.form.amount) || 0) * 100,
				)
				record.totalVat = Math.round(
					(Number(this.form.vatAmount) || 0) * 100,
				)
				record.statusCode = record.statusCode || 'received'

				let created
				if (this.isDraftReview && record.id) {
					// REQ-RXC-004: commit the correction on the existing draft —
					// never fabricates a duplicate SupplierInvoice.
					const response = await axios.put(
						generateUrl(
							`/apps/shillinq/api/v1/extraction/drafts/${record.id}?schema=${SUPPLIER_INVOICE_SCHEMA}`,
						),
						{
							supplierId: record.supplierId,
							invoiceNumber: record.invoiceNumber,
							invoiceDate: record.invoiceDate,
							glAccount: record.glAccount,
							totalInclVat: record.totalInclVat,
							totalVat: record.totalVat,
						},
					)
					created = response.data?.record ?? response.data ?? {}
				} else {
					const response = await axios.post(
						generateUrl(
							`/apps/openregister/api/objects/${REGISTER_SLUG}/${SUPPLIER_INVOICE_SCHEMA}`,
						),
						record,
					)
					created = response.data?.object ?? response.data ?? {}
				}

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

		/** @spec openspec/specs/shillinq-bill-import-modal/spec.md */
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

.bim__field-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.bim__field-row .bim__input {
	flex: 1;
}

.bim__pending {
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
	margin-top: 4px;
}

.bim__pending-list {
	list-style: none;
	margin: 8px 0 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.bim__pending-button {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	width: 100%;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	text-align: left;
}

.bim__pending-button:hover,
.bim__pending-button:focus {
	background: var(--color-background-hover);
}

.bim__suggestion {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.bim__suggestion-text {
	flex: 1;
	color: var(--color-main-text);
}

.bim__suggestion-rationale {
	margin: 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
</style>
