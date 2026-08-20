<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ReceiptCapture — receipt-extraction-consume (REQ-RXC-003 / REQ-RXC-004 /
 REQ-RXC-005).

 Custom detail page for a single `Receipt` record. Fetches the record via
 OR's real ObjectService API, renders amount / receiptDate / currency /
 category / extractedText with per-field confidence (FieldConfidenceBadge)
 when the record is an extraction draft (`extractionStatus: pending-review`,
 created asynchronously by ExtractionCompletedListener from docudesk's
 nl.conduction.docudesk.extraction.completed event), and lets the operator
 correct any field before committing. Save on a draft PUTs through the
 ExtractionRequestController confirm proxy (recording `humanCorrected`
 provenance server-side, REQ-RXC-004); Save on an already-confirmed/manually
 created receipt PUTs the plain OR object endpoint. A "Request extraction"
 action re-asks docudesk via the same proxy (REQ-RXC-005); the resulting
 event round-trips back through ExtractionCompletedListener. Confidence never
 bypasses the explicit Save click (REQ-RXC-006).

 Registered as a `kind: "page"` custom component in src/registry.js so the
 v2 manifest router owns the URL -> component mapping (see
 src/manifest.d/receipt-extraction-consume.json, `type: "custom"`,
 `component: "ReceiptCapture"`, overlaying the base ReceiptDetail
 `type: "detail"` page by id — mergePages() replaces by id, mirroring
 AfspraakDetail). Field parity: every field the native field-list page
 rendered (receiptNumber, photoUri, exchangeRate, costCentreCode, claimId)
 is still shown here so the overlay does not drop functionality — only the
 native page's Files / Audit-trail sidebar tabs are not reproduced (the OR
 audit trail itself is unaffected; it is recorded server-side regardless of
 which UI renders the object).

 @spec openspec/specs/receipt-extraction-consume/spec.md
-->

