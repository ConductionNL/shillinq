<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Deadline calendar settings (compliance-deadline-calendar REQ-CDC-006).

 Per-user category toggles + reminder lead times for the compliance
 deadline calendar. Strictly current-user scoped: the backend resolves
 the acting user from the session (no user id in the payload). Saving
 immediately re-publishes the user's calendar so a disabled category's
 VEVENTs are removed (REQ-CDC-006).

 @spec openspec/specs/compliance-deadline-calendar/spec.md
-->
<template>
	<NcAppContent>
		<div class="deadline-calendar-settings">
			<header class="deadline-calendar-settings__header">
				<h2 class="deadline-calendar-settings__title">
					{{ t('shillinq', 'Deadline calendar') }}
				</h2>
				<p class="deadline-calendar-settings__description">
					{{
						t(
							'shillinq',
							'Choose which deadline categories appear on your deadline calendar and when you want to be reminded. Filing, payment-run and contract deadlines are on by default; invoice due dates are opt-in.',
						)
					}}
				</p>
			</header>

			<section class="deadline-calendar-settings__body">
				<NcLoadingIcon
					v-if="loading"
					:size="32"
					:name="t('shillinq', 'Loading deadline calendar settings')" />
				<form
					v-else
					class="deadline-calendar-settings__form"
					@submit.prevent="save">
					<fieldset
						v-for="row in rows"
						:key="row.id"
						class="deadline-calendar-settings__category"
						:data-testid="'deadline-category-' + row.id">
						<NcCheckboxRadioSwitch
							v-model="row.enabled"
							type="switch"
							:data-testid="'deadline-toggle-' + row.id">
							{{ t('shillinq', row.label) }}
						</NcCheckboxRadioSwitch>
						<p class="deadline-calendar-settings__category-description">
							{{ t('shillinq', row.description) }}
						</p>
						<NcTextField
							v-if="row.enabled"
							v-model="row.leadDaysInput"
							type="number"
							min="0"
							max="365"
							inputmode="numeric"
							class="deadline-calendar-settings__lead"
							:label="t('shillinq', 'Reminder lead time (days)')"
							:data-testid="'deadline-lead-' + row.id" />
					</fieldset>

					<div class="deadline-calendar-settings__actions">
						<NcButton
							variant="primary"
							type="submit"
							:disabled="saving"
							data-testid="deadline-settings-save">
							{{
								saving
									? t('shillinq', 'Saving…')
									: t('shillinq', 'Save')
							}}
						</NcButton>
						<span
							v-if="savedMessage"
							class="deadline-calendar-settings__saved"
							role="status">
							{{ savedMessage }}
						</span>
					</div>
					<p
						v-if="errorMessage"
						class="deadline-calendar-settings__error"
						role="alert">
						{{ errorMessage }}
					</p>
				</form>
			</section>
		</div>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppContent,
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcTextField,
} from '@nextcloud/vue'
import {
	buildSavePayload,
	normaliseSettings,
} from './deadlineCalendarSettingsHelpers.js'

export default {
	name: 'DeadlineCalendarSettings',

	components: {
		NcAppContent,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			saving: false,
			errorMessage: '',
			savedMessage: '',
			rows: [],
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/** @spec openspec/specs/compliance-deadline-calendar/spec.md */
		async load() {
			this.loading = true
			this.errorMessage = ''

			try {
				const url = generateUrl(
					'/apps/shillinq/api/deadline-calendar/settings',
				)
				const { data } = await axios.get(url)
				this.rows = normaliseSettings(data).map((row) => ({
					...row,
					leadDaysInput: String(row.leadDays),
				}))
			} catch (error) {
				// Fall back to the documented defaults so the form stays usable.
				this.rows = normaliseSettings(null).map((row) => ({
					...row,
					leadDaysInput: String(row.leadDays),
				}))
				this.errorMessage = this.t(
					'shillinq',
					'Failed to load deadline calendar settings.',
				)
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/compliance-deadline-calendar/spec.md */
		async save() {
			this.saving = true
			this.errorMessage = ''
			this.savedMessage = ''

			try {
				const payload = buildSavePayload(
					this.rows.map((row) => ({
						id: row.id,
						enabled: row.enabled,
						leadDays: Number.parseInt(row.leadDaysInput, 10),
					})),
				)
				const url = generateUrl(
					'/apps/shillinq/api/deadline-calendar/settings',
				)
				const { data } = await axios.post(url, payload)
				this.rows = normaliseSettings(data).map((row) => ({
					...row,
					leadDaysInput: String(row.leadDays),
				}))
				this.savedMessage = this.t(
					'shillinq',
					'Deadline calendar settings saved.',
				)
			} catch (error) {
				this.errorMessage = this.t(
					'shillinq',
					'Failed to save deadline calendar settings.',
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.deadline-calendar-settings {
	max-width: 720px;
	padding: 20px;
}

.deadline-calendar-settings__title {
	margin-bottom: 4px;
}

.deadline-calendar-settings__description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
}

.deadline-calendar-settings__category {
	border: none;
	border-bottom: 1px solid var(--color-border);
	margin: 0;
	padding: 12px 0;
}

.deadline-calendar-settings__category-description {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 8px;
}

.deadline-calendar-settings__lead {
	max-width: 260px;
}

.deadline-calendar-settings__actions {
	align-items: center;
	display: flex;
	gap: 12px;
	margin-top: 20px;
}

.deadline-calendar-settings__saved {
	color: var(--color-success-text, var(--color-success));
}

.deadline-calendar-settings__error {
	color: var(--color-error-text, var(--color-error));
	margin-top: 12px;
}
</style>
