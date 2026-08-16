<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  BookingConflictDialog — modal shown when the API returns 409 on booking
  create (REQ-007 of bookings-resource-calendar). Lists the conflicting
  bookings and lets the operator either confirm-and-override or cancel
  back to the form.

  Modal isolation per Hydra gate-13: the dialog lives in its own .vue file
  under src/modals/ and is imported by BookingForm.vue. It must never be
  inlined into the parent component.

  @spec openspec/changes/bookings-resource-calendar/tasks.md#task-6
-->

<template>
	<div
		v-if="open"
		class="bk-conflict-dialog"
		role="dialog"
		aria-modal="true"
		aria-labelledby="bk-conflict-dialog-title"
		data-testid="bk-conflict-dialog">
		<div class="bk-conflict-dialog__panel">
			<header class="bk-conflict-dialog__header">
				<h2 id="bk-conflict-dialog-title">
					{{ label('Booking conflict detected') }}
				</h2>
			</header>
			<section class="bk-conflict-dialog__body">
				<p>
					{{ label('The proposed booking overlaps existing bookings:') }}
				</p>
				<ul class="bk-conflict-dialog__list" data-testid="bk-conflict-list">
					<li
						v-for="c in conflicts"
						:key="c.bookingId"
						class="bk-conflict-dialog__item"
						:data-testid="`bk-conflict-${c.bookingId}`">
						<strong>{{ c.title || c.bookingId }}</strong>
						<span
							>{{ formatTime(c.startTime) }} –
							{{ formatTime(c.endTime) }}</span
						>
					</li>
				</ul>
				<p class="bk-conflict-dialog__warn">
					{{
						label(
							'Confirm to create the booking anyway, or cancel to adjust the times.',
						)
					}}
				</p>
			</section>
			<footer class="bk-conflict-dialog__footer">
				<button
					type="button"
					class="bk-conflict-dialog__btn"
					data-testid="bk-conflict-cancel"
					@click="$emit('cancel')">
					{{ label('Cancel') }}
				</button>
				<button
					type="button"
					class="bk-conflict-dialog__btn bk-conflict-dialog__btn--primary"
					data-testid="bk-conflict-confirm"
					@click="$emit('confirm')">
					{{ label('Confirm') }}
				</button>
			</footer>
		</div>
	</div>
</template>

<script>
/**
 * Conflict confirmation dialog (REQ-007).
 *
 * Props:
 *   - open: boolean — whether to render the modal.
 *   - conflicts: Array<{bookingId, title, startTime, endTime}>
 *
 * Emits:
 *   - confirm: user accepted the conflict and wants to retry with override.
 *   - cancel: user rejected — close the dialog and let the form be edited.
 */
export default {
	name: 'BookingConflictDialog',
	props: {
		open: { type: Boolean, default: false },
		conflicts: { type: Array, default: () => [] },
		timeZone: { type: String, default: 'Europe/Amsterdam' },
	},

	emits: ['confirm', 'cancel'],
	methods: {
		label(key) {
			if (typeof t === 'function') {
				return t('shillinq', key)
			}
			return key
		},

		formatTime(iso) {
			if (!iso) {
				return ''
			}
			try {
				const d = new Date(iso)
				return d.toLocaleString(undefined, {
					hour: '2-digit',
					minute: '2-digit',
					day: '2-digit',
					month: '2-digit',
					timeZone: this.timeZone,
				})
			} catch {
				return iso
			}
		},
	},
}
</script>

<style scoped>
.bk-conflict-dialog {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.45);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 1000;
}

.bk-conflict-dialog__panel {
	background: var(--color-main-background, #fff);
	max-width: 480px;
	width: 90%;
	border-radius: 8px;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
	display: flex;
	flex-direction: column;
}

.bk-conflict-dialog__header,
.bk-conflict-dialog__body,
.bk-conflict-dialog__footer {
	padding: 12px 16px;
}

.bk-conflict-dialog__header {
	border-bottom: 1px solid var(--color-border, #ddd);
}

.bk-conflict-dialog__footer {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	border-top: 1px solid var(--color-border, #ddd);
}

.bk-conflict-dialog__list {
	list-style: disc;
	padding-left: 20px;
}

.bk-conflict-dialog__item {
	display: flex;
	flex-direction: column;
	padding: 4px 0;
}

.bk-conflict-dialog__warn {
	color: var(--color-error, #c62828);
}

.bk-conflict-dialog__btn {
	padding: 6px 14px;
	border: 1px solid var(--color-border, #ccc);
	background: transparent;
	cursor: pointer;
}

.bk-conflict-dialog__btn--primary {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	border-color: var(--color-primary-element, #0082c9);
}
</style>
