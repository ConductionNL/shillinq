/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Server-side rendering of a view (the suite runs in `node`, so SFCs compile
 * to ssrRender) with stand-ins that keep what the template handed them: the
 * attributes each stub received (onClick, disabled, modelValue,
 * onUpdate:modelValue …) and the vnodes of the slot it rendered. A test can
 * then call exactly the handler the compiled template bound, against the
 * rendered instance, and read the instance afterwards.
 */

import { createSSRApp } from 'vue'
import { renderToString, ssrRenderAttrs, ssrRenderSlot } from 'vue/server-renderer'

/**
 * The text of a list of vnodes, whitespace collapsed.
 *
 * @param {Array<object>|undefined} vnodes The vnodes.
 * @return {string}
 */
export function textOf(vnodes) {
	const collect = (list) =>
		[].concat(list || []).map((vnode) => {
			if (typeof vnode === 'string') {
				return vnode
			}
			if (typeof vnode?.children === 'string') {
				return vnode.children
			}
			return Array.isArray(vnode?.children) ? collect(vnode.children) : ''
		}).join(' ')
	return collect(vnodes).replace(/\s+/g, ' ').trim()
}

/**
 * Vnodes with the default slot of every component among them called, so
 * the template's vnode branch runs all the way down. A component's slot
 * vnodes are kept as its `children`.
 *
 * @param {Array<object>|undefined} vnodes The vnodes.
 * @return {Array<object>|undefined}
 */
function expand(vnodes) {
	for (const vnode of [].concat(vnodes || [])) {
		if (!vnode || typeof vnode !== 'object') {
			continue
		}
		if (typeof vnode.children?.default === 'function') {
			vnode.children = expand(vnode.children.default())
		} else if (Array.isArray(vnode.children)) {
			expand(vnode.children)
		}
	}
	return vnodes
}

/**
 * A render session: stubs that record into it, and the rendered instance.
 *
 * @return {object}
 */
export function session() {
	const record = { stubs: [], slots: [], vm: null }

	/**
	 * A stand-in that records its attributes and the vnodes of its default
	 * slot, and renders that slot into the HTML. The slot is called both
	 * ways the compiled template supports: as vnodes (recorded, with the
	 * slots of the components inside it expanded too) and streamed.
	 *
	 * @param {string} name The component name.
	 * @param {string} [tag] The element it renders.
	 * @return {object}
	 */
	record.stub = (name, tag = 'div') => ({
		name,
		inheritAttrs: false,
		ssrRender(ctx, push, parent) {
			const children = expand(ctx.$slots.default?.())
			record.stubs.push({ name, attrs: ctx.$attrs, text: textOf(children) })
			if (children) {
				record.slots.push(children)
			}
			const plain = Object.fromEntries(
				Object.entries(ctx.$attrs).filter(
					([, value]) => typeof value !== 'function' && typeof value !== 'object',
				),
			)
			push(`<${tag}${ssrRenderAttrs({ 'data-stub': name, ...plain })}>`)
			ssrRenderSlot(ctx.$slots, 'default', {}, null, push, parent)
			push(`</${tag}>`)
		},
	})

	/**
	 * Render a view with part of its data replaced.
	 *
	 * @param {object} view The component.
	 * @param {object} state The data to lay over the view's own.
	 * @param {object} [globals] app.config.globalProperties.
	 * @param {Array<string|Array>} [stubbed] Components of the view to replace: a name for a
	 *     recording stub, or [name, component] for a stand-in of the test's own.
	 * @return {Promise<string>} The HTML.
	 */
	record.render = async (view, state = {}, globals = {}, stubbed = []) => {
		record.stubs = []
		record.slots = []
		const app = createSSRApp({
			...view,
			components: {
				...view.components,
				...Object.fromEntries(
					stubbed.map((entry) =>
						Array.isArray(entry) ? entry : [entry, record.stub(entry)],
					),
				),
			},
			data() {
				return { ...view.data.call(this), ...state }
			},
			mixins: [
				...(view.mixins || []),
				{
					created() {
						record.vm = this
					},
				},
			],
		})
		Object.assign(app.config.globalProperties, globals)
		app.config.warnHandler = () => {}
		return renderToString(app)
	}

	/**
	 * The attributes of the stubs of this name.
	 *
	 * @param {string} name The stub name.
	 * @return {Array<object>}
	 */
	record.attrsOf = (name) =>
		record.stubs.filter((entry) => entry.name === name).map((entry) => entry.attrs)

	/**
	 * The attributes of the first stub of this name whose slot text contains `text`.
	 *
	 * @param {string} name The stub name.
	 * @param {string} text The slot text.
	 * @return {object|undefined}
	 */
	record.labelled = (name, text) =>
		record.stubs.find((entry) => entry.name === name && entry.text.includes(text))?.attrs

	/**
	 * Every vnode in the recorded slots that matches.
	 *
	 * @param {(vnode: object) => boolean} match The predicate.
	 * @return {Array<object>}
	 */
	record.vnodes = (match) => {
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
				}
			}
		}
		record.slots.forEach(walk)
		return found
	}

	return record
}
