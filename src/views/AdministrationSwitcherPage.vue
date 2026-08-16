<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Hosts AdministratieSwitcher as a navigable manifest page (Task 13). The
 declarative manifest shell does not have a global header slot, so the
 switcher is exposed via a top-level "Switch administration" menu entry that
 renders the dropdown plus a help blurb. Single-administratie users see a
 short explainer instead of a useless dropdown (REQ-MA-003).

 @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-13
-->
<template>
	<NcAppContent>
		<div class="administratie-switcher-page">
			<h2 class="administratie-switcher-page__title">
				{{ t('shillinq', 'Switch administration') }}
			</h2>
			<p class="administratie-switcher-page__description">
				{{
					t(
						'shillinq',
						'Select which administration you want to work in. Only administrations you have a membership for are listed.',
					)
				}}
			</p>
			<AdministratieSwitcher :reloadAfterSwitch="true" @error="onError" />
			<p
				v-if="errorMessage"
				class="administratie-switcher-page__error"
				role="alert">
				{{ errorMessage }}
			</p>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent } from '@nextcloud/vue'
import AdministratieSwitcher from '../components/AdministratieSwitcher.vue'

export default {
	name: 'AdministrationSwitcherPage',

	components: {
		NcAppContent,
		AdministratieSwitcher,
	},

	data() {
		return {
			errorMessage: '',
		}
	},

	methods: {
		/**
		 * Propagate AdministratieSwitcher errors into the page header.
		 *
		 * @param {object} error Axios error from the switcher.
		 * @return {void}
		 */
		onError(error) {
			const status = error?.response?.status
			if (status === 401) {
				this.errorMessage = t(
					'shillinq',
					'Please sign in to switch administrations.',
				)
				return
			}
			this.errorMessage = t('shillinq', 'Failed to load administrations.')
		},
	},
}
</script>

<style scoped>
.administratie-switcher-page {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}

.administratie-switcher-page__title {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 2);
}

.administratie-switcher-page__description {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 3);
	color: var(--color-text-maxcontrast, #555);
}

.administratie-switcher-page__error {
	margin: calc(var(--default-grid-baseline, 4px) * 2) 0 0;
	color: var(--color-error, #d40000);
}
</style>
