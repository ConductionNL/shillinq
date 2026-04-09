// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import DashboardPage from '../views/dashboard/DashboardPage.vue'
import OrganizationList from '../views/organization/OrganizationList.vue'
import OrganizationDetail from '../views/organization/OrganizationDetail.vue'
import DataJobList from '../views/dataJob/DataJobList.vue'
import DataJobDetail from '../views/dataJob/DataJobDetail.vue'
import AppSettingsPage from '../views/appSettings/AppSettingsPage.vue'
import UserPreferencesPage from '../views/settings/UserPreferencesPage.vue'
import AdminRoot from '../views/settings/AdminRoot.vue'

Vue.use(Router)

/**
 * @spec openspec/changes/core/tasks.md#task-8.5
 */
export default new Router({
	mode: 'history',
	base: generateUrl('/apps/shillinq'),
	routes: [
		{ path: '/', name: 'Dashboard', component: DashboardPage },
		{ path: '/organizations', name: 'OrganizationList', component: OrganizationList },
		{ path: '/organizations/:id', name: 'OrganizationDetail', component: OrganizationDetail },
		{ path: '/data-jobs', name: 'DataJobList', component: DataJobList },
		{ path: '/data-jobs/:id', name: 'DataJobDetail', component: DataJobDetail },
		{ path: '/settings', name: 'Settings', component: AppSettingsPage },
		{ path: '/preferences', name: 'UserPreferences', component: UserPreferencesPage },
		{ path: '/admin', name: 'Admin', component: AdminRoot },
		{ path: '*', redirect: '/' },
	],
})
