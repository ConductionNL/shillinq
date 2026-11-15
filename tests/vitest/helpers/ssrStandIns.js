/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Render a component's own (SSR-compiled) template to a string, with chosen
 * children replaced by stand-ins that record the props and listeners they
 * were given and call every slot, scoped slots with the props named for them.
 * The stand-ins' recorded listeners are the component's real closures, so a
 * test can call one and read the instance it changed.
 */

import { createSSRApp, h } from 'vue'
import { renderToString, ssrRenderSlot } from 'vue/server-renderer'

/**
 * A child stand-in.
 *
 * In `ssr` mode it renders slots the way a server-rendered parent does, so
 * the caller's compiled slot takes its server branch. In `vnode` mode it
 * calls them as a client render function would, taking the other branch, and
 * keeps the vnodes under `seen[name + ':vnodes']` so a test can call the
 * listeners on them (an input's `onUpdate:modelValue` is the real v-model
 * assignment).
 *
 * @param {string} name The name it records under.
 * @param {object} seen Where the attrs are recorded, by name.
 * @param {Function} slotProps The props a slot is called with, by slot name.
 * @param {string} mode ssr or vnode.
 * @return {object} The component.
 */
function standIn(name, seen, slotProps, mode) {
	if (mode === 'vnode') {
		return {
			name,
			inheritAttrs: false,
			setup(_props, { slots, attrs }) {
				return () => {
					seen[name] = attrs
					const vnodes = Object.entries(slots).map(([slot, fn]) => h('slot-' + slot, null, fn(slotProps(slot))))
					seen[name + ':vnodes'] = vnodes
					return h('stand-in', { 'data-name': name }, vnodes)
				}
			},
		}
	}
	return {
		name,
		inheritAttrs: false,
		setup(_props, { attrs }) {
			seen[name] = attrs
			return {}
		},
		ssrRender(ctx, push, parent) {
			push(`<stand-in data-name="${name}">`)
			for (const slot of Object.keys(ctx.$slots)) {
				push(`<slot-${slot}>`)
				ssrRenderSlot(ctx.$slots, slot, slotProps(slot), null, push, parent)
				push(`</slot-${slot}>`)
			}
			push('</stand-in>')
		},
	}
}

/**
 * Every vnode below some vnodes matching a predicate on its props.
 *
 * @param {object[]} vnodes The vnodes.
 * @param {Function} match The predicate, given the vnode.
 * @return {object[]} The matches.
 */
export function findVnodes(vnodes, match) {
	const found = []
	const walk = (list) => {
		for (const vnode of [].concat(list || [])) {
			if (!vnode || typeof vnode !== 'object') {
				continue
			}
			if (match(vnode)) {
				found.push(vnode)
			}
			if (Array.isArray(vnode.children)) {
				walk(vnode.children)
			} else if (vnode.children && typeof vnode.children === 'object' && typeof vnode.children.default === 'function') {
				walk(vnode.children.default())
			}
		}
	}
	walk(vnodes)
	return found
}

/**
 * Render a component with stand-ins.
 *
 * @param {object} component The component.
 * @param {object} options props; data overrides; the child names to stand in for; slotProps(slot); slotMode ssr or vnode.
 * @return {Promise<{html: string, vm: object, seen: object}>} The markup, the instance and the recorded attrs.
 */
export async function renderSsr(component, { props = {}, data = {}, standIns = [], slotProps = () => ({}), slotMode = 'ssr' } = {}) {
	const seen = {}
	const children = {}
	for (const name of standIns) {
		children[name] = standIn(name, seen, slotProps, slotMode)
	}
	let vm = null
	const subject = {
		...component,
		components: { ...(component.components || {}), ...children },
		data() {
			return { ...(component.data ? component.data.call(this) : {}), ...data }
		},
	}
	const app = createSSRApp(subject, props)
	app.config.globalProperties.t = (_app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, key) => (key in vars ? String(vars[key]) : m))
	app.config.warnHandler = () => {}
	app.mixin({
		created() {
			if (this.$options.name === component.name) {
				vm = this
			}
		},
	})
	const html = await renderToString(app)
	return { html, vm, seen }
}
