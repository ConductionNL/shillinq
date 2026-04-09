<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<CnDetailPage :title="t('shillinq', 'Recertification Review')">
		<NcLoadingIcon v-if="userStore.loading" />

		<template v-else>
			<table class="shillinq-recert-review__table">
				<thead>
					<tr>
						<th>{{ t('shillinq', 'User') }}</th>
						<th>{{ t('shillinq', 'Last Login') }}</th>
						<th>{{ t('shillinq', 'Decision') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="user in userStore.users" :key="user.id">
						<td>{{ user.displayName }} ({{ user.username }})</td>
						<td>{{ user.lastLogin || '—' }}</td>
						<td>
							<NcButton :type="decisions[user.username] === 'confirm' ? 'primary' : 'secondary'"
								@click="setDecision(user.username, 'confirm')">
								{{ t('shillinq', 'Confirm Access') }}
							</NcButton>
							<NcButton :type="decisions[user.username] === 'revoke' ? 'error' : 'secondary'"
								@click="setDecision(user.username, 'revoke')">
								{{ t('shillinq', 'Revoke Access') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<NcButton type="primary" @click="submitDecisions">
				{{ t('shillinq', 'Submit Review') }}
			</NcButton>
		</template>
	</CnDetailPage>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { CnDetailPage } from '@conduction/nextcloud-vue'
import { useUserStore } from '../../store/modules/user.js'
import { useRecertificationStore } from '../../store/modules/recertification.js'

export default {
	name: 'RecertificationReview',
	components: { CnDetailPage, NcButton, NcLoadingIcon },
	data() {
		return {
			userStore: useUserStore(),
			recertStore: useRecertificationStore(),
			decisions: {},
		}
	},
	created() {
		this.userStore.fetchUsers()
	},
	methods: {
		setDecision(userId, action) {
			this.$set(this.decisions, userId, action)
		},
		async submitDecisions() {
			const decisionArray = Object.entries(this.decisions).map(([userId, action]) => ({
				userId,
				action,
			}))
			await this.recertStore.submitReview(this.$route.params.id, decisionArray)
			this.$router.push({ name: 'Recertifications' })
		},
	},
}
</script>

<style scoped>
.shillinq-recert-review__table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
.shillinq-recert-review__table th, .shillinq-recert-review__table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
.shillinq-recert-review__table td:last-child { display: flex; gap: 8px; }
</style>
