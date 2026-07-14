// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import VueRouter from 'vue-router'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	buildManifest,
	CnPageRenderer,
	defaultPageTypes,
	installIntegrationRegistry,
	registerBuiltinIntegrations,
	registerIcons,
	registerLeafIntegrations,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import registry from './registry.js'
import appIcons from './icons.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'

// Global (unscoped) app styles
import './assets/app.css'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)
Vue.use(VueRouter)

// Populate the shared `window.OCA.OpenRegister.integrations` registry from
// shillinq's OWN bundle. OpenRegister's main.js calls the same three
// functions at ITS bootstrap, but Nextcloud only loads an app's JS bundle
// when a route under that app is active — so on /apps/shillinq routes
// OpenRegister's bootstrap never runs and the registry stays empty unless a
// consuming app populates it itself (mirrors decidesk / pipelinq). This is
// what surfaces the `flow` (NC Flow) and `talk` (NC Talk) — plus every other
// leaf — sidebar tab and widget on shillinq's object detail pages; per
// CnObjectSidebar's `useRegistry` default, no per-page manifest change is
// needed for pages that don't already declare a bespoke `sidebarProps.tabs`.
installIntegrationRegistry()
registerBuiltinIntegrations()
registerLeafIntegrations()

// Register the app's MDI icon set + lib translations once at bootstrap.
// registerIcons() merges the given map into the lib's ICON_MAP registry;
// calling it without arguments registers nothing, which left every
// MDI-named menu icon rendering blank.
registerIcons(appIcons)
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn('[shillinq] registerTranslations failed; falling back to English', e)
}

// Fire-and-forget translation load. Some Nextcloud installs (including
// this repo's standard dev container) only allow the JS/CSS allowlist
// through Apache and rewrite everything else to index.php — there's no
// route for /custom_apps/<app>/l10n/<locale>.json so the request 404s.
// `loadTranslations` rejects on 404, so wrapping the Vue mount inside its
// callback meant boot silently failed when translations couldn't load.
// Strings just fall back to their English source on miss; boot MUST NOT
// depend on this resolving.
function tryLoadTranslations() {
	try {
		const result = loadTranslations('shillinq', () => {})
		if (result && typeof result.then === 'function') {
			result.then(() => {}, () => {})
		}
	} catch {
		// no-op
	}
}

// Shallow-clone CnPageRenderer because the lib's barrel exports are
// non-extensible (webpack ESM module records). Vue 2's `Vue.extend()`
// adds an internal `_Ctor` cache to the component definition; mutating a
// non-extensible export throws "Cannot add property _Ctor, object is not
// extensible". Cloning gives Vue Router an extensible component-options
// object without altering the lib's internals.
const RoutePageRenderer = { ...CnPageRenderer }

const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx.keys().sort().map((key) => fragmentCtx(key))
const mergedManifest = buildManifest(bundledManifest, fragments, menuLayout)

/**
 * Build the vue-router config from the manifest. Each manifest page becomes
 * one route; the route's `name` IS `page.id` (per the lib's manifest contract).
 * Routes whose path declares a `:` parameter receive `props: true` so the
 * underlying detail/custom component receives the route param.
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 3 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to the dashboard, preserving prior router behaviour.
	routes.push({ path: '*', redirect: '/' })
	return routes
}

const router = new VueRouter({
	mode: 'history',
	base: generateUrl('/apps/shillinq'),
	routes: routesFromManifest(mergedManifest),
})

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot. The lib exports
// `defaultPageTypes` and `registry` as frozen module objects in some bundle
// shapes — Vue 2's `Vue.extend()` mutates component definitions to attach an
// internal `_Ctor` cache, which throws "Cannot add property _Ctor, object is
// not extensible" against a frozen source map. Cloning yields extensible
// objects without changing the resolved values.
const pageTypesProp = { ...defaultPageTypes }
const registryProp = { ...registry }

// Flat `{ name: component }` map of every registry component, derived from
// the kind-tagged `registry`. The published `@conduction/nextcloud-vue` beta
// resolves a page's `component` / `headerComponent` / `actionsComponent` and a
// dashboard widget's custom `widgetKey` against the `customComponents` prop,
// NOT the kind-tagged `registry`. Without this map every custom page (the
// bookings calendar, the confirmation portal, the WBSO views, …) renders an
// empty `cn-page` shell AND custom action/widget components (e.g. the Dashboard
// `actionsComponent: FinancialDashboardActions`) silently disappear. Flatten
// ALL kinds (page + widget + …) so every name a manifest can reference resolves.
// Mirrors the procest / docudesk / opencatalogi wiring.
const customComponentsProp = Object.fromEntries(
	Object.entries(registry)
		.filter(([, entry]) => entry && entry.component)
		.map(([name, entry]) => [name, entry.component]),
)

new Vue({
	pinia,
	router,
	render: (h) => h(App, {
		props: {
			manifest: mergedManifest,
			pageTypes: pageTypesProp,
			registry: registryProp,
			customComponents: customComponentsProp,
		},
	}),
}).$mount('#content')
