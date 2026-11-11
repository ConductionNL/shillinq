// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What every finance leaf panel shares: the host-object props it stands on,
// the guarded read of its data leaf (refused without a resolvable host, the
// loading flag around it, one failure text), and the server's own words for a
// refused act.

import { translate as t } from '@nextcloud/l10n'
import { hostIdentity, isResolvable, readLeaf } from './leafApi.js'

export const leafHostPanelMixin = {
	props: {
		/** OpenRegister register id of the host object. */
		register: { type: String, default: '' },
		/** OpenRegister schema id of the host object. */
		schema: { type: String, default: '' },
		/** Id of the host object the panel stands on. */
		objectId: { type: [String, Number], default: '' },
		/** Whole registry context, the fallback when the discrete props are absent. */
		integrationContext: { type: Object, default: () => ({}) },
	},

	methods: {
		/**
		 * Read the host object's data leaf and hand its envelope to `apply`;
		 * without a resolvable host nothing is read. Any failure, reading or
		 * applying, shows `failedText()`.
		 *
		 * @param {string} dataLeaf The data leaf id.
		 * @param {{apply: (envelope: object) => void, failedText: () => string}} handling What to do with the envelope, and the failure text.
		 * @return {Promise<void>}
		 * @spec openspec/specs/leaves-library-card/spec.md
		 */
		async readHostLeaf(dataLeaf, { apply, failedText }) {
			const identity = hostIdentity(this.$props)
			if (isResolvable(identity) === false) {
				this.error = t('shillinq', 'This panel needs a record to stand on.')
				return
			}

			this.loading = true
			this.error = ''
			try {
				apply(await readLeaf(identity, dataLeaf))
			} catch {
				this.error = failedText()
			} finally {
				this.loading = false
			}
		},

		/**
		 * The server's own words for a refusal, so a handler reads why rather
		 * than a generic failure.
		 *
		 * @param {object} e The axios error.
		 * @return {string} The message.
		 * @spec openspec/specs/leaves-library-card/spec.md
		 */
		refusal(e) {
			const message =
				e && e.response && e.response.data && e.response.data.error
			if (typeof message === 'string' && message !== '') {
				return message
			}
			return t('shillinq', 'That did not go through.')
		},
	},
}
