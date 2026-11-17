<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RepeatAppointmentModal: book a repeating appointment in one go
 (bookings-messages-and-embedding REQ-BRS-004).

 The user picks the service, the resource and the first start, then how often
 it repeats (daily, weekly, monthly, every n) and when it ends (after a number
 of times or on a date). Saving sends the series to
 POST /api/v1/appointment-series, which plans every occurrence inside the
 resource's opening hours and skips the ones that clash; the dialog then says
 how many appointments were planned and how many were skipped.

 Opened with spawnDialog from the "New repeating appointment" header action on
 Appointments (src/utils/appointmentSeriesActions.js), so it lives in its own
 file (hydra gate-13).

 @spec openspec/changes/bookings-messages-and-embedding/specs/bookings-recurring-series/spec.md#requirement-the-booking-form-offers-a-repeat-option-that-creates-the-series-req-brs-004
-->

<template>
	<NcDialog
		:name="t('shillinq', 'New repeating appointment')"
		size="normal"
		data-testid="repeat-appointment-modal"
		@closing="close">
		<div class="rap">
			<template v-if="!result">
				<NcSelect
					v-model="service"
					:options="services"
					label="label"
					:inputLabel="t('shillinq', 'Service')"
					:loading="loading"
					data-testid="repeat-appointment-service"
					@update:modelValue="takeServiceDuration" />
				<NcSelect
					v-model="resource"
					:options="resources"
					label="label"
					:inputLabel="t('shillinq', 'Resource')"
					:loading="loading"
					data-testid="repeat-appointment-resource" />
				<NcTextField
					v-model="startTime"
					type="datetime-local"
					:label="t('shillinq', 'First appointment')"
					data-testid="repeat-appointment-start" />
				<NcTextField
					v-model="durationMinutes"
					type="number"
					min="5"
					:label="t('shillinq', 'Duration in minutes')"
					data-testid="repeat-appointment-duration" />

				<fieldset class="rap__group">
					<legend>{{ t('shillinq', 'Repeat') }}</legend>
					<NcSelect
						v-model="frequency"
						:options="frequencyOptions"
						label="label"
						:reduce="(option) => option.id"
						:clearable="false"
						:inputLabel="t('shillinq', 'How often')"
						data-testid="repeat-appointment-frequency" />
					<NcTextField
						v-model="interval"
						type="number"
						min="1"
						:label="t('shillinq', 'Every how many')"
						data-testid="repeat-appointment-interval" />
					<NcSelect
						v-model="endType"
						:options="endOptions"
						label="label"
						:reduce="(option) => option.id"
						:clearable="false"
						:inputLabel="t('shillinq', 'Ends')"
						data-testid="repeat-appointment-end-type" />
					<NcTextField
						v-if="endType === 'count'"
						v-model="count"
						type="number"
						min="2"
						:label="t('shillinq', 'Number of appointments')"
						data-testid="repeat-appointment-count" />
					<NcTextField
						v-else
						v-model="until"
						type="date"
						:label="t('shillinq', 'Last date')"
						data-testid="repeat-appointment-until" />
				</fieldset>
			</template>

			<template v-else>
				<p data-testid="repeat-appointment-created">
					{{
						n(
							'shillinq',
							'{count} appointment was planned in the series.',
							'{count} appointments were planned in the series.',
							Number(result.generated || 0),
							{ count: Number(result.generated || 0) },
						)
					}}
				</p>
				<p v-if="Number(result.skipped || 0) > 0" data-testid="repeat-appointment-skipped">
					{{
						n(
							'shillinq',
							'{count} time was skipped because it falls outside the opening hours or is already booked.',
							'{count} times were skipped because they fall outside the opening hours or are already booked.',
							Number(result.skipped),
							{ count: Number(result.skipped) },
						)
					}}
				</p>
			</template>

			<p
				v-if="error"
				class="rap__error"
				role="alert"
				data-testid="repeat-appointment-error">
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
				:disabled="submitting || administrationId === ''"
				data-testid="repeat-appointment-submit"
				@click="submit">
				{{ submitting ? t('shillinq', 'Saving…') : t('shillinq', 'Book the series') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	createAppointmentSeries,
	loadOptions,
	newSeriesId,
	seriesPayload,
	seriesProblem,
} from '../utils/appointmentSeriesApi.js'
import { errorMessage } from '../utils/downPaymentApi.js'

export default {
	name: 'RepeatAppointmentModal',

	components: { NcButton, NcDialog, NcSelect, NcTextField },

	emits: ['close'],

	data() {
		return {
			administrationId: '',
			services: [],
			resources: [],
			service: null,
			resource: null,
			startTime: '',
			durationMinutes: 30,
			frequency: 'weekly',
			interval: 1,
			endType: 'count',
			count: 6,
			until: '',
			loading: false,
			submitting: false,
			result: null,
			error: '',
		}
	},

	computed: {
		/**
		 * The frequency choices.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.4
		 */
		frequencyOptions() {
			return [
				{ id: 'daily', label: t('shillinq', 'Daily') },
				{ id: 'weekly', label: t('shillinq', 'Weekly') },
				{ id: 'monthly', label: t('shillinq', 'Monthly') },
			]
		},

		/**
		 * The end choices.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.4
		 */
		endOptions() {
			return [
				{ id: 'count', label: t('shillinq', 'After a number of appointments') },
				{ id: 'until', label: t('shillinq', 'On a date') },
			]
		},

		/**
		 * The form as the series helpers read it.
		 *
		 * @return {object}
		 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.4
		 */
		form() {
			return {
				serviceId: this.service?.id || '',
				resourceId: this.resource?.id || '',
				startTime: this.startTime,
				durationMinutes: this.durationMinutes,
				repeat: {
					frequency: this.frequency,
					interval: this.interval,
					endType: this.endType,
					count: this.count,
					until: this.until,
				},
			}
		},
	},

	/**
	 * Load the active administration, its services and its resources.
	 *
	 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.4
	 */
	async mounted() {
		this.loading = true
		try {
			const context = await axios.get(generateUrl('/apps/shillinq/api/administrations/context'))
			this.administrationId = String(context.data?.activeAdministrationId || '')
			if (this.administrationId !== '') {
				this.services = await loadOptions('Service', this.administrationId, 'serviceId')
				this.resources = await loadOptions('Resource', this.administrationId, 'resourceId')
			}
		} catch (error) {
			this.error = errorMessage(error, t('shillinq', 'The services and resources could not be loaded.'))
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
		n,

		/**
		 * Take the chosen service's duration as the appointment's.
		 *
		 * @param {object|null} service The chosen service.
		 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.4
		 */
		takeServiceDuration(service) {
			if (Number(service?.duration) > 0) {
				this.durationMinutes = Number(service.duration)
			}
		},

		/**
		 * Check the form and create the series.
		 *
		 * @spec openspec/changes/bookings-messages-and-embedding/specs/bookings-recurring-series/spec.md#requirement-the-booking-form-offers-a-repeat-option-that-creates-the-series-req-brs-004
		 */
		async submit() {
			this.error = seriesProblem(this.form, t)
			if (this.error) {
				return
			}

			this.submitting = true
			try {
				this.result = await createAppointmentSeries(seriesPayload(this.form, this.administrationId, newSeriesId()))
			} catch (error) {
				this.error = errorMessage(error, t('shillinq', 'The series could not be booked.'))
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close the dialog.
		 *
		 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.4
		 */
		close() {
			this.$emit('close', this.result)
		},
	},
}
</script>

<style scoped>
.rap {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.rap__group {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px 12px 12px;
}

.rap__error {
	color: var(--color-error-text);
}
</style>
