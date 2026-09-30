<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 GenerateReportDialog — modal isolation of the report-generation flow
 (reporting-compliance-consolidation). Triggered by the "Generate" button
 on a report card in ReportingComplianceOverview.vue. Collects the period,
 administration and output format the report needs, then POSTs
 /api/reporting/generate.

 Modal isolation per hydra gate-13: the dialog lives in its own .vue file
 under src/modals/ and is imported by ReportingComplianceOverview.vue. It
 must never be inlined into the parent.

 Unlike a pure presentation dialog the generate flow owns its own network
 call (the parent only reacts to the result), because the dialog assembles
 a self-contained generation context (reportType + period + administration
 + format) that the parent does not otherwise hold. On success the
 dialog emits `generated` with the endpoint response (id + downloadUrl +
 fileName) so the parent can toast a download link; on failure it surfaces
 the error inline and stays open.

 @spec exclude The reporting capability has no canonical spec. This tag pointed at
       openspec/changes/reporting-compliance-consolidation (a change directory that
       exists neither under changes nor under changes/archive), and no canonical
       reporting capability exists under openspec/specs either. Tracked in #525.
       Deliberately NOT resolved by writing that spec — authoring the requirement
       a tag is checked against turns the gate green over an unspecified capability.

 KNOWINGLY DANGLING — do not repoint this tag at a spec (gate-46, shillinq#499).
 The change directory it named was never committed, and the `reporting`
 capability has NO canonical spec. One was drafted during gate remediation and
 withdrawn: a spec written to fit the code, by the process whose job is to
 check the code against a spec, is not a specification anyone agreed to.
 Authoring it is the capability owner's decision, not a gate fix.

 The dangling path is replaced by the reason-bearing `@spec exclude` above —
 the same declaration lib/Controller/ReportingController.php already carries for
 the same capability. The prose note alone did not say this to gate-46, which
 reads the tag and not the paragraph under it, so the two halves of the same
 decision disagreed and only the PHP half was legible.
-->

<template>
	<div
		class="generate-report-dialog"
		role="dialog"
		aria-modal="true"
		aria-labelledby="generate-report-dialog-title"
		data-testid="generate-report-dialog">
		<div class="generate-report-dialog__panel">
			<header class="generate-report-dialog__header">
				<h2 id="generate-report-dialog-title">
					{{ t('shillinq', 'Generate report') }}
				</h2>
				<p class="generate-report-dialog__subtitle">
					{{ report.label }}
				</p>
			</header>

			<section class="generate-report-dialog__body">
				<div class="generate-report-dialog__field">
					<label
						class="generate-report-dialog__label"
						for="generate-report-administration">
						{{ t('shillinq', 'Administration') }}
						<span class="generate-report-dialog__required">*</span>
					</label>
					<select
						v-if="administrationOptions.length > 0"
						id="generate-report-administration"
						v-model="form.administrationId"
						class="generate-report-dialog__control"
						:disabled="submitting"
						data-testid="generate-report-administration">
						<option value="">
							{{ t('shillinq', 'Select an administration…') }}
						</option>
						<option
							v-for="admin in administrationOptions"
							:key="admin.value"
							:value="admin.value">
							{{ admin.label }}
						</option>
					</select>
					<input
						v-else
						id="generate-report-administration"
						v-model="form.administrationId"
						type="text"
						class="generate-report-dialog__control"
						:disabled="submitting"
						data-testid="generate-report-administration"
						:placeholder="t('shillinq', 'Administration id')" />
				</div>

				<div class="generate-report-dialog__row">
					<div class="generate-report-dialog__field">
						<label
							class="generate-report-dialog__label"
							for="generate-report-period-type">
							{{ t('shillinq', 'Period type') }}
						</label>
						<select
							id="generate-report-period-type"
							v-model="form.periodType"
							class="generate-report-dialog__control"
							:disabled="submitting"
							data-testid="generate-report-period-type">
							<option value="year">
								{{ t('shillinq', 'Year') }}
							</option>
							<option value="quarter">
								{{ t('shillinq', 'Quarter') }}
							</option>
							<option value="month">
								{{ t('shillinq', 'Month') }}
							</option>
						</select>
					</div>

					<div class="generate-report-dialog__field">
						<label
							class="generate-report-dialog__label"
							for="generate-report-year">
							{{ t('shillinq', 'Fiscal year') }}
							<span class="generate-report-dialog__required">*</span>
						</label>
						<input
							id="generate-report-year"
							v-model.number="form.periodYear"
							type="number"
							class="generate-report-dialog__control"
							:disabled="submitting"
							data-testid="generate-report-year" />
					</div>

					<div
						v-if="form.periodType !== 'year'"
						class="generate-report-dialog__field">
						<label
							class="generate-report-dialog__label"
							for="generate-report-period-number">
							{{ periodNumberLabel }}
							<span class="generate-report-dialog__required">*</span>
						</label>
						<select
							id="generate-report-period-number"
							v-model.number="form.periodNumber"
							class="generate-report-dialog__control"
							:disabled="submitting"
							data-testid="generate-report-period-number">
							<option
								v-for="n in periodNumberOptions"
								:key="n"
								:value="n">
								{{ n }}
							</option>
						</select>
					</div>
				</div>

				<div class="generate-report-dialog__field">
					<label
						class="generate-report-dialog__label"
						for="generate-report-format">
						{{ t('shillinq', 'Format') }}
						<span class="generate-report-dialog__required">*</span>
					</label>
					<select
						id="generate-report-format"
						v-model="form.format"
						class="generate-report-dialog__control"
						:disabled="submitting"
						data-testid="generate-report-format">
						<option
							v-for="fmt in report.formats || []"
							:key="fmt"
							:value="fmt">
							{{ fmt.toUpperCase() }}
						</option>
					</select>
				</div>

				<fieldset
					v-if="scheduling"
					class="generate-report-dialog__schedule"
					data-testid="generate-report-schedule">
					<legend class="generate-report-dialog__label">
						{{ t('shillinq', 'Schedule') }}
					</legend>
					<div class="generate-report-dialog__row">
						<div class="generate-report-dialog__field">
							<label
								class="generate-report-dialog__label"
								for="schedule-report-frequency">
								{{ t('shillinq', 'Frequency') }}
							</label>
							<select
								id="schedule-report-frequency"
								v-model="schedule.frequency"
								class="generate-report-dialog__control"
								:disabled="submitting">
								<option value="weekly">
									{{ t('shillinq', 'Weekly') }}
								</option>
								<option value="monthly">
									{{ t('shillinq', 'Monthly') }}
								</option>
								<option value="quarterly">
									{{ t('shillinq', 'Quarterly') }}
								</option>
							</select>
						</div>
						<div class="generate-report-dialog__field">
							<label
								class="generate-report-dialog__label"
								for="schedule-report-run-day">
								{{ runDayLabel }}
							</label>
							<input
								id="schedule-report-run-day"
								v-model.number="schedule.runDay"
								type="number"
								min="1"
								:max="schedule.frequency === 'weekly' ? 7 : 28"
								class="generate-report-dialog__control"
								:disabled="submitting" />
						</div>
						<div class="generate-report-dialog__field">
							<label
								class="generate-report-dialog__label"
								for="schedule-report-period-rule">
								{{ t('shillinq', 'Period') }}
							</label>
							<select
								id="schedule-report-period-rule"
								v-model="schedule.periodRule"
								class="generate-report-dialog__control"
								:disabled="submitting">
								<option value="previous-period">
									{{ t('shillinq', 'Previous period') }}
								</option>
								<option value="year-to-date">
									{{ t('shillinq', 'Year to date') }}
								</option>
							</select>
						</div>
					</div>
					<div class="generate-report-dialog__field">
						<label
							class="generate-report-dialog__label"
							for="schedule-report-recipients">
							{{ t('shillinq', 'Recipients') }}
						</label>
						<input
							id="schedule-report-recipients"
							v-model="schedule.recipients"
							type="text"
							class="generate-report-dialog__control"
							:disabled="submitting"
							:placeholder="
								t('shillinq', 'group:controllers, user:anna')
							" />
					</div>
					<div class="generate-report-dialog__field">
						<label
							class="generate-report-dialog__label"
							for="schedule-report-folder">
							{{ t('shillinq', 'Folder') }}
						</label>
						<input
							id="schedule-report-folder"
							v-model="schedule.folderPath"
							type="text"
							class="generate-report-dialog__control"
							:disabled="submitting" />
					</div>
				</fieldset>
				<p
					v-if="scheduledMessage"
					class="generate-report-dialog__notice"
					data-testid="generate-report-scheduled">
					{{ scheduledMessage }}
				</p>
				<p
					v-if="error"
					class="generate-report-dialog__error"
					data-testid="generate-report-dialog-error">
					{{ error }}
				</p>
			</section>

			<footer class="generate-report-dialog__footer">
				<button
					type="button"
					class="generate-report-dialog__btn"
					:disabled="submitting"
					data-testid="generate-report-dialog-cancel"
					@click="onCancel">
					{{ t('shillinq', 'Cancel') }}
				</button>
				<button
					v-if="!scheduling"
					type="button"
					class="generate-report-dialog__btn"
					:disabled="submitting"
					data-testid="generate-report-dialog-schedule"
					@click="scheduling = true">
					{{ t('shillinq', 'Schedule this report') }}
				</button>
				<button
					v-else
					type="button"
					class="generate-report-dialog__btn generate-report-dialog__btn--primary"
					:disabled="!canSchedule"
					data-testid="generate-report-dialog-save-schedule"
					@click="onSchedule">
					{{ t('shillinq', 'Save schedule') }}
				</button>
				<button
					v-if="!scheduling"
					type="button"
					class="generate-report-dialog__btn generate-report-dialog__btn--primary"
					:disabled="!canSubmit"
					data-testid="generate-report-dialog-submit"
					@click="onSubmit">
					{{
						submitting
							? t('shillinq', 'Generating…')
							: t('shillinq', 'Generate')
					}}
				</button>
			</footer>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { schedulePayload } from '../utils/reportSchedule.js'

export default {
	name: 'GenerateReportDialog',
	props: {
		/**
		 * The catalogue row the user clicked "Generate" on: id, label,
		 * category, kind, formats, description.
		 */
		report: {
			type: Object,
			required: true,
		},

		/**
		 * The format pre-selected on the card's format picker; the dialog
		 * seeds its own picker from this so the choice carries over.
		 */
		format: {
			type: String,
			default: '',
		},

		administrationOptions: {
			type: Array,
			default: () => [],
		},

		defaultAdministrationId: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'generated', 'scheduled'],
	data() {
		return {
			submitting: false,
			error: '',
			scheduling: false,
			scheduledMessage: '',
			schedule: {
				frequency: 'monthly',
				runDay: 1,
				periodRule: 'previous-period',
				recipients: '',
				folderPath: '/Shillinq/Reports',
			},

			form: {
				administrationId: this.defaultAdministrationId || '',
				periodType: 'year',
				periodYear: new Date().getFullYear(),
				periodNumber: 1,
				format:
					this.format
					|| (this.report.formats && this.report.formats[0])
					|| '',
			},
		}
	},

	computed: {
		periodNumberLabel() {
			return this.form.periodType === 'month'
				? this.t('shillinq', 'Month')
				: this.t('shillinq', 'Quarter')
		},

		periodNumberOptions() {
			const max = this.form.periodType === 'month' ? 12 : 4
			return Array.from({ length: max }, (_, i) => i + 1)
		},

		/**
		 * The label of the run-day field: a weekday for a weekly schedule, a day of the month otherwise.
		 *
		 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
		 */
		runDayLabel() {
			return this.schedule.frequency === 'weekly'
				? this.t('shillinq', 'Weekday (1 Monday to 7 Sunday)')
				: this.t('shillinq', 'Day of the month')
		},

		/**
		 * Whether the schedule form is complete enough to save.
		 *
		 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
		 */
		canSchedule() {
			return (
				!this.submitting
				&& Boolean(this.form.administrationId)
				&& Boolean(this.form.format)
				&& Number(this.schedule.runDay) >= 1
			)
		},

		canSubmit() {
			if (this.submitting) {
				return false
			}
			if (!this.form.administrationId) {
				return false
			}
			if (!this.form.format) {
				return false
			}
			if (!this.form.periodYear) {
				return false
			}
			if (this.form.periodType !== 'year' && !this.form.periodNumber) {
				return false
			}
			return true
		},
	},

	methods: {
		t,
		onCancel() {
			if (this.submitting) {
				return
			}
			this.$emit('close')
		},

		/**
		 * Assemble the generation context and POST it. The period is sent
		 * both as its structured parts (periodType/periodYear/periodNumber)
		 * and a derived label (e.g. "2026", "2026-Q1", "2026-03") so the
		 * back end can persist a human-readable period on the GeneratedReport
		 * record without re-deriving it.
		 */
		async onSubmit() {
			if (!this.canSubmit) {
				return
			}
			this.submitting = true
			this.error = ''
			try {
				const response = await axios.post(
					generateUrl('/apps/shillinq/api/reporting/generate'),
					{
						reportType: this.report.id,
						administrationId: this.form.administrationId,
						format: this.form.format,
						periodType: this.form.periodType,
						periodYear: this.form.periodYear,
						periodNumber:
							this.form.periodType === 'year'
								? null
								: this.form.periodNumber,
						period: this.periodLabel(),
					},
				)
				this.$emit('generated', response.data || {})
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('shillinq', 'Report generation failed')
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Save the dialog's report and format as a report schedule.
		 *
		 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
		 */
		async onSchedule() {
			if (!this.canSchedule) {
				return
			}
			this.submitting = true
			this.error = ''
			try {
				const payload = schedulePayload(
					this.report,
					{
						...this.schedule,
						administrationId: this.form.administrationId,
						format: this.form.format,
					},
					new Date(),
				)
				const response = await axios.post(
					generateUrl(
						'/apps/openregister/api/objects/shillinq/ReportSchedule',
					),
					payload,
				)
				this.scheduledMessage = this.t(
					'shillinq',
					'Scheduled. The first run is on {date}.',
					{ date: payload.nextRunAt.slice(0, 10) },
				)
				this.$emit('scheduled', response.data || payload)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('shillinq', 'The schedule could not be saved')
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Human-readable period label matching the structured parts.
		 *
		 * @return {string} e.g. "2026", "2026-Q1" or "2026-03".
		 */
		periodLabel() {
			const year = this.form.periodYear
			if (this.form.periodType === 'year') {
				return String(year)
			}
			if (this.form.periodType === 'quarter') {
				return `${year}-Q${this.form.periodNumber}`
			}
			return `${year}-${String(this.form.periodNumber).padStart(2, '0')}`
		},
	},
}
</script>

<style scoped>
.generate-report-dialog {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.4);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 9999;
}

.generate-report-dialog__panel {
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: var(--border-radius-large);
	padding: 16px 20px;
	width: min(520px, 92vw);
	box-shadow: 0 6px 30px rgba(0, 0, 0, 0.25);
}

.generate-report-dialog__header h2 {
	margin: 0 0 4px;
}

.generate-report-dialog__subtitle {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}

.generate-report-dialog__body {
	margin: 0 0 16px;
}

.generate-report-dialog__row {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
}

.generate-report-dialog__row .generate-report-dialog__field {
	flex: 1 1 8rem;
}

.generate-report-dialog__field {
	margin-bottom: 12px;
}

.generate-report-dialog__label {
	display: block;
	margin: 0 0 4px;
	font-weight: 600;
}

.generate-report-dialog__required {
	color: var(--color-error);
}

.generate-report-dialog__control {
	width: 100%;
	box-sizing: border-box;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	font-family: inherit;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.generate-report-dialog__error {
	margin-top: 8px;
	color: var(--color-error);
}

.generate-report-dialog__footer {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}

.generate-report-dialog__btn {
	padding: 6px 14px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	background: var(--color-background-darker);
	color: var(--color-main-text);
	cursor: pointer;
}

.generate-report-dialog__btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.generate-report-dialog__btn--primary {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-color: var(--color-primary);
}
</style>
