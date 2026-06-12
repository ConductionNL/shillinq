/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Minimal @nextcloud/axios stub for the offline Vitest suite. The W8
 * External-Adapters SFCs import the default axios export for their `get`
 * calls; the unit tests replace `get` with a vi.fn() per-test, so the stub
 * only needs a default object carrying a `get` method that is safe to
 * override.
 */

const axios = {
	get: async () => ({ data: {} }),
}

export default axios
