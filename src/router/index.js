import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import Dashboard from '../views/Dashboard.vue'
import AdminRoot from '../views/settings/AdminRoot.vue'
import RoleIndex from '../views/role/RoleIndex.vue'
import RoleDetail from '../views/role/RoleDetail.vue'
import TeamIndex from '../views/team/TeamIndex.vue'
import TeamDetail from '../views/team/TeamDetail.vue'
import UserIndex from '../views/user/UserIndex.vue'
import UserDetail from '../views/user/UserDetail.vue'
import AccessControlIndex from '../views/accessControl/AccessControlIndex.vue'
import AccessControlDetail from '../views/accessControl/AccessControlDetail.vue'
import RecertificationIndex from '../views/recertification/RecertificationIndex.vue'
import RecertificationReview from '../views/recertification/RecertificationReview.vue'
import AccessRightsReport from '../views/report/AccessRightsReport.vue'

Vue.use(Router)

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/shillinq'),
	routes: [
		{ path: '/', name: 'Dashboard', component: Dashboard },
		{ path: '/settings', name: 'Settings', component: AdminRoot },
		{ path: '/roles', name: 'Roles', component: RoleIndex, meta: { breadcrumb: ['Shillinq', 'Security', 'Roles'] } },
		{ path: '/roles/:id', name: 'RoleDetail', component: RoleDetail, meta: { breadcrumb: ['Shillinq', 'Security', 'Roles', ':name'] } },
		{ path: '/teams', name: 'Teams', component: TeamIndex, meta: { breadcrumb: ['Shillinq', 'Security', 'Teams'] } },
		{ path: '/teams/:id', name: 'TeamDetail', component: TeamDetail, meta: { breadcrumb: ['Shillinq', 'Security', 'Teams', ':name'] } },
		{ path: '/users', name: 'Users', component: UserIndex, meta: { breadcrumb: ['Shillinq', 'Security', 'Users'] } },
		{ path: '/users/:id', name: 'UserDetail', component: UserDetail, meta: { breadcrumb: ['Shillinq', 'Security', 'Users', ':name'] } },
		{ path: '/access-log', name: 'AccessLog', component: AccessControlIndex, meta: { breadcrumb: ['Shillinq', 'Security', 'Access Log'] } },
		{ path: '/access-log/:id', name: 'AccessControlDetail', component: AccessControlDetail, meta: { breadcrumb: ['Shillinq', 'Security', 'Access Log', ':id'] } },
		{ path: '/recertifications', name: 'Recertifications', component: RecertificationIndex, meta: { breadcrumb: ['Shillinq', 'Security', 'Recertification'] } },
		{ path: '/recertifications/:id/review', name: 'RecertificationReview', component: RecertificationReview, meta: { breadcrumb: ['Shillinq', 'Security', 'Recertification', 'Review'] } },
		{ path: '/reports/access-rights', name: 'AccessRightsReport', component: AccessRightsReport, meta: { breadcrumb: ['Shillinq', 'Security', 'Reports', 'Access Rights'] } },
		{ path: '*', redirect: '/' },
	],
})
