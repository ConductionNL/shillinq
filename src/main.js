// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import VueRouter from 'vue-router'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	CnPageRenderer,
	defaultPageTypes,
	registerIcons,
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

/**
 * Merge an array of incoming menu items into a target array, keyed by `id`.
 * New ids are appended; existing ids are merged in place: the first
 * definition of `label` / `icon` / `route` / `order` wins (the base manifest
 * loads first, so its canonical group definitions take precedence), and
 * `children` are unioned recursively by the same rule. Fragments may
 * therefore extend an existing group by re-declaring only its `id` plus
 * their own `children`.
 *
 * @param {Array<object>} target The accumulated menu (mutated in place).
 * @param {Array<object>} incoming Menu items from a fragment.
 * @return {void}
 */
function mergeMenuItems(target, incoming) {
	incoming.forEach((item) => {
		const existing = target.find((t) => t.id === item.id)
		if (!existing) {
			target.push({ ...item, children: Array.isArray(item.children) ? [...item.children] : item.children })
			return
		}
		for (const key of ['label', 'icon', 'route', 'order', 'section', 'featureFlag', 'permission', 'visibleIf', 'href', 'action']) {
			if (existing[key] === undefined && item[key] !== undefined) {
				existing[key] = item[key]
			}
		}
		if (Array.isArray(item.children) && item.children.length > 0) {
			if (!Array.isArray(existing.children)) {
				existing.children = []
			}
			mergeMenuItems(existing.children, item.children)
		}
	})
}

/**
 * ADR-037: merge modular manifest fragments from src/manifest.d/*.json onto the
 * bundled base manifest. Each OpenSpec change drops its own fragment (pages/menu)
 * instead of editing the monolith src/manifest.json, so concurrent builds touch
 * disjoint files. `pages` are concatenated; `menu` items are merged by `id`
 * (top-level and children) so fragments that re-declare an existing group
 * (e.g. "Bookkeeping") extend it instead of duplicating it in the navigation.
 *
 * @param {object} base The bundled base manifest.
 * @return {object} The manifest with all fragment pages/menu merged in.
 */
function mergeManifestFragments(base) {
	const merged = { ...base, pages: [...(base.pages || [])], menu: [] }
	mergeMenuItems(merged.menu, base.menu || [])
	// require.context is resolved at build time; src/manifest.d/ must exist (it
	// ships with a .gitkeep). It is a no-op when the directory holds no fragments.
	const ctx = require.context('./manifest.d/', false, /\.json$/)
	ctx.keys().sort().forEach((key) => {
		const frag = ctx(key)
		if (Array.isArray(frag.pages)) {
			mergePages(merged.pages, frag.pages)
		}
		if (Array.isArray(frag.menu)) {
			mergeMenuItems(merged.menu, frag.menu)
		}
	})
	merged.menu = applyMenuRelocations(merged.menu, menuLayout.relocations)
	merged.menu = applyMenuRemovals(merged.menu, menuLayout.removals)
	return merged
}

/**
 * Merge fragment pages onto the accumulated page list by `id` — a later
 * declaration REPLACES an earlier one wholesale. Several fragment chains
 * (purchase-order-3way slices 02/05/08, waterschappen-bbv slice 07) were
 * authored against this ADR-037 overlay semantic — re-declaring a page id
 * with `type: "custom"` to swap the bare schema-driven detail for a bespoke
 * component — but pages used to be concatenated, so the overlay silently
 * never applied (vue-router and CnPageRenderer both resolve the FIRST
 * declaration of a route name). Duplicate ids also registered duplicate
 * route names. Replace-by-id makes the documented overlay actually work
 * and guarantees one route per page id.
 *
 * @param {Array<object>} target Accumulated pages (mutated in place).
 * @param {Array<object>} incoming Pages from a fragment.
 * @return {void}
 */
