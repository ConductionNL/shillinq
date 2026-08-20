<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Budget scenario comparison — standalone, day-one comparison surface for
 `budget-scenarios` (REQ-BSC-008, design.md §9). A plain table (LedgerGroup
 rows × month columns × base/scenario/delta), NOT the spreadsheet grid —
 `budget-grid-view` builds the grid-embedded overlay separately, sequenced
 after this change (design.md §10).

 Registered as a kind:"page" custom component (manifest `type: "custom"`)
 rather than a declarative dashboard/detail page: the comparison needs an
 administration + fiscal-year + scenario PICKER composed with a bespoke
 three-row-per-LedgerGroup table, neither of which fits the built-in
 index/detail/dashboard page types — mirrors the AccountantPortalDashboard /
 BBVComplianceDashboard precedent in registry.js.

 No `:id` route param: reachable as a plain top-level nav entry
 (`/begroting/scenarios/compare`) rather than requiring the operator to
 first open a scenario's own detail page — the picker defaults to the
 administration's own DEFAULT scenario (REQ-BSC-002's own zero-default
 convention: when none is default, no scenario is pre-selected and the
 operator chooses one explicitly).

 @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
-->
<template>
	<NcAppContent>
		<div class="budget-scenario-comparison">
			<header class="budget-scenario-comparison__header">
				<h2 class="budget-scenario-comparison__title">
					{{ t('shillinq', 'Scenario comparison') }}
				</h2>
				<p class="budget-scenario-comparison__description">
					{{
						t(
							'shillinq',
							'Compare a what-if scenario side-by-side against the real budget. The real AnnualBudget and BudgetLine data is never changed by this page.',
						)
					}}
				</p>
			</header>

			<div class="budget-scenario-comparison__controls">
				<div class="budget-scenario-comparison__field">
					<label for="budget-scenario-comparison-administration">
						{{ t('shillinq', 'Administration') }}
					</label>
					<select
						id="budget-scenario-comparison-administration"
						v-model="administrationId"
						data-testid="budget-scenario-comparison-administration"
						@change="onAdministrationChange">
						<option
							v-for="option in administrationOptions"
							:key="option.value"
							:value="option.value">
							{{ option.label }}
						</option>
					</select>
				</div>

				<div class="budget-scenario-comparison__field">
					<label for="budget-scenario-comparison-fiscal-year">
						{{ t('shillinq', 'Fiscal year') }}
					</label>
					<input
						id="budget-scenario-comparison-fiscal-year"
						v-model.number="fiscalYear"
						type="number"
						data-testid="budget-scenario-comparison-fiscal-year"
						@change="onFiscalYearChange" />
				</div>

				<div class="budget-scenario-comparison__field">
					<label for="budget-scenario-comparison-scenario">
						{{ t('shillinq', 'Scenario') }}
					</label>
					<select
						id="budget-scenario-comparison-scenario"
						v-model="scenarioId"
						data-testid="budget-scenario-comparison-scenario"
						@change="loadComparison">
						<option value="">
							{{ t('shillinq', 'Select a scenario…') }}
						</option>
						<option
							v-for="scenario in scenarios"
							:key="scenario.id"
							:value="scenario.id">
							{{ scenarioLabel(scenario) }}
						</option>
					</select>
				</div>
			</div>

			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('shillinq', 'Loading comparison')" />
			<NcEmptyContent
				v-else-if="!scenarios.length"
				:name="t('shillinq', 'No scenarios yet')"
				:description="
					t(
						'shillinq',
						'Create a BudgetScenario and at least one BudgetScenarioModifier to see a comparison here.',
					)
				" />
			<NcEmptyContent
				v-else-if="!scenarioId"
				:name="t('shillinq', 'Select a scenario to compare')" />
			<p
				v-else-if="errorMessage"
				class="budget-scenario-comparison__error"
				role="alert">
				{{ errorMessage }}
			</p>
			<div v-else class="budget-scenario-comparison__table-wrap">
				<table
					class="budget-scenario-comparison__table"
					data-testid="budget-scenario-comparison-table">
					<caption class="budget-scenario-comparison__caption">
						{{
							t(
								'shillinq',
								'Base vs. scenario vs. delta, per ledger group and month (EUR).',
							)
						}}
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{{ t('shillinq', 'Ledger group') }}
							</th>
							<th scope="col">
								{{ t('shillinq', 'Row') }}
							</th>
							<th
								v-for="month in monthLabels"
								:key="month"
								scope="col">
								{{ month }}
							</th>
						</tr>
					</thead>
					<tbody>
						<template
							v-for="group in groupedRows"
							:key="group.ledgerGroupId">
							<tr
								v-for="rowKind in ['base', 'scenario', 'delta']"
								:key="group.ledgerGroupId + '-' + rowKind"
								:class="{
									'budget-scenario-comparison__row--delta':
										rowKind === 'delta',
								}">
								<th
									v-if="rowKind === 'base'"
									scope="rowgroup"
									:rowspan="3">
									{{ group.name }}
								</th>
								<td v-else />
								<th scope="row">
									{{ rowLabel(rowKind) }}
								</th>
								<td
									v-for="(amount, index) in group[rowKind]"
									:key="index"
									class="budget-scenario-comparison__amount">
									{{ formatCents(amount) }}
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'

