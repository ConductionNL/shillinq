<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Accountant portal dashboard (accountant-portal, REQ-ACP-001/002). Registered
 as a kind:"page" custom component rather than a declarative manifest
 `dashboard` page type: the built-in dashboard widgets (summary/table/chart)
 bind to ONE register+schema, whereas this page composes a per-client card
 from FOUR different signals (period-close, BTW filing, missing documents,
 open items) aggregated server-side by AccountantDashboardService, plus a
 per-card "Download handover pack" action — neither concern fits the
 declarative dashboard vocabulary (mirrors the PurchaseOrderDetail /
 inventory-mobile-scanner precedent in registry.js).

 Every card is an administration the authenticated user has a valid
 AdministrationMembership for (AdministrationContextService) — an accountant
 with a grant for client A never sees a card, nor can download a pack, for a
 client they have no membership for (REQ-ACP-003; the server masks any other
 id as 404).

 @spec openspec/specs/accountant-portal/spec.md
-->
<template>
	<NcAppContent>
		<div class="accountant-portal">
			<header class="accountant-portal__header">
				<h2 class="accountant-portal__title">
					{{ t('shillinq', 'Accountant portal') }}
				</h2>
				<p class="accountant-portal__description">
					{{
						t(
							'shillinq',
							'Status overview of every client administration you have access to. Only administrations you have a membership for are listed.',
						)
					}}
				</p>
			</header>

			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('shillinq', 'Loading client administrations')" />
			<NcEmptyContent
				v-else-if="!administrations.length"
				:name="t('shillinq', 'No client administrations')"
				:description="
					t(
						'shillinq',
						'You have no administration memberships yet. Ask an administration owner to grant you access.',
					)
				" />
			<div v-else class="accountant-portal__grid">
				<article
					v-for="client in administrations"
					:key="client.administrationId"
					class="accountant-portal__card"
					data-testid="accountant-client-card">
					<header class="accountant-portal__card-header">
						<h3 class="accountant-portal__card-title">
							{{
								client.name
								|| client.administrationCode
								|| client.administrationId
							}}
						</h3>
						<span class="accountant-portal__role-badge">{{
							client.role
						}}</span>
					</header>

					<dl class="accountant-portal__status-list">
						<div class="accountant-portal__status-row">
							<dt>{{ t('shillinq', 'Period close') }}</dt>
							<dd>{{ periodCloseLabel(client.periodClose) }}</dd>
						</div>
						<div class="accountant-portal__status-row">
							<dt>{{ t('shillinq', 'BTW filing') }}</dt>
							<dd
								:class="{
									'accountant-portal__status-row--overdue':
										client.vatFiling && client.vatFiling.overdue,
								}">
								{{ vatFilingLabel(client.vatFiling) }}
							</dd>
						</div>
						<div class="accountant-portal__status-row">
							<dt>{{ t('shillinq', 'Missing documents') }}</dt>
							<dd
								:class="{
									'accountant-portal__status-row--overdue':
										client.missingDocuments > 0,
								}">
								{{ client.missingDocuments }}
							</dd>
						</div>
						<div class="accountant-portal__status-row">
							<dt>{{ t('shillinq', 'Needs attention') }}</dt>
							<dd
								:class="{
									'accountant-portal__status-row--overdue':
										client.openItemsCount > 0,
								}">
								{{ client.openItemsCount }}
							</dd>
						</div>
					</dl>

					<ul
						v-if="client.attentionItems && client.attentionItems.length"
						class="accountant-portal__attention-list">
						<li
							v-for="item in client.attentionItems.slice(0, 3)"
							:key="item.id">
							{{ item.message }}
						</li>
					</ul>

					<NcButton
						variant="secondary"
						data-testid="accountant-handover-pack-button"
						@click="downloadPack(client.administrationId)">
						{{ t('shillinq', 'Download handover pack') }}
					</NcButton>
				</article>
			</div>

			<p v-if="errorMessage" class="accountant-portal__error" role="alert">
				{{ errorMessage }}
			</p>
		</div>
	</NcAppContent>
