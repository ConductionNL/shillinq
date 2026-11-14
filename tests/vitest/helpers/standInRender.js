/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Server-render a view whose child components are replaced by recording
 * stand-ins, and hand back the markup, the live root instance and what each
 * stand-in received.
 *
 * By default a stand-in calls its slots as plain functions, so the view's
 * compiled slot functions take their client-vnode branch: every element, listener
 * and `v-model` writer the template wires inside a component's slot comes
 * back as a vnode a spec can find and fire (`onSubmit`, `onClick`,
 * `onUpdate:modelValue`). A server render never runs event handlers itself,
 * so firing one changes the live instance's state; `rerender()` renders the
 * view again from that state to read what the user would then see. With
 * `slots: 'ssr'` the stand-ins render their slots the server way instead,
 * which takes the other branch of the same slot functions (markup only, no
 * vnodes to fire). The state a spec passes in is copied, never shared.
 * Lifecycle hooks that load data on mount do not run on the server, so the
 * state a spec starts from is the one it passes in.
 */

import { createSSRApp, h } from 'vue'
import { renderToString, ssrRenderAttrs, ssrRenderSlot, ssrRenderVNode } from 'vue/server-renderer'

let seen = []
let slotMode = 'vnode'

/**
 * A stand-in child component: records its attributes (listeners included)
 * and the vnodes its slots return, and renders those slots.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
export function standIn(name) {
	return {
		name,
		inheritAttrs: false,
		ssrRender(ctx, push, parent) {
			if (slotMode === 'ssr') {
				seen.push({ name, attrs: { ...ctx.$attrs }, vnodes: [] })
				push(`<stand-in${ssrRenderAttrs({ 'data-stand-in': name })}>`)
				for (const slot of Object.keys(ctx.$slots)) {
					ssrRenderSlot(ctx.$slots, slot, {}, null, push, parent)
				}
				push('</stand-in>')
				return
			}
			const vnodes = Object.values(ctx.$slots).flatMap((slot) => [].concat(slot() ?? []))
			seen.push({ name, attrs: { ...ctx.$attrs }, vnodes })
			ssrRenderVNode(push, h('stand-in', { 'data-stand-in': name }, vnodes), parent)
		},
	}
}

/**
 * Every vnode in `trees`, depth first. Component vnodes are included but not
 * descended into: their slots are recorded by their own stand-in.
 *
 * @param {Array} trees The vnode trees.
 * @return {Array<object>} The vnodes.
 */
function flatten(trees) {
	const out = []
	const walk = (vnode) => {
		if (!vnode || typeof vnode !== 'object') {
			return
		}
		out.push(vnode)
		if (Array.isArray(vnode.children)) {
			vnode.children.forEach(walk)
		}
	}
	trees.forEach(walk)
	return out
}

/**
 * The text a vnode renders, whitespace collapsed (element children only).
 *
 * @param {object} vnode The vnode.
 * @return {string} The text.
 */
export function vnodeText(vnode) {
	const collect = (v) => {
		if (v === null || v === undefined || typeof v === 'boolean') {
			return ''
		}
		if (typeof v !== 'object') {
			return String(v)
		}
		if (typeof v.children === 'string') {
			return v.children
		}
		return Array.isArray(v.children) ? v.children.map(collect).join(' ') : ''
	}
	return collect(vnode).replace(/\s+/g, ' ').trim()
}

/**
 * Render `component` with stand-ins and the given state.
 *
 * @param {object} component The view's component options.
 * @param {object} options `data` (merged over the view's own), `props`,
 *   `globals` (app-wide properties such as `t`) and `slots` ('vnode' or 'ssr').
 * @return {Promise<object>} `html`, `vm`, `standIns(name)`, `find(predicate)`,
 *   `byTestId(id)` and `rerender()`.
 */
export async function renderWithStandIns(component, { data = {}, props = {}, globals = {}, slots = 'vnode' } = {}) {
	seen = []
	slotMode = slots
	let vm = null
	const root = {
		...component,
		data() {
			return { ...(component.data ? component.data.call(this) : {}), ...structuredClone(data) }
		},
		beforeCreate() {
			vm = this
		},
	}
	const app = createSSRApp(root, props)
	Object.assign(app.config.globalProperties, globals)
	const html = await renderToString(app)
	const recorded = seen
	const vnodes = flatten(recorded.flatMap((entry) => entry.vnodes))

	return {
		html,
		vm,
		/**
		 * @param {string} name A stand-in's component name.
		 * @return {Array<object>} The attributes each render of it received.
		 */
		standIns: (name) => recorded.filter((entry) => entry.name === name).map((entry) => entry.attrs),
		/**
		 * @param {Function} predicate Called with each vnode.
		 * @return {Array<object>} The matching vnodes.
		 */
		find: (predicate) => vnodes.filter(predicate),
		/**
		 * @param {string} id A `data-testid`.
		 * @return {object|undefined} The first vnode carrying it.
		 */
		byTestId: (id) => vnodes.find((vnode) => vnode.props?.['data-testid'] === id),
		/**
		 * @return {Promise<object>} The view rendered again from the live state.
		 */
		rerender: () => renderWithStandIns(component, {
			data: JSON.parse(JSON.stringify(vm.$data)),
			props,
			globals,
			slots,
		}),
	}
}

/**
 * A minimal DOM-like event for a listener compiled with modifiers.
 *
 * @param {object} fields Extra fields (e.g. `{ key: 'Enter' }`).
 * @return {object} The event.
 */
export function domEvent(fields = {}) {
	return { preventDefault() {}, stopPropagation() {}, ...fields }
}
