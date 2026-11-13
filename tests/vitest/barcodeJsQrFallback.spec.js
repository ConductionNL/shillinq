/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The jsQR fallback decode path, with the library present. Kept in its own
 * spec so the `jsqr` module mock does not reach barcodeScanner.spec.js, whose
 * "library not installed" case needs the real (absent) import to fail.
 */

import { describe, expect, it, vi } from 'vitest'

const jsQR = vi.fn()

vi.mock('jsqr', () => ({ default: (...args) => jsQR(...args) }))

const { decodeWithJsQrFallback } = await import('../../src/composables/useBarcodeScanner.js')

const imageData = () => ({ data: new Uint8ClampedArray(4), width: 1, height: 1 })

describe('decodeWithJsQrFallback with the library present', () => {
	it('returns the decoded QR value as a qr_code result', async () => {
		jsQR.mockReturnValue({ data: 'LOC-42' })

		expect(await decodeWithJsQrFallback(imageData())).toEqual({ rawValue: 'LOC-42', format: 'qr_code' })
		expect(jsQR).toHaveBeenCalledWith(expect.any(Uint8ClampedArray), 1, 1)
	})

	it('returns null when jsQR finds no code', async () => {
		jsQR.mockReturnValue(null)

		expect(await decodeWithJsQrFallback(imageData())).toBeNull()
	})

	it('returns null when jsQR returns a result without data', async () => {
		jsQR.mockReturnValue({ data: '' })

		expect(await decodeWithJsQrFallback(imageData())).toBeNull()
	})
})