const REGISTER_SLUG = 'shillinq'

export default {
	name: 'BudgetScenarioComparison',

	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			errorMessage: '',
			administrationOptions: [],
			administrationId: '',
			fiscalYear: new Date().getFullYear(),
			scenarios: [],
			scenarioId: '',
			ledgerGroupNames: {},
			cells: [],
		}
	},

	computed: {
		/**
		 * Month header labels, "Jan".."Dec".
		 *
		 * @return {Array<string>}
		 *
		 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
		 */
		monthLabels() {
			return [
				this.t('shillinq', 'Jan'),
				this.t('shillinq', 'Feb'),
				this.t('shillinq', 'Mar'),
				this.t('shillinq', 'Apr'),
				this.t('shillinq', 'May'),
				this.t('shillinq', 'Jun'),
				this.t('shillinq', 'Jul'),
				this.t('shillinq', 'Aug'),
				this.t('shillinq', 'Sep'),
				this.t('shillinq', 'Oct'),
				this.t('shillinq', 'Nov'),
				this.t('shillinq', 'Dec'),
			]
		},

		/**
		 * `this.cells` (flat, keyed `"{ledgerGroupId}:{YYYY-MM}"`) grouped
		 * into one row-set per LedgerGroup, each carrying 12-long
		 * base/scenario/delta arrays in calendar order.
		 *
		 * @return {Array<object>}
		 *
		 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
		 */
		groupedRows() {
			const byGroup = new Map()
			for (const cell of this.cells) {
				if (!byGroup.has(cell.ledgerGroupId)) {
					byGroup.set(cell.ledgerGroupId, {
						ledgerGroupId: cell.ledgerGroupId,
						name:
							this.ledgerGroupNames[cell.ledgerGroupId]
							|| cell.ledgerGroupId,
						base: new Array(12).fill(0),
						scenario: new Array(12).fill(0),
						delta: new Array(12).fill(0),
					})
				}
				const monthIndex = Number(String(cell.month).slice(-2)) - 1
				const row = byGroup.get(cell.ledgerGroupId)
				if (monthIndex >= 0 && monthIndex < 12) {
					row.base[monthIndex] = cell.base
					row.scenario[monthIndex] = cell.scenario
					row.delta[monthIndex] = cell.delta
				}
			}
			return Array.from(byGroup.values()).sort((a, b) =>
				a.name.localeCompare(b.name),
			)
		},
	},

	mounted() {
		this.loadAdministrationContext()
	},

	methods: {
		/**
		 * Load the authenticated user's accessible administrations, defaulting
		 * to the active one — the same `/api/administrations/context` pattern
		 * BBVComplianceDashboard already establishes.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
		 */
		async loadAdministrationContext() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/administrations/context'),
				)
				const admins = response.data?.administrations || []
				this.administrationOptions = admins.map((a) => ({
					value: a.administrationId,
					label: a.name || a.administrationCode || a.administrationId,
				}))
				this.administrationId =
					response.data?.activeAdministrationId
					|| this.administrationOptions[0]?.value
					|| ''
			} catch {
				this.errorMessage = this.t(
					'shillinq',
					'Failed to load administration context',
				)
			}

			if (this.administrationId) {
				await Promise.all([
					this.loadScenarios(),
					this.loadLedgerGroupNames(),
				])
			}
			this.loading = false
		},

		/**
		 * Re-load scenarios + LedgerGroup names for the newly-picked
		 * administration, and reset the scenario/comparison selection.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
		 */
		async onAdministrationChange() {
			this.scenarioId = ''
			this.cells = []
			this.loading = true
			await Promise.all([this.loadScenarios(), this.loadLedgerGroupNames()])
			this.loading = false
		},

		/**
		 * Re-evaluate the current scenario for the newly-picked fiscal year.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
		 */
		onFiscalYearChange() {
			if (this.scenarioId) {
				this.loadComparison()
			}
		},

		/**
		 * Load every BudgetScenario for the current administration, defaulting
		 * the picker to the administration's own `isDefault: true` scenario
		 * (REQ-BSC-002) when one exists.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
		 */
		async loadScenarios() {
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/BudgetScenario`,
					),
					{
						params: {
							administrationId: this.administrationId,
							limit: 500,
						},
					},
				)
				const rows =
					response.data?.results
					?? response.data?.objects
					?? response.data
					?? []
				this.scenarios = Array.isArray(rows) ? rows : []
				const defaultScenario = this.scenarios.find(
					(s) => s.isDefault === true,
				)
				this.scenarioId = defaultScenario?.id || ''
				if (this.scenarioId) {
					await this.loadComparison()
				}
			} catch {
				this.scenarios = []
				this.errorMessage = this.t('shillinq', 'Failed to load scenarios')
			}
		},

		/**
		 * Load LedgerGroup id -> name for the current administration, used to
		 * render human-readable row headers.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-009
		 */
		async loadLedgerGroupNames() {
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/LedgerGroup`,
					),
					{
						params: {
							administrationId: this.administrationId,
							limit: 500,
						},
					},
				)
				const rows =
					response.data?.results
					?? response.data?.objects
					?? response.data
					?? []
				const names = {}
				for (const row of Array.isArray(rows) ? rows : []) {
					const id = row.id || row['@self']?.id
					if (id) {
						names[id] = row.name || row.code || id
					}
				}
				this.ledgerGroupNames = names
			} catch {
				this.ledgerGroupNames = {}
			}
		},

		/**
		 * Evaluate the currently-selected scenario for the currently-selected
		 * fiscal year, via `BudgetScenarioController::evaluate()` (which
		 * delegates to `BudgetScenarioReader` + `BudgetScenarioEvaluator`).
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-005
		 */
		async loadComparison() {
			if (!this.scenarioId) {
				this.cells = []
				return
			}

			this.errorMessage = ''
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/shillinq/api/v1/budget-scenarios/${this.scenarioId}/evaluate`,
					),
					{
						params: {
							administration_id: this.administrationId,
							fiscal_year: this.fiscalYear,
						},
					},
				)
				this.cells = response.data?.data?.cells || []
			} catch {
				this.cells = []
				this.errorMessage = this.t(
					'shillinq',
					'Failed to evaluate this scenario',
				)
			}
			this.loading = false
		},

		/**
		 * Display label for a scenario option — flags the default scenario.
		 *
		 * @param {object} scenario - The BudgetScenario row.
		 * @return {string}
		 *
		 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
		 */
		scenarioLabel(scenario) {
			if (scenario.isDefault) {
				return this.t('shillinq', '{name} (default)', {
					name: scenario.name,
				})
			}
			return scenario.name || scenario.id
		},

		/**
		 * Localized row label for base/scenario/delta.
		 *
		 * @param {string} rowKind - One of 'base' | 'scenario' | 'delta'.
		 * @return {string}
		 *
		 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
		 */
		rowLabel(rowKind) {
			if (rowKind === 'base') {
				return this.t('shillinq', 'Base')
			}
			if (rowKind === 'scenario') {
				return this.t('shillinq', 'Scenario')
			}
			return this.t('shillinq', 'Delta')
		},

		/**
		 * Format a signed EUR-cents integer as a euro amount.
		 *
		 * @param {number} cents - The signed amount in EUR cents.
		 * @return {string}
		 *
		 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
		 */
		formatCents(cents) {
			return ((Number(cents) || 0) / 100).toLocaleString(undefined, {
				style: 'currency',
				currency: 'EUR',
			})
		},
	},
}
</script>

<style scoped>
.budget-scenario-comparison {
	padding: 20px;
}

.budget-scenario-comparison__controls {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin-block-end: 16px;
}

.budget-scenario-comparison__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.budget-scenario-comparison__table-wrap {
	overflow-x: auto;
}

.budget-scenario-comparison__table {
	border-collapse: collapse;
	min-width: 100%;
}

.budget-scenario-comparison__table th,
.budget-scenario-comparison__table td {
	border: 1px solid var(--color-border);
	padding: 4px 8px;
	text-align: end;
	white-space: nowrap;
}

.budget-scenario-comparison__table th[scope='col'],
.budget-scenario-comparison__table th[scope='rowgroup'] {
	text-align: start;
}

.budget-scenario-comparison__row--delta {
	font-weight: bold;
}

.budget-scenario-comparison__error {
	color: var(--color-error);
}
</style>
