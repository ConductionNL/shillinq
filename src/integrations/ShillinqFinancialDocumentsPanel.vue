<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ShillinqFinancialDocumentsPanel: the render surface of the
 `shillinq-financial-documents-panel` leaf (REQ-FDO-003, REQ-FDO-004).

 It lists the quotes, invoices, credit notes, payment requests and payouts
 linked to the host object and offers the acts the caller holds: issue an
 invoice on the object, request payment of an outstanding invoice, send a
 payment link, and record money that arrived another way. A placement narrows
 it with `kinds` and `actions`. The server refuses every act on its own
 grant; the panel shows the refusal as the server words it.
-->
<template>
	<div
		v-if="!summarised"
		class="shillinq-financial-documents-panel"
		data-testid="shillinq-financial-documents-panel">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="error" class="shillinq-leaf__error" role="alert">
			{{ error }}
		</div>

		<template v-else>
			<div v-if="offers('issue-invoice') && !carded" class="shillinq-leaf__actions">
				<NcButton
					variant="secondary"
					data-testid="shillinq-issue-invoice"
					@click="openIssue">
					{{ issueLabel }}
				</NcButton>
			</div>

			<form
				v-if="issueOpen"
				class="shillinq-leaf__form"
				data-testid="shillinq-issue-invoice-form"
				@submit.prevent="issue">
				<label for="shillinq-issue-amount">{{ amountLabel }}</label>
				<input
					id="shillinq-issue-amount"
					v-model="issueForm.amount"
					type="number"
					min="0.01"
					step="0.01"
					required>
				<label for="shillinq-issue-description">{{ descriptionLabel }}</label>
				<input id="shillinq-issue-description" v-model="issueForm.description" type="text">
				<label for="shillinq-issue-name">{{ debtorNameLabel }}</label>
				<input id="shillinq-issue-name" v-model="issueForm.name" type="text" required>
				<label for="shillinq-issue-email">{{ debtorEmailLabel }}</label>
				<input id="shillinq-issue-email" v-model="issueForm.email" type="email" required>
				<div class="shillinq-leaf__actions">
					<NcButton :disabled="busyId === 'issue'" variant="primary" type="submit">
						{{ confirmIssueLabel }}
					</NcButton>
					<NcButton variant="tertiary" @click="issueOpen = false">
						{{ cancelLabel }}
					</NcButton>
				</div>
			</form>

			<p v-if="notices.issue" class="shillinq-leaf__notice" role="status">
				{{ notices.issue }}
			</p>

			<p
				v-if="visibleRows.length === 0 && !carded"
				class="shillinq-leaf__empty"
				data-testid="shillinq-financial-documents-empty">
				{{ emptyLabel }}
			</p>

			<ul v-else-if="visibleRows.length > 0" class="shillinq-leaf__list">
				<li
					v-for="row in visibleRows"
					:key="row.id"
					class="shillinq-leaf__row"
					:data-testid="`shillinq-financial-document-${row.id}`">
					<div class="shillinq-leaf__row-head">
						<span>{{ titleOf(row) }}</span>
						<span class="shillinq-leaf__state">{{ stateOf(row) }}</span>
					</div>

					<p v-if="row.restricted" class="shillinq-leaf__description">
						{{ restrictedLabel }}
					</p>
					<p v-else-if="dueLine(row)" class="shillinq-leaf__due">
						{{ dueLine(row) }}
					</p>

					<div v-if="row.path || rowActs(row).length > 0" class="shillinq-leaf__actions">
						<NcButton
							v-if="row.path"
							variant="tertiary"
							:href="pageOf(row)">
							{{ openLabel }}
						</NcButton>
						<NcButton
							v-if="rowActs(row).includes('request-payment')"
							:disabled="busyId === row.id"
							variant="secondary"
							:data-testid="`shillinq-request-payment-${row.id}`"
							@click="requestPayment(row)">
							{{ requestPaymentLabel }}
						</NcButton>
						<NcButton
							v-if="rowActs(row).includes('send-link')"
							:disabled="busyId === row.id"
							variant="secondary"
							:data-testid="`shillinq-send-link-${row.id}`"
							@click="send(row)">
							{{ sendLabel }}
						</NcButton>
						<NcButton
							v-if="rowActs(row).includes('record-payment')"
							:disabled="busyId === row.id"
							variant="tertiary"
							:data-testid="`shillinq-settle-${row.id}`"
							@click="openSettle(row)">
							{{ settleLabel }}
						</NcButton>
					</div>

					<form
						v-if="settleFor === row.id"
						class="shillinq-leaf__form"
						@submit.prevent="settle(row)">
						<label :for="`shillinq-settle-method-${row.id}`">{{ methodLabel }}</label>
						<select :id="`shillinq-settle-method-${row.id}`" v-model="settleForm.method">
							<option
								v-for="option in settlementMethods"
								:key="option.value"
								:value="option.value">
								{{ option.label }}
							</option>
						</select>
						<label :for="`shillinq-settle-reference-${row.id}`">{{ referenceLabel }}</label>
						<input
							:id="`shillinq-settle-reference-${row.id}`"
							v-model="settleForm.settlementReference"
							type="text">
						<label :for="`shillinq-settle-reason-${row.id}`">{{ reasonLabel }}</label>
						<input :id="`shillinq-settle-reason-${row.id}`" v-model="settleForm.reason" type="text">
						<div class="shillinq-leaf__actions">
							<NcButton :disabled="busyId === row.id" variant="primary" type="submit">
								{{ confirmSettleLabel }}
							</NcButton>
							<NcButton variant="tertiary" @click="settleFor = ''">
								{{ cancelLabel }}
							</NcButton>
						</div>
					</form>

					<p v-if="notices[row.id]" class="shillinq-leaf__notice" role="status">
						{{ notices[row.id] }}
					</p>
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import {
	FINANCIAL_DOCUMENTS_DATA_LEAF,
	formatAmount,
	hostIdentity,
	isResolvable,
	issueInvoice,
	readLeaf,
	requestInvoicePayment,
	sendPaymentLink,
	settleByOtherMeans,
} from './leafApi.js'
import { leafReportMixin } from './leafReport.js'

