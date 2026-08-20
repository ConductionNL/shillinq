<!--
  Cashflow Dashboard Skeleton

  ⚠️ THIS COMPONENT IS AN ORPHAN AND ITS "Export PDF" BUTTON IS NOT THE ONE
  THAT WORKS. Nothing imports this file, it has no registry entry and no
  manifest binding, so it never mounts; its @click only $emit's 'export-pdf'
  and nothing listens. It is left in place because its crisis-banner and
  scenario-switcher skeletons are still unspent design, but it must not be
  mistaken for the implementation.

  The live REQ-CF-016 affordance (#865) is the declarative
  `config.headerActions[]` entry on the CashflowDashboard page in
  src/manifest.json: an api-call action with `download: true` that POSTs
  /apps/shillinq/api/cashflow/export-pdf and saves the returned PDF. Its
  chain is CashflowExportController -> CashflowExportService ->
  CashflowPdfRenderer::renderPdf().

  Per ADR-031: the dashboard is rendered by the manifest-v2 page (type: dashboard)
  using widgets backed by x-openregister-aggregations. This component is a thin
  skeleton mounted by the manifest renderer for any host that wants a
  per-component override (e.g. embedded preview). All business logic lives in
  schema aggregations on CashflowForecastHorizon / CashflowWeek.

  REQ-CF-015 — 13-week bar chart, buffer zone, alerts, scenario switcher.
  REQ-CF-010 — crisis-mode banner conditional on weeks 1-4 negative saldo.
  REQ-CF-016 — Export PDF action.

  @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-25
-->
<template>
	<div class="cashflow-dashboard">
		<div
			v-if="crisisActive"
			class="cashflow-dashboard__crisis-banner"
			role="alert">
			{{
				t(
					'shillinq',
					'CRISIS ACTIVE: predicted negative saldo within 4 weeks. Review action suggestions below.',
				)
			}}
		</div>

		<div class="cashflow-dashboard__header">
			<h2>{{ t('shillinq', '13-Week Cashflow Forecast') }}</h2>
			<div class="cashflow-dashboard__actions">
				<select
					v-model="selectedScenarioId"
					class="cashflow-dashboard__scenario-switcher"
					:aria-label="t('shillinq', 'Switch scenario')">
					<option value="baseline">
						{{ t('shillinq', 'Baseline') }}
					</option>
					<option
						v-for="scenario in scenarios"
						:key="scenario.scenarioId"
						:value="scenario.scenarioId">
						{{ scenario.name }}
					</option>
				</select>
				<button
					type="button"
					class="cashflow-dashboard__export-btn"
					@click="exportPdf">
					{{ t('shillinq', 'Export PDF') }}
				</button>
			</div>
		</div>

		<!-- The chart itself is rendered by the manifest widget; this is a placeholder slot. -->
		<div
			class="cashflow-dashboard__chart-slot"
			data-widget="cashflow-13week-chart">
			<slot name="chart" />
		</div>

		<div v-if="selectedWeek" class="cashflow-dashboard__week-detail">
			<h3>{{ t('shillinq', 'Week') }} {{ selectedWeek.weekNumber }}</h3>
			<ul>
				<li>
					{{ t('shillinq', 'Inflows AR') }}:
					{{ selectedWeek.inflows_ar_geprognosticeerd }}
				</li>
				<li>
					{{ t('shillinq', 'Outflows AP') }}:
					{{ selectedWeek.outflows_ap_geprognosticeerd }}
				</li>
				<li>
					{{ t('shillinq', 'Net Mutatie') }}:
					{{ selectedWeek.netMovement }}
				</li>
				<li>
					{{ t('shillinq', 'Eind Saldo') }}:
					{{ selectedWeek.closingBalance }}
				</li>
			</ul>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'CashflowDashboard',

	props: {
		horizon: {
			type: Object,
			required: true,
		},

		weeks: {
			type: Array,
			default: () => [],
		},

		scenarios: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			selectedScenarioId: 'baseline',
			selectedWeek: null,
		}
	},

	computed: {
		crisisActive() {
			if (Array.isArray(this.weeks) === false) {
				return false
			}
			const leading = this.weeks.slice(0, 4)
			return leading.some((w) => Number(w.closingBalance) < 0)
		},
	},

	methods: {
		t,
		exportPdf() {
			this.$emit('export-pdf', {
				horizonId: this.horizon.horizonId,
				scenarioId: this.selectedScenarioId,
			})
		},
	},
}
</script>

<style scoped>
.cashflow-dashboard {
	padding: 1rem;
}

.cashflow-dashboard__crisis-banner {
	background-color: var(--color-error, #e9322d);
	color: var(--color-main-background, #fff);
	padding: 0.75rem 1rem;
	border-radius: var(--border-radius, 4px);
	margin-bottom: 1rem;
	font-weight: bold;
}

.cashflow-dashboard__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1rem;
}

.cashflow-dashboard__actions {
	display: flex;
	gap: 0.5rem;
}

.cashflow-dashboard__chart-slot {
	min-height: 300px;
	background-color: var(--color-background-hover, #f5f5f5);
	border: 1px dashed var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
	display: flex;
	align-items: center;
	justify-content: center;
}

.cashflow-dashboard__week-detail {
	margin-top: 1rem;
}
</style>
