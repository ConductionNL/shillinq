// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// sales-invoice-document 4.1 (REQ-SID-001): the WCAG 2.x contrast of an
// invoice layout colour against the white page. The server refuses a primary
// colour below 4.5:1 (InvoiceLayoutContrastListener, same formula in
// ColourContrast.php); the preview uses this to say so before saving.

import { translate as t } from '@nextcloud/l10n'

/** The WCAG AA minimum for normal text. */
export const AA_NORMAL_TEXT = 4.5

/**
 * The linear value of one sRGB channel.
 *
 * @param {string} hex Two hex digits.
 * @return {number}
 */
function channel(hex) {
	const value = parseInt(hex, 16) / 255
	return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
}

/**
 * The contrast ratio of a `#RRGGBB` colour against white (1 to 21), or null
 * when the value is not such a colour.
 *
 * @param {string} hex The colour.
 * @return {number|null}
 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.1
 */
export function ratioAgainstWhite(hex) {
	const match = /^#([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})$/.exec(
		String(hex ?? ''),
	)
	if (match === null) {
		return null
	}
	const luminance
		= 0.2126 * channel(match[1])
		+ 0.7152 * channel(match[2])
		+ 0.0722 * channel(match[3])
	return 1.05 / (luminance + 0.05)
}

/**
 * The verdict on one layout colour, written out so it is not told by colour
 * alone: the ratio rounded down to one decimal, whether it passes, and the
 * sentence to show.
 *
 * @param {string} label The colour's name, already translated.
 * @param {string} hex The colour.
 * @return {{ratio: number|null, passes: boolean, text: string}}
 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.1
 */
export function contrastVerdict(label, hex) {
	const ratio = ratioAgainstWhite(hex)
	if (ratio === null) {
		return {
			ratio: null,
			passes: false,
			text: t('shillinq', '{label} is not a #RRGGBB colour.', { label }),
		}
	}
	const shown = (Math.floor(ratio * 10) / 10).toFixed(1)
	const passes = ratio >= AA_NORMAL_TEXT
	return {
		ratio,
		passes,
		text: passes
			? t('shillinq', '{label}: contrast {ratio}:1 against white, readable.', {
				label,
				ratio: shown,
			})
			: t(
				'shillinq',
				'{label}: contrast {ratio}:1 against white, below 4.5:1. Choose a darker colour.',
				{ label, ratio: shown },
			),
	}
}
