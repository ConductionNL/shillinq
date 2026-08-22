<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SpendAnalyticsPanel — the first and only frontend consumer of
  `GET /apps/shillinq/api/analytics/spend`.

  The endpoint has been fully implemented and unit-tested since
  `spend-analytics` landed, and had ZERO consumers: `grep -rn
  "analytics/spend" src/` returned nothing and no `src/manifest.d/` page
  declared it. This panel renders all four of its dimensions —
  supplier / category / costCentre / period — on the `SpendAnalytics`
  dashboard page.

  ⚠️ WHY THIS IS A CUSTOM kind:"widget" AND NOT A DECLARATIVE type:"chart"
  ------------------------------------------------------------------------
  Because of how this endpoint FAILS, not how it succeeds.

  `glline-administration-scope` (REQ-GLS-003) makes the three GL-backed
  views — category, cost centre, period — RAISE while the
  `GLLine.administrationId` backfill is unproven, which the controller
  turns into HTTP 500. That raise is the whole point of the requirement: a
  filter over a half-backfilled ledger matches nothing for the un-backfilled
  rows, so the alternative to raising is a silent zero in a bookkeeping
  total — a wrong number that looks like a real one.

  The library's declarative widgets cannot render that state honestly:

   - `CnChartWidget` subscribes to `useEndpointSource` but keeps only
     `ep.data` / `ep.refetch` and DISCARDS `ep.error` (see its `setup()`).
     A 500 therefore reaches the user as `emptyLabel` — "no data" — which
     is precisely the silent-zero reading REQ-GLS-003 exists to forbid.
   - `CnStatWidget` / `CnDeltaWidget` do surface the error, but as a bare
     `—` whose only explanation is a `title` tooltip, with no test id and
     nothing a keyboard or screen-reader user reaches.

  So this panel keeps FOUR distinct, individually rendered states per view —
  loading / unavailable / no-rows / rows — and never prints a figure for a
  view that did not answer. A view that failed shows no total at all.

  @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
