<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Period Close detail view — guided month-end / quarter-end close workflow
 (REQ-PC-005, REQ-PC-006). Renders the FiscalPeriod metadata header, the
 close-task checklist (AP / AR / bank / expense claims) with inline
 close-assistant flags, the lifecycle action buttons (Start close, Close
 period, Reopen, Lock for audit), the reopen-history audit trail and a
 trial-balance preview link.

 The page operates on a single FiscalPeriod record fetched from the
 server-authoritative shillinq controller (GET /api/period-close/{id}).
 Lifecycle transitions are routed to:
   - POST /api/period-close/{id}/start-close   (open → closing)
   - POST /api/period-close/{id}/close         (closing → closed)
   - POST /api/period-close/{id}/reopen        (closed → open, modal)
   - POST /api/period-close/{id}/lock-audit    (closed → audit-locked)

 The reopen action opens an isolated ReopenPeriodDialog modal that
 captures the audit-trailed close reason (REQ-PC-006). The dialog lives
 in its own .vue file under src/modals/ per hydra gate-13 (modal
 isolation).

 Registered as a kind:"page" custom component in src/registry.js so the
 manifest router dispatches `customComponent: "PeriodCloseDetail"` for
 the bookkeeping-period-close detail page (REQ-PC-007). The bespoke
 lifecycle action ribbon, AI-flag pills and reopen-history timeline do
 not fit the built-in declarative `detail` page type — see the registry
 docblock for the kind-page justification per ADR-024 / ADR-036.

 @spec openspec/changes/bookkeeping-period-close/tasks.md#task-9
