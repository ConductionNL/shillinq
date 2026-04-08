<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<NcDialog :name="t('shillinq', 'Invite Member')" @close="$emit('close')">
		<div class="shillinq-invite-form">
			<label>{{ t('shillinq', 'Email') }}
				<input v-model="email" type="email">
			</label>
			<label>{{ t('shillinq', 'Role') }}
				<select v-model="roleId">
					<option v-for="role in roleStore.roles" :key="role.id" :value="role.id">
						{{ role.name }}
					</option>
				</select>
			</label>
			<NcButton type="primary" @click="invite">
				{{ t('shillinq', 'Invite Member') }}
			</NcButton>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { useTeamStore } from '../../store/modules/team.js'
import { useRoleStore } from '../../store/modules/role.js'

export default {
	name: 'TeamInviteDialog',
	components: { NcButton, NcDialog },
	props: {
		teamId: { type: String, required: true },
	},
	emits: ['close'],
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
			await this.teamStore.inviteMember(this.teamId, this.email, this.roleId)
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.shillinq-invite-form label { display: block; margin-bottom: 12px; }
.shillinq-invite-form input, .shillinq-invite-form select { display: block; width: 100%; margin-top: 4px; }
</style>
