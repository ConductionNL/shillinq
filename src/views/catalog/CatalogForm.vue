<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-8.1 -->
<template>
	<NcDialog
		:name="isEditing ? t('shillinq', 'Edit Catalog') : t('shillinq', 'New Catalog')"
		size="normal"
		@closing="$emit('close')">
		<form class="catalog-form" @submit.prevent="save">
			<div class="catalog-form__field">
				<label for="catalog-name">{{ t('shillinq', 'Name') }} *</label>
				<NcTextField
					id="catalog-name"
					v-model="form.name"
					:label="t('shillinq', 'Catalog name')"
					required />
			</div>

			<div class="catalog-form__field">
				<label for="catalog-description">{{ t('shillinq', 'Description') }}</label>
				<NcTextArea
					id="catalog-description"
					v-model="form.description"
					:label="t('shillinq', 'Description')" />
			</div>

			<div class="catalog-form__field">
				<label for="catalog-status">{{ t('shillinq', 'Status') }}</label>
				<NcSelect
					id="catalog-status"
					v-model="form.status"
					:options="statusOptions"
					:placeholder="t('shillinq', 'Select status')" />
			</div>

			<div class="catalog-form__field">
				<label for="catalog-supplier">{{ t('shillinq', 'Supplier Profile') }}</label>
				<NcSelect
					id="catalog-supplier"
					v-model="form.supplierProfileId"
					:options="supplierOptions"
					:placeholder="t('shillinq', 'Select supplier')"
					label="name"
					track-by="id" />
			</div>

			<div class="catalog-form__row">
				<div class="catalog-form__field">
					<label for="catalog-from">{{ t('shillinq', 'Effective From') }}</label>
					<NcTextField
						id="catalog-from"
						v-model="form.effectiveFrom"
						type="date"
						:label="t('shillinq', 'Start date')" />
				</div>
				<div class="catalog-form__field">
					<label for="catalog-to">{{ t('shillinq', 'Effective To') }}</label>
					<NcTextField
						id="catalog-to"
						v-model="form.effectiveTo"
						type="date"
						:label="t('shillinq', 'End date')" />
				</div>
			</div>

			<div class="catalog-form__field">
				<label for="catalog-owner">{{ t('shillinq', 'Owner') }}</label>
				<NcTextField
					id="catalog-owner"
					v-model="form.ownerId"
					:label="t('shillinq', 'Owner ID')" />
			</div>

			<div class="catalog-form__field">
				<label for="catalog-contract">{{ t('shillinq', 'Contract Reference') }}</label>
				<NcTextField
					id="catalog-contract"
					v-model="form.contractReference"
					:label="t('shillinq', 'Contract reference')" />
			</div>

			<div class="catalog-form__actions">
				<NcButton type="tertiary" @click="$emit('close')">
					{{ t('shillinq', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" native-type="submit" :disabled="saving">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ isEditing ? t('shillinq', 'Save') : t('shillinq', 'Create') }}
				</NcButton>
			</div>
		</form>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'CatalogForm',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		catalog: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			saving: false,
			supplierOptions: [],
			statusOptions: ['draft', 'active', 'archived'],
			form: {
				name: '',
				description: '',
				status: 'draft',
				supplierProfileId: null,
				effectiveFrom: '',
				effectiveTo: '',
				ownerId: '',
				contractReference: '',
			},
		}
	},

	computed: {
		isEditing() {
			return !!this.catalog
		},
	},

	created() {
		if (this.catalog) {
			this.form = {
				name: this.catalog.name || '',
				description: this.catalog.description || '',
				status: this.catalog.status || 'draft',
				supplierProfileId: this.catalog.supplierProfileId || null,
				effectiveFrom: this.catalog.effectiveFrom || '',
				effectiveTo: this.catalog.effectiveTo || '',
				ownerId: this.catalog.ownerId || '',
				contractReference: this.catalog.contractReference || '',
			}
		}
		this.loadSuppliers()
	},

	methods: {
		t(app, text) {
			return t(app, text)
		},

		async loadSuppliers() {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/supplier-profiles')
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.supplierOptions = data.results || data
				}
			} catch (error) {
				console.error('Failed to load suppliers:', error)
			}
		},

		async save() {
			this.saving = true
			try {
				const isEdit = this.isEditing
				const url = isEdit
					? generateUrl(`/apps/shillinq/api/v1/catalogs/${this.catalog.id}`)
					: generateUrl('/apps/shillinq/api/v1/catalogs')
				const response = await fetch(url, {
					method: isEdit ? 'PUT' : 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(this.form),
				})
				if (response.ok) {
					this.$emit('saved')
				}
			} catch (error) {
				console.error('Failed to save catalog:', error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.catalog-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.catalog-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1;
}

.catalog-form__field label {
	font-weight: 600;
	font-size: 14px;
}

.catalog-form__row {
	display: flex;
	gap: 12px;
}

.catalog-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
