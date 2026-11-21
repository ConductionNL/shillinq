<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The withdrawal line on the booking widget's confirmation step
  (sales-cancellation REQ-SCX-004): the last day a booking made online can
  be withdrawn without a fee and the signed link to do it. Renders nothing
  unless the link is https and the day is a date.

  @spec openspec/changes/sales-cancellation/tasks.md#task-3.4
-->
<template>
	<p v-if="usable" class="wsw-widget__withdrawal">
		{{ t('Changed your mind? You can withdraw from this booking without a fee until') }}
		<time :datetime="lastDay">{{ lastDay }}</time>.
		<a
			class="wsw-widget__withdrawal-link"
			:href="link"
			target="_blank"
			rel="noopener noreferrer">{{ t('Withdraw from this booking') }}</a>
	</p>
</template>

<script>
export default {
	name: 'WithdrawalNotice',
	props: {
		/** The signed withdrawal link the server sent. */
		link: { type: String, default: '' },
		/** The last day of the withdrawal period, YYYY-MM-DD. */
		lastDay: { type: String, default: '' },
		/** The widget's translator. */
		t: { type: Function, required: true },
	},

	computed: {
		/**
		 * Whether the link is https and the day a date.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.4
		 */
		usable() {
			return /^https:\/\//i.test(this.link) && /^\d{4}-\d{2}-\d{2}$/.test(this.lastDay)
		},
	},
}
</script>
