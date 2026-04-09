<template>
	<NcAppNavigation>
		<template #list>
			<NcAppNavigationItem
				:name="t('shillinq', 'Dashboard')"
				:to="{ name: 'Dashboard' }"
				:exact="true">
				<template #icon>
					<HomeIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('shillinq', 'Collaboration')"
				:open="true">
				<template #icon>
					<AccountGroupOutline :size="20" />
				</template>
				<template #counter>
					<NcCounterBubble v-if="unresolvedCount > 0">
						{{ unresolvedCount }}
					</NcCounterBubble>
				</template>
				<NcAppNavigationItem
					:name="t('shillinq', 'Comments')"
					:to="{ name: 'CommentList' }">
					<template #icon>
						<CommentTextOutline :size="20" />
					</template>
					<template #counter>
						<NcCounterBubble v-if="unresolvedCount > 0">
							{{ unresolvedCount }}
						</NcCounterBubble>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('shillinq', 'Team Roles')"
					:to="{ name: 'CollaborationRoleList' }">
					<template #icon>
						<ShieldAccountOutline :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('shillinq', 'Documentation')"
				@click="openLink('https://conduction.nl', '_blank')">
				<template #icon>
					<BookOpenVariantOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<NcAppNavigationItem
				:name="t('shillinq', 'Settings')"
				:to="{ name: 'Settings' }">
				<template #icon>
					<CogIcon :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem, NcCounterBubble } from '@nextcloud/vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import CommentTextOutline from 'vue-material-design-icons/CommentTextOutline.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import ShieldAccountOutline from 'vue-material-design-icons/ShieldAccountOutline.vue'
import { useCommentStore } from '../store/modules/comment.js'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcCounterBubble,
		AccountGroupOutline,
		BookOpenVariantOutline,
		CogIcon,
		CommentTextOutline,
		HomeIcon,
		ShieldAccountOutline,
	},
	computed: {
		unresolvedCount() {
			const commentStore = useCommentStore()
			return commentStore.comments.filter(c => !c.resolved).length
		},
	},
	methods: {
		openLink(url, target = '_blank') {
			window.open(url, target)
		},
	},
}
</script>