-->
<template>
	<div class="spend-analytics" data-testid="spend-analytics-panel">
		<!-- Administration context. The endpoint REQUIRES administration_id
		     and masks a non-member as 404, so the panel says which
		     administration it is reporting on rather than leaving the reader
		     to assume "all of them". -->
		<p
			v-if="contextState === 'loading'"
			class="spend-analytics__muted"
			data-testid="spend-analytics-context-loading">
			{{ t('shillinq', 'Loading administration context…') }}
		</p>
		<p
			v-else-if="contextState === 'error'"
			class="spend-analytics__error"
			data-testid="spend-analytics-context-error"
			role="alert">
			{{
				t(
					'shillinq',
					'The administration context could not be loaded, so no spend figures were requested.',
				)
			}}
			<span class="spend-analytics__detail">{{ contextError }}</span>
		</p>
		<p
			v-else-if="contextState === 'none'"
			class="spend-analytics__error"
			data-testid="spend-analytics-no-administration"
			role="alert">
			{{
				t(
					'shillinq',
					'You are not a member of any administration, so there is no spend to report on.',
				)
			}}
		</p>
		<p
			v-else
			class="spend-analytics__context"
			data-testid="spend-analytics-administration">
			{{ t('shillinq', 'Administration') }}:
			<strong>{{ administrationLabel }}</strong>
		</p>

		<div class="spend-analytics__views">
			<section
				v-for="view in dimensions"
				:key="view.key"
				class="spend-analytics__view"
				:data-testid="`spend-analytics-view-${view.key}`">
				<h3 class="spend-analytics__view-title">
					{{ titleFor(view.key) }}
				</h3>

				<p
					v-if="stateFor(view.key) === 'loading'"
					class="spend-analytics__muted"
					:data-testid="`spend-analytics-loading-${view.key}`">
					{{ t('shillinq', 'Loading…') }}
				</p>

				<!-- UNAVAILABLE. Deliberately renders no number of any kind:
				     the request did not answer, so there is nothing to show,
				     and a 0 here would be the exact failure REQ-GLS-003
				     forbids. -->
				<div
					v-else-if="stateFor(view.key) === 'error'"
					class="spend-analytics__unavailable"
					:data-testid="`spend-analytics-error-${view.key}`"
					role="alert">
					<p class="spend-analytics__unavailable-headline">
						{{
							t(
								'shillinq',
								'This view is unavailable — no figure is shown because none could be trusted.',
							)
						}}
					</p>
					<p
						class="spend-analytics__detail"
						:data-testid="`spend-analytics-error-detail-${view.key}`">
						{{ errorFor(view.key) }}
					</p>
					<p v-if="isGlBacked(view.key)" class="spend-analytics__detail">
						{{
							t(
								'shillinq',
								'The category, cost centre and period views read GLLine. They refuse to report until the GLLine administrationId backfill is proven complete, because filtering on a property some rows still lack would silently exclude those rows and report a zero total as though it were a measurement.',
							)
						}}
					</p>
				</div>

				<!-- NO ROWS. A different statement from the one above, and it
				     must stay different: this one IS a measurement — the
				     aggregation ran and matched nothing. -->
				<p
					v-else-if="stateFor(view.key) === 'empty'"
					class="spend-analytics__muted"
					:data-testid="`spend-analytics-empty-${view.key}`">
					{{
						t(
							'shillinq',
							'The aggregation ran and matched no rows for this administration.',
						)
					}}
				</p>

				<div v-else :data-testid="`spend-analytics-rows-${view.key}`">
					<p
						class="spend-analytics__total"
						:data-testid="`spend-analytics-total-${view.key}`">
						{{ t('shillinq', 'Total') }}:
						{{ formatAmount(totalFor(view.key)) }}
					</p>
					<table
						class="spend-analytics__table"
						:data-testid="`spend-analytics-table-${view.key}`">
						<thead>
							<tr>
								<th scope="col">
									{{ view.groupLabel }}
								</th>
								<th scope="col" class="spend-analytics__num">
									{{ t('shillinq', 'Amount') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="(group, index) in groupsFor(view.key)"
								:key="`${view.key}-${index}`">
								<td>{{ keyLabel(group.key) }}</td>
								<td class="spend-analytics__num">
									{{ formatAmount(group.amount) }}
								</td>
							</tr>
						</tbody>
					</table>
					<p
						class="spend-analytics__detail"
						:data-testid="`spend-analytics-backend-${view.key}`">
						{{ t('shillinq', 'Computed by') }}:
						{{ backendFor(view.key) }}
					</p>
				</div>
			</section>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The four dimensions the endpoint serves, in the order
 * `SpendAnalyticsController::DIMENSIONS` declares them. `key` is the literal
 * `dimension` query-parameter value — the controller rejects anything else
 * with a 400, so these strings are the contract, not a display choice.
 *
 * `glBacked` marks the three views sourced from `GLLine` rather than
 * `APTransaction`; only those are subject to the REQ-GLS-003 backfill gate.
 *
 * @type {Array<{key: string, title: string, groupLabel: string, glBacked: boolean}>}
 */
export const SPEND_DIMENSIONS = [
	{
		key: 'supplier',
		title: 'Spend by supplier',
		groupLabel: 'Supplier',
		glBacked: false,
	},
	{
		key: 'category',
		title: 'Spend by category',
		groupLabel: 'GL account',
		glBacked: true,
	},
	{
		key: 'costCentre',
		title: 'Spend by cost centre',
		groupLabel: 'Cost centre',
		glBacked: true,
	},
	{
		key: 'period',
		title: 'Spend by period',
		groupLabel: 'Period',
		glBacked: true,
	},
]

export default {
	name: 'SpendAnalyticsPanel',

	data() {
		return {
			/** `loading` | `ready` | `none` | `error`. */
			contextState: 'loading',
			contextError: '',
			administrationId: null,
			administrationLabel: '',
			/**
			 * Per-dimension fetch state, keyed by dimension. Each entry is
			 * `{ status, payload, error }` with `status` in
			 * `idle|loading|ok|error`. `payload` is only ever set on `ok`,
			 * so no render path can reach a figure from a failed request.
			 *
			 * @type {Record<string, {status: string, payload: ?object, error: string}>}
			 */
			views: SPEND_DIMENSIONS.reduce((acc, d) => {
				acc[d.key] = { status: 'idle', payload: null, error: '' }
				return acc
			}, {}),
		}
	},

	computed: {
		/**
		 * The dimension descriptors, exposed to the template.
		 *
		 * @return {Array<object>} The four dimensions.
		 * @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
		 */
		dimensions() {
			return SPEND_DIMENSIONS
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Translated heading for one dimension. Kept client-side as well as
		 * server-side: the endpoint returns a translated `label`, but a view
		 * that FAILED returns no payload at all, and an unavailable view
		 * still needs a heading saying which view is unavailable.
		 *
		 * @param {string} key The dimension key.
		 * @return {string} The translated title.
		 * @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
		 */
		titleFor(key) {
			const view = SPEND_DIMENSIONS.find((d) => d.key === key)
			return view ? t('shillinq', view.title) : key
		},

		/**
		 * Whether this dimension is sourced from `GLLine` and therefore
		 * subject to the REQ-GLS-003 backfill gate.
		 *
		 * @param {string} key The dimension key.
		 * @return {boolean} True for category / costCentre / period.
		 * @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
		 */
		isGlBacked(key) {
			const view = SPEND_DIMENSIONS.find((d) => d.key === key)
			return Boolean(view && view.glBacked)
		},

		/**
		 * Render state for one dimension:
		 * `loading` | `error` | `empty` | `rows`.
		 *
		 * `empty` and `error` are DIFFERENT states and must never collapse
		 * into one another: `empty` is a successful aggregation that matched
		 * nothing, `error` is a request that produced no measurement at all.
		 *
		 * @param {string} key The dimension key.
		 * @return {string} The render state.
		 * @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
		 */
		stateFor(key) {
			const view = this.views[key]
			if (!view || view.status === 'idle' || view.status === 'loading') {
				return 'loading'
			}
			if (view.status === 'error') {
				return 'error'
			}
			const groups = (view.payload && view.payload.groups) || []
			return groups.length === 0 ? 'empty' : 'rows'
		},

		/**
		 * The server's own explanation for a failed view, never a
		 * paraphrase — the controller's error envelope is `{ error: … }`.
		 *
		 * @param {string} key The dimension key.
		 * @return {string} The message to show.
		 * @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
		 */
		errorFor(key) {
			const view = this.views[key]
			return (view && view.error) || t('shillinq', 'The request failed.')
		},

		/**
		 * Groups of a SUCCESSFUL view. Returns an empty array for any other
		 * state, so no caller can read rows off a failed request.
		 *
		 * @param {string} key The dimension key.
		 * @return {Array<{key: (string|number|null), amount: number}>} The groups.
		 * @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
		 */
		groupsFor(key) {
			const view = this.views[key]
			if (!view || view.status !== 'ok') {
				return []
			}
			return (view.payload && view.payload.groups) || []
		},

		/**
		 * Total of a SUCCESSFUL view, or `null` when the view did not
		 * answer. Returning null rather than 0 is the point: 0 is a
		 * measurement and this is the absence of one.
		 *
		 * @param {string} key The dimension key.
		 * @return {?number} The total, or null.
		 * @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
		 */
		totalFor(key) {
			const view = this.views[key]
			if (!view || view.status !== 'ok') {
				return null
			}
			const total = view.payload && view.payload.total
			return typeof total === 'number' ? total : null
		},

		/**
		 * Which engine computed a successful view (`backend` in the
		 * envelope) — the endpoint reports it, so surface it rather than
		 * dropping it.
		 *
		 * @param {string} key The dimension key.
		 * @return {string} The backend name.
		 * @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
		 */
		backendFor(key) {
			const view = this.views[key]
			if (!view || view.status !== 'ok') {
				return ''
			}
			return (view.payload && view.payload.backend) || 'unknown'
		},

		/**
		 * Label for one group key. The endpoint groups by a raw scalar
		 * (`vendorId`, `accountNumber`, `costCenterCode`, `periodId`) and
		 * OpenRegister returns null for rows whose group field is unset —
		 * which must read as "unassigned", not as an empty cell.
		 *
		 * @param {(string|number|null|undefined)} value The raw group key.
		 * @return {string} The display label.
		 * @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
		 */
		keyLabel(value) {
			if (value === null || value === undefined || value === '') {
				return t('shillinq', '(unassigned)')
			}
			return String(value)
		},

		/**
		 * Format a euro amount. Returns an em dash for null so a missing
		 * measurement is never rendered as `€0.00`.
		 *
		 * @param {?number} amount The amount.
		 * @return {string} The formatted amount.
		 * @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
		 */
		formatAmount(amount) {
			if (typeof amount !== 'number' || Number.isNaN(amount)) {
				return '—'
			}
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: 'EUR',
			}).format(amount)
		},

		/**
		 * Read the caller's administration context, then fetch every
		 * dimension. Each dimension is requested independently so one
		 * failing view (the expected shape while the GLLine backfill is
		 * unproven) never suppresses the three that answer.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
		 */
		async load() {
			const administrationId = await this.loadAdministrationContext()
			if (!administrationId) {
				return
			}
			await Promise.all(
				SPEND_DIMENSIONS.map((d) =>
					this.loadDimension(d.key, administrationId),
				),
			)
		},

		/**
		 * Resolve the administration to report on from
		 * `GET /api/administrations/context`. The spend endpoint requires
		 * `administration_id` and masks a non-member as 404, so a caller
		 * with no administration is a first-class state, not an error.
		 *
		 * @return {Promise<?string>} The administration id, or null.
		 * @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
		 */
		async loadAdministrationContext() {
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/administrations/context'),
				)
				const administrations = response.data?.administrations || []
				const active =
					response.data?.activeAdministrationId
					|| (administrations[0] && administrations[0].administrationId)
					|| null
				if (!active) {
					this.contextState = 'none'
					return null
				}
				const match = administrations.find(
					(a) => a.administrationId === active,
				)
				this.administrationId = active
				this.administrationLabel =
					(match && (match.name || match.administrationCode)) || active
				this.contextState = 'ready'
				return active
			} catch (e) {
				this.contextState = 'error'
				this.contextError = this.readError(e)
				return null
			}
		},

		/**
		 * Fetch one dimension. A non-2xx response leaves `payload` null, so
		 * the error render path is the only one reachable afterwards.
		 *
		 * @param {string} key The dimension key.
		 * @param {string} administrationId The administration to scope to.
		 * @return {Promise<void>}
		 * @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
		 */
		async loadDimension(key, administrationId) {
			this.views[key] = { status: 'loading', payload: null, error: '' }
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/analytics/spend'),
					{
						params: {
							administration_id: administrationId,
							dimension: key,
						},
					},
				)
				this.views[key] = {
					status: 'ok',
					payload: response.data,
					error: '',
				}
			} catch (e) {
				this.views[key] = {
					status: 'error',
					payload: null,
					error: this.readError(e),
				}
			}
		},

		/**
		 * Pull the server's own message out of an axios failure. The
		 * controller's envelope is `{ error: "<message>" }` for every
		 * non-2xx status it produces; anything else falls back to the
		 * transport message so the reader still gets a cause.
		 *
		 * @param {(Error & {response?: {data?: {error?: string}}})} e The thrown error.
		 * @return {string} A human-readable cause.
		 * @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
		 */
		readError(e) {
			const serverMessage = e?.response?.data?.error
			if (typeof serverMessage === 'string' && serverMessage !== '') {
				return serverMessage
			}
			return (e && e.message) || t('shillinq', 'Unknown error')
		},
	},
}
</script>

<style scoped>
.spend-analytics {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 12px;
}

.spend-analytics__views {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: 16px;
}

.spend-analytics__view {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 12px;
}

.spend-analytics__view-title {
	margin: 0 0 8px;
	font-size: 1rem;
	font-weight: 600;
}

.spend-analytics__muted {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.spend-analytics__context {
	margin: 0;
}

.spend-analytics__error,
.spend-analytics__unavailable {
	color: var(--color-error);
	margin: 0;
}

.spend-analytics__unavailable {
	border-left: 4px solid var(--color-error);
	padding-left: 8px;
}

.spend-analytics__unavailable-headline {
	margin: 0 0 4px;
	font-weight: 600;
}

.spend-analytics__detail {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 4px 0 0;
}

.spend-analytics__total {
	margin: 0 0 8px;
	font-weight: 600;
}

.spend-analytics__table {
	width: 100%;
	border-collapse: collapse;
}

.spend-analytics__table th,
.spend-analytics__table td {
	text-align: left;
	padding: 4px 6px;
	border-bottom: 1px solid var(--color-border);
}

.spend-analytics__num {
	text-align: right;
}
</style>
