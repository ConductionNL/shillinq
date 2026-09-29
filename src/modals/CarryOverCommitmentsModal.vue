<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CarryOverCommitmentsModal: carry a year's open commitments to the next year
 (planning-commitment-year-end, REQ-PCYE-004).

 The controller picks the year that ends and sees every open line with what
 remains, and per programme how much the next year's budget cannot absorb.
 Confirming writes a line in the next year under the same commitment number
 and closes the old one. A second run finds nothing.

 Opened with spawnDialog from the header action on the commitments register
 (src/utils/commitmentYearEndApi.js), so it lives in its own file (gate 13).

 @spec openspec/changes/planning-commitment-year-end/specs/bookkeeping-verplichtingenadministratie/spec.md
-->

<template>
	<NcDialog
		:name="t('shillinq', 'Carry open commitments to next year')"
		size="normal"
		data-testid="carry-over-modal"
		@closing="close">
		<div class="coc">
			<NcTextField
				v-model="fromYear"
				type="number"
				:label="t('shillinq', 'Year that ends')"
				:disabled="done"
				data-testid="carry-over-year"
				@change="load" />

			<template v-if="preview">
				<p v-if="!preview.lines.length" data-testid="carry-over-nothing">
					{{
						t('shillinq', 'No open commitment lines in {year}.', {
							year: preview.fromYear,
						})
					}}
				</p>
				<template v-else>
					<p>
						{{
							t(
								'shillinq',
								'{count} lines continue in {year}, EUR {total} in total.',
								{
									count: preview.lines.length,
									year: preview.toYear,
									total: euro(preview.total),
								},
							)
						}}
					</p>
					<ul class="coc__lines" data-testid="carry-over-lines">
						<li v-for="line in preview.lines" :key="line.lineId">
							{{ line.commitmentNumber }}, {{ line.programme }}: EUR
							{{ euro(line.remaining) }}
						</li>
					</ul>
					<template v-if="preview.shortfalls.length">
						<h3 class="coc__title">
							{{
								t('shillinq', 'Budgets that fall short in {year}', {
									year: preview.toYear,
								})
							}}
						</h3>
						<ul
							class="coc__shortfalls"
							data-testid="carry-over-shortfalls">
							<li
								v-for="item in preview.shortfalls"
								:key="item.programme">
								{{
									t(
										'shillinq',
										'Programme {programme} is EUR {shortfall} short ({commitments}).',
										{
											programme: item.programme,
											shortfall: euro(item.shortfall),
											commitments: item.commitments.join(', '),
										},
									)
								}}
							</li>
						</ul>
					</template>
				</template>
			</template>

			<p v-if="done" data-testid="carry-over-done">
				{{
					t('shillinq', 'The open commitments now continue in {year}.', {
						year: preview.toYear,
					})
				}}
			</p>
			<p
				v-if="error"
				class="coc__error"
				role="alert"
				data-testid="carry-over-error">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="close">
				{{ done ? t('shillinq', 'Close') : t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!done"
				variant="primary"
				:disabled="!preview || !preview.lines.length || submitting"
				data-testid="carry-over-submit"
				@click="submit">
				{{ t('shillinq', 'Carry over') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import { carryOver, euro, previewCarryOver } from '../utils/commitmentYearEndApi.js'
import { errorMessage } from '../utils/downPaymentApi.js'

export default {
	name: 'CarryOverCommitmentsModal',

	components: { NcButton, NcDialog, NcTextField },

	emits: ['close'],

	data() {
		return {
			administrationId: '',
			fromYear: String(new Date().getFullYear()),
			preview: null,
			done: false,
			submitting: false,
			error: '',
		}
	},

	/**
	 * Load the administration and the preview.
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-3.2
	 */
	async mounted() {
		try {
			const context = await axios.get(
				generateUrl('/apps/shillinq/api/administrations/context'),
			)
			this.administrationId = String(
				context.data?.activeAdministrationId || '',
			)
			await this.load()
		} catch (error) {
			this.error = errorMessage(
				error,
				t('shillinq', 'The administration could not be loaded.'),
			)
		}
	},

	methods: {
		t,
		euro,

		/**
		 * The preview for the chosen year.
		 *
		 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-3.2
		 */
		async load() {
			this.error = ''
			try {
				this.preview = await previewCarryOver(
					this.administrationId,
					Number(this.fromYear),
				)
			} catch (error) {
				this.preview = null
				this.error = errorMessage(
					error,
					t('shillinq', 'The open commitments could not be loaded.'),
				)
			}
		},

		/**
		 * Carry the lines over.
		 *
		 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-3.2
		 */
		async submit() {
			this.submitting = true
			this.error = ''
			try {
				this.preview = await carryOver(
					this.administrationId,
					Number(this.fromYear),
				)
				this.done = true
			} catch (error) {
				this.error = errorMessage(
					error,
					t('shillinq', 'The commitments could not be carried over.'),
				)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close the dialog.
		 *
		 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-3.2
		 */
		close() {
			this.$emit('close', this.done)
		},
	},
}
</script>

<style scoped>
.coc {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.coc__title {
	font-size: 1em;
	margin: 0;
}

.coc__error {
	color: var(--color-error);
}
</style>
