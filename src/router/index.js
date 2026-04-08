import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import Dashboard from '../views/Dashboard.vue'
import AdminRoot from '../views/settings/AdminRoot.vue'

Vue.use(Router)

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/app-template'),
	routes: [
		{ path: '/', name: 'Dashboard', component: Dashboard },
		{ path: '/settings', name: 'Settings', component: AdminRoot },
		{ path: '*', redirect: '/' },
	],
})
