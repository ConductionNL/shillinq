<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2

Booking Notification Config Modal — isolated per ADR-004.
Allows organizers to enable/disable notification triggers and customize
recipient channels for a specific booking (REQ-BNT-007).

@spec openspec/changes/bookings-notification-triggers/tasks.md#task-10
-->
<template>
	<NcModal
		v-if="show"
		:name="t('shillinq', 'Notification Triggers')"
		size="normal"
		@close="onClose">
		<div class="booking-notification-modal">
			<h2 class="booking-notification-modal__title">
				{{ t('shillinq', 'Notification Triggers') }}
			</h2>

			<div v-if="loading" class="booking-notification-modal__loading">
				<NcLoadingIcon :size="32" />
				<span>{{ t('shillinq', 'Loading triggers...') }}</span>
			</div>

			<div v-else-if="error" class="booking-notification-modal__error">
				{{ error }}
			</div>

			<div v-else>
				<p class="booking-notification-modal__description">
					{{ t('shillinq', 'Configure which notifications are sent for this booking.') }}
				</p>

				<div v-if="triggers.length === 0" class="booking-notification-modal__empty">
					{{ t('shillinq', 'No notification triggers configured.') }}
				</div>

				<ul v-else class="booking-notification-modal__triggers">
					<li
						v-for="trigger in triggers"
						:key="trigger.id || trigger.eventType"
						class="booking-notification-modal__trigger">
						<div class="booking-notification-modal__trigger-header">
							<NcCheckboxRadioSwitch
								:checked="trigger.active"
								type="switch"
								@update:checked="(val) => onToggleTrigger(trigger, val)">
								{{ triggerLabel(trigger.eventType) }}
							</NcCheckboxRadioSwitch>
						</div>

						<div v-if="trigger.active" class="booking-notification-modal__channels">
							<label class="booking-notification-modal__channels-label">
								{{ t('shillinq', 'Channels') }}:
							</label>
							<NcCheckboxRadioSwitch
								v-for="channel in availableChannels"
								:key="channel"
								:checked="isChannelSelected(trigger, channel)"
								type="checkbox"
								@update:checked="(val) => onToggleChannel(trigger, channel, val)">
								{{ channelLabel(channel) }}
							</NcCheckboxRadioSwitch>
						</div>
					</li>
				</ul>
			</div>

			<div class="booking-notification-modal__actions">
				<NcButton
					:disabled="saving"
					type="secondary"
					@click="onClose">
					{{ t('shillinq', 'Cancel') }}
				</NcButton>
				<NcButton
					:disabled="saving || loading"
					type="primary"
					@click="onSave">
					<template v-if="saving">
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('shillinq', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

export default {
	name: 'BookingNotificationConfigModal',

	components: {
		NcModal,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
	},

	props: {
		/**
		 * Whether the modal is visible.
		 */
		show: {
			type: Boolean,
			default: false,
		},
		/**
		 * UUID of the booking to configure.
		 */
		bookingId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			loading: false,
			saving: false,
			error: null,
			triggers: [],
			availableChannels: ['email', 'sms', 'chat'],
		}
	},

	watch: {
		show(val) {
			if (val === true) {
				this.loadTriggers()
			}
		},
	},

	methods: {
		t,

		/**
		 * Load triggers for the current booking.
		 *
		 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-10
		 */
		async loadTriggers() {
			this.loading = true
			this.error = null
			try {
				const url = generateUrl('/apps/shillinq/api/bookings/' + encodeURIComponent(this.bookingId) + '/notification-triggers')
				const { data } = await axios.get(url)
				this.triggers = data.triggers || this.defaultTriggers()
			} catch (e) {
				this.error = t('shillinq', 'Failed to load notification triggers.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Return default trigger stubs when none are configured yet.
		 *
		 * @return {Array}
		 */
		defaultTriggers() {
			return [
				{ eventType: 'created', active: true, recipients: [{ role: 'customer', channels: ['email'] }] },
				{ eventType: 'changed', active: true, recipients: [{ role: 'customer', channels: ['email'] }] },
				{ eventType: 'cancelled', active: true, recipients: [{ role: 'customer', channels: ['email'] }] },
				{ eventType: 'reminder', active: false, recipients: [{ role: 'customer', channels: ['email'] }] },
			]
		},

		/**
		 * Toggle a trigger's active state.
		 *
		 * @param {object} trigger  The trigger object.
		 * @param {boolean} active  New active value.
		 */
		onToggleTrigger(trigger, active) {
			trigger.active = active
		},

		/**
		 * Toggle a channel on a trigger's first recipient rule.
		 *
		 * @param {object} trigger  The trigger object.
		 * @param {string} channel  Channel name.
		 * @param {boolean} enabled Whether to enable the channel.
		 */
		onToggleChannel(trigger, channel, enabled) {
			if (!trigger.recipients || trigger.recipients.length === 0) {
				trigger.recipients = [{ role: 'customer', channels: [] }]
			}
			const channels = trigger.recipients[0].channels || []
			if (enabled && !channels.includes(channel)) {
				trigger.recipients[0].channels = [...channels, channel]
			} else if (!enabled) {
				trigger.recipients[0].channels = channels.filter(c => c !== channel)
			}
		},

		/**
		 * Check whether a channel is selected for the first recipient of a trigger.
		 *
		 * @param {object} trigger  The trigger object.
		 * @param {string} channel  Channel name.
		 * @return {boolean}
		 */
		isChannelSelected(trigger, channel) {
			return (trigger.recipients?.[0]?.channels || []).includes(channel)
		},

		/**
		 * Save trigger configuration via PATCH API.
		 *
		 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-10
		 */
		async onSave() {
			this.saving = true
			try {
				const url = generateUrl('/apps/shillinq/api/bookings/' + encodeURIComponent(this.bookingId) + '/notification-triggers')
				await axios.patch(url, { triggers: this.triggers })
				showSuccess(t('shillinq', 'Notification triggers saved.'))
				this.$emit('saved')
				this.onClose()
			} catch (e) {
				showError(t('shillinq', 'Failed to save notification triggers.'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Close the modal.
		 */
		onClose() {
			this.$emit('close')
		},

		/**
		 * Human-readable label for a trigger event type.
		 *
		 * @param {string} eventType
		 * @return {string}
		 */
		triggerLabel(eventType) {
			const labels = {
				created: t('shillinq', 'Send booking confirmation'),
				changed: t('shillinq', 'Send booking update'),
				cancelled: t('shillinq', 'Send cancellation notice'),
				reminder: t('shillinq', 'Send reminder'),
			}
			return labels[eventType] || eventType
		},

		/**
		 * Human-readable label for a channel name.
		 *
		 * @param {string} channel
		 * @return {string}
		 */
		channelLabel(channel) {
			const labels = {
				email: t('shillinq', 'Email'),
				sms: t('shillinq', 'SMS'),
				chat: t('shillinq', 'Chat'),
			}
			return labels[channel] || channel
		},
	},
}
</script>

<style scoped>
.booking-notification-modal {
	padding: 24px;
	min-width: 400px;
}

.booking-notification-modal__title {
	margin-top: 0;
	font-size: 1.2em;
}

.booking-notification-modal__description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.booking-notification-modal__loading,
.booking-notification-modal__error,
.booking-notification-modal__empty {
	padding: 16px 0;
	text-align: center;
}

.booking-notification-modal__error {
	color: var(--color-error);
}

.booking-notification-modal__triggers {
	list-style: none;
	padding: 0;
	margin: 0 0 16px 0;
}

.booking-notification-modal__trigger {
	border-bottom: 1px solid var(--color-border);
	padding: 12px 0;
}

.booking-notification-modal__trigger:last-child {
	border-bottom: none;
}

.booking-notification-modal__trigger-header {
	display: flex;
	align-items: center;
}

.booking-notification-modal__channels {
	margin-top: 8px;
	margin-left: 24px;
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.booking-notification-modal__channels-label {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.booking-notification-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 24px;
}
</style>