function mergePages(target, incoming) {
	incoming.forEach((page) => {
		const idx = target.findIndex((p) => p.id === page.id)
		if (idx === -1) {
			target.push(page)
		} else {
			target[idx] = page
		}
	})
}

/**
 * Re-home merged menu entries onto the canonical navigation layout declared
 * by `src/menu-layout.json#relocations` (`{ sourceId: targetGroupId }`).
 *
 * Fragments stay the canonical source of WHAT exists in the menu (per
 * ADR-037 they drop entries wherever their change authored them); this map
 * is the single place that decides WHERE entries live, so the navigation
 * can be consolidated without rewriting dozens of fragments:
 *
 *  - A relocated GROUP dissolves: its children merge (by id) into the
 *    target group and the now-empty shell is dropped.
 *  - A relocated LEAF (top-level or child of any group) moves under the
 *    target group.
 *  - A child group relocated onto its own parent flattens into it
 *    (used for legacy two-level nests like Bookkeeping → Dimensions).
 *  - Unknown source ids are inert; a missing target group keeps the entry
 *    at the top level so nothing silently disappears.
 *
 * Runs in passes until stable (children freed by a dissolved group can
 * themselves be relocated on the next pass).
 *
 * @param {Array<object>} menu The merged menu (mutated in place).
 * @param {Record<string, string>|undefined} relocations Source-id → target-group-id map.
 * @return {Array<object>} The menu with relocations applied.
 */
function applyMenuRelocations(menu, relocations) {
	if (!relocations || typeof relocations !== 'object') return menu
	for (let pass = 0; pass < 5; pass++) {
		const moves = []
		for (let i = menu.length - 1; i >= 0; i--) {
			const node = menu[i]
			const target = relocations[node.id]
			if (target && target !== node.id) {
				menu.splice(i, 1)
				moves.push({ node, target })
				continue
			}
			if (!Array.isArray(node.children)) continue
			for (let j = node.children.length - 1; j >= 0; j--) {
				const child = node.children[j]
				const childTarget = relocations[child.id]
				// A leaf already sitting in its target group stays put; a
				// child GROUP targeting its own parent is a flatten request.
				if (!childTarget) continue
				if (childTarget === node.id && !Array.isArray(child.children)) continue
				node.children.splice(j, 1)
				moves.push({ node: child, target: childTarget })
			}
		}
		if (moves.length === 0) break
		moves.forEach(({ node, target }) => {
			const group = menu.find((m) => m.id === target)
			if (!group) {
				menu.push(node)
				return
			}
			if (!Array.isArray(group.children)) group.children = []
			if (Array.isArray(node.children)) {
				mergeMenuItems(group.children, node.children)
			} else {
				mergeMenuItems(group.children, [node])
			}
		})
	}
	// Drop empty group shells left behind by relocations.
	return menu.filter((m) => m.route || m.href || m.action
		|| (Array.isArray(m.children) && m.children.length > 0))
}

/**
 * Remove individual menu entries by id after relocation — used to retire
 * duplicate navigation entries whose PAGE must stay routable (deep links
 * and e2e specs hit the route directly). Declared in
 * `src/menu-layout.json#removals`. Only leaf entries are removed; group ids
 * are ignored so a removal can never silently hide a whole cluster.
 *
 * @param {Array<object>} menu The merged menu (mutated in place).
 * @param {Array<string>|undefined} removals Menu-entry ids to drop.
 * @return {Array<object>} The menu without the removed entries.
 */
function applyMenuRemovals(menu, removals) {
	if (!Array.isArray(removals) || removals.length === 0) return menu
	const drop = new Set(removals)
	const isLeaf = (n) => !Array.isArray(n.children) || n.children.length === 0
	menu.forEach((node) => {
		if (Array.isArray(node.children)) {
			node.children = node.children.filter((c) => !(drop.has(c.id) && isLeaf(c)))
		}
	})
	return menu.filter((n) => !(drop.has(n.id) && isLeaf(n)))
}

const mergedManifest = mergeManifestFragments(bundledManifest)

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