</template>

<script>
import {
	NcAppContent,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
} from '@nextcloud/vue'
import {
	downloadHandoverPack,
	fetchAccountantDashboard,
} from '../api/accountantApi.js'

export default {
	name: 'AccountantPortalDashboard',

	components: {
		NcAppContent,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			errorMessage: '',
			administrations: [],
		}
	},

	mounted() {
		this.loadDashboard()
	},

	methods: {
		/**
		 * Load the authenticated user's accountant dashboard.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/accountant-portal/spec.md
		 */
		async loadDashboard() {
			this.loading = true
			this.errorMessage = ''

			try {
				const data = await fetchAccountantDashboard()
				this.administrations = Array.isArray(data?.administrations)
					? data.administrations
					: []
			} catch (error) {
				const status = error?.response?.status
				if (status === 401) {
					this.errorMessage = this.t(
						'shillinq',
						'Please sign in to view the accountant portal.',
					)
				} else {
					this.errorMessage = this.t(
						'shillinq',
						'Failed to load the accountant dashboard.',
					)
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Trigger the handover-pack ZIP download for one client administration.
		 *
		 * @param {string} administrationId - The client administration id.
		 * @return {void}
		 * @spec openspec/specs/accountant-portal/spec.md
		 */
		downloadPack(administrationId) {
			downloadHandoverPack(administrationId)
		},

		/**
		 * Format the period-close status for display.
		 *
		 * @param {object|null} periodClose - { periodId, state, endDate } or null.
		 * @return {string}
		 * @spec openspec/specs/accountant-portal/spec.md
		 */
		periodCloseLabel(periodClose) {
			if (!periodClose) {
				return this.t('shillinq', 'No period recorded')
			}
			return `${periodClose.state} (${periodClose.endDate})`
		},

		/**
		 * Format the BTW filing status + deadline for display.
		 *
		 * @param {object|null} vatFiling - { statusCode, dueDate, overdue } or null.
		 * @return {string}
		 * @spec openspec/specs/accountant-portal/spec.md
		 */
		vatFilingLabel(vatFiling) {
			if (!vatFiling) {
				return this.t('shillinq', 'No return on file')
			}
			if (!vatFiling.dueDate) {
				return vatFiling.statusCode
			}
			return `${vatFiling.statusCode} — ${this.t('shillinq', 'due')} ${vatFiling.dueDate}`
		},
	},
}
</script>

<style scoped>
.accountant-portal {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}

.accountant-portal__title {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 2);
}

.accountant-portal__description {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 4);
	color: var(--color-text-maxcontrast, #555);
}

.accountant-portal__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.accountant-portal__card {
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	padding: calc(var(--default-grid-baseline, 4px) * 3);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.accountant-portal__card-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
}

.accountant-portal__card-title {
	margin: 0;
	font-size: 1rem;
}

.accountant-portal__role-badge {
	font-size: 0.75rem;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 16px);
	background-color: var(--color-background-dark, #eee);
	color: var(--color-text-maxcontrast, #555);
	white-space: nowrap;
}

.accountant-portal__status-list {
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.accountant-portal__status-row {
	display: flex;
	justify-content: space-between;
	gap: 12px;
}

.accountant-portal__status-row dt {
	color: var(--color-text-maxcontrast, #777);
}

.accountant-portal__status-row dd {
	margin: 0;
	font-variant-numeric: tabular-nums;
}

.accountant-portal__status-row--overdue {
	color: var(--color-error, #d40000);
	font-weight: bold;
}

.accountant-portal__attention-list {
	margin: 0;
	padding-left: 18px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast, #777);
}

.accountant-portal__error {
	margin-top: calc(var(--default-grid-baseline, 4px) * 3);
	color: var(--color-error, #d40000);
}
</style>
