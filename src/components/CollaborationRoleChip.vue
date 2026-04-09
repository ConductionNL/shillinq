<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="collaboration-role-chip" :class="'collaboration-role-chip--' + role.role">
		<NcAvatar
			:user="role.principalId"
			:display-name="role.displayName || role.principalId"
			:size="24" />
		<span class="collaboration-role-chip__name">
			{{ role.displayName || role.principalId }}
		</span>
		<span class="collaboration-role-chip__badge">
			{{ roleLabel }}
		</span>
		<NcButton
			v-if="canRemove"
			type="tertiary-no-background"
			class="collaboration-role-chip__remove"
			:aria-label="t('shillinq', 'Remove member')"
			@click="$emit('remove')">
			<template #icon>
				<CloseCircleOutline :size="16" />
			</template>
		</NcButton>
	</div>
</template>

<script>
import { NcAvatar, NcButton } from '@nextcloud/vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'

export default {
	name: 'CollaborationRoleChip',
	components: {
		NcAvatar,
		NcButton,
		CloseCircleOutline,
	},
	props: {
		role: {
			type: Object,
			required: true,
		},
	},
	emits: ['remove'],
	computed: {
		roleLabel() {
			const labels = {
				approver: t('shillinq', 'Approver'),
				reviewer: t('shillinq', 'Reviewer'),
				contributor: t('shillinq', 'Contributor'),
				viewer: t('shillinq', 'Viewer'),
			}
			return labels[this.role.role] || this.role.role
		},
		canRemove() {
			// Approvers can remove members
			return this.role.role === 'approver'
		},
	},
}
</script>

<style scoped>
.collaboration-role-chip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 4px 8px;
	border-radius: 16px;
	font-size: 13px;
	background-color: var(--color-background-hover);
}

.collaboration-role-chip--approver {
	background-color: var(--color-primary-element-light, #e8f0fe);
}

.collaboration-role-chip--reviewer {
	background-color: var(--color-success-hover, #e8f5e9);
}

.collaboration-role-chip--contributor {
	background-color: var(--color-background-dark, #ededed);
}

.collaboration-role-chip--viewer {
	background-color: var(--color-background-hover, #f5f5f5);
}

.collaboration-role-chip__name {
	font-weight: 500;
	max-width: 120px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.collaboration-role-chip__badge {
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.02em;
	padding: 1px 6px;
	border-radius: 8px;
	background-color: rgba(0, 0, 0, 0.08);
}

.collaboration-role-chip--approver .collaboration-role-chip__badge {
	color: var(--color-primary-element, #0082c9);
}

.collaboration-role-chip--reviewer .collaboration-role-chip__badge {
	color: var(--color-success, #46ba61);
}

.collaboration-role-chip--contributor .collaboration-role-chip__badge {
	color: var(--color-text-maxcontrast);
}

.collaboration-role-chip--viewer .collaboration-role-chip__badge {
	color: var(--color-text-lighter);
}

.collaboration-role-chip__remove {
	margin: -4px -4px -4px 0;
}
</style>
