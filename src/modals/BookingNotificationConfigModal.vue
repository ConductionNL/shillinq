<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  BookingNotificationConfigModal — per-booking notification-trigger
  override UI (REQ-BNT-007). Opened from the Booking detail page; lists
  the global triggers whose triggerType matches one of the booking
  lifecycle events plus any triggers already scoped to this booking
  (appliesToBookingSlug == booking.slug). The operator can toggle each
  trigger between enabled and disabled and pick which channels fire
  (email / sms / chat / teams / slack). Save calls the backend
  notification-triggers API; on success the modal closes and the parent
  re-fetches the list.

  Modal isolation per Hydra gate-13 (ADR-004): this dialog lives in its
  own .vue file under src/modals/ and is imported by the Booking detail
  page (manifest page id BookingDetail). It must never be inlined into
  the parent component.

  @spec openspec/changes/bookings-notification-triggers/tasks.md#task-10
-->

<template>
	<div
		v-if="open"
		class="bk-notification-modal"
		role="dialog"
		aria-modal="true"
		aria-labelledby="bk-notification-modal-title"
		data-testid="bk-notification-modal">
		<div class="bk-notification-modal__panel">
			<header class="bk-notification-modal__header">
				<h2 id="bk-notification-modal-title">
					{{ label('Notifications') }}
				</h2>
				<p class="bk-notification-modal__subtitle">
					{{
						label(
							'Configure how this booking notifies customers, organizers and administrators.',
						)
					}}
				</p>
			</header>

			<section class="bk-notification-modal__body">
				<p v-if="loading" data-testid="bk-notification-loading">
					{{ label('Loading triggers…') }}
				</p>
				<p
					v-else-if="error"
					class="bk-notification-modal__error"
					data-testid="bk-notification-error">
					{{ error }}
				</p>
				<ul
					v-else
					class="bk-notification-modal__list"
					data-testid="bk-notification-list">
					<li
						v-for="t in editableTriggers"
						:key="t.slug"
						class="bk-notification-modal__item"
						:data-testid="`bk-notification-${t.slug}`">
						<header class="bk-notification-modal__item-header">
							<label class="bk-notification-modal__toggle">
								<input
									type="checkbox"
									:checked="t.status === 'enabled'"
									:data-testid="`bk-notification-${t.slug}-enabled`"
									@change="setEnabled(t, $event.target.checked)" />
								<span>{{ t.name }}</span>
							</label>
							<span class="bk-notification-modal__type">{{
								t.triggerType
							}}</span>
						</header>
						<div class="bk-notification-modal__channels">
							<label
								v-for="c in allChannels"
								:key="c"
								class="bk-notification-modal__channel">
								<input
									type="checkbox"
									:checked="t.channels.includes(c)"
									:data-testid="`bk-notification-${t.slug}-channel-${c}`"
									@change="
										toggleChannel(t, c, $event.target.checked)
									" />
								<span>{{ label(channelLabel(c)) }}</span>
							</label>
						</div>
					</li>
				</ul>
			</section>

			<footer class="bk-notification-modal__footer">
				<button
					type="button"
					class="bk-notification-modal__btn"
					data-testid="bk-notification-cancel"
					@click="$emit('cancel')">
					{{ label('Cancel') }}
				</button>
				<button
					type="button"
					class="bk-notification-modal__btn bk-notification-modal__btn--primary"
					data-testid="bk-notification-save"
					:disabled="saving || loading"
					@click="save">
					{{ label(saving ? 'Saving…' : 'Save') }}
				</button>
			</footer>
		</div>
	</div>
</template>

<script>
/**
 * Per-booking notification-trigger override modal (REQ-BNT-007).
 *
 * Props:
 *   - open: boolean — whether to render the modal.
 *   - bookingId: string — uuid / slug of the Booking under edit.
 *   - triggers: Array<{slug, name, triggerType, channels, status,
 *     appliesToBookingSlug}> — triggers eligible for this booking
 *     (caller pre-filters). The modal holds an internal editable copy
 *     so cancel restores the original state.
 *
 * Emits:
 *   - save: { bookingId, updates: Array<{slug, status, channels}> }
 *           — caller persists via PATCH /api/bookings/{id}/notification-triggers.
 *   - cancel: user closed the dialog without saving.
 */
