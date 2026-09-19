// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The two things shillinq's finance leaves share: how they render, and where
// they register (ADR-019 / ADR-066, openregister#2127).
//
// RENDER. Shillinq is Vue 3. A host that mounts this leaf may be Vue 2.7, and a
// Vue 3 single file component handed to a Vue 2 host is interpreted under the
// host's runtime and renders blank, with no error anywhere. So the host hands
// the leaf a bare element it owns, and the leaf roots its OWN Vue app at that
// element. The DOM is the boundary both framework versions agree on.
//
// REGISTER. A leaf app must never install OpenRegister's singleton registry. It
// either registers on the real one or leaves its descriptor in the load-order
// safe queue stub that OpenRegister's bundle replays when it arrives.
//
// Each leaf keeps its own `integrations.register(...)` call beside its own
// descriptor, rather than handing both to a helper here. That is what lets
// gate-24 read the id it registers under.

import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { createApp } from 'vue'

/**
 * Build the `mount` / `unmount` pair for one leaf component.
 *
 * Instances are keyed by ELEMENT, not by leaf id: the same leaf can be mounted
 * twice on one page, once as a sidebar tab and once as a detail-page widget,
 * and each mount is its own app.
 *
 * @param {object} component The Vue component to root at the host's element.
 *
 * @return {{mount: Function, unmount: Function}} The pair the registry stores.
 */
export function mountPairFor(component) {
	const mountedApps = new Map()

	/**
	 * Root the leaf's own Vue app at a host-owned element. Idempotent per
	 * element, so a host that mounts twice gets one app.
	 *
	 * @param {Element} el    The host-owned container.
	 * @param {object}  props The forwarded object context.
	 *
	 * @return {void}
	 */
	function mount(el, props) {
		if (el === undefined || el === null || mountedApps.has(el) === true) {
			return
		}
		const app = createApp(component, { ...(props || {}) })
		// The panels import `t()` directly, but any shared library component
		// they pull in reads `this.t` / `this.n` off the app instance. main.js
		// installs both in the app bundle; a leaf mounts its own app, so it
		// installs them again here.
		app.config.globalProperties.t = t
		app.config.globalProperties.n = n
		app.mount(el)
		mountedApps.set(el, app)
	}

	/**
	 * Destroy the app rooted at an element and release the entry, so a mount
	 * and unmount cycle leaks no instance.
	 *
	 * @param {Element} el The container the host is about to remove.
	 *
	 * @return {void}
	 */
	function unmount(el) {
		const app = mountedApps.get(el)
		if (app === undefined) {
			return
		}
		mountedApps.delete(el)
		app.unmount()
	}

	return { mount, unmount }
}

/**
 * The shared OpenRegister integration registry, installing the queue stub when
 * OpenRegister's own bundle has not loaded yet.
 *
 * @param {object} [globalRef] The global to attach to, defaulting to `window`.
 *
 * @return {?object} The registry, or null when there is no global to attach to.
 */
export function sharedRegistry(globalRef) {
	const target = globalRef || (typeof window !== 'undefined' ? window : null)
	if (target === null) {
		return null
	}

	target.OCA = target.OCA || {}
	target.OCA.OpenRegister = target.OCA.OpenRegister || {}

	if (
		target.OCA.OpenRegister.integrations === undefined
		|| target.OCA.OpenRegister.integrations === null
	) {
		target.OCA.OpenRegister.integrations = {
			_queue: [],
			register(entry) {
				this._queue.push(entry)
			},
		}
	}

	return target.OCA.OpenRegister.integrations
}
