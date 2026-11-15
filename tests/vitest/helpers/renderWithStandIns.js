/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Server-render a component's real template with recording stand-ins for
 * its child components, and hand back the markup, the live component
 * instance and what each stand-in received. A spec can then fire a
 * recorded listener (`onUpdate:modelValue`, `onSelected`, …) and read back
 * the state the template wrote, which a plain server render cannot show
 * because event handlers do not run there. The lifecycle hooks and
 * watchers that load data are left out, so the state is the one the spec
 * names.
 */

import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

/**
 * A stand-in child that records every render's props and listeners under
 * its name and renders all its slots, so the parent's slot content is
 * rendered (and its listeners wired) too.
 *
 * @param {string} name The component name.
 * @param {object} seen The record, name => list of attrs.
 * @return {object} The component.
 */
export function recorder(name, seen) {
	return {
		name,
		inheritAttrs: false,
		setup(_props, { slots, attrs }) {
			return () => {
				;(seen[name] = seen[name] || []).push({ ...attrs })
				return h('stand-in', { 'data-name': name }, Object.values(slots).map((slot) => slot()))
			}
		},
	}
}

/**
 * Render the component with recording stand-ins.
 *
 * @param {object} component The component options.
 * @param {object} options What to render with.
 * @param {object} [options.props] Root props (listeners as `onX`).
 * @param {object} [options.data] Data to preset over the component's own.
 * @param {string[]} [options.record] Child component names to record.
 * @param {object} [options.components] Other stand-ins, by name.
 * @return {Promise<{html: string, vm: object, seen: object}>} The markup, the instance and the record.
 */
export async function renderWithStandIns(component, { props = {}, data = {}, record = [], components = {} } = {}) {
	const seen = {}
	const children = { ...components }
	for (const name of record) {
		children[name] = recorder(name, seen)
	}
	let vm = null
	const { created, mounted, watch, ...rest } = component
	const subject = {
		...rest,
		components: children,
		data() {
			return { ...(component.data ? component.data.call(this) : {}), ...data }
		},
	}
	const app = createSSRApp(subject, props)
	app.config.globalProperties.t = (_app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, key) => (key in vars ? String(vars[key]) : m))
	app.mixin({
		created() {
			if (this.$options.name === component.name) {
				vm = this
			}
		},
	})
	app.config.warnHandler = () => {}
	const html = await renderToString(app)
	return { html, vm, seen }
}
