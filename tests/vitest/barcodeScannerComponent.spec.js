/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BarcodeScanner: it starts the camera only when the browser can decode
 * barcodes, emits the first code it reads, falls back to manual entry with a
 * reason, and releases the camera when it goes away. The browser's
 * BarcodeDetector and getUserMedia are the stubbed boundary.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Scanner from '../../src/components/inventory/BarcodeScanner.vue'

const FORMATS = Scanner.props.formats.default()

/**
 * A stand-in instance.
 *
 * @param {object} [fields] Fields to override.
 * @return {object} The instance.
 */
function instance(fields = {}) {
	return {
		formats: FORMATS,
		t: (_app, text) => text,
		$emit: vi.fn(),
		$nextTick: () => Promise.resolve(),
		$refs: { video: {} },
		...Scanner.data(),
		...fields,
	}
}

/**
 * Install a BarcodeDetector that supports the given formats and reads codes.
 *
 * @param {Array<string>} supported Supported formats.
 * @param {Array<object>} codes What detect() answers.
 */
function detector(supported, codes = []) {
	class FakeDetector {
		static getSupportedFormats() {
			return Promise.resolve(supported)
		}

		detect() {
			return Promise.resolve(codes)
		}
	}
	vi.stubGlobal('window', { BarcodeDetector: FakeDetector })
}

let track
let stream

beforeEach(() => {
	track = { stop: vi.fn() }
	stream = { getTracks: () => [track] }
	vi.stubGlobal('navigator', { mediaDevices: { getUserMedia: vi.fn(() => Promise.resolve(stream)) } })
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('BarcodeScanner mount', () => {
	it('offers manual entry when the browser has no decoder', async () => {
		const self = instance()
		await Scanner.mounted.call(self)
		expect(self.cameraError).toBe('No barcode decoder available; use manual entry.')
		expect(navigator.mediaDevices.getUserMedia).not.toHaveBeenCalled()
	})

	it('offers manual entry when the decoder supports none of the formats', async () => {
		detector(['aztec'])
		const self = instance()
		await Scanner.mounted.call(self)
		expect(self.cameraError).toBe('No barcode decoder available; use manual entry.')
	})

	it('opens the rear camera, shows it and emits the first code read', async () => {
		detector(['ean_13'], [{ rawValue: '8712345678906', format: 'ean_13' }])
		const video = {}
		const self = instance({ $refs: { video } })
		await Scanner.mounted.call(self)
		expect(navigator.mediaDevices.getUserMedia).toHaveBeenCalledWith({
			audio: false,
			video: { facingMode: { ideal: 'environment' } },
		})
		expect(video.srcObject).toBe(stream)
		expect(self.cameraReady).toBe(true)
		expect(self.loop.stop).toBeTypeOf('function')
		expect(self.$emit).toHaveBeenCalledWith('scan', '8712345678906')
	})

	it('emits nothing when the scan loop is stopped before a code is read', async () => {
		detector(['qr_code'])
		const self = instance()
		const mounting = Scanner.mounted.call(self)
		await vi.waitFor(() => expect(self.loop).not.toBeNull())
		self.loop.stop()
		await mounting
		expect(self.$emit).not.toHaveBeenCalled()
	})

	it('does not start the camera view without a video element', async () => {
		detector(['qr_code'])
		const self = instance({ $refs: {} })
		await Scanner.mounted.call(self)
		expect(self.stream).toBe(stream)
		expect(self.cameraReady).toBe(false)
		expect(self.loop).toBeNull()
	})

	it('falls back to manual entry when the camera is refused', async () => {
		detector(['qr_code'])
		navigator.mediaDevices.getUserMedia.mockRejectedValue(new Error('NotAllowedError'))
		const self = instance()
		await Scanner.mounted.call(self)
		expect(self.cameraError).toBe('Camera unavailable; use manual entry.')
		expect(self.cameraReady).toBe(false)
	})

	it('falls back to manual entry when the browser has no camera API', async () => {
		detector(['qr_code'])
		vi.stubGlobal('navigator', {})
		const self = instance()
		await Scanner.mounted.call(self)
		expect(self.cameraError).toBe('Camera unavailable; use manual entry.')
	})
})

describe('BarcodeScanner teardown and manual entry', () => {
	it('stops the loop and the camera tracks on unmount', () => {
		const loop = { stop: vi.fn() }
		const self = instance({ loop, stream })
		Scanner.beforeUnmount.call(self)
		expect(loop.stop).toHaveBeenCalledTimes(1)
		expect(track.stop).toHaveBeenCalledTimes(1)
		expect(self.stream).toBeNull()
	})

	it('unmounts cleanly with no loop or stream', () => {
		const self = instance({ loop: {}, stream: null })
		expect(() => Scanner.beforeUnmount.call(self)).not.toThrow()
	})

	it('emits a typed code trimmed, and nothing for a blank one', () => {
		const self = instance({ manualValue: '  ABC-123 ' })
		Scanner.methods.handleManualSubmit.call(self)
		expect(self.$emit).toHaveBeenCalledWith('scan', 'ABC-123')

		const blank = instance({ manualValue: '   ' })
		Scanner.methods.handleManualSubmit.call(blank)
		const empty = instance({ manualValue: null })
		Scanner.methods.handleManualSubmit.call(empty)
		expect(blank.$emit).not.toHaveBeenCalled()
		expect(empty.$emit).not.toHaveBeenCalled()
	})

	it('scans the common retail and QR formats by default', () => {
		expect(FORMATS).toEqual(['qr_code', 'ean_13', 'code_128', 'code_39', 'code_93'])
	})
})