/**
 * Every kind the leaf lists, in the order a placement names them.
 *
 * @type {string[]}
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
 */
export const ALL_KINDS = ['quote', 'invoice', 'credit-note', 'payment-request', 'payout']

/**
 * Every act the panel can offer.
 *
 * @type {string[]}
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
 */
export const ALL_ACTIONS = ['issue-invoice', 'request-payment', 'send-link', 'record-payment']

/**
 * Which acts a row offers, given the caller's grants and the placement's actions.
 *
 * @param {object} row One row of the leaf.
 * @param {object} acts The grants the leaf reported: issueInvoice, requestPayment, administerPayments.
 * @param {string[]} allowed The acts the placement allows.
 *
 * @return {string[]} The acts.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
 */
export function actsForRow(row, acts, allowed) {
	if (!row || row.restricted === true || row.paymentState !== 'outstanding') {
		return []
	}
	const offered = []
	if (row.kind === 'invoice' && acts.requestPayment === true) {
		offered.push('request-payment')
	}
	if (row.kind === 'payment-request' && acts.administerPayments === true) {
		offered.push('send-link', 'record-payment')
	}
	return offered.filter((act) => allowed.includes(act))
}

/**
 * The panel mounted by the `shillinq-financial-documents-panel` leaf.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
 */
