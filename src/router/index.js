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

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/shillinq'),
	routes: [
		{
			path: '/',
			name: 'Dashboard',
			component: DashboardPage,
			meta: {
				breadcrumb: [
					{ label: 'Shillinq' },
				],
			},
		},
		{
			path: '/organizations',
			name: 'OrganizationList',
			component: OrganizationList,
			meta: {
				breadcrumb: [
					{ label: 'Shillinq', to: '/' },
					{ label: 'Organizations' },
				],
			},
		},
		{
			path: '/organizations/:id',
			name: 'OrganizationDetail',
			component: OrganizationDetail,
			meta: {
				breadcrumb: [
					{ label: 'Shillinq', to: '/' },
					{ label: 'Organizations', to: '/organizations' },
					{ label: ':name', dynamic: true },
				],
			},
		},
		{
			path: '/data-jobs',
			name: 'DataJobList',
			component: DataJobList,
			meta: {
				breadcrumb: [
					{ label: 'Shillinq', to: '/' },
					{ label: 'Data Jobs' },
				],
			},
		},
		{
			path: '/data-jobs/:id',
			name: 'DataJobDetail',
			component: DataJobDetail,
			meta: {
				breadcrumb: [
					{ label: 'Shillinq', to: '/' },
					{ label: 'Data Jobs', to: '/data-jobs' },
					{ label: ':fileName', dynamic: true },
				],
			},
		},
		{
			path: '/settings',
			name: 'AppSettingsPage',
			component: AppSettingsPage,
			meta: {
				breadcrumb: [
					{ label: 'Shillinq', to: '/' },
					{ label: 'Settings' },
				],
			},
		},
		{
			path: '/settings/admin',
			name: 'AdminSettings',
			component: AdminRoot,
		},
		{
			path: '/settings/preferences',
			name: 'UserPreferencesPage',
			component: UserPreferencesPage,
		},
		{ path: '*', redirect: '/' },
	],
})
