<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 integration-config-to-openconnector — External Connections roster.

 Renders one row per external-API adapter family (15 — Digipoort/SBR,
 Salarisbureau, RvO, IB47, CBS Bestanden, CBS Iv3, BZK SiSa, Mollie,
 Bunq, KvK, UWV, Treasury Rates, CCM Rule Engine, CSRD ESRS XBRL,
 DepositPayment), replacing the former index + 15 per-adapter detail
 pages (REQ-ICO-002). Per ADR-067/ADR-091/ADR-022, integration
 configuration (credentials, endpoints, protocol mapping) belongs to
 openconnector; this row only ever shows the declared source-slug
 reference, the live dormant/live verdict, and the live
 provisioned-in-openconnector verdict (REQ-ICO-003) — never a
 credential. The activation recipe (config keys, feature flag,
 ordered steps) that used to live on its own page is now an
 in-row expandable section, so no information is lost by dropping
 the per-family route.

 Reads /api/admin/external-adapters
 (ExternalAdaptersAdminController#index — admin-gated).

 @spec openspec/changes/integration-config-to-openconnector/specs/integration-config-to-openconnector/spec.md
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
							'Operator roster over every external-API adapter family the app ships. Each family is dormant by default (log-only) so regulatory-filing and bank-sync lifecycles advance without contacting a third party. Credentials and protocol mapping are configured in OpenConnector — expand a row for the activation recipe.',
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
									<code class="external-adapters__slug">{{
										entry.sourceSlug
									}}</code>
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
								<span
									class="external-adapters__badge"
									:class="
										provisioningBadgeClass(
											entry.provisioning
												&& entry.provisioning.status,
										)
									"
									:data-provisioning-status="
										entry.provisioning
										&& entry.provisioning.status
									">
									{{
										provisioningLabel(
											entry.provisioning
												&& entry.provisioning.status,
										)
									}}
								</span>
								<NcButton
									v-if="
										entry.provisioning
										&& entry.provisioning.status
											!== 'provisioned'
									"
									variant="secondary"
									:href="
										entry.provisioning
										&& entry.provisioning.deepLink
									"
									target="_blank"
									rel="noopener noreferrer">
									{{ t('shillinq', 'Provision in OpenConnector') }}
								</NcButton>
								<NcButton
									variant="tertiary"
									:aria-expanded="
										isExpanded(entry.id) ? 'true' : 'false'
									"
									@click="toggleExpanded(entry.id)">
									{{
										isExpanded(entry.id)
											? t('shillinq', 'Hide activation recipe')
											: t('shillinq', 'Show activation recipe')
									}}
								</NcButton>
							</div>
						</div>

						<div
							v-if="isExpanded(entry.id)"
							class="external-adapters__recipe">
							<div class="external-adapters__fact">
								<span class="external-adapters__fact-label">{{
									t('shillinq', 'Feature flag')
								}}</span>
								<code class="external-adapters__fact-value">{{
									entry.featureFlag || t('shillinq', 'n/a')
								}}</code>
							</div>
							<div class="external-adapters__fact">
								<span class="external-adapters__fact-label">{{
									t('shillinq', 'App-config keys')
								}}</span>
								<ul class="external-adapters__keys">
									<li
										v-for="key in entry.configKeys || []"
										:key="key">
										<code>{{ key }}</code>
									</li>
								</ul>
							</div>
							<div class="external-adapters__fact">
								<span class="external-adapters__fact-label">{{
									t('shillinq', 'Activation steps')
								}}</span>
								<ol class="external-adapters__steps">
									<li
										v-for="(step, idx) in entry.steps || []"
										:key="idx">
										{{ step }}
									</li>
								</ol>
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
			expandedIds: [],
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

		/**
		 * Map a REQ-ICO-003 provisioning status to the badge modifier class.
		 * `unknown` fails soft to the SAME visual as "not provisioned" —
		 * never to "live" — so a lookup failure can never read as a
		 * confirmed provisioned source.
		 *
		 * @spec openspec/changes/integration-config-to-openconnector/specs/integration-config-to-openconnector/spec.md
		 */
		provisioningBadgeClass(status) {
			if (status === 'provisioned') {
				return 'external-adapters__badge--live'
			}
			if (status === 'unknown') {
				return 'external-adapters__badge--unknown'
			}
			return 'external-adapters__badge--dormant'
		},

		/**
		 * Operator-facing label for a REQ-ICO-003 provisioning status.
		 *
		 * @spec openspec/changes/integration-config-to-openconnector/specs/integration-config-to-openconnector/spec.md
		 */
		provisioningLabel(status) {
			if (status === 'provisioned') {
				return t('shillinq', 'Provisioned in OpenConnector')
			}
			if (status === 'unknown') {
				return t('shillinq', 'Provisioning status unknown')
			}
			return t('shillinq', 'Declared, provision in OpenConnector')
		},

		/**
		 * Whether a family's in-row activation-recipe disclosure is open.
		 *
		 * @spec openspec/changes/integration-config-to-openconnector/specs/integration-config-to-openconnector/spec.md
		 */
		isExpanded(id) {
			return this.expandedIds.includes(id)
		},

		/**
		 * Toggle a family's in-row activation-recipe disclosure — the
		 * replacement, per REQ-ICO-002, for the removed per-family route.
		 *
		 * @spec openspec/changes/integration-config-to-openconnector/specs/integration-config-to-openconnector/spec.md
		 */
		toggleExpanded(id) {
			if (this.isExpanded(id)) {
				this.expandedIds = this.expandedIds.filter((x) => x !== id)
			} else {
				this.expandedIds = [...this.expandedIds, id]
			}
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
	flex-wrap: wrap;
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
	align-items: center;
}

.external-adapters__slug {
	word-break: break-all;
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
	flex-wrap: wrap;
}

.external-adapters__badge {
	padding: 4px 10px;
	border-radius: var(--border-radius-pill, 100px);
	font-weight: 600;
	font-size: 0.85em;
	white-space: nowrap;
}

.external-adapters__badge--dormant {
	background: var(--color-warning-rgba, rgba(255, 169, 0, 0.2));
	color: var(--color-warning, #b06c00);
}

.external-adapters__badge--live {
	background: var(--color-success-rgba, rgba(46, 160, 67, 0.2));
	color: var(--color-success, #2ea043);
}

.external-adapters__badge--unknown {
	background: var(--color-background-darker, rgba(127, 127, 127, 0.2));
	color: var(--color-text-maxcontrast);
}

.external-adapters__recipe {
	margin-top: calc(var(--default-grid-baseline, 8px) * 2);
	padding-top: calc(var(--default-grid-baseline, 8px) * 2);
	border-top: 1px solid var(--color-border);
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 8px);
}

.external-adapters__fact {
	display: flex;
	gap: 16px;
	align-items: flex-start;
}

.external-adapters__fact-label {
	flex: 0 0 160px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.external-adapters__fact-value {
	flex: 1 1 auto;
	min-width: 0;
	word-break: break-all;
}

.external-adapters__keys {
	list-style: none;
	padding: 0;
	margin: 0;
}

.external-adapters__steps {
	padding-left: 20px;
	margin: 0;
}

.external-adapters__loading,
.external-adapters__error {
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.external-adapters__error {
	color: var(--color-error);
}
</style>
