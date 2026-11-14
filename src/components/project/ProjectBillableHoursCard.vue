<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

The Billable hours card on the project page (sales-billable-rate-per-hour
REQ-BRPH-003): one month of the project's hours with person, date, hours,
rate, tier, value and billed state, and the unbilled hours, unbilled value
and hours without a rate. Without an hours source it says the hours are not
available instead of showing zero. Rendered on ProjectDetail through the
slot widget-project-billable-hours.

@spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.2
-->
<template>
	<section class="pbh" data-testid="project-billable-hours">
		<div class="pbh__toolbar">
			<label class="pbh__month">
				<span>{{ t('shillinq', 'Month') }}</span>
				<input
					v-model="month"
					type="month"
					data-testid="pbh-month"
					@change="load">
			</label>
		</div>

		<p v-if="loading" class="pbh__muted" role="status">
			{{ t('shillinq', 'Loading the billable hours …') }}
		</p>
		<p v-else-if="error" class="pbh__error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="!available" class="pbh__muted" data-testid="pbh-unavailable">
			{{ t('shillinq', 'Hours are not available because the hours app is not connected.') }}
		</p>
		<p v-else-if="rows.length === 0" class="pbh__muted" data-testid="pbh-empty">
			{{ t('shillinq', 'No hours on this project in this month.') }}
		</p>
		<template v-else>
			<table class="pbh__table">
				<caption class="hidden-visually">
					{{ t('shillinq', 'Billable hours of {month}', { month }) }}
				</caption>
				<thead>
					<tr>
						<th scope="col">{{ t('shillinq', 'Person') }}</th>
						<th scope="col">{{ t('shillinq', 'Date') }}</th>
						<th scope="col" class="pbh__number">{{ t('shillinq', 'Hours') }}</th>
						<th scope="col" class="pbh__number">{{ t('shillinq', 'Rate') }}</th>
						<th scope="col">{{ t('shillinq', 'Tier') }}</th>
						<th scope="col" class="pbh__number">{{ t('shillinq', 'Value') }}</th>
						<th scope="col">{{ t('shillinq', 'Billed') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in rows"
						:key="row.id"
						:class="{ 'pbh__row--unrated': row.status !== 'rated' }"
						data-testid="pbh-row">
						<td>{{ row.person }}</td>
						<td>{{ row.date }}</td>
						<td class="pbh__number">{{ hoursText(row.hours) }}</td>
						<template v-if="row.status === 'rated'">
							<td class="pbh__number">{{ euro(row.rate) }}</td>
							<td>{{ tierLabel(row.tier) }}</td>
							<td class="pbh__number">{{ euro(row.value) }}</td>
						</template>
						<td v-else colspan="3" class="pbh__no-rate">
							<strong>{{ t('shillinq', 'No rate') }}</strong>
							<span v-if="row.message">: {{ row.message }}</span>
						</td>
						<td>{{ billedLabel(row) }}</td>
					</tr>
				</tbody>
			</table>
			<dl class="pbh__totals" data-testid="pbh-totals">
				<div>
					<dt>{{ t('shillinq', 'Unbilled hours') }}</dt>
					<dd>{{ hoursText(totals.unbilledHours) }}</dd>
				</div>
				<div>
					<dt>{{ t('shillinq', 'Unbilled value') }}</dt>
					<dd>{{ euro(totals.unbilledValue) }}</dd>
				</div>
				<div>
					<dt>{{ t('shillinq', 'Hours without a rate') }}</dt>
					<dd>{{ hoursText(totals.unratedHours) }}</dd>
				</div>
			</dl>
		</template>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	billedLabel,
	euro,
	hoursText,
	loadProjectBillableHours,
	monthOf,
	tierLabel,
} from '../../utils/billableRate.js'

export default {
	name: 'ProjectBillableHoursCard',

	props: {
		/** The project's id, bound from the route by CnDetailPage. */
		objectId: {
			type: [String, Number],
			default: '',
		},
	},

	data() {
		return {
			month: monthOf(new Date()),
			loading: false,
			error: '',
			available: true,
			rows: [],
			totals: { unbilledHours: 0, unbilledValue: 0, unratedHours: 0 },
		}
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.2 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,
		billedLabel,
		euro,
		hoursText,
		tierLabel,

		/**
		 * Read the chosen month of the project's rated hours.
		 *
		 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.2
		 */
		async load() {
			if (!this.objectId) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const data = await loadProjectBillableHours(String(this.objectId), this.month)
				this.available = data.available === true
				this.rows = Array.isArray(data.rows) ? data.rows : []
				this.totals = { ...this.totals, ...(data.totals || {}) }
			} catch {
				this.error = t('shillinq', 'The billable hours could not be loaded.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.pbh__toolbar {
	display: flex;
	justify-content: flex-end;
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.pbh__month {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: center;
}

.pbh__table {
	width: 100%;
	border-collapse: collapse;
}

.pbh__table th,
.pbh__table td {
	padding: calc(var(--default-grid-baseline) * 1) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.pbh__number {
	text-align: end !important;
	font-variant-numeric: tabular-nums;
}

.pbh__row--unrated {
	box-shadow: inset 3px 0 0 0 var(--color-warning);
}

.pbh__no-rate {
	color: var(--color-main-text);
}

.pbh__totals {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 6);
	margin-top: calc(var(--default-grid-baseline) * 3);
}

.pbh__totals dt {
	color: var(--color-text-maxcontrast);
}

.pbh__totals dd {
	margin: 0;
	font-weight: bold;
}

.pbh__muted {
	color: var(--color-text-maxcontrast);
}

.pbh__error {
	color: var(--color-error-text);
}
</style>
