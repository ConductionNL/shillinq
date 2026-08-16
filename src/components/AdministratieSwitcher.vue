<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 AdministratieSwitcher (Task 13)

 Thin client over the AdministrationController context/switch API. Lists the
 user's accessible administraties, highlights the active one, and lets the
 user switch in-session (REQ-MA-003). The component:

 - hides itself when the user has zero or one accessible administraties — a
   single-administratie user has nothing to switch (REQ-MA-003);
 - validates access via the server's masked-404 response: the dropdown only
   ever lists the user's own memberships, never confirms others exist
   (REQ-MA-001);
 - supports keyboard navigation (Tab/Shift+Tab through items, Enter to
   select, Esc to close) via the underlying NcActions menu;
 - reloads the page after a successful switch so every page picks up the
   new active administration on the next request — matches the
   single-source-of-truth session semantics declared in the design.

 @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-13
-->
<template>
	<div
		v-if="visibleAdministrations.length > 1"
		class="administratie-switcher"
		:aria-busy="loading ? 'true' : 'false'"
		role="region"
		:aria-label="t('shillinq', 'Switch administration')">
		<NcActions
			:menuName="activeLabel"
			:title="t('shillinq', 'Switch administration')"
			:disabled="loading"
			:forceName="true"
			variant="tertiary"
			:aria-label="t('shillinq', 'Switch administration')">
			<template #icon>
				<OfficeBuilding :size="20" />
			</template>
			<NcActionCaption :name="captionLabel" />
			<NcActionButton
				v-for="administration in visibleAdministrations"
				:key="administration.administrationId"
				:disabled="loading || administration.administrationId === activeId"
				:closeAfterClick="true"
				@click="onSelect(administration.administrationId)">
				<template #icon>
					<CheckBold
						v-if="administration.administrationId === activeId"
						:size="18" />
					<OfficeBuildingOutline v-else :size="18" />
				</template>
				{{ formatLabel(administration) }}
			</NcActionButton>
		</NcActions>
		<p v-if="errorMessage" class="administratie-switcher__error" role="alert">
			{{ errorMessage }}
		</p>
	</div>
</template>

<script>
import { NcActionButton, NcActionCaption, NcActions } from '@nextcloud/vue'
import CheckBold from 'vue-material-design-icons/CheckBold.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import {
	fetchAdministrationContext,
	switchAdministration,
} from '../api/administrationApi.js'

export default {
	name: 'AdministratieSwitcher',

	components: {
		NcActions,
		NcActionButton,
		NcActionCaption,
		CheckBold,
		OfficeBuilding,
		OfficeBuildingOutline,
	},

	props: {
		/**
		 * When true, a successful switch reloads the page to refresh every
		 * page's data. Defaults to true; tests pass false to assert state.
		 */
		reloadAfterSwitch: {
			type: Boolean,
			default: true,
		},
	},

	emits: ['switched', 'error'],

	data() {
		return {
			loading: false,
			administrations: [],
			activeId: null,
			errorMessage: '',
		}
	},

	computed: {
		/**
		 * Administraties to render. The list comes from the server already
		 * filtered to the user's memberships (REQ-MA-001) — we just hide
		 * the switcher when there is nothing to switch.
		 *
		 * @return {Array<object>}
		 */
		visibleAdministrations() {
			return this.administrations
		},

		/**
		 * Label shown on the closed dropdown button.
		 *
		 * @return {string}
		 */
		activeLabel() {
			const active = this.administrations.find(
				(item) => item.administrationId === this.activeId,
			)
			if (!active) {
				return t('shillinq', 'Switch administration')
			}
			return this.formatLabel(active)
		},

		/**
		 * Caption displayed at the top of the dropdown menu.
		 *
		 * @return {string}
		 */
		captionLabel() {
			return t('shillinq', '{count} accessible administrations', {
				count: this.administrations.length,
			})
		},
	},

	async mounted() {
		await this.loadContext()
	},

	methods: {
		/**
		 * Load the administration context from the server.
		 *
		 * @return {Promise<void>}
		 */
		async loadContext() {
			this.loading = true
			this.errorMessage = ''
			try {
				const context = await fetchAdministrationContext()
				this.administrations = Array.isArray(context.administrations)
					? context.administrations
					: []
				this.activeId = context.activeAdministrationId || null
			} catch (error) {
				this.administrations = []
				this.activeId = null
				this.errorMessage = t('shillinq', 'Failed to load administrations')
				this.$emit('error', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Format a single administration entry for the dropdown.
		 *
		 * @param {object} administration The administration record from the context API.
		 * @return {string}
		 */
		formatLabel(administration) {
			const code = administration.administrationCode || ''
			const name = administration.name || ''
			if (code && name) {
				return `${code} — ${name}`
			}
			return name || code || administration.administrationId
		},

		/**
		 * Handle a user selection. Validates access via the server (masked
		 * 404 on failure) and updates the active administration.
		 *
		 * @param {string} administrationId The selected administration id.
		 * @return {Promise<void>}
		 */
		async onSelect(administrationId) {
			if (this.loading) {
				return
			}
			if (administrationId === this.activeId) {
				return
			}
			this.loading = true
			this.errorMessage = ''
			try {
				const response = await switchAdministration(administrationId)
				this.activeId = response.activeAdministrationId
				this.$emit('switched', this.activeId)
				if (
					this.reloadAfterSwitch
					&& typeof window !== 'undefined'
					&& window.location
				) {
					window.location.reload()
				}
			} catch (error) {
				const status = error?.response?.status
				if (status === 404) {
					this.errorMessage = t('shillinq', 'Administration not found')
				} else if (status === 401) {
					this.errorMessage = t('shillinq', 'Not authenticated')
				} else {
					this.errorMessage = t(
						'shillinq',
						'Failed to switch administration',
					)
				}
				this.$emit('error', error)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.administratie-switcher {
	display: inline-flex;
	align-items: center;
	gap: var(--default-grid-baseline, 4px);
}

.administratie-switcher__error {
	margin: 0 0 0 var(--default-grid-baseline, 4px);
	color: var(--color-error, #d40000);
	font-size: 0.85em;
}
</style>
