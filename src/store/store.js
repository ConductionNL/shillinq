// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'
import { useCommentStore } from './modules/comment.js'
import { useCollaborationRoleStore } from './modules/collaborationRole.js'
import { usePresenceStore } from './modules/presence.js'

export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()
	const commentStore = useCommentStore()
	const collaborationRoleStore = useCollaborationRoleStore()
	const presenceStore = usePresenceStore()

	objectStore.configure({
		baseUrl: generateUrl('/apps/openregister/api/objects'),
		schemaBaseUrl: generateUrl('/apps/openregister/api/schemas'),
	})

	await settingsStore.fetchSettings()

	return { settingsStore, objectStore, commentStore, collaborationRoleStore, presenceStore }
}

export { useObjectStore, useSettingsStore, useCommentStore, useCollaborationRoleStore, usePresenceStore }
