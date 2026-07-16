<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Shillinq W8 — External Adapter detail / activation panel.

 Single Vue component reused for all 14 adapter families
 (Digipoort/SBR, Salarisbureau, RvO, IB47, CBS Bestanden, CBS Iv3,
 BZK SiSa, Mollie, Bunq, KvK, UWV, Treasury Rates, CCM Rule Engine,
 CSRD ESRS XBRL, DepositPayment). The page reads
 /api/admin/external-adapters/{id} and renders:

  - live dormancy badge,
  - OpenSpec change slug + REQ ids the adapter belongs to,
  - app-config keys operators must set (oc_appconfig),
  - feature-flag slug that gates the live binding,
  - openconnector Source slug operators must provision,
  - ordered activation-step checklist.

 The `adapterId` prop is set by the manifest page entry
 (`props: { adapterId: "<slug>" }`) so the same component services
 every family without per-page boilerplate. The component is also
 robust to a missing prop — it then falls back to the trailing path
 segment of the current route so deep-links still work.

 @spec openspec/specs/bookkeeping-bank-connectors/spec.md
-->
<template>
	<NcAppContent>
		<div class="adapter-detail" :data-adapter-id="effectiveAdapterId">
			<NcLoadingIcon v-if="loading"
				:size="20"
				:name="t('shillinq', 'Loading adapter')" />

			<section v-else-if="errorMessage" class="adapter-detail__error" role="alert">
				{{ errorMessage }}
			</section>

			<template v-else-if="adapter">
				<header class="adapter-detail__header">
					<div class="adapter-detail__header-row">
						<h2 class="adapter-detail__title">
							{{ adapter.title }}
						</h2>
						<span class="adapter-detail__badge"
							:class="badgeClass"
							:data-state="adapter.dormant ? 'dormant' : 'live'">
							{{ adapter.dormant ? t('shillinq', 'Dormant') : t('shillinq', 'Live') }}
						</span>
					</div>
					<p class="adapter-detail__description">
						{{ adapter.description }}
					</p>
				</header>

				<section class="adapter-detail__facts">
					<div class="adapter-detail__fact">
						<span class="adapter-detail__fact-label">{{ t('shillinq', 'Category') }}</span>
						<span class="adapter-detail__fact-value">{{ adapter.category }}</span>
					</div>
					<div class="adapter-detail__fact">
						<span class="adapter-detail__fact-label">{{ t('shillinq', 'OpenSpec change') }}</span>
						<span class="adapter-detail__fact-value">{{ adapter.specSlug }}</span>
					</div>
					<div class="adapter-detail__fact">
						<span class="adapter-detail__fact-label">{{ t('shillinq', 'Requirements') }}</span>
						<span class="adapter-detail__fact-value">{{ (adapter.requirements || []).join(', ') }}</span>
					</div>
					<div class="adapter-detail__fact">
						<span class="adapter-detail__fact-label">{{ t('shillinq', 'Adapter interface') }}</span>
						<code class="adapter-detail__fact-value">{{ adapter.interface }}</code>
					</div>
					<div class="adapter-detail__fact">
						<span class="adapter-detail__fact-label">{{ t('shillinq', 'Log-only default') }}</span>
						<code class="adapter-detail__fact-value">{{ adapter.logClass }}</code>
					</div>
					<div class="adapter-detail__fact">
						<span class="adapter-detail__fact-label">{{ t('shillinq', 'openconnector source slug') }}</span>
						<code class="adapter-detail__fact-value">{{ adapter.sourceSlug }}</code>
					</div>
					<div class="adapter-detail__fact">
						<span class="adapter-detail__fact-label">{{ t('shillinq', 'Feature flag') }}</span>
						<code class="adapter-detail__fact-value">{{ adapter.featureFlag || t('shillinq', 'n/a') }}</code>
					</div>
					<div class="adapter-detail__fact">
						<span class="adapter-detail__fact-label">{{ t('shillinq', 'App-config keys') }}</span>
						<ul class="adapter-detail__keys">
							<li v-for="key in (adapter.configKeys || [])" :key="key">
								<code>{{ key }}</code>
							</li>
						</ul>
					</div>
				</section>

				<section class="adapter-detail__activation">
					<h3 class="adapter-detail__section-title">
						{{ t('shillinq', 'Activation steps') }}
					</h3>
					<ol class="adapter-detail__steps">
						<li v-for="(step, idx) in (adapter.steps || [])"
							:key="idx"
							class="adapter-detail__step">
							{{ step }}
						</li>
					</ol>
					<p v-if="adapter.dormant" class="adapter-detail__hint">
						{{ t('shillinq', 'This adapter is dormant. The surrounding lifecycle advances safely — submissions are recorded in the structured log but never sent to a third party — until the activation steps above are completed.') }}
					</p>
					<p v-else class="adapter-detail__hint adapter-detail__hint--live">
						{{ t('shillinq', 'This adapter is live. Submissions are sent to the configured third party. Audit oc_jobs + the relevant register lifecycle for delivery confirmations.') }}
					</p>
				</section>

				<section class="adapter-detail__back">
					<NcButton type="secondary" @click="backToIndex">
						{{ t('shillinq', 'Back to External Connections') }}
					</NcButton>
				</section>
			</template>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'ExternalAdapterDetail',

	components: {
		NcAppContent,
		NcButton,
		NcLoadingIcon,
	},

	props: {
		adapterId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			errorMessage: '',
			adapter: null,
		}
	},

	computed: {
		effectiveAdapterId() {
			if (this.adapterId) {
				return this.adapterId
			}
			// Fallback: trailing segment of the current path
			// (deep-link entry without the manifest props blob).
			const path = (this.$route?.path ?? '')
			const parts = path.split('/').filter(Boolean)
			return (parts.length > 0 ? parts[parts.length - 1] : '')
		},
		badgeClass() {
			if (!this.adapter) {
				return ''
			}
			return this.adapter.dormant
				? 'adapter-detail__badge--dormant'
				: 'adapter-detail__badge--live'
		},
	},

	watch: {
		effectiveAdapterId: {
			immediate: true,
			handler() {
				this.loadAdapter()
			},
		},
	},

	methods: {
		async loadAdapter() {
			const id = this.effectiveAdapterId
			if (!id) {
				this.errorMessage = t('shillinq', 'No adapter id provided.')
				this.loading = false
				return
			}
			this.loading = true
			this.errorMessage = ''
			this.adapter = null
			try {
				const url = generateUrl('/apps/shillinq/api/admin/external-adapters/{id}', { id })
				const { data } = await axios.get(url)
				this.adapter = data
			} catch (err) {
				if (err?.response?.status === 404) {
					this.errorMessage = t('shillinq', 'Unknown adapter: {id}', { id })
				} else {
					this.errorMessage = t('shillinq', 'Could not load adapter: {message}', { message: err?.message ?? 'unknown error' })
				}
			} finally {
				this.loading = false
			}
		},
		backToIndex() {
			this.$router.push({ name: 'ExternalAdaptersStatus' })
		},
	},
}
</script>

