<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<NcDialog :name="t('shillinq', 'Invite Member')" @closing="$emit('close')">
		<form @submit.prevent="invite">
			<div class="form-group">
				<label>{{ t('shillinq', 'Email') }}</label>
				<input v-model="email" type="email" required>
			</div>
			<div class="form-group">
				<label>{{ t('shillinq', 'Role') }}</label>
				<select v-model="roleId" required>
					<option value="">{{ t('shillinq', 'Select role') }}</option>
					<option v-for="role in roleStore.roles" :key="role.id" :value="role.id">
						{{ role.name }}
					</option>
				</select>
			</div>
			<NcButton type="primary" native-type="submit" :disabled="teamStore.loading">
				{{ t('shillinq', 'Invite Member') }}
			</NcButton>
		</form>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { useTeamStore } from '../../store/modules/team.js'
import { useRoleStore } from '../../store/modules/role.js'

export default {
	name: 'TeamInviteDialog',
	components: {
		NcButton,
		NcDialog,
	},
	props: {
		teamId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			teamStore: useTeamStore(),
			roleStore: useRoleStore(),
			email: '',
			roleId: '',
		}
	},
	created() {
		this.roleStore.fetchRoles()
	},
	methods: {
		async invite() {
			const success = await this.teamStore.inviteMember(this.teamId, this.email, this.roleId)
			if (success) {
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
.form-group select {
	width: 100%;
}
</style>
