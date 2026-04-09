import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'
import { useRoleStore } from './modules/role.js'
import { useTeamStore } from './modules/team.js'
import { useUserStore } from './modules/user.js'
import { useAccessControlStore } from './modules/accessControl.js'
import { useDelegationStore } from './modules/delegation.js'
import { useRecertificationStore } from './modules/recertification.js'

export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()
	const roleStore = useRoleStore()
	const teamStore = useTeamStore()
	const userStore = useUserStore()
	const accessControlStore = useAccessControlStore()
	const delegationStore = useDelegationStore()
	const recertificationStore = useRecertificationStore()

	objectStore.configure({
		baseUrl: generateUrl('/apps/openregister/api/objects'),
		schemaBaseUrl: generateUrl('/apps/openregister/api/schemas'),
	})

	await settingsStore.fetchSettings()

	return {
		settingsStore,
		objectStore,
		roleStore,
		teamStore,
		userStore,
		accessControlStore,
		delegationStore,
		recertificationStore,
	}
}

export {
	useObjectStore,
	useSettingsStore,
	useRoleStore,
	useTeamStore,
	useUserStore,
	useAccessControlStore,
	useDelegationStore,
	useRecertificationStore,
}
