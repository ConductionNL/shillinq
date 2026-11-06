<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 DunningNextRunModal: what the next daily dunning run would send, per
 invoice, and how the last run went (receivables-automatic-dunning 4.1,
 REQ-RAD-008).

 The rows come from DunningPreviewController::nextRun, which asks the same
 stage choice as the job and writes nothing. Opened by the Next run header
 action on Dunning runs (every administration the caller may see) and by
 Switch on reminders on an administration, which shows this list first and
 switches reminders on only when the bookkeeper confirms.

 Opened through spawnDialog from src/utils/dunningActions.js. Its own file
 for hydra gate-13.

 @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
-->

<template>
	<NcDialog
		:name="title"
		size="large"
		data-testid="dunning-next-run-modal"
		@closing="onClose">
		<div class="dnr">
			<p v-if="loading" data-testid="dunning-next-run-loading">
				{{ t('shillinq', 'Loading the next run…') }}
			</p>
			<p
				v-if="error"
				class="dnr__error"
				role="alert"
				data-testid="dunning-next-run-error">
				{{ error }}
			</p>
			<p v-if="!loading && !error && administrations.length === 0">
				{{ t('shillinq', 'You have no administration to show.') }}
			</p>
			<section
				v-for="administration in administrations"
				:key="administration.administrationId"
				class="dnr__administration"
				data-testid="dunning-next-run-administration">
				<h3>{{ administration.name || administration.administrationId }}</h3>
				<p>
					{{
						administration.enabled
							? t('shillinq', 'Automatic reminders are on.')
							: t(
									'shillinq',
									'Automatic reminders are off. Nothing is sent until you switch them on.',
								)
					}}
				</p>
				<p data-testid="dunning-next-run-report">
					{{ reportLine(administration.lastRun) }}
				</p>
				<p v-if="(administration.rows || []).length === 0">
					{{
						t('shillinq', 'No invoice gets a reminder in the next run.')
					}}
				</p>
				<table v-else class="dnr__table">
					<caption>
						{{
							t('shillinq', 'Reminders the next run would send')
						}}
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{{ t('shillinq', 'Invoice') }}
							</th>
							<th scope="col">
								{{ t('shillinq', 'Customer') }}
							</th>
							<th scope="col">
								{{ t('shillinq', 'Due date') }}
							</th>
							<th scope="col">
								{{ t('shillinq', 'Stage') }}
							</th>
							<th scope="col">
								{{ t('shillinq', 'Channel') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in administration.rows" :key="row.invoiceId">
							<td>{{ row.invoiceNumber || row.invoiceId }}</td>
							<td>{{ row.customer }}</td>
							<td>{{ row.dueDate }}</td>
							<td>{{ row.stageNr }}</td>
							<td>{{ channelLabel(row.channel) }}</td>
						</tr>
					</tbody>
				</table>
			</section>
		</div>
		<template #actions>
			<NcButton @click="onClose">
				{{ switchOn ? t('shillinq', 'Cancel') : t('shillinq', 'Close') }}
			</NcButton>
			<NcButton
				v-if="switchOn"
				variant="primary"
				:disabled="loading || submitting || !target || target.enabled"
				data-testid="dunning-switch-on-submit"
				@click="submit">
				{{ t('shillinq', 'Switch on reminders') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog } from '@nextcloud/vue'
import {
	channelLabel,
	dunningError,
	fetchNextRun,
	reportLine,
	switchOnDunning,
} from '../utils/dunningApi.js'

export default {
	name: 'DunningNextRunModal',

	components: { NcButton, NcDialog },

	props: {
		// The administration to show, or '' for every one the caller may see.
		administrationId: {
			type: String,
			default: '',
		},

		// Whether the dialog ends in switching reminders on.
		switchOn: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],

	data() {
		return {
			administrations: [],
			loading: false,
			submitting: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The dialog title.
		 *
		 * @return {string}
		 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
		 */
		title() {
			return this.switchOn
				? t('shillinq', 'Check the next run, then switch reminders on')
				: t('shillinq', 'Next run')
		},

		/**
		 * The administration Switch on reminders acts on.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
		 */
		target() {
			return this.administrations[0] ?? null
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		channelLabel,
		reportLine,

		/**
		 * Read the preview and the last run.
		 *
		 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.administrations = await fetchNextRun(this.administrationId)
			} catch (error) {
				this.error = dunningError(
					error,
					t('shillinq', 'The next run could not be loaded.'),
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Switch reminders on for the administration shown.
		 *
		 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
		 */
		async submit() {
			if (!this.target) {
				return
			}
			this.submitting = true
			this.error = ''
			try {
				await switchOnDunning(this.target.id || this.administrationId)
				showSuccess(
					t(
						'shillinq',
						'Reminders are on. The next daily run sends the list above.',
					),
				)
				this.$emit('close', true)
			} catch (error) {
				this.error = dunningError(
					error,
					t('shillinq', 'Reminders could not be switched on.'),
				)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close without switching anything on.
		 *
		 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
		 */
		onClose() {
			this.$emit('close', false)
		},
	},
}
</script>

<style scoped>
.dnr {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.dnr__table {
	width: 100%;
	border-collapse: collapse;
}

.dnr__table th,
.dnr__table td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.dnr__error {
	color: var(--color-error-text);
}
</style>