<template>
	<div class="receipt-capture">
		<header class="receipt-capture__header">
			<router-link
				v-if="hasIndexRoute"
				:to="{ name: 'Receipts' }"
				class="receipt-capture__back"
				data-testid="receipt-capture-back">
				&larr; {{ t('shillinq', 'Back to receipts') }}
			</router-link>
			<h1 class="receipt-capture__title">
				{{ t('shillinq', 'Receipt') }}
			</h1>
		</header>

		<div
			v-if="loading"
			class="receipt-capture__loading"
			data-testid="receipt-capture-loading">
			{{ t('shillinq', 'Loading receipt…') }}
		</div>

		<div
			v-else-if="loadError"
			class="receipt-capture__error"
			data-testid="receipt-capture-error">
			<p>{{ loadError }}</p>
			<button
				type="button"
				class="receipt-capture__retry"
				data-testid="receipt-capture-retry"
				@click="reload">
				{{ t('shillinq', 'Retry') }}
			</button>
		</div>

		<div v-else class="receipt-capture__body">
			<p
				v-if="isDraftReview"
				class="receipt-capture__hint"
				data-testid="receipt-capture-review-hint">
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

			<div class="receipt-capture__row">
				<div class="receipt-capture__field">
					<label class="receipt-capture__label" for="rc-receipt-number">{{
						t('shillinq', 'Receipt #')
					}}</label>
					<input
						id="rc-receipt-number"
						v-model="form.receiptNumber"
						type="text"
						class="receipt-capture__input"
						data-testid="rc-receipt-number" />
				</div>
				<div class="receipt-capture__field">
					<label class="receipt-capture__label" for="rc-photo-uri">{{
						t('shillinq', 'Photo')
					}}</label>
					<input
						id="rc-photo-uri"
						v-model="form.photoUri"
						type="text"
						class="receipt-capture__input"
						data-testid="rc-photo-uri" />
				</div>
			</div>

			<div class="receipt-capture__field">
				<label class="receipt-capture__label" for="rc-amount">{{
					t('shillinq', 'Amount')
				}}</label>
				<div class="receipt-capture__field-row">
					<input
						id="rc-amount"
						v-model.number="form.amount"
						type="number"
						step="0.01"
						class="receipt-capture__input"
						data-testid="rc-amount" />
					<FieldConfidenceBadge
						v-if="isDraftReview"
						field="amount"
						:confidence="confidenceFor('amount')"
						:corrected="isCorrected('amount')" />
				</div>
			</div>

			<div class="receipt-capture__row">
				<div class="receipt-capture__field">
					<label class="receipt-capture__label" for="rc-currency">{{
						t('shillinq', 'Currency')
					}}</label>
					<input
						id="rc-currency"
						v-model="form.currency"
						type="text"
						class="receipt-capture__input"
						data-testid="rc-currency" />
				</div>
				<div class="receipt-capture__field">
					<label class="receipt-capture__label" for="rc-receipt-date">{{
						t('shillinq', 'Receipt date')
					}}</label>
					<div class="receipt-capture__field-row">
						<input
							id="rc-receipt-date"
							v-model="form.receiptDate"
							type="date"
							class="receipt-capture__input"
							data-testid="rc-receipt-date" />
						<FieldConfidenceBadge
							v-if="isDraftReview"
							field="receiptDate"
							:confidence="confidenceFor('receiptDate')"
							:corrected="isCorrected('receiptDate')" />
					</div>
				</div>
			</div>

			<div class="receipt-capture__field">
				<label class="receipt-capture__label" for="rc-category">{{
					t('shillinq', 'Category')
				}}</label>
				<input
					id="rc-category"
					v-model="form.category"
					type="text"
					class="receipt-capture__input"
					data-testid="rc-category" />
			</div>

			<div class="receipt-capture__field">
				<div class="receipt-capture__field-row">
					<GlAccountPicker
						v-model="form.glAccount"
						data-testid="rc-gl-account" />
					<FieldConfidenceBadge
						v-if="isDraftReview"
						field="glAccount"
						:confidence="confidenceFor('glAccount')"
						:corrected="isCorrected('glAccount')" />
				</div>
			</div>

			<!-- gl-account-suggestion-consume (REQ-GAC-003/004) — same pattern as
			     BillImportModal: the operator must click "Use suggestion" AND
			     Save — never auto-filled/auto-booked. -->
			<div
				v-if="glSuggestion"
				class="receipt-capture__suggestion"
				data-testid="rc-gl-suggestion">
				<div class="receipt-capture__field-row">
					<FieldConfidenceBadge
						field="suggestedGlAccount"
						:confidence="glSuggestion.confidence" />
					<span class="receipt-capture__suggestion-text">
						{{
							t('shillinq', 'Suggested: {code} {label}', {
								code: glSuggestion.code,
								label: glSuggestion.label,
							})
						}}
					</span>
					<button
						type="button"
						class="receipt-capture__secondary"
						:disabled="busy"
						data-testid="rc-use-suggestion"
						@click="onUseSuggestion">
						{{ t('shillinq', 'Use suggestion') }}
					</button>
				</div>
				<p
					class="receipt-capture__suggestion-rationale"
					data-testid="rc-gl-suggestion-rationale">
					{{ glSuggestion.rationale }}
				</p>
			</div>

			<div class="receipt-capture__field">
				<label class="receipt-capture__label" for="rc-vendor">{{
					t('shillinq', 'Vendor')
				}}</label>
				<input
					id="rc-vendor"
					v-model="form.vendorName"
					type="text"
					class="receipt-capture__input"
					data-testid="rc-vendor" />
			</div>

			<div class="receipt-capture__field">
				<label class="receipt-capture__label" for="rc-extracted-text">{{
					t('shillinq', 'Extracted text')
				}}</label>
				<textarea
					id="rc-extracted-text"
					v-model="form.extractedText"
					class="receipt-capture__textarea"
					rows="3"
					data-testid="rc-extracted-text" />
			</div>

			<div class="receipt-capture__row">
				<div class="receipt-capture__field">
					<label class="receipt-capture__label" for="rc-amount-base">{{
						t('shillinq', 'Amount (EUR)')
					}}</label>
					<input
						id="rc-amount-base"
						v-model.number="form.amountInBaseCurrency"
						type="number"
						step="0.01"
						class="receipt-capture__input"
						data-testid="rc-amount-base" />
				</div>
				<div class="receipt-capture__field">
					<label class="receipt-capture__label" for="rc-exchange-rate">{{
						t('shillinq', 'Exchange Rate')
					}}</label>
					<input
						id="rc-exchange-rate"
						v-model.number="form.exchangeRate"
						type="number"
						step="0.0001"
						class="receipt-capture__input"
						data-testid="rc-exchange-rate" />
				</div>
			</div>

			<div class="receipt-capture__row">
				<div class="receipt-capture__field">
					<label class="receipt-capture__label" for="rc-cost-centre">{{
						t('shillinq', 'Cost Centre')
					}}</label>
					<input
						id="rc-cost-centre"
						v-model="form.costCentreCode"
						type="text"
						class="receipt-capture__input"
						data-testid="rc-cost-centre" />
				</div>
				<div class="receipt-capture__field">
					<label class="receipt-capture__label" for="rc-claim">{{
						t('shillinq', 'Claim')
					}}</label>
					<input
						id="rc-claim"
						v-model="form.claimId"
						type="text"
						class="receipt-capture__input"
						data-testid="rc-claim" />
				</div>
			</div>

			<div class="receipt-capture__field">
				<label class="receipt-capture__label" for="rc-description">{{
					t('shillinq', 'Description')
				}}</label>
				<input
					id="rc-description"
					v-model="form.description"
					type="text"
					class="receipt-capture__input"
					data-testid="rc-description" />
			</div>

			<p
				v-if="sourceDocumentUri"
				class="receipt-capture__source"
				data-testid="rc-source-document">
				{{ t('shillinq', 'Source document') }}: {{ sourceDocumentUri }}
			</p>

			<div class="receipt-capture__actions">
				<button
					v-if="isDraftReview && canRerequest"
					type="button"
					class="receipt-capture__secondary"
					:disabled="busy"
					data-testid="rc-rerequest"
					@click="onRerequest">
					{{ t('shillinq', 'Request extraction') }}
				</button>
				<button
					type="button"
					class="receipt-capture__primary"
					:disabled="!canSave || busy"
					data-testid="rc-save"
					@click="onSave">
					{{ busy ? t('shillinq', 'Saving…') : t('shillinq', 'Save') }}
				</button>
			</div>

			<p
				v-if="saveError"
				class="receipt-capture__error"
				data-testid="rc-save-error">
				{{ saveError }}
			</p>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import GlAccountPicker from '../components/BudgetBBVMapping/GlAccountPicker.vue'
