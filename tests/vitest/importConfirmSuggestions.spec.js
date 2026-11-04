/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * "Confirm all" is one server call, not a PATCH per mapping row.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { confirmSuggestions } from '../../src/utils/importWizard.js'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), patch: vi.fn(), get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

describe('confirmSuggestions', () => {
	beforeEach(() => {
		axios.post.mockReset()
		axios.patch.mockReset()
	})

	it('posts once to the batch endpoint and returns the count', async () => {
		axios.post.mockResolvedValue({ data: { confirmed: 7 } })

		await expect(confirmSuggestions('b 1')).resolves.toBe(7)

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/import-batches/b%201/confirm-suggestions')
		expect(axios.patch).not.toHaveBeenCalled()
	})
})
