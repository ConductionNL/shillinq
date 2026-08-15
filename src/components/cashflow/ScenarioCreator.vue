<!--
  Scenario Creator Skeleton

  Lets the operator compose a CashflowScenario with one or more adjustments
  (AR override, recurring pause, new revenue, buffer override) and triggers
  the OR aggregation recompute on save. The recompute itself is declarative
  — this component only emits the new scenario payload.

  REQ-CF-011 — scenario branching.

  @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-26
-->
<template>
	<form class="scenario-creator" @submit.prevent="handleSubmit">
		<h2>{{ t('shillinq', 'Create scenario') }}</h2>

		<label class="scenario-creator__field">
			<span>{{ t('shillinq', 'Scenario name') }}</span>
			<input
				v-model="scenario.name"
				type="text"
				required
				:placeholder="t('shillinq', 'e.g. Acme pays late by 4 weeks')" />
		</label>

		<label class="scenario-creator__field">
			<span>{{ t('shillinq', 'Description') }}</span>
			<textarea v-model="scenario.description" rows="3" />
		</label>

		<fieldset class="scenario-creator__adjustments">
			<legend>{{ t('shillinq', 'Adjustments') }}</legend>

			<div
				v-for="(adjustment, index) in scenario.aanpassingen"
				:key="index"
				class="scenario-creator__adjustment">
				<select
					v-model="adjustment.type"
					:aria-label="t('shillinq', 'Adjustment type')">
					<option value="AR_PROJECTION_OVERRIDE">
						{{ t('shillinq', 'AR Override') }}
					</option>
					<option value="RECURRING_COST_ADJUSTMENT">
						{{ t('shillinq', 'Recurring Adjustment') }}
					</option>
					<option value="NEW_REVENUE">
						{{ t('shillinq', 'New Revenue') }}
					</option>
					<option value="BUFFER_POLICY_OVERRIDE">
						{{ t('shillinq', 'Buffer Override') }}
					</option>
				</select>

				<input
					v-if="adjustment.type === 'AR_PROJECTION_OVERRIDE'"
					v-model="adjustment.arInvoiceId"
					type="text"
					:aria-label="t('shillinq', 'AR invoice ID')"
					:placeholder="t('shillinq', 'AR invoice ID')" />
				<input
					v-if="adjustment.type === 'AR_PROJECTION_OVERRIDE'"
					v-model.number="adjustment.weekShift"
					type="number"
					:aria-label="t('shillinq', 'Week shift')"
					:placeholder="t('shillinq', 'Week shift')" />
				<input
					v-if="adjustment.type === 'AR_PROJECTION_OVERRIDE'"
					v-model.number="adjustment.kansFromPayment"
					type="number"
					min="0"
					max="1"
					step="0.05"
					:aria-label="t('shillinq', 'Probability (0-1)')"
					:placeholder="t('shillinq', 'Probability (0-1)')" />

				<input
					v-if="adjustment.type === 'RECURRING_COST_ADJUSTMENT'"
					v-model="adjustment.recurId"
					type="text"
					:aria-label="t('shillinq', 'Recurring ID')"
					:placeholder="t('shillinq', 'Recurring ID')" />
				<select
					v-if="adjustment.type === 'RECURRING_COST_ADJUSTMENT'"
					v-model="adjustment.adjustmentType"
					:aria-label="t('shillinq', 'Adjustment direction')">
					<option value="PAUSE">
						{{ t('shillinq', 'Pause') }}
					</option>
					<option value="INCREASE">
						{{ t('shillinq', 'Increase') }}
					</option>
					<option value="DECREASE">
						{{ t('shillinq', 'Decrease') }}
					</option>
				</select>

				<input
					v-if="adjustment.type === 'NEW_REVENUE'"
					v-model="adjustment.name"
					type="text"
					:aria-label="t('shillinq', 'Deal name')"
					:placeholder="t('shillinq', 'Deal name')" />
				<input
					v-if="adjustment.type === 'NEW_REVENUE'"
					v-model.number="adjustment.amount"
					type="number"
					step="0.01"
					:aria-label="t('shillinq', 'Amount EUR')"
					:placeholder="t('shillinq', 'Amount EUR')" />
				<input
					v-if="adjustment.type === 'NEW_REVENUE'"
					v-model.number="adjustment.probability"
					type="number"
					min="0"
					max="1"
					step="0.05"
					:aria-label="t('shillinq', 'Probability')"
					:placeholder="t('shillinq', 'Probability')" />
				<input
					v-if="adjustment.type === 'NEW_REVENUE'"
					v-model.number="adjustment.expectedReceiptWeek"
					type="number"
					:aria-label="t('shillinq', 'Expected week')"
					:placeholder="t('shillinq', 'Expected week')" />

				<input
					v-if="adjustment.type === 'BUFFER_POLICY_OVERRIDE'"
					v-model.number="adjustment.bufferAmount"
					type="number"
					step="0.01"
					:aria-label="t('shillinq', 'Buffer EUR')"
					:placeholder="t('shillinq', 'Buffer EUR')" />

				<button type="button" @click="removeAdjustment(index)">
					{{ t('shillinq', 'Remove') }}
				</button>
			</div>

			<button type="button" @click="addAdjustment">
				+ {{ t('shillinq', 'Add adjustment') }}
			</button>
		</fieldset>

		<div class="scenario-creator__actions">
			<button type="submit">
				{{ t('shillinq', 'Calculate') }}
			</button>
		</div>
	</form>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'ScenarioCreator',

	props: {
		horizonId: {
			type: String,
			required: true,
		},

		administrationId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			scenario: {
				horizonId: this.horizonId,
				name: '',
				description: '',
				aanpassingen: [],
				administrationId: this.administrationId,
			},
		}
	},

	methods: {
		t,
		addAdjustment() {
			this.scenario.aanpassingen.push({
				type: 'AR_PROJECTION_OVERRIDE',
			})
		},

		removeAdjustment(index) {
			this.scenario.aanpassingen.splice(index, 1)
		},

		handleSubmit() {
			this.$emit('create-scenario', {
				...this.scenario,
				createdAt: new Date().toISOString(),
			})
		},
	},
}
</script>

<style scoped>
.scenario-creator {
	padding: 1rem;
}

.scenario-creator__field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	margin-bottom: 0.75rem;
}

.scenario-creator__adjustments {
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
	padding: 0.75rem;
	margin-bottom: 1rem;
}

.scenario-creator__adjustment {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	padding: 0.5rem 0;
	border-bottom: 1px solid var(--color-border-dark, #eee);
}
</style>