export default {
	name: 'ShillinqFinancialDocumentsPanel',

	components: {
		NcButton,
		NcLoadingIcon,
	},

	mixins: [leafReportMixin],

	props: {
		/** OpenRegister register id of the host object. */
		register: { type: String, default: '' },
		/** OpenRegister schema id of the host object. */
		schema: { type: String, default: '' },
		/** Id of the host object the documents are linked to. */
		objectId: { type: [String, Number], default: '' },
		/** Whole registry context, the fallback when the discrete props are absent. */
		integrationContext: { type: Object, default: () => ({}) },
		/** The kinds this placement shows. */
		kinds: { type: Array, default: () => ALL_KINDS },
		/** The acts this placement offers. */
		actions: { type: Array, default: () => ALL_ACTIONS },
		/** The semantic type of the host object, written on an issued invoice. */
		objectType: { type: String, default: '' },
	},

	data() {
		return {
			rows: [],
			acts: { issueInvoice: false, requestPayment: false, administerPayments: false },
			loading: false,
			error: '',
			busyId: '',
			notices: {},
			issueOpen: false,
			issueForm: { amount: '', description: '', name: '', email: '' },
			settleFor: '',
			settleForm: { method: 'pin', settlementReference: '', reason: '' },
		}
	},

	computed: {
		/**
		 * @return {object[]} The rows of the kinds this placement shows.
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		visibleRows() {
			return this.rows.filter((row) => this.kinds.includes(row.kind))
		},

		/**
		 * @return {object} What this leaf reports to a carding host or a "linked" card.
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		leafReport() {
			const actions = []
			if (this.offers('issue-invoice')) {
				actions.push({ label: this.issueLabel, run: () => this.openIssue() })
			}
			return {
				count: this.visibleRows.length,
				emptyLabel: this.emptyLabel,
				noun: t('shillinq', 'financial documents'),
				rows: this.visibleRows.map((row) => ({
					label: [this.titleOf(row), this.stateOf(row)].filter((part) => part !== '').join(' · '),
					icon: 'FileDocumentOutline',
				})),

				actions,
			}
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		emptyLabel() {
			return t('shillinq', 'No quotes, invoices or payments are linked to this record.')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		issueLabel() {
			return t('shillinq', 'Issue invoice')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		confirmIssueLabel() {
			return t('shillinq', 'Issue the invoice')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		amountLabel() {
			return t('shillinq', 'Amount')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		descriptionLabel() {
			return t('shillinq', 'What it is for')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		debtorNameLabel() {
			return t('shillinq', 'Who pays')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		debtorEmailLabel() {
			return t('shillinq', 'Their email address')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		requestPaymentLabel() {
			return t('shillinq', 'Request payment')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		sendLabel() {
			return t('shillinq', 'Send payment link')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		settleLabel() {
			return t('shillinq', 'Mark paid by other means')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		confirmSettleLabel() {
			return t('shillinq', 'Record the payment')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		cancelLabel() {
			return t('shillinq', 'Cancel')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		openLabel() {
			return t('shillinq', 'Open in Shillinq')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		restrictedLabel() {
			return t('shillinq', 'You cannot open this document; the finance administration holds it.')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		methodLabel() {
			return t('shillinq', 'How the money arrived')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		referenceLabel() {
			return t('shillinq', 'Reference')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		reasonLabel() {
			return t('shillinq', 'Reason, which a waiver needs')
		},

		/** @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003) */
		settlementMethods() {
			return [
				{ value: 'pin', label: t('shillinq', 'Pin at the counter') },
				{ value: 'cash', label: t('shillinq', 'Cash at the counter') },
				{ value: 'bank-transfer', label: t('shillinq', 'Bank transfer') },
				{ value: 'waived', label: t('shillinq', 'Waived') },
				{ value: 'other', label: t('shillinq', 'Another way') },
			]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the documents linked to the host object.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		async load() {
			const identity = hostIdentity(this.$props)
			if (isResolvable(identity) === false) {
				this.error = t('shillinq', 'This panel needs a record to stand on.')
				return
			}

			this.loading = true
			this.error = ''
			try {
				const envelope = await readLeaf(identity, FINANCIAL_DOCUMENTS_DATA_LEAF)
				this.rows = Array.isArray(envelope.items) ? envelope.items : []
				this.acts = { ...this.acts, ...(envelope.acts || {}) }
			} catch {
				this.error = t('shillinq', 'The financial documents could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Whether the panel offers an act that is not tied to a row.
		 *
		 * @param {string} act The act.
		 * @return {boolean} True when the caller holds it and the placement allows it.
		 *
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		offers(act) {
			return act === 'issue-invoice' && this.acts.issueInvoice === true && this.actions.includes(act)
		},

		/**
		 * The acts one row offers.
		 *
		 * @param {object} row One row.
		 * @return {string[]} The acts.
		 *
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		rowActs(row) {
			return actsForRow(row, this.acts, this.actions)
		},

		/**
		 * The row's title: its kind, its number and its amount.
		 *
		 * @param {object} row One row.
		 * @return {string} The title.
		 *
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		titleOf(row) {
			const kinds = {
				quote: t('shillinq', 'Quote'),
				invoice: t('shillinq', 'Invoice'),
				'credit-note': t('shillinq', 'Credit note'),
				'payment-request': t('shillinq', 'Payment request'),
				payout: t('shillinq', 'Payout'),
			}
			return [kinds[row.kind] || row.kind, row.number || '', row.amount === null ? '' : formatAmount(row.amount, row.currency)]
				.filter((part) => part !== '')
				.join(' ')
		},

		/**
		 * The payment state in words.
		 *
		 * @param {object} row One row.
		 * @return {string} The state, or an empty string when nothing is owed.
		 *
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		stateOf(row) {
			const states = {
				outstanding: t('shillinq', 'Outstanding'),
				paid: t('shillinq', 'Paid'),
				waived: t('shillinq', 'Waived'),
			}
			return states[row.paymentState] || ''
		},

		/**
		 * The due line, when the row carries a date.
		 *
		 * @param {object} row One row.
		 * @return {string} The line, or an empty string.
		 *
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		dueLine(row) {
			if (!row.dueOn) {
				return ''
			}
			return t('shillinq', 'Due {date}', { date: row.dueOn })
		},

		/**
		 * The document's page in shillinq.
		 *
		 * @param {object} row One row with a path.
		 * @return {string} The URL.
		 *
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		pageOf(row) {
			return generateUrl(row.path)
		},

		/**
		 * @return {void}
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		openIssue() {
			this.issueForm = { amount: '', description: '', name: '', email: '' }
			this.issueOpen = true
		},

		/**
		 * Issue an invoice on the host object.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		async issue() {
			this.busyId = 'issue'
			this.notices = { ...this.notices, issue: '' }
			try {
				await issueInvoice(hostIdentity(this.$props), {
					amount: Number(this.issueForm.amount),
					description: this.issueForm.description,
					debtor: { name: this.issueForm.name, email: this.issueForm.email },
					objectType: this.objectType,
				})
				this.issueOpen = false
				this.notices = { ...this.notices, issue: t('shillinq', 'The invoice is issued.') }
				await this.load()
			} catch (e) {
				this.notices = { ...this.notices, issue: this.refusal(e) }
			} finally {
				this.busyId = ''
			}
		},

		/**
		 * Ask for payment of an outstanding invoice.
		 *
		 * @param {object} row The invoice row.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		async requestPayment(row) {
			await this.act(row, () => requestInvoicePayment(row.id), t('shillinq', 'The payment request is raised.'))
		},

		/**
		 * Mail the payment link to the debtor.
		 *
		 * @param {object} row The payment request row.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		async send(row) {
			await this.act(row, () => sendPaymentLink(row.id), t('shillinq', 'The payment link went out to the debtor.'))
		},

		/**
		 * Open the settlement form for one row.
		 *
		 * @param {object} row The payment request row.
		 * @return {void}
		 *
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		openSettle(row) {
			this.settleFor = row.id
			this.settleForm = { method: 'pin', settlementReference: '', reason: '' }
		},

		/**
		 * Record that the money arrived another way.
		 *
		 * @param {object} row The payment request row.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		async settle(row) {
			const settlement = { ...this.settleForm, amount: 0 }
			await this.act(row, () => settleByOtherMeans(row.id, settlement), t('shillinq', 'The payment is on the record.'))
			this.settleFor = ''
		},

		/**
		 * Run one act on a row, show the outcome, and read the list again.
		 *
		 * @param {object} row The row.
		 * @param {() => Promise<object>} call The request.
		 * @param {string} done What to say when it went through.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		async act(row, call, done) {
			this.busyId = row.id
			this.notices = { ...this.notices, [row.id]: '' }
			try {
				await call()
				this.notices = { ...this.notices, [row.id]: done }
				await this.load()
			} catch (e) {
				this.notices = { ...this.notices, [row.id]: this.refusal(e) }
			} finally {
				this.busyId = ''
			}
		},

		/**
		 * The server's own words for a refusal.
		 *
		 * @param {object} e The axios error.
		 * @return {string} The message.
		 *
		 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
		 */
		refusal(e) {
			const message = e && e.response && e.response.data && e.response.data.error
			if (typeof message === 'string' && message !== '') {
				return message
			}
			return t('shillinq', 'That did not go through.')
		},
	},
}
</script>

<style scoped>
.shillinq-financial-documents-panel {
	padding: 8px;
}

.shillinq-leaf__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.shillinq-leaf__row {
	border-bottom: 1px solid var(--color-border);
	padding: 8px 0;
}

.shillinq-leaf__row-head {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	font-weight: bold;
}

.shillinq-leaf__state,
.shillinq-leaf__description,
.shillinq-leaf__due,
.shillinq-leaf__empty {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin: 4px 0;
}

.shillinq-leaf__actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
	flex-wrap: wrap;
}

.shillinq-leaf__form {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 8px;
}

.shillinq-leaf__form input,
.shillinq-leaf__form select {
	width: 100%;
}

.shillinq-leaf__error {
	color: var(--color-error);
}

.shillinq-leaf__notice {
	margin-top: 8px;
}
</style>
