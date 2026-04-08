<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-12.1 -->
<template>
	<NcDialog :name="t('shillinq', 'New Request for Quotation')"
		size="normal"
		@close="$emit('close')">
		<form class="rfq-form" @submit.prevent="submit">
			<div class="rfq-form__field">
				<label>{{ t('shillinq', 'Number') }}</label>
				<input type="text"
					:value="form.number || t('shillinq', 'Auto-generated')"
					disabled
					class="rfq-form__input rfq-form__input--readonly" />
			</div>

			<div class="rfq-form__field">
				<label>{{ t('shillinq', 'Title') }} *</label>
				<input v-model="form.title"
					type="text"
					class="rfq-form__input"
					required
					:placeholder="t('shillinq', 'Enter RFQ title...')" />
			</div>

			<div class="rfq-form__field">
				<label>{{ t('shillinq', 'Description') }}</label>
				<textarea v-model="form.description"
					class="rfq-form__input"
					rows="4"
					:placeholder="t('shillinq', 'Describe what you are requesting...')" />
			</div>

			<div class="rfq-form__field">
				<label>{{ t('shillinq', 'Type') }} *</label>
				<select v-model="form.type"
					class="rfq-form__input"
					required>
					<option value="" disabled>
						{{ t('shillinq', 'Select type...') }}
					</option>
					<option value="rfq">{{ t('shillinq', 'RFQ — Request for Quotation') }}</option>
					<option value="rfi">{{ t('shillinq', 'RFI — Request for Information') }}</option>
					<option value="rfp">{{ t('shillinq', 'RFP — Request for Proposal') }}</option>
				</select>
			</div>

			<div class="rfq-form__field">
				<label>{{ t('shillinq', 'Budget') }}</label>
				<input v-model.number="form.budget"
					type="number"
					min="0"
					step="0.01"
					class="rfq-form__input"
					:placeholder="t('shillinq', '0.00')" />
			</div>

			<div class="rfq-form__field">
				<label>{{ t('shillinq', 'Currency') }}</label>
				<select v-model="form.currency"
					class="rfq-form__input">
					<option value="EUR">EUR</option>
					<option value="USD">USD</option>
					<option value="GBP">GBP</option>
				</select>
			</div>

			<div class="rfq-form__field">
				<label>{{ t('shillinq', 'Due Date') }} *</label>
				<input v-model="form.dueDate"
					type="date"
					class="rfq-form__input"
					required />
			</div>
		</form>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="saving || !isValid"
				@click="submit">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
				</template>
				{{ t('shillinq', 'Create RFQ') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import { useRFQStore } from '../../store/modules/rFQ.js'

export default {
	name: 'RFQForm',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
	},
	emits: ['close', 'saved'],
	data() {
		return {
			saving: false,
			form: {
				number: '',
				title: '',
				description: '',
				type: '',
				budget: null,
				currency: 'EUR',
				dueDate: '',
			},
		}
	},
	computed: {
		rfqStore() {
			return useRFQStore()
		},
		isValid() {
			return this.form.title.trim() && this.form.type && this.form.dueDate
		},
	},
	methods: {
		t,
		async submit() {
			if (!this.isValid || this.saving) return
			this.saving = true
			try {
				await this.rfqStore.create(this.form)
				this.$emit('saved')
			} catch (error) {
				console.error('Failed to create RFQ:', error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.rfq-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;
}

.rfq-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.rfq-form__field label {
	font-weight: 600;
	font-size: 14px;
}

.rfq-form__input {
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	font-size: 14px;
}

.rfq-form__input--readonly {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	cursor: not-allowed;
}
</style>
