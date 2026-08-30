<!--
  StandardsPolicyEditor — Settings → Accounting standards.

  Lets an administrator declare which accounting/reporting frameworks the
  administration follows and in which order of precedence (REQ-ASP-002), saved
  as a single StandardsPolicy object via the OpenRegister objects API. The
  array order IS the precedence order (top = highest priority); precedence is
  written as the 1-based index on save. The resolver preview mirrors
  StandardsPolicyService.resolveFromPolicy — the first enabled framework wins.

  @spec openspec/specs/accounting-standards-policy/spec.md
-->
<template>
	<!--
	  The root carries the component's own name as a test id so a browser test
	  can assert THIS page mounted, rather than inferring it from a class name
	  or a heading string that a restyle or a copy change could move. The route
	  alone is not enough: an unresolved route lands on the SPA shell, which
	  renders a page with no error of any kind.
	-->
	<div class="standards-policy" data-testid="StandardsPolicyEditor">
		<div class="standards-policy__header">
			<h2>{{ t('shillinq', 'Accounting standards') }}</h2>
			<p class="standards-policy__intro">
				{{
					t(
						'shillinq',
						'Declare which accounting and reporting frameworks this administration follows, and drag them into order of precedence. When frameworks disagree on a treatment (revenue, leases, inventory, …), business logic follows the highest-ranked enabled framework.',
					)
				}}
			</p>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" class="standards-policy__loading" />

		<template v-else>
			<ul class="standards-policy__list" data-testid="standards-policy-list">
				<li
					v-for="(row, index) in rows"
					:key="row.key"
					class="standards-policy__row"
					:class="{ 'standards-policy__row--enabled': row.enabled }"
					:data-testid="`standards-policy-row-${row.key}`">
					<span class="standards-policy__rank">{{ index + 1 }}</span>
					<NcCheckboxRadioSwitch
						:modelValue="row.enabled"
						type="switch"
						@update:modelValue="setEnabled(index, $event)">
						<span class="standards-policy__label">{{ row.label }}</span>
					</NcCheckboxRadioSwitch>
					<a
						:href="row.docs"
						target="_blank"
						rel="noopener noreferrer"
						class="standards-policy__docs"
						:aria-label="
							t(
								'shillinq',
								'Read about {standard} (opens in a new tab)',
								{ standard: row.label },
							)
						"
						:title="t('shillinq', 'Read about this standard')">
						<OpenInNew :size="16" />
					</a>
					<span class="standards-policy__spacer" />
					<NcButton
						variant="tertiary"
						:aria-label="t('shillinq', 'Move up')"
						:disabled="index === 0"
						@click="moveUp(index)">
						<template #icon>
							<ArrowUp :size="20" />
						</template>
					</NcButton>
					<NcButton
						variant="tertiary"
						:aria-label="t('shillinq', 'Move down')"
						:disabled="index === rows.length - 1"
						@click="moveDown(index)">
						<template #icon>
							<ArrowDown :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>

			<div
				class="standards-policy__resolved"
				data-testid="standards-policy-resolved">
				<span class="standards-policy__resolved-label">{{
					t('shillinq', 'Resolved framework (highest enabled):')
				}}</span>
				<strong>{{ resolvedLabel }}</strong>
			</div>

			<div class="standards-policy__actions">
				<NcButton variant="primary" :disabled="saving" @click="save">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ t('shillinq', 'Save policy') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

const REGISTER_SLUG = 'shillinq'
const SCHEMA_SLUG = 'StandardsPolicy'
const DOCS_BASE = 'https://shillinq.conduction.nl/standards/'

/**
 * Canonical framework catalogue — keys mirror the StandardsPolicy schema enum
 * and the docs/standards/ pages. Order here is the default precedence.
 */
