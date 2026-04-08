<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<NcDialog :name="t('shillinq', 'Grant Delegation')" @close="$emit('close')">
		<div class="shillinq-delegation-form">
			<label>{{ t('shillinq', 'User') }}
				<input v-model="form.userId" type="text" :placeholder="t('shillinq', 'Username or ID')">
			</label>
			<label>{{ t('shillinq', 'Role') }}
				<select v-model="form.roleId">
					<option v-for="role in roleStore.roles" :key="role.id" :value="role.id">
						{{ role.name }}
					</option>
				</select>
			</label>
			<label>{{ t('shillinq', 'Start Date') }}
				<input v-model="form.startDate" type="date">
			</label>
			<label>{{ t('shillinq', 'End Date') }}
				<input v-model="form.endDate" type="date">
			</label>
			<label>{{ t('shillinq', 'Reason') }}
				<textarea v-model="form.reason" rows="3" />
			</label>

			<p v-if="error" class="shillinq-delegation-form__error">
				{{ error }}
			</p>

			<NcButton type="primary" @click="submit">
				{{ t('shillinq', 'Save') }}
			</NcButton>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { useRoleStore } from '../../store/modules/role.js'
import { useDelegationStore } from '../../store/modules/delegation.js'

export default {
	name: 'DelegationDialog',
	components: { NcButton, NcDialog },
	emits: ['close'],
	data() {
		return {
			roleStore: useRoleStore(),
			delegationStore: useDelegationStore(),
			error: '',
			form: {
				userId: '',
				roleId: '',
				startDate: '',
				endDate: '',
				reason: '',
			},
		}
	},
	created() {
		this.roleStore.fetchRoles()
	},
	methods: {
		async submit() {
			this.error = ''
			if (this.form.endDate <= this.form.startDate) {
				this.error = this.t('shillinq', 'End date must be after start date')
				return
			}
			try {
				await this.delegationStore.createDelegation(this.form)
				this.$emit('close')
			} catch (e) {
				this.error = e.message || this.t('shillinq', 'Failed to create delegation')
			}
		},
	},
}
</script>

<style scoped>
.shillinq-delegation-form label { display: block; margin-bottom: 12px; }
.shillinq-delegation-form input, .shillinq-delegation-form select, .shillinq-delegation-form textarea { display: block; width: 100%; margin-top: 4px; }
.shillinq-delegation-form__error { color: var(--color-error); margin-bottom: 8px; }
</style>
