<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 FX Rates admin page (add-shillinq-multi-currency Task 14).

 Wraps the declarative FxRate index grid with an "Import status" header
 strip that surfaces the last-run timestamp of FxRateImportJob and the
 TreasuryRateAdapter dormancy flag (read from
 /api/admin/fx-rate-import-status). Admin-only — the underlying
 controller is gated by #[AuthorizedAdminSetting(Application::class)].

 The grid itself is intentionally left to the declarative
 OR list-page builder elsewhere in the manifest; this page is the
 operator-facing administration overlay (cron health + admin help text)
 for the FxRate register.

 @spec openspec/changes/add-shillinq-multi-currency/tasks.md#task-14
-->
<template>
	<NcAppContent>
		<div class="fx-rates-admin">
			<header class="fx-rates-admin__header">
				<h2 class="fx-rates-admin__title">
					{{ t('shillinq', 'FX Rates') }}
				</h2>
				<p class="fx-rates-admin__description">
					{{
						t(
							'shillinq',
							'Daily exchange-rate snapshots used by the GL posting engine and IAS 21 consolidation. ECB rates are imported daily by the FxRateImportJob; manual rates require a written reason and override the ECB value for the affected date.',
						)
					}}
				</p>
			</header>

			<section class="fx-rates-admin__status" aria-live="polite">
				<NcLoadingIcon
					v-if="loading"
					:size="20"
					:name="t('shillinq', 'Loading import status')" />
				<template v-else>
					<div class="fx-rates-admin__status-row">
						<span class="fx-rates-admin__status-label">
							{{ t('shillinq', 'Import status') }}:
						</span>
						<span
							class="fx-rates-admin__status-badge"
							:class="badgeClass"
							:data-status="status.status">
							{{ badgeLabel }}
						</span>
					</div>
					<div v-if="status.lastRunAt" class="fx-rates-admin__status-row">
						<span class="fx-rates-admin__status-label">
							{{ t('shillinq', 'Last successful run') }}:
						</span>
						<span class="fx-rates-admin__status-value">
							{{ formatTimestamp(status.lastRunAt) }}
						</span>
					</div>
					<div v-else class="fx-rates-admin__status-row">
						<span
							class="fx-rates-admin__status-value fx-rates-admin__status-value--muted">
							{{
								t(
									'shillinq',
									'The cron has not produced a successful run yet.',
								)
							}}
						</span>
					</div>
					<p v-if="status.adapterDormant" class="fx-rates-admin__hint">
						{{
							t(
								'shillinq',
								'The Treasury rate adapter is currently dormant. Bind the openconnector source "treasury-rates" (ECB SDMX) and override TreasuryRateAdapterInterface in Application::register() to start ingesting real rates. Manual rate entries are unaffected.',
							)
						}}
					</p>
				</template>
				<p v-if="errorMessage" class="fx-rates-admin__error" role="alert">
					{{ errorMessage }}
				</p>
			</section>

			<section class="fx-rates-admin__index">
				<p class="fx-rates-admin__index-help">
					{{
						t(
							'shillinq',
							'The grid below is the declarative FxRate index. Use the filters to narrow by currency pair or source.',
						)
					}}
				</p>
				<NcButton variant="secondary" @click="navigateToIndex">
					{{ t('shillinq', 'Open FX Rates index') }}
				</NcButton>
			</section>
		</div>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcAppContent, NcButton, NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'FxRatesAdmin',

	components: {
		NcAppContent,
		NcButton,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			errorMessage: '',
			status: {
				jobClass: 'OCA\\Shillinq\\BackgroundJob\\FxRateImportJob',
				lastRunAt: null,
				lastRunEpoch: null,
				adapterDormant: false,
				interval: 86400,
				status: 'never-ran',
			},
		}
	},

	computed: {
		badgeLabel() {
			if (this.status.status === 'dormant') {
				return this.t('shillinq', 'Dormant')
			}
			if (this.status.status === 'never-ran') {
				return this.t('shillinq', 'Never ran')
			}
			return this.t('shillinq', 'OK')
		},

		badgeClass() {
			return {
				'fx-rates-admin__status-badge--ok': this.status.status === 'ok',
				'fx-rates-admin__status-badge--dormant':
					this.status.status === 'dormant',

				'fx-rates-admin__status-badge--warn':
					this.status.status === 'never-ran',
			}
		},
	},

	mounted() {
		this.fetchStatus()
	},

	methods: {
		async fetchStatus() {
			this.loading = true
			this.errorMessage = ''
			try {
				const url = generateUrl(
					'/apps/shillinq/api/admin/fx-rate-import-status',
				)
				const { data } = await axios.get(url)
				this.status = {
					jobClass: data?.jobClass ?? this.status.jobClass,
					lastRunAt: data?.lastRunAt ?? null,
					lastRunEpoch: data?.lastRunEpoch ?? null,
					adapterDormant: Boolean(data?.adapterDormant),
					interval: Number(data?.interval ?? 86400),
					status: data?.status ?? 'never-ran',
				}
			} catch (error) {
				const status = error?.response?.status
				if (status === 401 || status === 403) {
					this.errorMessage = this.t(
						'shillinq',
						'Admin permission required to read FX import status.',
					)
				} else {
					this.errorMessage = this.t(
						'shillinq',
						'Failed to load FX import status.',
					)
				}
			} finally {
				this.loading = false
			}
		},

		formatTimestamp(iso) {
			try {
				const d = new Date(iso)
				return d.toLocaleString()
			} catch (e) {
				return iso
			}
		},

		navigateToIndex() {
			// Delegate to the declarative FXRates manifest index page.
			this.$router?.push?.('/bookkeeping/multi-currency/fx-rates')
		},
	},
}
</script>

<style scoped>
.fx-rates-admin {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}

.fx-rates-admin__title {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 2);
}

.fx-rates-admin__description {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 3);
	color: var(--color-text-maxcontrast, #555);
}

.fx-rates-admin__status {
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	padding: calc(var(--default-grid-baseline, 4px) * 3);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
	background-color: var(--color-background-hover, #f5f5f5);
}

.fx-rates-admin__status-row {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 1);
}

.fx-rates-admin__status-label {
	font-weight: bold;
}

.fx-rates-admin__status-value--muted {
	color: var(--color-text-maxcontrast, #777);
}

.fx-rates-admin__status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 4px;
	font-weight: bold;
	font-size: 12px;
}

.fx-rates-admin__status-badge--ok {
	background-color: var(--color-success, #46ba61);
	color: white;
}

.fx-rates-admin__status-badge--dormant {
	background-color: var(--color-warning, #c28900);
	color: white;
}

.fx-rates-admin__status-badge--warn {
	background-color: var(--color-warning, #c28900);
	color: white;
}

.fx-rates-admin__hint {
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
	color: var(--color-text-maxcontrast, #555);
	font-style: italic;
}

.fx-rates-admin__error {
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
	color: var(--color-error, #d40000);
}

.fx-rates-admin__index {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	flex-wrap: wrap;
}

.fx-rates-admin__index-help {
	flex: 1 1 auto;
	margin: 0;
	color: var(--color-text-maxcontrast, #555);
}
</style>
