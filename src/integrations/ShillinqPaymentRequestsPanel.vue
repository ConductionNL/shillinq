<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ShillinqPaymentRequestsPanel — the render surface of the
 `shillinq-payment-requests-panel` leaf (REQ-SOPR-004).

 It shows the payment requests standing on the host object and offers the two
 actions the requirement names: send the payment link to the debtor, and
 record that the money arrived another way. It reads through OpenRegister's
 generic per-object integration endpoint, so the host object identity arrives
 from the registry and no case app is ever named here.

 Both actions run against shillinq's own controller, which refuses a caller
 without `payment.administer` before it writes anything. The panel shows that
 refusal as the server words it.
-->
<template>
	<div
		class="shillinq-payment-requests-panel"
		data-testid="shillinq-payment-requests-panel">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="error" class="shillinq-leaf__error" role="alert">
			{{ error }}
		</div>

		<template v-else>
			<p v-if="feeLine" class="shillinq-leaf__fee">
				{{ feeLine }}
			</p>

			<p
				v-if="requests.length === 0"
				class="shillinq-leaf__empty"
				data-testid="shillinq-payment-requests-empty">
				{{ emptyLabel }}
			</p>

			<ul v-else class="shillinq-leaf__list">
				<li
					v-for="request in requests"
					:key="request.id"
					class="shillinq-leaf__row"
					:data-testid="`shillinq-payment-request-${request.id}`">
					<div class="shillinq-leaf__row-head">
						<span class="shillinq-leaf__amount">{{
							amountOf(request)
						}}</span>
						<span class="shillinq-leaf__state">{{
							stateOf(request)
						}}</span>
					</div>

					<p v-if="request.description" class="shillinq-leaf__description">
						{{ request.description }}
					</p>

					<p v-if="dueLine(request)" class="shillinq-leaf__due">
						{{ dueLine(request) }}
					</p>

					<div class="shillinq-leaf__actions">
						<NcButton
							:disabled="busyId === request.id"
							variant="secondary"
							:data-testid="`shillinq-send-link-${request.id}`"
							@click="send(request)">
							{{ sendLabel }}
						</NcButton>
						<NcButton
							:disabled="busyId === request.id"
							variant="tertiary"
							:data-testid="`shillinq-settle-${request.id}`"
							@click="openSettle(request)">
							{{ settleLabel }}
						</NcButton>
					</div>

					<form
						v-if="settleFor === request.id"
						class="shillinq-leaf__settle"
						@submit.prevent="settle(request)">
						<label :for="`shillinq-settle-method-${request.id}`">
							{{ methodLabel }}
						</label>
						<select
							:id="`shillinq-settle-method-${request.id}`"
							v-model="settleForm.method">
							<option
								v-for="option in methods"
								:key="option.value"
								:value="option.value">
								{{ option.label }}
							</option>
						</select>

						<label :for="`shillinq-settle-reference-${request.id}`">
							{{ referenceLabel }}
						</label>
						<input
							:id="`shillinq-settle-reference-${request.id}`"
							v-model="settleForm.settlementReference"
							type="text"
							:placeholder="referencePlaceholder" />

						<label :for="`shillinq-settle-reason-${request.id}`">
							{{ reasonLabel }}
						</label>
						<input
							:id="`shillinq-settle-reason-${request.id}`"
							v-model="settleForm.reason"
							type="text" />

						<div class="shillinq-leaf__actions">
							<NcButton
								:disabled="busyId === request.id"
								variant="primary"
								type="submit">
								{{ confirmSettleLabel }}
							</NcButton>
							<NcButton variant="tertiary" @click="closeSettle">
								{{ cancelLabel }}
							</NcButton>
						</div>
					</form>

					<p
						v-if="notices[request.id]"
						class="shillinq-leaf__notice"
						role="status">
						{{ notices[request.id] }}
					</p>
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import {
	formatAmount,
	hostIdentity,
	isResolvable,
	PAYMENT_REQUESTS_DATA_LEAF,
	readLeaf,
	sendPaymentLink,
	settleByOtherMeans,
} from './leafApi.js'

/**
 * The payment-request panel mounted by the `shillinq-payment-requests-panel`
 * leaf. See the file-level comment for what it shows and what it refuses.
 */
