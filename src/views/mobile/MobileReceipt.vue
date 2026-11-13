<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  Shillinq Mobile receipt

  Photograph a receipt with the phone's camera and give its amount, date and
  category. The receipt is queued on the phone and sent at once when there
  is a connection, else when it returns.

  @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-a-receipt-is-captured-with-the-camera-req-pmp-002
  @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
-->
<template>
	<section class="mobile-form">
		<h1>{{ t('shillinq', 'Capture a receipt') }}</h1>

		<form class="mobile-form__fields" @submit.prevent="save">
			<label class="mobile-form__field">
				<span>{{ t('shillinq', 'Photo of the receipt') }}</span>
				<input
					type="file"
					accept="image/*"
					capture="environment"
					required
					data-testid="mobile-receipt-photo"
					@change="onPhoto">
			</label>
			<img
				v-if="preview"
				class="mobile-form__preview"
				:src="preview"
				:alt="t('shillinq', 'The photographed receipt')">

			<label class="mobile-form__field">
				<span>{{ t('shillinq', 'Amount (EUR)') }}</span>
				<input
					v-model="form.amount"
					type="number"
					inputmode="decimal"
					min="0"
					step="0.01"
					required
					data-testid="mobile-receipt-amount">
			</label>

			<label class="mobile-form__field">
				<span>{{ t('shillinq', 'Date') }}</span>
				<input
					v-model="form.receiptDate"
					type="date"
					required
					data-testid="mobile-receipt-date">
			</label>

			<label class="mobile-form__field">
				<span>{{ t('shillinq', 'Category') }}</span>
				<select v-model="form.category" required data-testid="mobile-receipt-category">
					<option
						v-for="option in categoryOptions"
						:key="option.value"
						:value="option.value">
						{{ option.label }}
					</option>
				</select>
			</label>

			<label class="mobile-form__field">
				<span>{{ t('shillinq', 'Description (optional)') }}</span>
				<input v-model="form.description" type="text" data-testid="mobile-receipt-description">
			</label>

			<div class="mobile-form__buttons">
				<NcButton variant="secondary" @click="back">
					{{ t('shillinq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					type="submit"
					:disabled="!complete || saving"
					data-testid="mobile-receipt-save">
					{{ t('shillinq', 'Save receipt') }}
				</NcButton>
			</div>
		</form>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import { enqueue, isOnline, QUEUE_KINDS, syncQueue } from '../../composables/useOfflineQueue.js'
import {
	buildReceiptPayload,
	mobileQueue,
	RECEIPT_CATEGORIES,
	receiptComplete,
	today,
} from './mobileApp.js'

export default {
	name: 'MobileReceipt',

	components: { NcButton },

	data() {
		return {
			form: { amount: '', receiptDate: today(), category: 'travel', description: '' },
			photo: null,
			preview: '',
			saving: false,
		}
	},

	computed: {
		complete() {
			return receiptComplete(this.form, this.photo)
		},

		categoryOptions() {
			const labels = {
				travel: t('shillinq', 'Travel'),
				meals: t('shillinq', 'Meals'),
				supplies: t('shillinq', 'Supplies'),
				accommodation: t('shillinq', 'Accommodation'),
				other: t('shillinq', 'Other'),
			}
			return RECEIPT_CATEGORIES.map((value) => ({ value, label: labels[value] }))
		},
	},

	beforeUnmount() {
		if (this.preview) {
			URL.revokeObjectURL(this.preview)
		}
	},

	methods: {
		t,

		/**
		 * Keep the photo the camera took.
		 *
		 * @param {Event} event The change event of the file input.
		 * @return {void}
		 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-a-receipt-is-captured-with-the-camera-req-pmp-002
		 */
		onPhoto(event) {
			const file = event?.target?.files?.[0] ?? null
			if (this.preview) {
				URL.revokeObjectURL(this.preview)
			}
			this.photo = file
			this.preview = file ? URL.createObjectURL(file) : ''
		},

		/**
		 * Queue the receipt, send it now when online, and go back home.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
		 */
		async save() {
			if (!this.complete || this.saving) {
				return
			}
			this.saving = true
			try {
				const queue = await mobileQueue()
				const suffix = Math.random().toString(36).slice(2, 6).toUpperCase()
				await enqueue(queue.store, {
					kind: QUEUE_KINDS.RECEIPT,
					payload: buildReceiptPayload(this.form, suffix),
					image: this.photo,
				})
				if (isOnline()) {
					await syncQueue(queue.store, queue.transport)
				}
				this.back()
			} finally {
				this.saving = false
			}
		},

		/**
		 * Back to the mobile home screen.
		 *
		 * @return {void}
		 */
		back() {
			this.$router.push('/mobile')
		},
	},
}
</script>

<style scoped>
.mobile-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
	max-width: 640px;
	margin: 0 auto;
}

.mobile-form__fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.mobile-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-weight: bold;
}

.mobile-form__field input,
.mobile-form__field select {
	min-height: 44px;
	font-weight: normal;
}

.mobile-form__preview {
	max-width: 100%;
	max-height: 240px;
	object-fit: contain;
	border-radius: var(--border-radius-large);
}

.mobile-form__buttons {
	display: flex;
	justify-content: space-between;
	gap: 12px;
}
</style>
