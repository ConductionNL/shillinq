/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Render a component's real template to HTML in the node environment, with
 * Vue's server renderer, so a spec can read what the template shows for a
 * given state without a DOM. The component's own render function, computed
 * properties and methods are used; only its child components are replaced by
 * the given stand-ins, its data is preset, and the lifecycle hooks that load
 * data (`created`, `mounted`) are left out, so the state is the one the spec
 * names. Event handlers do not run in a server render.
 */

import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

/**
 * A stand-in child component that renders its tag, its props as data
 * attributes and its default slot.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
export function standIn(name) {
	return {
		name,
		inheritAttrs: false,
		props: { disabled: null, to: null },
		setup(props, { slots, attrs }) {
			const target = props.to ? JSON.stringify(props.to) : undefined
			return () => h('cn-stub', { 'data-name': name, 'data-disabled': props.disabled ? 'true' : undefined, 'data-to': target, ...attrs }, [slots.icon?.(), slots.default?.()])
		},
	}
}

/**
 * Render the component's template for a preset state.
 *
 * @param {object} component The component options.
 * @param {object} options What to render with.
 * @param {object} [options.props] The props.
 * @param {object} [options.data] Data fields to preset over the component's own.
 * @param {object} [options.components] Stand-ins for its child components.
 * @param {object} [options.globals] Extra global properties, e.g. `$route`.
 * @return {Promise<string>} The HTML.
 */
export async function renderTemplate(component, { props = {}, data = {}, components = {}, globals = {} } = {}) {
	const { created, mounted, ...rest } = component
	const subject = {
		...rest,
		components,
		data() {
			return { ...(component.data ? component.data.call(this) : {}), ...data }
		},
	}
	const app = createSSRApp(subject, props)
	app.config.globalProperties.t = (_app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, key) => (key in vars ? String(vars[key]) : m))
	app.config.globalProperties.n = (_app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))
	Object.assign(app.config.globalProperties, globals)
	app.config.warnHandler = () => {}
	return renderToString(app)
}
