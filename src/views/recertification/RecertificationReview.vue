<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-recert-review">
		<NcBreadcrumbs>
			<NcBreadcrumb :name="t('shillinq', 'Shillinq')" :to="{ name: 'Dashboard' }" />
			<NcBreadcrumb :name="t('shillinq', 'Recertification')" :to="{ name: 'RecertificationIndex' }" />
			<NcBreadcrumb :name="t('shillinq', 'Review')" />
		</NcBreadcrumbs>

		<header class="shillinq-recert-review__header">
			<h2>{{ t('shillinq', 'Recertification Review') }}</h2>
		</header>

		<NcLoadingIcon v-if="userStore.loading" />

		<template v-else>
			<table class="shillinq-recert-review__table">
				<thead>
					<tr>
						<th>{{ t('shillinq', 'Display Name') }}</th>
						<th>{{ t('shillinq', 'Email') }}</th>
						<th>{{ t('shillinq', 'Last Login') }}</th>
						<th>{{ t('shillinq', 'Decision') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="user in userStore.users" :key="user.id">
						<td>{{ user.displayName }}</td>
						<td>{{ user.email }}</td>
						<td>{{ user.lastLogin || '—' }}</td>
						<td>
							<NcButton :type="getDecision(user.id) === 'confirm' ? 'primary' : 'secondary'"
								@click="setDecision(user.id, 'confirm')">
								{{ t('shillinq', 'Confirm Access') }}
							</NcButton>
							<NcButton :type="getDecision(user.id) === 'revoke' ? 'error' : 'secondary'"
								@click="setDecision(user.id, 'revoke')">
								{{ t('shillinq', 'Revoke Access') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<NcButton type="primary" :disabled="recertificationStore.loading" @click="submitReview">
				{{ t('shillinq', 'Submit Review') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { NcBreadcrumb, NcBreadcrumbs, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useUserStore } from '../../store/modules/user.js'
import { useRecertificationStore } from '../../store/modules/recertification.js'

export default {
	name: 'RecertificationReview',
	components: {
		NcBreadcrumb,
		NcBreadcrumbs,
		NcButton,
		NcLoadingIcon,
	},
	data() {
		return {
			userStore: useUserStore(),
			recertificationStore: useRecertificationStore(),
			decisions: {},
		}
	},
	created() {
		this.userStore.fetchUsers()
	},
	methods: {
		getDecision(userId) {
			return this.decisions[userId] || ''
		},
		setDecision(userId, action) {
			this.$set(this.decisions, userId, action)
		},
		async submitReview() {
			const decisionsArray = Object.entries(this.decisions).map(([userId, action]) => ({
				userId,
				action,
			}))
			await this.recertificationStore.submitReview(this.$route.params.id, decisionsArray)
			this.$router.push({ name: 'RecertificationIndex' })
		},
	},
}
</script>

<style scoped>
.shillinq-recert-review {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.shillinq-recert-review__header {
	margin-bottom: 16px;
}

.shillinq-recert-review__table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 16px;
}

.shillinq-recert-review__table th,
.shillinq-recert-review__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.shillinq-recert-review__table td:last-child {
	display: flex;
	gap: 4px;
}
</style>