<style scoped>
.adapter-detail {
	padding: var(--default-grid-baseline, 8px);
	max-width: 900px;
}
.adapter-detail__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}
.adapter-detail__header-row {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	align-items: center;
}
.adapter-detail__title {
	margin: 0;
}
.adapter-detail__badge {
	padding: 4px 10px;
	border-radius: var(--border-radius-pill, 100px);
	font-weight: 600;
	font-size: 0.85em;
}
.adapter-detail__badge--dormant {
	background: var(--color-warning-rgba, rgba(255, 169, 0, 0.2));
	color: var(--color-warning, #b06c00);
}
.adapter-detail__badge--live {
	background: var(--color-success-rgba, rgba(46, 160, 67, 0.2));
	color: var(--color-success, #2ea043);
}
.adapter-detail__description {
	margin: var(--default-grid-baseline, 8px) 0 0 0;
	color: var(--color-text-maxcontrast);
}
.adapter-detail__facts {
	display: grid;
	grid-template-columns: minmax(0, 1fr);
	gap: var(--default-grid-baseline, 8px);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	background: var(--color-background-hover);
	border-radius: var(--border-radius, 6px);
}
.adapter-detail__fact {
	display: flex;
	gap: 16px;
	align-items: flex-start;
}
.adapter-detail__fact-label {
	flex: 0 0 220px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}
.adapter-detail__fact-value {
	flex: 1 1 auto;
	min-width: 0;
	word-break: break-all;
}
.adapter-detail__keys {
	list-style: none;
	padding: 0;
	margin: 0;
}
.adapter-detail__activation {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}
.adapter-detail__section-title {
	margin: 0 0 var(--default-grid-baseline, 8px) 0;
}
.adapter-detail__steps {
	padding-left: 20px;
	margin: 0 0 calc(var(--default-grid-baseline, 8px) * 2) 0;
}
.adapter-detail__step {
	margin-bottom: 6px;
}
.adapter-detail__hint {
	margin: var(--default-grid-baseline, 8px) 0 0 0;
	padding: var(--default-grid-baseline, 8px);
	border-left: 3px solid var(--color-warning, #b06c00);
	background: var(--color-warning-rgba, rgba(255, 169, 0, 0.1));
}
.adapter-detail__hint--live {
	border-left-color: var(--color-success, #2ea043);
	background: var(--color-success-rgba, rgba(46, 160, 67, 0.1));
}
.adapter-detail__error {
	color: var(--color-error);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}
.adapter-detail__back {
	margin-top: calc(var(--default-grid-baseline, 8px) * 2);
}
</style>