const FRAMEWORKS = [
	{ key: 'ifrs', label: 'IFRS / IAS (IASB)', docs: `${DOCS_BASE}ifrs` },
	{
		key: 'ifrs-eu',
		label: 'IFRS (EU-endorsed)',
		docs: `${DOCS_BASE}eu-national-gaap#eu-endorsed-ifrs`,
	},
	{
		key: 'dutch-gaap',
		label: 'Dutch GAAP (BW Title 9 + RJ)',
		docs: `${DOCS_BASE}dutch-gaap`,
	},
	{
		key: 'de-hgb',
		label: 'German HGB (+ SKR / DRS)',
		docs: `${DOCS_BASE}eu-national-gaap#german-hgb`,
	},
	{
		key: 'fr-pcg',
		label: 'French PCG (ANC)',
		docs: `${DOCS_BASE}eu-national-gaap#french-pcg`,
	},
	{
		key: 'it-oic',
		label: 'Italian GAAP (OIC)',
		docs: `${DOCS_BASE}eu-national-gaap#italian-oic`,
	},
	{
		key: 'es-pgc',
		label: 'Spanish PGC (ICAC)',
		docs: `${DOCS_BASE}eu-national-gaap#spanish-pgc`,
	},
	{
		key: 'dutch-tax',
		label: 'Dutch tax (goed koopmansgebruik)',
		docs: `${DOCS_BASE}dutch-gaap#deep-dive--goed-koopmansgebruik-fiscal-accounting`,
	},
	{ key: 'us-gaap', label: 'US GAAP (FASB ASC)', docs: `${DOCS_BASE}us-gaap` },
	{
		key: 'us-tax-basis',
		label: 'US income-tax basis (IRC)',
		docs: `${DOCS_BASE}us-gaap#special-purpose-frameworks-ocboa`,
	},
	{
		key: 'us-cash-basis',
		label: 'US cash basis (OCBOA)',
		docs: `${DOCS_BASE}us-gaap#special-purpose-frameworks-ocboa`,
	},
	{
		key: 'us-modified-cash',
		label: 'US modified-cash basis',
		docs: `${DOCS_BASE}us-gaap#special-purpose-frameworks-ocboa`,
	},
	{
		key: 'us-frf-smes',
		label: 'US FRF for SMEs (AICPA)',
		docs: `${DOCS_BASE}us-gaap#special-purpose-frameworks-ocboa`,
	},
	{
		key: 'ipsas',
		label: 'IPSAS (public sector)',
		docs: `${DOCS_BASE}public-sector`,
	},
	{
		key: 'bbv',
		label: 'Dutch BBV (municipal)',
		docs: `${DOCS_BASE}public-sector#dutch-bbv`,
	},
	{
		key: 'us-gasb',
		label: 'US GASB (state & local gov)',
		docs: `${DOCS_BASE}public-sector#us-gasb`,
	},
	{
		key: 'us-fasab',
		label: 'US FASAB (federal gov)',
		docs: `${DOCS_BASE}public-sector#us-fasab`,
	},
	{
		key: 'esrs',
		label: 'ESRS (CSRD sustainability)',
		docs: `${DOCS_BASE}sustainability`,
	},
	{
		key: 'ifrs-sustainability',
		label: 'IFRS S1 / S2 (ISSB)',
		docs: `${DOCS_BASE}sustainability#ifrs-sustainability-issb`,
	},
]