-->
<template>
	<div class="period-close-detail" data-testid="period-close-detail">
		<div
			v-if="loading"
			class="period-close-detail__loading"
			data-testid="period-close-detail-loading">
			{{ t('shillinq', 'Loading period…') }}
		</div>

		<div
			v-else-if="loadError"
			class="period-close-detail__error"
			data-testid="period-close-detail-error">
			{{ loadError }}
		</div>

		<div v-else-if="period" class="period-close-detail__body">
			<header
				class="period-close-detail__header"
				data-testid="period-close-detail-header">
				<h2>{{ period.name || period.periodId }}</h2>
				<p class="period-close-detail__meta">
					<span
						class="period-close-detail__pill"
						:class="`period-close-detail__pill--${stateSlug}`">
						{{ stateLabel }}
					</span>
					<span class="period-close-detail__chip">
						{{ t('shillinq', 'Period') }}:
						<strong>{{ period.periodId }}</strong>
					</span>
					<span
						v-if="period.administrationId"
						class="period-close-detail__chip">
						{{ t('shillinq', 'Administration') }}:
						<strong>{{ period.administrationId }}</strong>
					</span>
					<span v-if="period.fiscalYear" class="period-close-detail__chip">
						{{ t('shillinq', 'Fiscal year') }}:
						<strong>{{ period.fiscalYear }}</strong>
					</span>
				</p>
				<p class="period-close-detail__dates">
					<span
						>{{ formatDate(period.startDate) }} –
						{{ formatDate(period.endDate) }}</span
					>
				</p>
				<p v-if="period.closedAt" class="period-close-detail__close-info">
					{{ t('shillinq', 'Closed at') }}:
					{{ formatTimestamp(period.closedAt) }}
					<template v-if="period.closedBy">
						— {{ period.closedBy }}
					</template>
				</p>
				<p
					v-if="period.auditLockedAt"
					class="period-close-detail__lock-info">
					{{ t('shillinq', 'Audit locked at') }}:
					{{ formatTimestamp(period.auditLockedAt) }}
					<template v-if="period.auditLockedBy">
						— {{ period.auditLockedBy }}
					</template>
				</p>
			</header>

			<section
				class="period-close-detail__actions"
				data-testid="period-close-detail-actions">
				<NcButton
					v-if="canStartClose"
					variant="primary"
					:disabled="transitioning"
					data-testid="period-close-detail-start-close"
					@click="onStartClose">
					{{
						transitioning
							? t('shillinq', 'Working…')
							: t('shillinq', 'Start close')
					}}
				</NcButton>
				<NcButton
					v-if="canClose"
					variant="primary"
					:disabled="transitioning"
					data-testid="period-close-detail-close"
					@click="onClose">
					{{
						transitioning
							? t('shillinq', 'Working…')
							: t('shillinq', 'Close period')
					}}
				</NcButton>
				<NcButton
					v-if="canReopen"
					variant="secondary"
					:disabled="transitioning"
					data-testid="period-close-detail-reopen"
					@click="openReopenDialog">
					{{ t('shillinq', 'Reopen') }}
				</NcButton>
				<NcButton
					v-if="canLockAudit"
					variant="warning"
					:disabled="transitioning"
					data-testid="period-close-detail-lock-audit"
					@click="onLockAudit">
					{{
						transitioning
							? t('shillinq', 'Working…')
							: t('shillinq', 'Lock for audit')
					}}
				</NcButton>
				<p
					v-if="transitionError"
					class="period-close-detail__error"
					data-testid="period-close-detail-transition-error">
					{{ transitionError }}
				</p>
				<p
					v-if="transitionNotice"
					class="period-close-detail__notice"
					data-testid="period-close-detail-transition-notice">
					{{ transitionNotice }}
				</p>
			</section>

			<section
				class="period-close-detail__checklist"
				data-testid="period-close-detail-checklist">
				<h3>{{ t('shillinq', 'Close checklist') }}</h3>
				<table
					v-if="checklistItems.length > 0"
					class="period-close-detail__checklist-table">
					<thead>
						<tr>
							<th scope="col">{{ t('shillinq', 'Category') }}</th>
							<th scope="col">{{ t('shillinq', 'Description') }}</th>
							<th scope="col">{{ t('shillinq', 'Status') }}</th>
							<th scope="col">{{ t('shillinq', 'Resolved at') }}</th>
							<th scope="col">{{ t('shillinq', 'Resolved by') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="item in checklistItems"
							:key="item.id"
							:data-testid="`period-close-detail-checklist-${item.id}`">
							<td>{{ categoryLabel(item.category) }}</td>
							<td>{{ item.description }}</td>
							<td>
								<span
									:class="`period-close-detail__pill period-close-detail__pill--${item.resolved ? 'closed' : 'open'}`">
									{{
										item.resolved
											? t('shillinq', 'Resolved')
											: t('shillinq', 'Open')
									}}
								</span>
							</td>
							<td>
								{{
									item.resolvedAt
										? formatTimestamp(item.resolvedAt)
										: '—'
								}}
							</td>
							<td>{{ item.resolvedBy || '—' }}</td>
						</tr>
					</tbody>
				</table>
				<p
					v-else
					class="period-close-detail__empty"
					data-testid="period-close-detail-checklist-empty">
					{{ t('shillinq', 'No checklist items yet.') }}
				</p>
			</section>

			<section
				class="period-close-detail__flags"
				data-testid="period-close-detail-flags">
				<h3>{{ t('shillinq', 'AI close assistant') }}</h3>
				<ul v-if="aiFlags.length > 0" class="period-close-detail__flag-list">
					<li
						v-for="flag in aiFlags"
						:key="flag.id || flag.code"
						class="period-close-detail__flag"
						:class="`period-close-detail__flag--${flag.severity || 'warning'}`"
						:data-testid="`period-close-detail-flag-${flag.id || flag.code}`">
						<strong>{{ flag.title || flag.code }}</strong>
						<span v-if="flag.description">
							— {{ flag.description }}</span
						>
						<span
							v-if="flag.count !== undefined"
							class="period-close-detail__flag-count">
							({{ flag.count }})
						</span>
					</li>
				</ul>
				<p
					v-else
					class="period-close-detail__empty"
					data-testid="period-close-detail-flags-empty">
					{{ t('shillinq', 'No close assistant flags raised.') }}
				</p>
			</section>

			<section
				v-if="reopenedHistory.length > 0"
				class="period-close-detail__history"
				data-testid="period-close-detail-history">
				<h3>{{ t('shillinq', 'Reopen history') }}</h3>
				<ol>
					<li
						v-for="(entry, idx) in reopenedHistory"
						:key="`reopen-${idx}`"
						:data-testid="`period-close-detail-history-${idx}`">
						<strong>{{ formatTimestamp(entry.reopenedAt) }}</strong>
						<span v-if="entry.reopenedBy">
							— {{ entry.reopenedBy }}</span
						>
						<p
							v-if="entry.closeReason"
							class="period-close-detail__history-reason">
							{{ t('shillinq', 'Close reason') }}:
							{{ entry.closeReason }}
						</p>
						<p
							v-if="entry.originalClosedAt"
							class="period-close-detail__history-orig">
							{{ t('shillinq', 'Original close') }}:
							{{ formatTimestamp(entry.originalClosedAt) }}
							<template v-if="entry.originalClosedBy">
								— {{ entry.originalClosedBy }}
							</template>
						</p>
					</li>
				</ol>
			</section>

			<section
				class="period-close-detail__links"
				data-testid="period-close-detail-links">
				<h3>{{ t('shillinq', 'Related views') }}</h3>
				<ul>
					<li>
						<a
							class="period-close-detail__link"
							data-testid="period-close-detail-trial-balance-link"
							:href="trialBalanceLink">
							{{ t('shillinq', 'Trial balance preview') }}
						</a>
					</li>
				</ul>
			</section>
		</div>

		<ReopenPeriodDialog
			:open="reopenDialogOpen"
			:periodName="reopenDialogPeriodName"
			:submitting="reopenSubmitting"
			:error="reopenError"
			@cancel="closeReopenDialog"
			@confirm="onReopenConfirmed" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import ReopenPeriodDialog from '../../modals/ReopenPeriodDialog.vue'

const STATE_OPEN = 'open'
const STATE_CLOSING = 'closing'
const STATE_CLOSED = 'closed'
const STATE_AUDIT_LOCKED = 'audit-locked'

export default {
	name: 'PeriodCloseDetail',
	components: {
		NcButton,
		ReopenPeriodDialog,
	},

	props: {
		// The manifest router passes the route param as `id` for type=detail
		// pages. Also accept `periodId` for direct usage.
		id: {
			type: String,
			default: '',
		},

		periodId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			period: null,
			loading: true,
			loadError: '',
			transitioning: false,
			transitionError: '',
			transitionNotice: '',
			reopenDialogOpen: false,
			reopenSubmitting: false,
			reopenError: '',
		}
	},

	computed: {
		recordId() {
			return this.id || this.periodId || this.$route?.params?.id || ''
		},

		stateSlug() {
			const state = (this.period && this.period.state) || STATE_OPEN
			return state.replace(/[^a-z0-9]+/gi, '-').toLowerCase()
		},

		stateLabel() {
			const state = (this.period && this.period.state) || STATE_OPEN
			switch (state) {
				case STATE_OPEN:
					return t('shillinq', 'Open')
				case STATE_CLOSING:
					return t('shillinq', 'Closing')
				case STATE_CLOSED:
					return t('shillinq', 'Closed')
				case STATE_AUDIT_LOCKED:
					return t('shillinq', 'Audit locked')
				default:
					return state
			}
		},

		canStartClose() {
			return this.period && this.period.state === STATE_OPEN
		},

		canClose() {
			return this.period && this.period.state === STATE_CLOSING
		},

		canReopen() {
			return this.period && this.period.state === STATE_CLOSED
		},

		canLockAudit() {
			return this.period && this.period.state === STATE_CLOSED
		},

		checklistItems() {
			return Array.isArray(this.period?.taskChecklistItems)
				? this.period.taskChecklistItems
				: []
		},

		aiFlags() {
			return Array.isArray(this.period?.aiFlags) ? this.period.aiFlags : []
		},

		reopenedHistory() {
			return Array.isArray(this.period?.reopenedHistory)
				? this.period.reopenedHistory
				: []
		},

		reopenDialogPeriodName() {
			if (!this.period) {
				return ''
			}
			return this.period.name || this.period.periodId || ''
		},

		trialBalanceLink() {
			const periodId = encodeURIComponent(this.period?.periodId || '')
			return generateUrl(`/apps/shillinq/trial-balance?periodId=${periodId}`)
		},
	},

	watch: {
		recordId(next, prev) {
			if (next && next !== prev) {
				this.load()
			}
		},
	},

	created() {
		this.load()
	},

	methods: {
		async load() {
			if (!this.recordId) {
				this.loading = false
				this.loadError = t('shillinq', 'No period id supplied.')
				return
			}
			this.loading = true
			this.loadError = ''
			try {
				const url = generateUrl(
					`/apps/shillinq/api/period-close/${encodeURIComponent(this.recordId)}`,
				)
				const response = await axios.get(url)
				const data = response?.data?.data
				if (!data || typeof data !== 'object') {
					this.loadError = t('shillinq', 'Period not found.')
					this.period = null
				} else {
					this.period = data
				}
			} catch (err) {
				this.loadError = this.extractErrorMessage(
					err,
					t('shillinq', 'Failed to load period.'),
				)
			} finally {
				this.loading = false
			}
		},

		formatDate(value) {
			if (!value) {
				return '—'
			}
			try {
				const d = new Date(value)
				if (Number.isNaN(d.getTime())) {
					return value
				}
				return d.toISOString().slice(0, 10)
			} catch (e) {
				return value
			}
		},

		formatTimestamp(value) {
			if (!value) {
				return '—'
			}
			try {
				const d = new Date(value)
				if (Number.isNaN(d.getTime())) {
					return value
				}
				return d.toLocaleString()
			} catch (e) {
				return value
			}
		},

		categoryLabel(category) {
			switch (category) {
				case 'ap':
					return t('shillinq', 'Accounts payable')
				case 'ar':
					return t('shillinq', 'Accounts receivable')
				case 'bank':
					return t('shillinq', 'Bank reconciliation')
				case 'expense':
					return t('shillinq', 'Expense claims')
				default:
					return category || t('shillinq', 'Other')
			}
		},

		async onStartClose() {
			await this.transition(
				'start-close',
				t('shillinq', 'Period close initiated.'),
			)
		},

		async onClose() {
			await this.transition('close', t('shillinq', 'Period closed.'))
		},

		async onLockAudit() {
			await this.transition(
				'lock-audit',
				t('shillinq', 'Period locked for audit.'),
			)
		},

		async transition(action, successMessage, body = {}) {
			if (!this.recordId) {
				return
			}
			this.transitioning = true
			this.transitionError = ''
			this.transitionNotice = ''
			try {
				const url = generateUrl(
					`/apps/shillinq/api/period-close/${encodeURIComponent(this.recordId)}/${action}`,
				)
				const response = await axios.post(url, body || {})
				const next = response?.data?.data
				if (next && typeof next === 'object') {
					this.period = { ...this.period, ...next }
				} else {
					await this.load()
				}
				this.transitionNotice = successMessage
			} catch (err) {
				this.transitionError = this.extractErrorMessage(
					err,
					t('shillinq', 'Transition failed.'),
				)
			} finally {
				this.transitioning = false
			}
		},

		openReopenDialog() {
			this.reopenError = ''
			this.reopenDialogOpen = true
		},

		closeReopenDialog() {
			if (this.reopenSubmitting) {
				return
			}
			this.reopenDialogOpen = false
			this.reopenError = ''
		},

		async onReopenConfirmed(closeReason) {
			if (!this.recordId) {
				return
			}
			const trimmed = (closeReason || '').trim()
			if (trimmed === '') {
				this.reopenError = t('shillinq', 'Close reason is required.')
				return
			}
			this.reopenSubmitting = true
			this.reopenError = ''
			try {
				const url = generateUrl(
					`/apps/shillinq/api/period-close/${encodeURIComponent(this.recordId)}/reopen`,
				)
				const response = await axios.post(url, { closeReason: trimmed })
				const next = response?.data?.data
				if (next && typeof next === 'object') {
					this.period = { ...this.period, ...next }
				} else {
					await this.load()
				}
				this.reopenDialogOpen = false
				this.transitionNotice = t('shillinq', 'Period reopened.')
			} catch (err) {
				this.reopenError = this.extractErrorMessage(
					err,
					t('shillinq', 'Reopen failed.'),
				)
			} finally {
				this.reopenSubmitting = false
			}
		},

		extractErrorMessage(err, fallback) {
			const status = err?.response?.status
			const message = err?.response?.data?.message
			if (status === 403) {
				return t(
					'shillinq',
					'You do not have permission to perform this action.',
				)
			}
			if (typeof message === 'string' && message.length > 0) {
				return message
			}
			return fallback
		},
	},
}
</script>

<style scoped>
.period-close-detail {
	padding: 16px 24px;
}

.period-close-detail__header h2 {
	margin: 0 0 8px;
}

.period-close-detail__meta,
.period-close-detail__dates,
.period-close-detail__close-info,
.period-close-detail__lock-info {
	margin: 4px 0;
	color: var(--color-text-maxcontrast);
}

.period-close-detail__chip {
	margin-right: 12px;
}

.period-close-detail__pill {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.85em;
	background: var(--color-background-dark);
	margin-right: 8px;
}

.period-close-detail__pill--open {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.period-close-detail__pill--closing {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.period-close-detail__pill--closed {
	background: var(--color-background-darker);
}

.period-close-detail__pill--audit-locked {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.period-close-detail__actions {
	margin: 16px 0;
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	align-items: center;
}

.period-close-detail__error {
	color: var(--color-error);
	margin: 8px 0;
}

.period-close-detail__notice {
	color: var(--color-success);
	margin: 8px 0;
}

.period-close-detail__checklist-table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 8px;
}

.period-close-detail__checklist-table th,
.period-close-detail__checklist-table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.period-close-detail__flag-list {
	list-style: none;
	padding: 0;
}

.period-close-detail__flag {
	padding: 6px 8px;
	margin-bottom: 4px;
	border-left: 3px solid var(--color-warning);
	background: var(--color-background-hover);
}

.period-close-detail__flag--info {
	border-left-color: var(--color-primary);
}

.period-close-detail__flag--error {
	border-left-color: var(--color-error);
}

.period-close-detail__flag-count {
	margin-left: 8px;
	color: var(--color-text-maxcontrast);
}

.period-close-detail__history-reason,
.period-close-detail__history-orig {
	margin: 4px 0 4px 16px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.period-close-detail__link {
	color: var(--color-primary);
}

.period-close-detail__empty {
	color: var(--color-text-maxcontrast);
}

.period-close-detail__loading {
	color: var(--color-text-maxcontrast);
	padding: 16px 0;
}
</style>
