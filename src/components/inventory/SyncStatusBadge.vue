<!--
  Sync Status Badge

  Persistent indicator in the mobile scanner shell that displays the current
  sync state per REQ-UI-001 / REQ-UI-002. Tap to trigger a manual sync.
  Renders LWW conflict toasts as a non-blocking list below the badge.

  Colors:
    🟢 green  — Synced < 2 min ago
    🟡 yellow — Pending changes / sync in progress / last sync stale
    🔴 red    — Offline

  @spec openspec/changes/inventory-mobile-scanner/tasks.md#T4.2
-->
<template>
	<div class="sync-status-badge">
		<button
			type="button"
			class="sync-status-badge__button"
			:class="badgeClass"
			:title="store.statusLabel"
			:aria-label="store.statusLabel"
			@click="handleClick">
			<span class="sync-status-badge__dot" aria-hidden="true" />
			<span class="sync-status-badge__text">
				{{ humanLabel }}
			</span>
		</button>

		<ul v-if="store.conflicts.length > 0" class="sync-status-badge__conflicts">
			<li
				v-for="(conflict, index) in store.conflicts"
				:key="conflict.at + index"
				role="status">
				<span>
					{{
						t(
							'shillinq',
							'Stock was updated by another user. {applied} record(s) merged at {at}.',
							{ applied: conflict.applied, at: conflict.at },
						)
					}}
				</span>
				<button type="button" @click="store.dismissConflict(index)">
					{{ t('shillinq', 'Dismiss') }}
				</button>
			</li>
		</ul>
	</div>
</template>

<script>
import { useInventoryMobileScannerStore } from '../../store/modules/inventoryMobileScanner.js'

const STALE_THRESHOLD_MS = 2 * 60 * 1000

export default {
	name: 'SyncStatusBadge',
	data() {
		return {
			now: Date.now(),
			ticker: null,
		}
	},

	computed: {
		store() {
			return useInventoryMobileScannerStore()
		},

		badgeClass() {
			if (
				this.store.syncState === 'syncing'
				|| this.store.syncState === 'pending'
				|| this.store.syncState === 'failed'
				|| this.isStale
			) {
				return 'sync-status-badge__button--yellow'
			}
			if (this.store.syncState === 'offline') {
				return 'sync-status-badge__button--red'
			}
			return 'sync-status-badge__button--green'
		},

		isStale() {
			if (!this.store.lastSyncedAt) {
				return false
			}
			const ts = Date.parse(this.store.lastSyncedAt)
			if (Number.isNaN(ts)) {
				return false
			}
			return this.now - ts > STALE_THRESHOLD_MS
		},

		humanLabel() {
			if (this.store.syncState === 'failed') {
				return this.t('shillinq', 'Sync failed; retry {sec}s', {
					sec: Math.ceil((this.store.retryInMs || 0) / 1000),
				})
			}
			if (this.store.syncState === 'syncing') {
				return this.t('shillinq', 'Syncing…')
			}
			if (this.store.syncState === 'offline') {
				return this.t('shillinq', 'Offline')
			}
			if (this.store.pendingCount > 0) {
				return this.t('shillinq', 'Pending ({n})', {
					n: this.store.pendingCount,
				})
			}
			if (this.store.lastSyncedAt) {
				return this.t('shillinq', 'Last synced {at}', {
					at: this.formatTime(this.store.lastSyncedAt),
				})
			}
			return this.t('shillinq', 'Sync now')
		},
	},

	mounted() {
		this.ticker = setInterval(() => {
			this.now = Date.now()
		}, 30_000)
	},

	beforeUnmount() {
		if (this.ticker !== null) {
			clearInterval(this.ticker)
			this.ticker = null
		}
	},

	methods: {
		async handleClick() {
			if (!this.store.isOnline) {
				return
			}
			await this.store.triggerSyncNow()
		},

		formatTime(iso) {
			const ts = Date.parse(iso)
			if (Number.isNaN(ts)) {
				return iso
			}
			const d = new Date(ts)
			return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
		},
	},
}
</script>

<style scoped>
.sync-status-badge {
	position: relative;
}

.sync-status-badge__button {
	display: inline-flex;
	align-items: center;
	gap: var(--default-grid-baseline, 4px);
	border-radius: var(--border-radius-pill, 999px);
	padding: 2px 8px;
	background: var(--color-background-dark);
}

.sync-status-badge__dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: var(--color-success);
}

.sync-status-badge__button--green .sync-status-badge__dot {
	background: var(--color-success);
}

.sync-status-badge__button--yellow .sync-status-badge__dot {
	background: var(--color-warning);
}

.sync-status-badge__button--red .sync-status-badge__dot {
	background: var(--color-error);
}

.sync-status-badge__conflicts {
	list-style: none;
	padding: 0;
	margin-top: var(--default-grid-baseline, 4px);
}

.sync-status-badge__conflicts li {
	display: flex;
	gap: var(--default-grid-baseline, 4px);
	background: var(--color-background-hover);
	padding: var(--default-grid-baseline, 4px);
	border-radius: var(--border-radius, 4px);
	margin-bottom: var(--default-grid-baseline, 4px);
}
</style>
