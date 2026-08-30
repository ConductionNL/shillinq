/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Barcode scanning helpers (T2.1-T2.5, REQ-BARCODE-001, REQ-BARCODE-002).
 *
 * The scanner stack is browser-API-first to avoid pulling jsQR + quagga2
 * (~80kB combined) into the bundle when the platform already ships a
 * decoder. The lookup order is:
 *
 *   1. Native BarcodeDetector API (Chromium-based browsers + recent Safari).
 *      Covers EAN-13, Code-128, Code-39, Code-93 and QR with hardware
 *      acceleration. Fastest and most accurate.
 *   2. Optional jsQR fallback for QR codes on older browsers — loaded
 *      lazily via dynamic import so the dependency is only fetched if
 *      `requestQrFallback()` is called.
 *   3. Manual SKU/barcode text entry — always available per REQ-BARCODE-002.
 *
 * The helpers exposed here keep the camera-lifecycle / decoding logic
 * out of the Vue component so they are unit-testable.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

const DEFAULT_FORMATS = ['qr_code', 'ean_13', 'code_128', 'code_39', 'code_93']

/**
 * Detect whether the running browser ships a BarcodeDetector that supports
 * any of the requested formats.
 *
 * @param {Array<string>} [formats] Requested formats (browser-API names).
 * @return {Promise<boolean>} True when a native decoder is available.
 */
export async function nativeDetectorAvailable(formats = DEFAULT_FORMATS) {
	if (
		typeof window === 'undefined'
		|| typeof window.BarcodeDetector !== 'function'
	) {
		return false
	}
	try {
		const supported = await window.BarcodeDetector.getSupportedFormats()
		if (!Array.isArray(supported) || supported.length === 0) {
			return false
		}
		return supported.some((fmt) => formats.includes(fmt))
	} catch (e) {
		return false
	}
}

/**
 * Request access to the device's rear-facing camera and return the active
 * MediaStream. Throws on permission denied or no camera available so the
 * caller can fall back to manual entry per REQ-BARCODE-002.
 *
 * @return {Promise<MediaStream>} The live camera stream.
 */
export async function requestCameraStream() {
	if (
		typeof navigator === 'undefined'
		|| !navigator.mediaDevices
		|| !navigator.mediaDevices.getUserMedia
	) {
		throw new Error('Camera not supported by this browser')
	}
	return navigator.mediaDevices.getUserMedia({
		audio: false,
		video: { facingMode: { ideal: 'environment' } },
	})
}

/**
 * Release every track on the supplied stream. Safe to call with null.
 *
 * @param {MediaStream|null} stream The stream to release.
 * @return {void}
 */
export function releaseStream(stream) {
	if (!stream) {
		return
	}
	try {
		stream.getTracks().forEach((track) => track.stop())
	} catch (e) {
		// Stream already released.
	}
}

/**
 * Decode a single still frame using the native BarcodeDetector. Returns
 * the first decoded value (rawValue) or null when no barcode is detected.
 *
 * @param {CanvasImageSource|ImageBitmap|HTMLVideoElement} source The frame.
 * @param {Array<string>} [formats] Format names to look for.
 * @return {Promise<{rawValue:string, format:string}|null>} Decoded result.
 */
export async function decodeFrame(source, formats = DEFAULT_FORMATS) {
	if (
		typeof window === 'undefined'
		|| typeof window.BarcodeDetector !== 'function'
	) {
		return null
	}
	try {
		const detector = new window.BarcodeDetector({ formats })
		const codes = await detector.detect(source)
		if (Array.isArray(codes) && codes.length > 0) {
			return {
				rawValue: codes[0].rawValue,
				format: codes[0].format || 'unknown',
			}
		}
	} catch (e) {
		// Returning null lets the caller try the next frame / format / fallback.
	}
	return null
}

/**
 * Optional jsQR fallback. Dynamic-imported so the library is only fetched
 * when actually needed; no-ops cleanly when the package is not installed.
 *
 * @param {ImageData} imageData The frame pixel data.
 * @return {Promise<{rawValue:string, format:string}|null>} Decoded result.
 */
export async function decodeWithJsQrFallback(imageData) {
	if (!imageData || !imageData.data) {
		return null
	}
	try {
		// No `eslint-disable` for `import/no-unresolved` here: the flat config
		// does not register that rule, so the disable comment was itself the
		// error ("Definition for rule 'import/no-unresolved' was not found").
		// `webpackIgnore` keeps this a real runtime import rather than a bundled
		// one, which is why the specifier is not resolvable at lint time.
		const mod = await import(/* webpackIgnore: true */ 'jsqr')
		const jsQR = mod && mod.default ? mod.default : mod
		if (typeof jsQR !== 'function') {
			return null
		}
		const result = jsQR(imageData.data, imageData.width, imageData.height)
		if (result && result.data) {
			return { rawValue: result.data, format: 'qr_code' }
		}
	} catch (e) {
		// jsQR not installed or decoding failed — graceful no-op.
	}
	return null
}

/**
 * Run an end-to-end scan loop against a `<video>` element. Polls every
 * `intervalMs` until a barcode is decoded, the timeout elapses, or
 * `stop()` is called. Resolves with the decoded value (or null on timeout).
 *
 * @param {HTMLVideoElement} videoEl The bound video element.
 * @param {object} [opts] Loop options.
 * @param {number} [opts.intervalMs] Polling interval (default 250ms).
 * @param {number} [opts.timeoutMs] Maximum scan duration (default 30000ms).
 * @param {Array<string>} [opts.formats] Formats to look for.
 * @return {{promise:Promise<{rawValue:string, format:string}|null>, stop:Function}}
 *   Loop handle.
 */
export function startScanLoop(videoEl, opts = {}) {
	const intervalMs = opts.intervalMs || 250
	const timeoutMs = opts.timeoutMs || 30_000
	const formats = opts.formats || DEFAULT_FORMATS

	let stopped = false
	let timerId = null
	let resolveFn = null

	const promise = new Promise((resolve) => {
		resolveFn = resolve

		const deadline = Date.now() + timeoutMs

		const tick = async () => {
			if (stopped) {
				return
			}
			if (Date.now() > deadline) {
				resolve(null)
				return
			}
			try {
				const result = await decodeFrame(videoEl, formats)
				if (result && result.rawValue) {
					resolve(result)
					return
				}
			} catch (e) {
				// Ignore single-frame failures; keep polling.
			}
			timerId = setTimeout(tick, intervalMs)
		}

		tick()
	})

	return {
		promise,
		stop() {
			stopped = true
			if (timerId !== null) {
				clearTimeout(timerId)
				timerId = null
			}
			if (resolveFn) {
				resolveFn(null)
			}
		},
	}
}

export const SCANNER_DEFAULTS = Object.freeze({ FORMATS: DEFAULT_FORMATS })
