<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  Shillinq Mobile home

  The installed app opens here: two large actions (capture a receipt, draft
  an invoice) and the receipts and drafts made on this phone with their sync
  state. It registers the mobile service worker, sends what is queued on
  start and whenever the connection returns, and offers Issue on a synced
  draft only while online.

  @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-shillinq-mobile-is-installable-on-a-phone-req-pmp-001
  @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
  @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-an-invoice-is-issued-from-the-phone-only-online-req-pmp-004
-->
<template>
	<section class="mobile-home">
		<header class="mobile-home__header">
			<h1>{{ t('shillinq', 'Shillinq Mobile') }}</h1>
			<span
				class="mobile-home__connection"
				:class="{ 'mobile-home__connection--offline': !online }"
				role="status">
				{{ online ? t('shillinq', 'Online') : t('shillinq', 'Offline') }}
			</span>
		</header>

		<p v-if="!persistent" class="mobile-home__notice" role="note">
			{{
				t(
					'shillinq',
					'This browser cannot keep work on the phone. Shillinq Mobile works here only with a connection.',
				)
			}}
		</p>

		<nav class="mobile-home__actions" :aria-label="t('shillinq', 'Mobile actions')">
			<router-link to="/mobile/receipt" class="mobile-home__action">
				{{ t('shillinq', 'Capture a receipt') }}
			</router-link>
			<router-link to="/mobile/invoice" class="mobile-home__action">
				{{ t('shillinq', 'Draft an invoice') }}
			</router-link>
		</nav>

		<h2 class="mobile-home__subheading">
			{{ t('shillinq', 'Made on this phone') }}
		</h2>
		<p v-if="items.length === 0" class="mobile-home__empty">
			{{ t('shillinq', 'Nothing yet. Receipts and invoice drafts you make here show up in this list.') }}
		</p>
		<ul v-else class="mobile-home__list">
			<li
				v-for="item in items"
				:key="item.clientId"
				class="mobile-home__item">
				<div class="mobile-home__item-main">
					<strong>{{ itemTitle(item) }}</strong>
					<span class="mobile-home__state" :class="`mobile-home__state--${item.state}`">
						{{ stateLabel(item) }}
					</span>
				</div>
				<p v-if="item.error" class="mobile-home__error">
					{{ item.error }}
				</p>
				<NcButton
					v-if="issuable(item)"
					variant="primary"
					:disabled="issuing === item.clientId"
					@click="issue(item)">
					{{ t('shillinq', 'Issue') }}
				</NcButton>
			</li>
		</ul>
	</section>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import {
	isOnline,
	listQueue,
	QUEUE_KINDS,
	QUEUE_STATES,
	syncQueue,
	watchConnectivity,
} from '../../composables/useOfflineQueue.js'
import { registerMobileServiceWorker } from '../../composables/useServiceWorker.js'
import { canIssue, issueInvoice, mobileQueue } from './mobileApp.js'

export default {
	name: 'MobileHome',

	components: { NcButton },

	data() {
		return {
			items: [],
			online: isOnline(),
			persistent: true,
			issuing: null,
			queue: null,
			stopWatching: null,
		}
	},

	async mounted() {
		registerMobileServiceWorker().catch(() => null)
		this.queue = await mobileQueue()
		this.persistent = this.queue.persistent
		this.stopWatching = watchConnectivity((online) => this.onConnectivity(online))
		await this.refresh()
		if (this.online) {
			await this.sync()
		}
	},

	beforeUnmount() {
		if (this.stopWatching) {
			this.stopWatching()
		}
	},

	methods: {
		t,

		/**
		 * Re-read the queue, newest first.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
		 */
		async refresh() {
			this.items = (await listQueue(this.queue.store)).reverse()
		},

		/**
		 * Send what is queued, then show the new states.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
		 */
		async sync() {
			await syncQueue(this.queue.store, this.queue.transport)
			await this.refresh()
		},

		/**
		 * Follow the connection; send the queue when it returns.
		 *
		 * @param {boolean} online Whether the phone is online now.
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
		 */
		async onConnectivity(online) {
			this.online = online
			if (online) {
				await this.sync()
			}
		},

		/**
		 * Whether Issue is offered on an item.
		 *
		 * @param {object} item The queue item.
		 * @return {boolean}
		 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-an-invoice-is-issued-from-the-phone-only-online-req-pmp-004
		 */
		issuable(item) {
			return canIssue(item, this.online)
		},

		/**
		 * Issue a synced draft and keep the number it got on the item.
		 *
		 * @param {object} item The queue item.
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-an-invoice-is-issued-from-the-phone-only-online-req-pmp-004
		 */
		async issue(item) {
			this.issuing = item.clientId
			try {
				const issued = await issueInvoice(item.recordId)
				await this.queue.store.put({ ...item, invoiceNumber: issued.invoiceNumber, lifecycleState: issued.lifecycleState })
				await this.refresh()
			} catch (e) {
				showError(e?.response?.data?.message || t('shillinq', 'The invoice could not be issued.'))
			} finally {
				this.issuing = null
			}
		},

		/**
		 * The line an item shows.
		 *
		 * @param {object} item The queue item.
		 * @return {string}
		 */
		itemTitle(item) {
			if (item.kind === QUEUE_KINDS.RECEIPT) {
				return t('shillinq', 'Receipt {date}, EUR {amount}', {
					date: item.payload.receiptDate,
					amount: Number(item.payload.amount ?? 0).toFixed(2),
				})
			}
			if (item.invoiceNumber) {
				return t('shillinq', 'Invoice {number}', { number: item.invoiceNumber })
			}
			return t('shillinq', 'Invoice draft {date}', { date: item.payload.invoiceDate })
		},

		/**
		 * The state an item shows.
		 *
		 * @param {object} item The queue item.
		 * @return {string}
		 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
		 */
		stateLabel(item) {
			if (item.invoiceNumber) {
				return t('shillinq', 'Issued')
			}
			return {
				[QUEUE_STATES.QUEUED]: t('shillinq', 'Queued'),
				[QUEUE_STATES.SYNCING]: t('shillinq', 'Sending'),
				[QUEUE_STATES.SYNCED]: t('shillinq', 'Synced'),
				[QUEUE_STATES.FAILED]: t('shillinq', 'Not sent'),
			}[item.state] ?? item.state
		},
	},
}
</script>

<style scoped>
.mobile-home {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
	max-width: 640px;
	margin: 0 auto;
}

.mobile-home__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.mobile-home__connection {
	padding: 4px 12px;
	border-radius: var(--border-radius-pill);
	background: var(--color-success);
	color: var(--color-success-text, var(--color-primary-element-text));
}

.mobile-home__connection--offline {
	background: var(--color-warning);
	color: var(--color-warning-text, var(--color-main-text));
}

.mobile-home__notice,
.mobile-home__error {
	color: var(--color-text-maxcontrast);
}

.mobile-home__actions {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}

.mobile-home__action {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 96px;
	padding: 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: bold;
	text-align: center;
}

.mobile-home__action:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.mobile-home__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 0;
	list-style: none;
}

.mobile-home__item {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-height: 44px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.mobile-home__item-main {
	display: flex;
	justify-content: space-between;
	gap: 8px;
}

.mobile-home__state--failed {
	color: var(--color-error-text, var(--color-error));
}

.mobile-home__state--synced {
	color: var(--color-success-text, var(--color-success));
}
</style>