export default {
	name: 'BookingNotificationConfigModal',
	props: {
		open: { type: Boolean, default: false },
		bookingId: { type: String, required: true },
		triggers: { type: Array, default: () => [] },
	},

	emits: ['save', 'cancel'],
	data() {
		return {
			editableTriggers: [],
			loading: false,
			saving: false,
			error: '',
			allChannels: ['email', 'sms', 'chat', 'teams', 'slack'],
		}
	},

	watch: {
		open(val) {
			if (val === true) {
				this.reset()
			}
		},

		triggers: {
			immediate: true,
			handler() {
				this.reset()
			},
		},
	},

	methods: {
		/**
		 * Translate UI strings via Nextcloud's t() helper when available.
		 *
		 * @param {string} key i18n key.
		 * @return {string} Translated string or the key itself.
		 */
		label(key) {
			if (typeof window !== 'undefined' && typeof window.t === 'function') {
				return window.t('shillinq', key)
			}
			return key
		},

		/**
		 * Human-readable channel label.
		 *
		 * @param {string} channel Channel id (email/sms/chat/teams/slack).
		 * @return {string} Capitalised label.
		 */
		channelLabel(channel) {
			const map = {
				email: 'Email',
				sms: 'SMS',
				chat: 'Chat',
				teams: 'Teams',
				slack: 'Slack',
			}
			return map[channel] || channel
		},

		/**
		 * Reset the editable copy from the incoming triggers prop.
		 */
		reset() {
			this.editableTriggers = (this.triggers || []).map((t) => ({
				slug: t.slug,
				name: t.name,
				triggerType: t.triggerType,
				channels: Array.isArray(t.channels) ? [...t.channels] : [],
				status: t.status || 'enabled',
				appliesToBookingSlug: t.appliesToBookingSlug || null,
			}))
			this.error = ''
		},

		/**
		 * Set the enabled/disabled status of one trigger.
		 *
		 * @param {object} trigger Editable trigger row.
		 * @param {boolean} enabled Whether the trigger is enabled.
		 */
		setEnabled(trigger, enabled) {
			trigger.status = enabled ? 'enabled' : 'disabled'
		},

		/**
		 * Toggle a channel on / off for one trigger.
		 *
		 * @param {object} trigger Editable trigger row.
		 * @param {string} channel Channel id.
		 * @param {boolean} on Whether the channel is enabled.
		 */
		toggleChannel(trigger, channel, on) {
			const idx = trigger.channels.indexOf(channel)
			if (on === true && idx === -1) {
				trigger.channels.push(channel)
			} else if (on === false && idx !== -1) {
				trigger.channels.splice(idx, 1)
			}
		},

		/**
		 * Emit the save payload — caller persists.
		 */
		save() {
			this.saving = true
			const updates = this.editableTriggers.map((t) => ({
				slug: t.slug,
				status: t.status,
				channels: t.channels,
			}))
			this.$emit('save', { bookingId: this.bookingId, updates })
			this.saving = false
		},
	},
}
</script>

<style scoped>
.bk-notification-modal {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.4);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 1000;
}

.bk-notification-modal__panel {
	background: var(--color-main-background, #fff);
	border-radius: 8px;
	padding: 24px;
	width: min(640px, 90vw);
	max-height: 80vh;
	overflow-y: auto;
	box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
}

.bk-notification-modal__header h2 {
	margin: 0 0 4px;
	font-size: 1.25rem;
}

.bk-notification-modal__subtitle {
	margin: 0 0 16px;
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.9rem;
}

.bk-notification-modal__list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.bk-notification-modal__item {
	border: 1px solid var(--color-border, #e0e0e0);
	border-radius: 6px;
	padding: 12px;
}

.bk-notification-modal__item-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.bk-notification-modal__toggle {
	display: flex;
	align-items: center;
	gap: 8px;
	font-weight: 600;
}

.bk-notification-modal__type {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85rem;
	font-family: monospace;
}

.bk-notification-modal__channels {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
}

.bk-notification-modal__channel {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 0.9rem;
}

.bk-notification-modal__error {
	color: #c00;
}

.bk-notification-modal__footer {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}

.bk-notification-modal__btn {
	border-radius: 4px;
	padding: 8px 16px;
	border: 1px solid var(--color-border, #d0d0d0);
	background: var(--color-main-background, #fff);
	cursor: pointer;
}

.bk-notification-modal__btn--primary {
	background: var(--color-primary, #006aa6);
	color: var(--color-primary-text, #fff);
	border-color: var(--color-primary, #006aa6);
}

.bk-notification-modal__btn:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}
</style>
