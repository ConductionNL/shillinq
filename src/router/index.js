import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import Dashboard from '../views/Dashboard.vue'
import AdminRoot from '../views/settings/AdminRoot.vue'
import AnalyticsDashboard from '../views/analytics/AnalyticsDashboard.vue'
import AnalyticsReportList from '../views/analytics/AnalyticsReportList.vue'
import AnalyticsReportDetail from '../views/analytics/AnalyticsReportDetail.vue'
import PortalTokenList from '../views/portal/PortalTokenList.vue'
import PortalTokenDetail from '../views/portal/PortalTokenDetail.vue'
import PortalInvoiceList from '../views/portal/PortalInvoiceList.vue'
import AutomationRuleList from '../views/automationRule/AutomationRuleList.vue'
import AutomationRuleDetail from '../views/automationRule/AutomationRuleDetail.vue'
import ExpenseClaimList from '../views/expenseClaim/ExpenseClaimList.vue'
import ExpenseClaimDetail from '../views/expenseClaim/ExpenseClaimDetail.vue'

Vue.use(Router)

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/shillinq'),
	routes: [
		{
			path: '/',
			name: 'Dashboard',
			component: Dashboard,
			meta: { breadcrumb: 'Dashboard' },
		},
		{
			path: '/settings',
			name: 'Settings',
			component: AdminRoot,
			meta: { breadcrumb: 'Settings' },
		},
		{
			path: '/analytics',
			name: 'AnalyticsDashboard',
			component: AnalyticsDashboard,
			meta: { breadcrumb: 'Analytics' },
		},
		{
			path: '/analytics/reports',
			name: 'AnalyticsReportList',
			component: AnalyticsReportList,
			meta: { breadcrumb: 'Reports' },
		},
		{
			path: '/analytics/reports/:reportId',
			name: 'AnalyticsReportDetail',
			component: AnalyticsReportDetail,
			meta: { breadcrumb: 'Report Detail' },
		},
		{
			path: '/portal',
			name: 'PortalTokenList',
			component: PortalTokenList,
			meta: { breadcrumb: 'Portal' },
		},
		{
			path: '/portal/invoices',
			name: 'PortalInvoiceList',
			component: PortalInvoiceList,
			meta: { breadcrumb: 'Portal Invoices' },
		},
		{
			path: '/portal/:tokenId',
			name: 'PortalTokenDetail',
			component: PortalTokenDetail,
			meta: { breadcrumb: 'Token Detail' },
		},
		{
			path: '/automation',
			name: 'AutomationRuleList',
			component: AutomationRuleList,
			meta: { breadcrumb: 'Automation' },
		},
		{
			path: '/automation/:ruleId',
			name: 'AutomationRuleDetail',
			component: AutomationRuleDetail,
			meta: { breadcrumb: 'Rule Detail' },
		},
		{
			path: '/expenses',
			name: 'ExpenseClaimList',
			component: ExpenseClaimList,
			meta: { breadcrumb: 'Expenses' },
		},
		{
			path: '/expenses/:claimId',
			name: 'ExpenseClaimDetail',
			component: ExpenseClaimDetail,
			meta: { breadcrumb: 'Claim Detail' },
		},
		{ path: '*', redirect: '/' },
	],
})
