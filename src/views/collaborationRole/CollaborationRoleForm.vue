<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<NcDialog
		:name="t('shillinq', 'Add collaboration member')"
		@closing="$emit('close')">
		<form class="collaboration-role-form" @submit.prevent="submit">
			<div class="collaboration-role-form__field">
				<label for="principalType">{{ t('shillinq', 'Principal type') }}</label>
				<select
					id="principalType"
					v-model="form.principalType"
					required>
					<option value="" disabled>
						{{ t('shillinq', 'Select a type') }}
					</option>
					<option value="user">
						{{ t('shillinq', 'User') }}
					</option>
					<option value="group">
						{{ t('shillinq', 'Group') }}
					</option>
					<option value="circle">
						{{ t('shillinq', 'Circle') }}
					</option>
				</select>
			</div>

			<div class="collaboration-role-form__field">
				<label for="principalId">{{ t('shillinq', 'Principal ID') }}</label>
				<input
					id="principalId"
					v-model="form.principalId"
					type="text"
					:placeholder="t('shillinq', 'Enter user, group, or circle ID')"
					required>
			</div>

			<div class="collaboration-role-form__field">
				<label for="role">{{ t('shillinq', 'Role') }}</label>
				<select
					id="role"
					v-model="form.role"
					required>
					<option value="" disabled>
						{{ t('shillinq', 'Select a role') }}
					</option>
					<option value="approver">
						{{ t('shillinq', 'Approver') }}
					</option>
					<option value="reviewer">
						{{ t('shillinq', 'Reviewer') }}
					</option>
					<option value="contributor">
						{{ t('shillinq', 'Contributor') }}
					</option>
					<option value="viewer">
						{{ t('shillinq', 'Viewer') }}
					</option>
				</select>
			</div>

			<div class="collaboration-role-form__field">
				<label for="expiresAt">{{ t('shillinq', 'Expires at (optional)') }}</label>
				<input
					id="expiresAt"
					v-model="form.expiresAt"
					type="datetime-local">
			</div>

			<div v-if="errorMessage" class="collaboration-role-form__error">
				{{ errorMessage }}
			</div>

			<div class="collaboration-role-form__actions">
				<NcButton type="tertiary" @click="$emit('close')">
					{{ t('shillinq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					native-type="submit"
					:disabled="saving">
					{{ saving ? t('shillinq', 'Adding...') : t('shillinq', 'Add member') }}
				</NcButton>
			</div>
		</form>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { useCollaborationRoleStore } from '../../store/modules/collaborationRole.js'

export default {
	name: 'CollaborationRoleForm',
	components: {
		NcButton,
		NcDialog,
	},
	emits: ['close', 'created'],
	data() {
		return {
			roleStore: useCollaborationRoleStore(),
			form: {
				principalType: '',
				principalId: '',
				role: '',
				expiresAt: '',
			},
			saving: false,
			errorMessage: '',
		}
	},
	methods: {
		async submit() {
			this.saving = true
			this.errorMessage = ''
			try {
				const payload = {
					principalType: this.form.principalType,
					principalId: this.form.principalId,
					role: this.form.role,
				}
				if (this.form.expiresAt) {
					payload.expiresAt = new Date(this.form.expiresAt).toISOString()
				}
				await this.roleStore.createRole(payload)
				this.$emit('created')
			} catch (error) {
				this.errorMessage = t('shillinq', 'Failed to add member. Please try again.')
				console.error('Failed to create collaboration role:', error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.collaboration-role-form {
	padding: 8px 0;
}

.collaboration-role-form__field {
	margin-bottom: 16px;
}

.collaboration-role-form__field label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}

.collaboration-role-form__field input,
.collaboration-role-form__field select {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.collaboration-role-form__error {
	color: var(--color-error);
	margin-bottom: 12px;
}

.collaboration-role-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 20px;
}
</style>
