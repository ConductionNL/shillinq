<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RegenerateKnownCostsModal: the operator-triggered run that turns recurring
 and contract costs into budget lines (budget-known-costs design.md §12,
 REQ-BKC-004, REQ-BKC-005, REQ-BKC-007).

 The run writes a budget line per ledger group and source for every year with
 a default budget. A line changed by hand is left alone, and a year without a
 default budget is named rather than created. The dialog shows the counts.

 Opened with spawnDialog from the "Regenerate known costs" header action on
 Budget Line Derivations (src/utils/knownCostActions.js), so it lives in its
 own file (hydra gate-13).

 @spec openspec/changes/archive/2026-11-24-budget-known-costs/specs/budget-known-costs/spec.md
-->

<template>
	<NcDialog
		:name="t('shillinq', 'Regenerate known costs')"
		size="normal"
		data-testid="regenerate-known-costs-modal"
		@closing="close">
		<div class="rkc">
			<p v-if="!result" class="rkc__hint">
				{{
					t(
						'shillinq',
						'Recurring and contract costs are written into the default budget of every year they touch. Lines you changed by hand are left alone.',
					)
				}}
			</p>

			<ul v-else class="rkc__summary" data-testid="regenerate-known-costs-summary">
				<li v-for="line in lines" :key="line">
					{{ line }}
				</li>
			</ul>

			<p
				v-if="error"
				class="rkc__error"
				role="alert"
				data-testid="regenerate-known-costs-error">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="close">
				{{ result ? t('shillinq', 'Close') : t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!result"
				variant="primary"
				:disabled="submitting"
				data-testid="regenerate-known-costs-submit"
				@click="submit">
				{{ t('shillinq', 'Regenerate') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog } from '@nextcloud/vue'
import {
	regenerateErrorText,
	regenerateKnownCosts,
	summaryLines,
} from '../utils/knownCostApi.js'

export default {
	name: 'RegenerateKnownCostsModal',

	components: { NcButton, NcDialog },

	emits: ['close'],

	data() {
		return {
			submitting: false,
			result: null,
			error: '',
		}
	},

	computed: {
		/**
		 * The result as sentences.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/archive/2026-11-24-budget-known-costs/design.md
		 */
		lines() {
			return this.result ? summaryLines(this.result) : []
		},
	},

	methods: {
		t,

		/**
		 * Run the regeneration for the active administration.
		 *
		 * @spec openspec/changes/archive/2026-11-24-budget-known-costs/design.md
		 */
		async submit() {
			this.error = ''
			this.submitting = true
			try {
				this.result = await regenerateKnownCosts()
			} catch (error) {
				this.error = regenerateErrorText(error)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close the dialog.
		 *
		 * @spec openspec/changes/archive/2026-11-24-budget-known-costs/design.md
		 */
		close() {
			this.$emit('close', this.result)
		},
	},
}
</script>

<style scoped>
.rkc {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.rkc__hint {
	color: var(--color-text-maxcontrast);
}

.rkc__summary {
	margin: 0;
	padding-inline-start: 20px;
	list-style: disc;
}

.rkc__error {
	color: var(--color-error-text);
}
</style>