export default {
	name: 'ShillinqPaymentRequestsPanel',

	components: {
		NcButton,
		NcLoadingIcon,
	},

	props: {
		/** OpenRegister register id of the host object. */
		register: { type: String, default: '' },
		/** OpenRegister schema id of the host object. */
		schema: { type: String, default: '' },
		/** Id of the host object the requests stand on. */
		objectId: { type: [String, Number], default: '' },
		/** Whole registry context, the fallback when the discrete props are absent. */
		integrationContext: { type: Object, default: () => ({}) },
	},

	data() {
		return {
			requests: [],
			fee: null,
			loading: false,
			error: '',
			busyId: '',
			settleFor: '',
			notices: {},
			settleForm: {
				method: 'pin',
				settlementReference: '',
				reason: '',
			},
		}
	},

	computed: {
		/** @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004) */
		emptyLabel() {
			return t(
				'shillinq',
				'Nobody has been asked to pay anything on this record yet.',
			)
		},

		/** @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004) */
		sendLabel() {
			return t('shillinq', 'Send payment link')
		},

		/** @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004) */
		settleLabel() {
			return t('shillinq', 'Mark paid by other means')
		},

		/** @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004) */
		confirmSettleLabel() {
			return t('shillinq', 'Record the payment')
		},

		/** @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004) */
		cancelLabel() {
			return t('shillinq', 'Cancel')
		},

		/** @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004) */
		methodLabel() {
			return t('shillinq', 'How the money arrived')
		},

		/** @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004) */
		referenceLabel() {
			return t('shillinq', 'Reference')
		},

		/** @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004) */
		referencePlaceholder() {
			return t('shillinq', 'What the bank statement will show')
		},

		/** @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004) */
		reasonLabel() {
			return t('shillinq', 'Reason, which a waiver needs')
		},

		/** @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004) */
		methods() {
			return [
				{ value: 'pin', label: t('shillinq', 'Pin at the counter') },
				{ value: 'cash', label: t('shillinq', 'Cash at the counter') },
				{ value: 'bank-transfer', label: t('shillinq', 'Bank transfer') },
				{ value: 'waived', label: t('shillinq', 'Waived') },
				{ value: 'other', label: t('shillinq', 'Another way') },
			]
		},

		/**
		 * The published fee for this record's type, so a clerk reads the tariff
		 * here instead of in a verordening (REQ-SOPR-008).
		 *
		 * @return {string} The line, or an empty string when no fee is published.
		 */
		feeLine() {
			if (this.fee === null || typeof this.fee !== 'object') {
				return ''
			}
			const amount = formatAmount(this.fee.amount, this.fee.currency)
			if (amount === '') {
				return ''
			}
			return t('shillinq', 'Published fee for this record: {amount}', {
				amount,
			})
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the requests standing on the host object.
		 *
		 * @return {Promise<void>}
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
				const envelope = await readLeaf(identity, PAYMENT_REQUESTS_DATA_LEAF)
				this.requests = Array.isArray(envelope.items) ? envelope.items : []
				this.fee = envelope.fee || null
			} catch {
				this.error = t('shillinq', 'The payment requests could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The amount, formatted.
		 *
		 * @param {object} request One request.
		 * @return {string} The amount.
		 */
		amountOf(request) {
			return formatAmount(request.amount, request.currency)
		},

		/**
		 * What the request reports once counter payments are counted in.
		 *
		 * @param {object} request One request.
		 * @return {string} The reported state.
		 */
		stateOf(request) {
			const reported = request.reported
			if (reported && typeof reported === 'object' && reported.state) {
				return String(reported.state)
			}
			return String(request.state || '')
		},

		/**
		 * The due line, when the request carries a date.
		 *
		 * @param {object} request One request.
		 * @return {string} The line, or an empty string.
		 */
		dueLine(request) {
			if (!request.dueAt) {
				return ''
			}
			return t('shillinq', 'Due {date}', {
				date: String(request.dueAt).slice(0, 10),
			})
		},

		/**
		 * Mail the payment link to the debtor.
		 *
		 * @param {object} request The request to send.
		 * @return {Promise<void>}
		 */
		async send(request) {
			this.busyId = request.id
			this.notices = { ...this.notices, [request.id]: '' }
			try {
				await sendPaymentLink(request.id)
				this.notices = {
					...this.notices,
					[request.id]: t(
						'shillinq',
						'The payment link went out to the debtor.',
					),
				}
			} catch (e) {
				this.notices = { ...this.notices, [request.id]: this.refusal(e) }
			} finally {
				this.busyId = ''
			}
		},

		/**
		 * Open the settlement form for one request.
		 *
		 * @param {object} request The request.
		 * @return {void}
		 */
		openSettle(request) {
			this.settleFor = request.id
			this.settleForm = { method: 'pin', settlementReference: '', reason: '' }
		},

		/**
		 * Close the settlement form.
		 *
		 * @return {void}
		 */
		closeSettle() {
			this.settleFor = ''
		},

		/**
		 * Record that the money arrived another way.
		 *
		 * @param {object} request The request to settle.
		 * @return {Promise<void>}
		 */
		async settle(request) {
			this.busyId = request.id
			this.notices = { ...this.notices, [request.id]: '' }
			try {
				await settleByOtherMeans(request.id, {
					method: this.settleForm.method,
					settlementReference: this.settleForm.settlementReference,
					amount: 0,
					reason: this.settleForm.reason,
				})
				this.settleFor = ''
				this.notices = {
					...this.notices,
					[request.id]: t('shillinq', 'The payment is on the record.'),
				}
				await this.load()
			} catch (e) {
				this.notices = { ...this.notices, [request.id]: this.refusal(e) }
			} finally {
				this.busyId = ''
			}
		},

		/**
		 * The server's own words for a refusal, so a handler reads why rather
		 * than a generic failure.
		 *
		 * @param {object} e The axios error.
		 * @return {string} The message.
		 */
		refusal(e) {
			const message =
				e && e.response && e.response.data && e.response.data.error
			if (typeof message === 'string' && message !== '') {
				return message
			}
			return t('shillinq', 'That did not go through.')
		},
	},
}
</script>

<style scoped>
.shillinq-payment-requests-panel {
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

.shillinq-leaf__state {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.shillinq-leaf__description,
.shillinq-leaf__due,
.shillinq-leaf__fee,
.shillinq-leaf__empty {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}

.shillinq-leaf__actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
	flex-wrap: wrap;
}

.shillinq-leaf__settle {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 8px;
}

.shillinq-leaf__settle input,
.shillinq-leaf__settle select {
	width: 100%;
}

.shillinq-leaf__error {
	color: var(--color-error);
}

.shillinq-leaf__notice {
	margin-top: 8px;
}
</style>
