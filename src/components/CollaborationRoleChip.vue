<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/collaboration/tasks.md#task-6.5
-->
<template>
	<div :class="['role-chip', 'role-chip--' + role.role]">
		<NcAvatar :user="role.principalId" :size="24" />
		<span class="role-chip__name">{{ role.principalId }}</span>
		<span class="role-chip__badge">{{ t('shillinq', role.role) }}</span>
		<NcButton
			v-if="canRemove"
			type="tertiary"
			class="role-chip__remove"
			@click="$emit('remove', role.id)">
			<template #icon>
				<CloseIcon :size="16" />
			</template>
		</NcButton>
	</div>
</template>

<script>
import { NcAvatar, NcButton } from '@nextcloud/vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'

export default {
	name: 'CollaborationRoleChip',
	components: {
		NcAvatar,
		NcButton,
		CloseIcon,
	},
	props: {
		role: {
			type: Object,
			required: true,
		},
		canRemove: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['remove'],
}
</script>

<style scoped>
.role-chip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 4px 8px;
	border-radius: 16px;
	margin: 2px;
	background-color: var(--color-background-dark);
}

.role-chip--approver { background-color: var(--color-primary-element-light); }
.role-chip--reviewer { background-color: var(--color-success-hover); }
.role-chip--contributor { background-color: var(--color-background-dark); }
.role-chip--viewer { background-color: var(--color-background-darker); }

.role-chip__name {
	font-weight: 500;
}

.role-chip__badge {
	font-size: 11px;
	text-transform: uppercase;
	font-weight: 600;
	opacity: 0.8;
}

.role-chip__remove {
	margin-left: auto;
}
</style>
