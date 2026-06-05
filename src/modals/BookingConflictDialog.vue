<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Booking conflict confirmation dialog (bookings-resource-calendar REQ-007).
 Shown when the booking API returns 409 Conflict: lists the conflicting
 bookings and lets the user cancel or override (confirm despite conflict).
 Lives in its own file under src/modals/ per ADR-004 modal isolation.
-->
<template>
	<NcModal
		size="normal"
		:name="t('shillinq', 'Booking Conflict Detected')"
		@close="$emit('cancel')">
		<div class="booking-conflict-dialog">
			<h2>{{ t('shillinq', 'Booking Conflict Detected') }}</h2>
			<p>{{ t('shillinq', 'The selected time overlaps with one or more existing bookings on this resource.') }}</p>

			<ul class="booking-conflict-dialog__list">
				<li v-for="conflict in conflicts" :key="conflict.id" class="booking-conflict-dialog__item">
					<strong>{{ conflict.title }}</strong>
					<span>{{ formatRange(conflict.startTime, conflict.endTime) }}</span>
				</li>
			</ul>

			<div class="booking-conflict-dialog__actions">
				<NcButton @click="$emit('cancel')">
					{{ t('shillinq', 'Cancel') }}
				</NcButton>
				<NcButton type="warning" @click="$emit('confirm')">
					{{ t('shillinq', 'Book anyway') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'

export default {
	name: 'BookingConflictDialog',

	components: {
		NcButton,
		NcModal,
	},

	props: {
		/**
		 * The conflicting bookings returned by the API (409 payload).
		 */
		conflicts: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['confirm', 'cancel'],

	methods: {
		/**
		 * Render a start–end range in the user's locale.
		 *
		 * @param {string} start ISO-8601 UTC start.
		 * @param {string} end ISO-8601 UTC end.
		 * @return {string} A human-readable range.
		 */
		formatRange(start, end) {
			try {
				const s = new Date(start)
				const e = new Date(end)
				const opts = { dateStyle: 'medium', timeStyle: 'short' }
				return `${s.toLocaleString(undefined, opts)} – ${e.toLocaleTimeString(undefined, { timeStyle: 'short' })}`
			} catch (err) {
				return `${start} – ${end}`
			}
		},
	},
}
</script>

<style scoped>
.booking-conflict-dialog {
	padding: 20px;
}

.booking-conflict-dialog__list {
	margin: 12px 0;
	padding: 0;
	list-style: none;
}

.booking-conflict-dialog__item {
	display: flex;
	flex-direction: column;
	padding: 8px 12px;
	margin-bottom: 6px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.booking-conflict-dialog__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 16px;
}
</style>
