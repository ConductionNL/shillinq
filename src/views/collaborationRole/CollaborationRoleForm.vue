<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/collaboration/tasks.md#task-5.2
-->
<template>
	<NcDialog
		:name="t('shillinq', 'Assign Role')"
		@close="$emit('close')">
		<div class="role-form">
			<div class="role-form__field">
				<label>{{ t('shillinq', 'Principal Type') }}</label>
				<NcSelect
					v-model="principalType"
					:options="['user', 'group']" />
			</div>
			<div class="role-form__field">
				<label>{{ t('shillinq', 'Principal ID') }}</label>
				<NcTextField
					v-model="principalId"
					:placeholder="t('shillinq', 'Search user or group...')" />
			</div>
			<div class="role-form__field">
				<label>{{ t('shillinq', 'Role') }}</label>
				<NcSelect
					v-model="role"
					:options="roleOptions" />
			</div>
			<div class="role-form__field">
				<label>{{ t('shillinq', 'Expires (optional)') }}</label>
				<NcDateTimePicker
					v-model="expiresAt"
					type="datetime" />
			</div>
			<NcButton type="primary" :disabled="!isValid" @click="submit">
				{{ t('shillinq', 'Assign') }}
			</NcButton>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDateTimePicker, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { useCollaborationRoleStore } from '../../store/modules/collaborationRole.js'

export default {
	name: 'CollaborationRoleForm',
	components: {
		NcButton,
		NcDateTimePicker,
		NcDialog,
		NcSelect,
		NcTextField,
	},
	props: {
		targetType: {
			type: String,
			default: '',
		},
		targetId: {
			type: String,
			default: '',
		},
	},
	emits: ['close', 'created'],
	data() {
		return {
			principalType: 'user',
			principalId: '',
			role: 'viewer',
			expiresAt: null,
			roleOptions: ['viewer', 'contributor', 'reviewer', 'approver'],
		}
	},
	computed: {
		isValid() {
			return this.principalId.length > 0 && this.role.length > 0
		},
	},
	methods: {
		async submit() {
			const roleStore = useCollaborationRoleStore()
			const data = {
				targetType: this.targetType,
				targetId: this.targetId,
				principalType: this.principalType,
				principalId: this.principalId,
				role: this.role,
			}
			if (this.expiresAt) {
				data.expiresAt = new Date(this.expiresAt).toISOString()
			}
			const created = await roleStore.assignRole(data)
			if (created) {
				this.$emit('created', created)
			}
		},
	},
}
</script>

<style scoped>
.role-form {
	padding: 16px;
}

.role-form__field {
	margin-bottom: 12px;
}

.role-form__field label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}
</style>
