<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Shillinq W8 — External Adapters status index.

 Lists the 14 dormant external-API adapter ports the app ships
 (Digipoort/SBR, Salarisbureau, RvO, IB47, CBS Bestanden, CBS Iv3,
 BZK SiSa, Mollie, Bunq, KvK, UWV, Treasury Rates, CCM Rule Engine,
 CSRD ESRS XBRL, DepositPayment) with a live dormant/live badge per
 family. Drives the operator-facing roll-up that complements the
 individual `ExternalAdapterDetail.vue` pages.

 Reads /api/admin/external-adapters
 (ExternalAdaptersAdminController#index — admin-gated).

 @spec openspec/specs/bookkeeping-bank-connectors/spec.md
-->
<template>
	<NcAppContent>
		<div class="external-adapters">
			<header class="external-adapters__header">
				<h2 class="external-adapters__title">
					{{ t('shillinq', 'External Connections') }}
				</h2>
				<p class="external-adapters__description">
					{{
						t(
							'shillinq',
							'Operator view over every external-API adapter port the app ships. Each adapter is dormant by default (log-only) so regulatory-filing and bank-sync lifecycles advance without contacting a third party. Pick a family to see the activation recipe.',
						)
					}}
				</p>
			</header>

			<section v-if="loading" class="external-adapters__loading">
				<NcLoadingIcon
					:size="20"
					:name="t('shillinq', 'Loading adapter status')" />
			</section>

			<section
				v-else-if="errorMessage"
				class="external-adapters__error"
				role="alert">
				{{ errorMessage }}
			</section>

			<section v-else class="external-adapters__body">
				<div class="external-adapters__summary" aria-live="polite">
					<span
						class="external-adapters__pill external-adapters__pill--total">
						{{ summary.total }} {{ t('shillinq', 'families') }}
					</span>
					<span
						class="external-adapters__pill external-adapters__pill--dormant">
						{{ summary.dormant }} {{ t('shillinq', 'dormant') }}
					</span>
					<span
						class="external-adapters__pill external-adapters__pill--live">
						{{ summary.live }} {{ t('shillinq', 'live') }}
					</span>
				</div>

				<ul class="external-adapters__list">
					<li
						v-for="entry in adapters"
						:key="entry.id"
						class="external-adapters__item"
						:data-adapter-id="entry.id">
						<div class="external-adapters__item-row">
							<div class="external-adapters__item-text">
								<h3 class="external-adapters__item-title">
									{{ entry.title }}
								</h3>
								<p class="external-adapters__item-meta">
									<span class="external-adapters__category">{{
										entry.category
									}}</span>
									<span
										v-if="entry.specSlug"
										class="external-adapters__spec">
										{{ entry.specSlug }}
									</span>
								</p>
								<p class="external-adapters__item-desc">
									{{ entry.description }}
								</p>
							</div>
							<div class="external-adapters__item-actions">
								<span
									class="external-adapters__badge"
									:class="badgeClass(entry.dormant)"
									:data-state="entry.dormant ? 'dormant' : 'live'">
									{{
										entry.dormant
											? t('shillinq', 'Dormant')
											: t('shillinq', 'Live')
									}}
								</span>
								<NcButton
									variant="secondary"
									@click="openDetail(entry.id)">
									{{ t('shillinq', 'View activation') }}
								</NcButton>
							</div>
						</div>
					</li>
				</ul>
			</section>
		</div>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcAppContent, NcButton, NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'ExternalAdaptersStatus',

	components: {
		NcAppContent,
		NcButton,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			errorMessage: '',
			adapters: [],
			summary: { total: 0, dormant: 0, live: 0 },
		}
	},

	async mounted() {
		await this.loadStatus()
	},

	methods: {
		badgeClass(dormant) {
			return dormant
				? 'external-adapters__badge--dormant'
				: 'external-adapters__badge--live'
		},

		async loadStatus() {
			this.loading = true
			this.errorMessage = ''
			try {
				const url = generateUrl('/apps/shillinq/api/admin/external-adapters')
				const { data } = await axios.get(url)
				this.adapters = data?.adapters ?? []
				this.summary = data?.summary ?? { total: 0, dormant: 0, live: 0 }
			} catch (err) {
				this.errorMessage = t(
					'shillinq',
					'Could not load external adapter status: {message}',
					{ message: err?.message ?? 'unknown error' },
				)
			} finally {
				this.loading = false
			}
		},

		openDetail(id) {
			const routeId = this.routeIdForAdapter(id)
			if (routeId !== null) {
				this.$router.push({ name: routeId })
			}
		},

		routeIdForAdapter(id) {
			const map = {
				'digipoort-sbr': 'ExternalAdapterDigipoort',
				salarisbureau: 'ExternalAdapterSalarisbureau',
				rvo: 'ExternalAdapterRvO',
				ib47: 'ExternalAdapterIb47',
				'cbs-bestanden': 'ExternalAdapterCbsBestanden',
				'cbs-iv3': 'ExternalAdapterCbsIv3',
				'bzk-sisa': 'ExternalAdapterSisa',
				mollie: 'ExternalAdapterMollie',
				bunq: 'ExternalAdapterBunq',
				kvk: 'ExternalAdapterKvk',
				uwv: 'ExternalAdapterUwv',
				'treasury-rates': 'ExternalAdapterTreasuryRates',
				'ccm-rule-engine': 'ExternalAdapterCcm',
				'csrd-esrs-xbrl': 'ExternalAdapterCsrd',
				'deposit-payment': 'ExternalAdapterDepositPayment',
			}
			return map[id] ?? null
		},
	},
}
</script>