export default {
	name: 'StandardsPolicyEditor',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		ArrowUp,
		ArrowDown,
		ContentSave,
		OpenInNew,
	},

	data() {
		return {
			loading: true,
			saving: false,
			recordId: null,
			rows: FRAMEWORKS.map((f) => ({ ...f, enabled: false })),
		}
	},

	computed: {
		/** @return {string} Label of the highest-ranked enabled framework, or a dash. */
		resolvedLabel() {
			const winner = this.rows.find((r) => r.enabled)
			return winner ? winner.label : '—'
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/** Load the existing StandardsPolicy object (if any) and order rows by precedence. */
		async load() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/${SCHEMA_SLUG}`,
					),
					{ params: { _limit: 1 } },
				)
				const rows = response.data?.results ?? response.data?.objects ?? []
				const policy =
					Array.isArray(rows) && rows.length > 0 ? rows[0] : null
				if (policy) {
					this.recordId = policy['@self']?.id ?? policy.id ?? null
					this.applyPolicy(
						Array.isArray(policy.frameworks) ? policy.frameworks : [],
					)
				}
			} catch (e) {
				// No saved policy yet (or the schema is not seeded) — start from
				// the default catalogue. This is the expected first-use state, so
				// it is not surfaced as an error.
				// eslint-disable-next-line no-console
				console.debug(
					'[StandardsPolicyEditor] no existing policy; using defaults',
					e,
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Merge a persisted frameworks[] list into rows, ordered by precedence.
		 *
		 * @param saved
		 */
		applyPolicy(saved) {
			const byKey = new Map(saved.map((f) => [f.key, f]))
			const precedenceOf = (key) => {
				const p = byKey.get(key)?.precedence
				return Number.isFinite(p) ? p : Number.MAX_SAFE_INTEGER
			}
			this.rows = FRAMEWORKS.map((f) => ({
				key: f.key,
				label: f.label,
				docs: f.docs,
				enabled: byKey.get(f.key)?.enabled === true,
			})).sort((a, b) => precedenceOf(a.key) - precedenceOf(b.key))
		},

		setEnabled(index, value) {
			// Vue 3's reactivity is a Proxy — a plain assignment on an existing
			// reactive object is tracked, so `this.$set` (removed in Vue 3) has
			// nothing left to do.
			this.rows[index].enabled = value
		},

		moveUp(index) {
			if (index <= 0) return
			const rows = this.rows.slice()
			;[rows[index - 1], rows[index]] = [rows[index], rows[index - 1]]
			this.rows = rows
		},

		moveDown(index) {
			if (index >= this.rows.length - 1) return
			const rows = this.rows.slice()
			;[rows[index + 1], rows[index]] = [rows[index], rows[index + 1]]
			this.rows = rows
		},

		/** Persist the policy: array order becomes 1-based precedence. */
		async save() {
			this.saving = true
			const payload = {
				name: 'Accounting standards policy',
				frameworks: this.rows.map((row, index) => ({
					key: row.key,
					enabled: row.enabled,
					precedence: index + 1,
				})),
			}
			try {
				if (this.recordId) {
					await axios.put(
						generateUrl(
							`/apps/openregister/api/objects/${REGISTER_SLUG}/${SCHEMA_SLUG}/${this.recordId}`,
						),
						payload,
					)
				} else {
					const response = await axios.post(
						generateUrl(
							`/apps/openregister/api/objects/${REGISTER_SLUG}/${SCHEMA_SLUG}`,
						),
						payload,
					)
					this.recordId =
						response.data?.['@self']?.id
						?? response.data?.id
						?? this.recordId
				}
				showSuccess(this.t('shillinq', 'Standards policy saved.'))
			} catch (e) {
				showError(this.t('shillinq', 'Failed to save the standards policy.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.standards-policy {
	max-width: 720px;
	padding: 24px;
}

.standards-policy__header h2 {
	margin: 0 0 4px;
}

.standards-policy__intro {
	color: var(--color-text-maxcontrast, #767676);
	margin: 0 0 20px;
}

.standards-policy__loading {
	margin: 40px auto;
}

.standards-policy__list {
	list-style: none;
	padding: 0;
	margin: 0;
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius-large, 10px);
	overflow: hidden;
}

.standards-policy__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 12px;
	border-bottom: 1px solid var(--color-border, #ededed);
}

.standards-policy__row:last-child {
	border-bottom: none;
}

.standards-policy__row--enabled {
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.08));
}

.standards-policy__rank {
	width: 22px;
	text-align: center;
	font-weight: 700;
	color: var(--color-text-maxcontrast, #767676);
}

.standards-policy__label {
	font-weight: 500;
}

.standards-policy__docs {
	display: inline-flex;
	color: var(--color-text-maxcontrast, #767676);
}

.standards-policy__spacer {
	flex: 1 1 auto;
}

.standards-policy__resolved {
	display: flex;
	gap: 8px;
	align-items: baseline;
	margin: 16px 0;
	padding: 12px 16px;
	border-radius: var(--border-radius-large, 10px);
	background-color: var(--color-background-hover, #f5f5f5);
}

.standards-policy__resolved-label {
	color: var(--color-text-maxcontrast, #767676);
}

.standards-policy__actions {
	margin-top: 8px;
}
</style>
