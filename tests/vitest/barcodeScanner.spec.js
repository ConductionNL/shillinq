/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The barcode scanner helpers: native detector support, the camera request,
 * releasing a stream, decoding one frame, the jsQR fallback without the
 * library, and the scan loop's hit, timeout and stop.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	decodeFrame,
	decodeWithJsQrFallback,
	nativeDetectorAvailable,
	releaseStream,
	requestCameraStream,
	SCANNER_DEFAULTS,
	startScanLoop,
} from '../../src/composables/useBarcodeScanner.js'

/**
 * A BarcodeDetector class whose detect() answers the given codes in turn.
 *
 * @param {object} opts supported formats, detect answers, and a constructed log.
 * @return {Function} The class.
 */
function detectorClass({ supported = ['qr_code'], answers = [[]], log = [] } = {}) {
	let call = 0
	return class {
		static getSupportedFormats() {
			return typeof supported === 'function' ? supported() : Promise.resolve(supported)
		}

		constructor(options) {
			log.push(options)
		}

		detect(source) {
			const answer = answers[Math.min(call, answers.length - 1)]
			call += 1
			return typeof answer === 'function' ? answer(source) : Promise.resolve(answer)
		}
	}
}

afterEach(() => {
	vi.unstubAllGlobals()
	vi.useRealTimers()
})

describe('nativeDetectorAvailable', () => {
	it('is false without a window or a detector', async () => {
		expect(await nativeDetectorAvailable()).toBe(false)
		vi.stubGlobal('window', {})
		expect(await nativeDetectorAvailable()).toBe(false)
	})

	it('is true when one wanted format is supported', async () => {
		vi.stubGlobal('window', { BarcodeDetector: detectorClass({ supported: ['aztec', 'ean_13'] }) })
		expect(await nativeDetectorAvailable()).toBe(true)
		expect(await nativeDetectorAvailable(['qr_code'])).toBe(false)
	})

	it('is false for no supported formats or a failing query', async () => {
		vi.stubGlobal('window', { BarcodeDetector: detectorClass({ supported: [] }) })
		expect(await nativeDetectorAvailable()).toBe(false)
		vi.stubGlobal('window', { BarcodeDetector: detectorClass({ supported: () => Promise.reject(new Error('no')) }) })
		expect(await nativeDetectorAvailable()).toBe(false)
	})
})

describe('requestCameraStream', () => {
	it('asks for the rear camera without audio', async () => {
		const getUserMedia = vi.fn(async () => 'stream')
		vi.stubGlobal('navigator', { mediaDevices: { getUserMedia } })

		expect(await requestCameraStream()).toBe('stream')
		expect(getUserMedia).toHaveBeenCalledWith({
			audio: false,
			video: { facingMode: { ideal: 'environment' } },
		})
	})

	it('refuses when the browser has no camera API', async () => {
		vi.stubGlobal('navigator', {})
		await expect(requestCameraStream()).rejects.toThrow('Camera not supported by this browser')
	})
})

describe('releaseStream', () => {
	it('stops every track', () => {
		const stops = [vi.fn(), vi.fn()]
		releaseStream({ getTracks: () => stops.map((stop) => ({ stop })) })
		expect(stops[0]).toHaveBeenCalledOnce()
		expect(stops[1]).toHaveBeenCalledOnce()
	})

	it('ignores a missing or already released stream', () => {
		expect(() => releaseStream(null)).not.toThrow()
		expect(() => releaseStream({ getTracks: () => { throw new Error('gone') } })).not.toThrow()
	})
})

describe('decodeFrame', () => {
	it('is null without a detector', async () => {
		expect(await decodeFrame({})).toBeNull()
	})

	it('answers the first code and builds the detector with the formats', async () => {
		const log = []
		vi.stubGlobal('window', {
			BarcodeDetector: detectorClass({ answers: [[{ rawValue: 'SKU-1', format: 'ean_13' }, { rawValue: 'x' }]], log }),
		})

		expect(await decodeFrame('frame', ['ean_13'])).toEqual({ rawValue: 'SKU-1', format: 'ean_13' })
		expect(log).toEqual([{ formats: ['ean_13'] }])
	})

	it('names an unknown format and answers null for no codes or a failing detect', async () => {
		vi.stubGlobal('window', { BarcodeDetector: detectorClass({ answers: [[{ rawValue: 'A' }]] }) })
		expect(await decodeFrame('frame')).toEqual({ rawValue: 'A', format: 'unknown' })

		vi.stubGlobal('window', { BarcodeDetector: detectorClass({ answers: [[]] }) })
		expect(await decodeFrame('frame')).toBeNull()

		vi.stubGlobal('window', { BarcodeDetector: detectorClass({ answers: [() => Promise.reject(new Error('busy'))] }) })
		expect(await decodeFrame('frame')).toBeNull()
	})
})

describe('decodeWithJsQrFallback', () => {
	it('is null without image data', async () => {
		expect(await decodeWithJsQrFallback(null)).toBeNull()
		expect(await decodeWithJsQrFallback({})).toBeNull()
	})

	it('is null when the jsQR library is not installed', async () => {
		expect(await decodeWithJsQrFallback({ data: new Uint8ClampedArray(4), width: 1, height: 1 })).toBeNull()
	})
})

describe('startScanLoop', () => {
	it('resolves with the first decoded code', async () => {
		vi.useFakeTimers()
		vi.stubGlobal('window', {
			BarcodeDetector: detectorClass({ answers: [[], [{ rawValue: 'LOC-7', format: 'qr_code' }]] }),
		})

		const loop = startScanLoop('video', { intervalMs: 100 })
		await vi.advanceTimersByTimeAsync(100)

		expect(await loop.promise).toEqual({ rawValue: 'LOC-7', format: 'qr_code' })
	})

	it('resolves null after the timeout', async () => {
		vi.useFakeTimers()
		vi.stubGlobal('window', { BarcodeDetector: detectorClass({ answers: [[]] }) })

		const loop = startScanLoop('video', { intervalMs: 100, timeoutMs: 250 })
		await vi.advanceTimersByTimeAsync(400)

		expect(await loop.promise).toBeNull()
	})

	it('resolves null when stopped and stops polling', async () => {
		vi.useFakeTimers()
		const detect = vi.fn(async () => [])
		vi.stubGlobal('window', {
			BarcodeDetector: class {
				detect(source) {
					return detect(source)
				}
			},
		})

		const loop = startScanLoop('video', { intervalMs: 100 })
		await vi.advanceTimersByTimeAsync(0)
		loop.stop()
		await vi.advanceTimersByTimeAsync(1000)

		expect(await loop.promise).toBeNull()
		expect(detect).toHaveBeenCalledTimes(1)
	})
})

describe('SCANNER_DEFAULTS', () => {
	it('lists the default formats', () => {
		expect(SCANNER_DEFAULTS.FORMATS).toEqual(['qr_code', 'ean_13', 'code_128', 'code_39', 'code_93'])
	})
})