import FieldConfidenceBadge from '../components/FieldConfidenceBadge.vue'
import {
	confidenceForField,
	glAccountSuggestionSummary,
	hasKnownExtractionId,
	isExtractionDraft,
	isFieldCorrected,
	requiresExplicitReview,
} from '../utils/extractionConfidence.js'
import {
	buildReceiptConfirmPayload,
	canSaveReceipt,
	receiptErrorMessage,
	reviewFormFromReceipt,
} from './receiptCapture.js'

const REGISTER_SLUG = 'shillinq'
const RECEIPT_SCHEMA = 'Receipt'

export default {
	name: 'ReceiptCapture',
	components: { FieldConfidenceBadge, GlAccountPicker },
	props: {
		/** Receipt OR object id, supplied by the manifest router (props: true on `/receipts/:id`). */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			record: null,
			form: reviewFormFromReceipt({}),
			loading: true,
			loadError: '',
			busy: false,
			saveError: '',
			glSuggestion: null,
		}
	},

	computed: {
		hasIndexRoute() {
			return !!this.$router?.options?.routes?.some?.(
				(r) => r.name === 'Receipts',
			)
		},

		/** @spec openspec/specs/receipt-extraction-consume/spec.md */
		isDraftReview() {
			return isExtractionDraft(this.record)
		},

		/** @spec openspec/specs/receipt-extraction-consume/spec.md */
		requiresReview() {
			return requiresExplicitReview(this.record)
		},

		/** @spec openspec/specs/receipt-extraction-consume/spec.md */
		canRerequest() {
			return !!this.record?.sourceDocumentUri
		},

		sourceDocumentUri() {
			return this.record?.sourceDocumentUri || ''
		},

		canSave() {
			return canSaveReceipt(this.form)
		},
	},

	watch: {
		id: {
			immediate: true,
			handler() {
				this.reload()
			},
		},
	},

	methods: {
		t,
		/**
		 * @param field
		 * @spec openspec/specs/receipt-extraction-consume/spec.md
		 */
		confidenceFor(field) {
			return confidenceForField(this.record, field)
		},

		/**
		 * @param field
		 * @spec openspec/specs/receipt-extraction-consume/spec.md
		 */
		isCorrected(field) {
			return isFieldCorrected(this.record, field)
		},

		/** @spec openspec/specs/gl-account-suggestion-consume/spec.md */
		async reload() {
			this.loading = true
			this.loadError = ''
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/${RECEIPT_SCHEMA}/${this.id}`,
					),
				)
				const body = response.data?.object ?? response.data ?? null
				if (!body || typeof body !== 'object') {
					throw new Error('Empty response')
				}
				this.record = body
				this.form = reviewFormFromReceipt(body)
				this.fetchGlSuggestion()
			} catch (e) {
				this.loadError = receiptErrorMessage(e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Request a GL-account suggestion for this receipt draft
		 * (gl-account-suggestion-consume, REQ-GAC-003) via the shillinq proxy.
		 * Degrades gracefully when the draft has no known docudesk extraction
		 * id or docudesk returns no suggestion (REQ-GAC-006).
		 *
		 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
		 */
		async fetchGlSuggestion() {
			this.glSuggestion = null
			if (!hasKnownExtractionId(this.record)) {
				return
			}

			try {
				const response = await axios.post(
					generateUrl(
						`/apps/shillinq/api/v1/extraction/drafts/${this.id}/suggest-account?schema=${RECEIPT_SCHEMA}`,
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
		 * REQ-RXC-004: commit an extraction-draft correction through the
		 * confirm proxy (records humanCorrected server-side); otherwise a
		 * plain OR object update.
		 *
		 * @spec openspec/specs/receipt-extraction-consume/spec.md
		 */
		async onSave() {
			if (!this.canSave || this.busy) return
			this.busy = true
			this.saveError = ''
			try {
				const payload = buildReceiptConfirmPayload(this.form)
				if (this.isDraftReview) {
					const response = await axios.put(
						generateUrl(
							`/apps/shillinq/api/v1/extraction/drafts/${this.id}?schema=${RECEIPT_SCHEMA}`,
						),
						payload,
					)
					this.record =
						response.data?.record ?? response.data ?? this.record
				} else {
					const response = await axios.put(
						generateUrl(
							`/apps/openregister/api/objects/${REGISTER_SLUG}/${RECEIPT_SCHEMA}/${this.id}`,
						),
						{ ...this.record, ...payload },
					)
					this.record =
						response.data?.object ?? response.data ?? this.record
				}
				this.form = reviewFormFromReceipt(this.record)
				showSuccess(t('shillinq', 'Receipt saved.'))
			} catch (e) {
				this.saveError = receiptErrorMessage(e)
				showError(this.saveError)
			} finally {
				this.busy = false
			}
		},

		/**
		 * REQ-RXC-005: (re-)request docudesk extraction for this receipt.
		 *
		 * @spec openspec/specs/receipt-extraction-consume/spec.md
		 */
		async onRerequest() {
			if (this.busy || !this.canRerequest) return
			this.busy = true
			this.saveError = ''
			try {
				await axios.post(
					generateUrl('/apps/shillinq/api/v1/extraction/request'),
					{
						documentUri: this.record.sourceDocumentUri,
						docType: 'receipt',
						id: this.id,
					},
				)
				showSuccess(
					t(
						'shillinq',
						'Extraction requested. The draft will update once docudesk responds.',
					),
				)
			} catch (e) {
				this.saveError = receiptErrorMessage(e)
				showError(this.saveError)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.receipt-capture {
	max-width: 720px;
	margin: 0 auto;
	padding: 16px;
}

.receipt-capture__header {
	margin-bottom: 16px;
}

.receipt-capture__back {
	display: inline-block;
	margin-bottom: 8px;
	color: var(--color-primary-element, #0082c9);
	text-decoration: none;
}

.receipt-capture__title {
	margin: 0;
	font-size: 1.4rem;
	color: var(--color-main-text);
}

.receipt-capture__loading,
.receipt-capture__error {
	padding: 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	color: var(--color-main-text);
}

/* Merged with the second `.receipt-capture__error` block that used to sit
   further down this stylesheet: two rules for the same selector meant the
   later `margin: 0` silently won and the first block was dead weight
   (stylelint no-duplicate-selectors). */
.receipt-capture__error {
	color: var(--color-error);
	margin: 0;
}

.receipt-capture__retry {
	margin-top: 8px;
	padding: 6px 14px;
	background: var(--color-background-dark, #f0f0f0);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	cursor: pointer;
}

.receipt-capture__hint {
	padding: 8px 10px;
	border-radius: var(--border-radius);
	background: var(--color-warning, #fae9b8);
	color: var(--color-main-text);
}

.receipt-capture__body {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.receipt-capture__row {
	display: flex;
	gap: 12px;
}

.receipt-capture__field {
	display: flex;
	flex-direction: column;
	flex: 1;
	gap: 4px;
}

.receipt-capture__field-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.receipt-capture__field-row .receipt-capture__input {
	flex: 1;
}

.receipt-capture__label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.receipt-capture__input,
.receipt-capture__textarea {
	box-sizing: border-box;
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-family: inherit;
}

.receipt-capture__source {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	word-break: break-all;
}

.receipt-capture__actions {
	display: flex;
	gap: 8px;
}

.receipt-capture__primary,
.receipt-capture__secondary {
	padding: 8px 16px;
	border-radius: var(--border-radius);
	cursor: pointer;
	border: 1px solid var(--color-border);
}

.receipt-capture__primary {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
}

.receipt-capture__secondary {
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.receipt-capture__suggestion {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.receipt-capture__suggestion-text {
	flex: 1;
	color: var(--color-main-text);
}

.receipt-capture__suggestion-rationale {
	margin: 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
</style>
