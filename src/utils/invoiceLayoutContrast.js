// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// sales-invoice-document REQ-SID-001: an invoice layout's primary colour must
// reach a 4.5:1 contrast ratio against white (WCAG 2.1 AA for normal text),
// because headings and totals print in it on a white page.

/** The ratio a primary colour must reach against white. */
export const MIN_CONTRAST = 4.5

/**
 * Parse `#RRGGBB` (or `#RGB`) to its three channels, or null when malformed.
 *
 * @param {string} hex The colour.
 * @return {number[]|null} The red, green and blue channel, 0-255.
 */
export function hexToRgb(hex) {
	const match = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(hex ?? '').trim())
	if (match === null) {
		return null
	}
	let digits = match[1]
	if (digits.length === 3) {
		digits = digits.split('').map((d) => d + d).join('')
	}
	return [0, 2, 4].map((i) => parseInt(digits.slice(i, i + 2), 16))
}

/**
 * WCAG 2.1 relative luminance of a colour.
 *
 * @param {number[]} rgb The channels, 0-255.
 * @return {number} The luminance, 0-1.
 */
export function relativeLuminance(rgb) {
	const [r, g, b] = rgb.map((channel) => {
		const c = channel / 255
		return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
	})
	return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

/**
 * The contrast ratio between two colours, or null when either is malformed.
 *
 * @param {string} foreground The first colour.
 * @param {string} background The second colour, white by default.
 * @return {number|null} The ratio, 1-21.
 */
export function contrastRatio(foreground, background = '#FFFFFF') {
	const a = hexToRgb(foreground)
	const b = hexToRgb(background)
	if (a === null || b === null) {
		return null
	}
	const [light, dark] = [relativeLuminance(a), relativeLuminance(b)].sort((x, y) => y - x)
	return (light + 0.05) / (dark + 0.05)
}

/**
 * Whether a primary colour may be saved: well formed and at least 4.5:1 on white.
 *
 * @param {string} primaryColor The colour.
 * @return {{ok: boolean, ratio: number|null}} The verdict and the measured ratio.
 */
export function checkPrimaryColor(primaryColor) {
	const ratio = contrastRatio(primaryColor)
	return { ok: ratio !== null && ratio >= MIN_CONTRAST, ratio }
}
