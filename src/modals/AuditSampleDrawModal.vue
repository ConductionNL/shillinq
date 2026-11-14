<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 AuditSampleDrawModal: draw an audit sample for a protocol from its posted
 ledger lines (public-sector-council-and-auditor-statements 2.2, REQ-011).

 Asks the sample size, an optional seed and an optional account prefix, and
 posts them to AuditSampleController::draw. The same seed over the same
 population draws the same lines, so the answer shows the seed to keep. A
 refusal (a size beyond the population) is shown as the server words it.

 Opened by the Draw sample header action on the audit protocol page
 (src/utils/auditSampleApi.js) through spawnDialog. Its own file for hydra
 gate-13.

 @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
-->

<template>
	<NcDialog
		:name="t('shillinq', 'Draw sample')"
		size="small"
		data-testid="audit-sample-draw-modal"
		@closing="onClose">
		<div class="asd">
			<p>
				{{ t('shillinq', 'The sample is drawn from the posted ledger lines of the audit year. Enter a seed to draw the same lines again.') }}
			</p>
			<NcTextField
				v-model="size"
				type="number"
				:label="t('shillinq', 'Number of lines')"
				data-testid="audit-sample-size" />
			<NcTextField
				v-model="seed"
				type="number"
				:label="t('shillinq', 'Seed (optional)')"
				data-testid="audit-sample-seed" />
			<NcTextField
				v-model="accountPrefix"
				:label="t('shillinq', 'Account number starts with (optional)')"
				data-testid="audit-sample-prefix" />
			<p
				v-if="drawn"
				class="asd__result"
				role="status"
				data-testid="audit-sample-result">
				{{ resultText }}
			</p>
			<p
				v-if="error"
				class="asd__error"
				role="alert"
				data-testid="audit-sample-error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="onClose">
				{{ drawn ? t('shillinq', 'Close') : t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="submitting"
				data-testid="audit-sample-submit"
				@click="submit">
				{{ t('shillinq', 'Draw') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import { drawAuditSample, drawFields, sampleError } from '../utils/auditSampleApi.js'

export default {
	name: 'AuditSampleDrawModal',

	components: { NcButton, NcDialog, NcTextField },

	props: {
		// The Controleprotocol id.
		protocolId: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			size: '25',
			seed: '',
			accountPrefix: '',
			submitting: false,
			drawn: null,
			error: '',
		}
	},

	computed: {
		/**
		 * What the draw stored: the number of lines, the population and the seed.
		 *
		 * @return {string}
		 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
		 */
		resultText() {
			if (!this.drawn) {
				return ''
			}
			return t('shillinq', '{count} of {population} lines drawn with seed {seed}.', {
				count: (this.drawn.sampledLineIds || []).length,
				population: this.drawn.populationSize,
				seed: this.drawn.reproducibleSeed,
			})
		},
	},

	methods: {
		t,

		/**
		 * Post the draw and show what was stored.
		 *
		 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
		 */
		async submit() {
			this.error = ''
			const fields = drawFields({ size: this.size, seed: this.seed, accountPrefix: this.accountPrefix })
			if (fields === null) {
				this.error = t('shillinq', 'The number of lines and the seed are positive whole numbers.')
				return
			}
			this.submitting = true
			try {
				this.drawn = await drawAuditSample(this.protocolId, fields)
				this.seed = String(this.drawn.reproducibleSeed || '')
			} catch (error) {
				this.error = sampleError(error)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close, with the sample when one was drawn.
		 *
		 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
		 */
		onClose() {
			this.$emit('close', this.drawn)
		},
	},
}
</script>

<style scoped>
.asd {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.asd__error {
	color: var(--color-error-text);
}

.asd__result {
	color: var(--color-success-text);
}
</style>