<style scoped>
.external-adapters {
	padding: var(--default-grid-baseline, 8px);
	max-width: 1100px;
}

.external-adapters__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.external-adapters__title {
	margin: 0 0 var(--default-grid-baseline, 8px) 0;
}

.external-adapters__description {
	color: var(--color-text-maxcontrast);
}

.external-adapters__summary {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.external-adapters__pill {
	padding: 4px 12px;
	border-radius: var(--border-radius-pill, 100px);
	font-weight: 600;
	background: var(--color-background-hover);
}

.external-adapters__pill--dormant {
	background: var(--color-warning-rgba, rgba(255, 169, 0, 0.2));
	color: var(--color-warning, #b06c00);
}

.external-adapters__pill--live {
	background: var(--color-success-rgba, rgba(46, 160, 67, 0.2));
	color: var(--color-success, #2ea043);
}

.external-adapters__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 8px);
}

.external-adapters__item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	background: var(--color-main-background);
}

.external-adapters__item-row {
	display: flex;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	align-items: flex-start;
}

.external-adapters__item-text {
	flex: 1 1 auto;
	min-width: 0;
}

.external-adapters__item-title {
	margin: 0 0 4px 0;
}

.external-adapters__item-meta {
	margin: 0 0 6px 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	display: flex;
	gap: 12px;
}

.external-adapters__item-desc {
	margin: 0;
	color: var(--color-main-text);
}

.external-adapters__item-actions {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	align-items: center;
	flex-shrink: 0;
}

.external-adapters__badge {
	padding: 4px 10px;
	border-radius: var(--border-radius-pill, 100px);
	font-weight: 600;
	font-size: 0.85em;
}

.external-adapters__badge--dormant {
	background: var(--color-warning-rgba, rgba(255, 169, 0, 0.2));
	color: var(--color-warning, #b06c00);
}

.external-adapters__badge--live {
	background: var(--color-success-rgba, rgba(46, 160, 67, 0.2));
	color: var(--color-success, #2ea043);
}

.external-adapters__loading,
.external-adapters__error {
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.external-adapters__error {
	color: var(--color-error);
}
</style>
