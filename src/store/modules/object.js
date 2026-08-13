import { defineStore } from 'pinia'

/**
 * Generic OpenRegister object store.
 * Configure it with baseUrl and schemaBaseUrl, then register object types.
 */
export const useObjectStore = defineStore('object', {
	state: () => ({
		baseUrl: '',
		schemaBaseUrl: '',
		objectTypes: {},
		objects: {},
		loading: {},
	}),

	actions: {
		/**
		 * Configure the store with the OpenRegister object/schema base URLs.
		 *
		 * @param {object} opts Configuration options.
		 * @param {string} opts.baseUrl OpenRegister object API base URL.
		 * @param {string} opts.schemaBaseUrl OpenRegister schema API base URL.
		 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-5
		 */
		configure({ baseUrl, schemaBaseUrl }) {
			this.baseUrl = baseUrl
			this.schemaBaseUrl = schemaBaseUrl
		},

		/**
		 * Register a named object type mapped to its register + schema.
		 *
		 * @param {string} type Logical object type name.
		 * @param {string} schema OpenRegister schema slug.
		 * @param {string} register OpenRegister register slug.
		 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-5
		 */
		registerObjectType(type, schema, register) {
			this.objectTypes[type] = { schema, register }
			if (!this.objects[type]) {
				this.objects[type] = []
			}
		},

		/**
		 * Fetch objects for a registered type from the OpenRegister object API.
		 * Warns and returns an empty list for unregistered types.
		 *
		 * @param {string} type Registered object type.
		 * @param {object} params Extra query parameters.
		 * @return {Promise<Array>} The fetched objects (empty on miss/error).
		 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-5
		 */
		async fetchObjects(type, params = {}) {
			if (!this.objectTypes[type]) {
				console.warn(`Object type "${type}" is not registered`)
				return []
			}

			this.loading[type] = true
			const { schema, register } = this.objectTypes[type]

			try {
				const url = new URL(this.baseUrl, window.location.origin)
				url.searchParams.set('register', register)
				url.searchParams.set('schema', schema)
				Object.entries(params).forEach(([k, v]) =>
					url.searchParams.set(k, v),
				)

				const response = await fetch(url.toString(), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.objects[type] = data.results || data
					return this.objects[type]
				}
			} catch (error) {
				console.error(`Failed to fetch ${type} objects:`, error)
			} finally {
				this.loading[type] = false
			}
			return []
		},
	},
})
