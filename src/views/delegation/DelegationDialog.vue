<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<NcDialog :name="t('shillinq', 'Grant Delegation')" @closing="$emit('close')">
		<form @submit.prevent="saveDelegation">
			<div class="form-group">
				<label>{{ t('shillinq', 'User') }}</label>
				<input v-model="form.userId" type="text" :placeholder="t('shillinq', 'User ID')" required>
			</div>
			<div class="form-group">
				<label>{{ t('shillinq', 'Role') }}</label>
				<select v-model="form.roleId" required>
					<option value="">{{ t('shillinq', 'Select role') }}</option>
					<option v-for="role in roleStore.roles" :key="role.id" :value="role.id">
						{{ role.name }}
					</option>
				</select>
			</div>
			<div class="form-group">
				<label>{{ t('shillinq', 'Start Date') }}</label>
				<input v-model="form.startDate" type="datetime-local" required>
			</div>
			<div class="form-group">
				<label>{{ t('shillinq', 'End Date') }}</label>
				<input v-model="form.endDate" type="datetime-local" required>
			</div>
			<div class="form-group">
				<label>{{ t('shillinq', 'Reason') }}</label>
				<textarea v-model="form.reason" rows="3" />
			</div>

			<p v-if="error" class="shillinq-delegation-dialog__error">{{ error }}</p>

			<NcButton type="primary" native-type="submit" :disabled="delegationStore.loading">
				{{ t('shillinq', 'Grant Delegation') }}
			</NcButton>
		</form>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { useDelegationStore } from '../../store/modules/delegation.js'
import { useRoleStore } from '../../store/modules/role.js'

export default {
	name: 'DelegationDialog',
	components: {
		NcButton,
		NcDialog,
	},
	data() {
		return {
			delegationStore: useDelegationStore(),
			roleStore: useRoleStore(),
			form: {
				userId: '',
				roleId: '',
				grantedBy: '',
				startDate: '',
				endDate: '',
				reason: '',
			},
			error: '',
		}
	},
	created() {
		this.roleStore.fetchRoles()
	},
	methods: {
		async saveDelegation() {
			this.error = ''

			if (this.form.endDate <= this.form.startDate) {
				this.error = t('shillinq', 'End date must be after start date')
				return
			}

			const result = await this.delegationStore.createDelegation(this.form)
			if (result && result.error) {
				this.error = result.error
			} else if (result) {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped>
.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}

.form-group input,
.form-group select,
.form-group textarea {
	width: 100%;
}

.shillinq-delegation-dialog__error {
	color: var(--color-error);
	margin-bottom: 8px;
}
</style>
