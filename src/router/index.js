// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import Dashboard from '../views/Dashboard.vue'
import AdminRoot from '../views/settings/AdminRoot.vue'
import CommentList from '../views/comment/CommentList.vue'
import CommentDetail from '../views/comment/CommentDetail.vue'
import CollaborationRoleList from '../views/collaborationRole/CollaborationRoleList.vue'

Vue.use(Router)

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/shillinq'),
	routes: [
		{ path: '/', name: 'Dashboard', component: Dashboard },
		{ path: '/settings', name: 'Settings', component: AdminRoot },
		{
			path: '/comments',
			name: 'CommentList',
			component: CommentList,
			meta: { breadcrumb: 'Comments' },
		},
		{
			path: '/comments/:commentId',
			name: 'CommentDetail',
			component: CommentDetail,
			meta: { breadcrumb: 'Comment Detail' },
			props: true,
		},
		{
			path: '/collaboration/roles',
			name: 'CollaborationRoleList',
			component: CollaborationRoleList,
			meta: { breadcrumb: 'Team Roles' },
		},
		{ path: '*', redirect: '/' },
	],
})
