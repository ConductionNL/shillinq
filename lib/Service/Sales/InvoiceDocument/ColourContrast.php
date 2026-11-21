<?php

/**
 * ColourContrast — the WCAG 2.x contrast ratio of a hex colour against white.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The invoice layout's primary colour is printed as heading and table header
 * text on a white page, so it must reach the WCAG AA ratio of 4.5:1 for
 * normal text (sales-invoice-document REQ-SID-001). The browser runs the same
 * formula in `src/utils/colourContrast.js` to warn while the colour is being
 * picked; this class is the one the write path trusts.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sales\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sales\InvoiceDocument;

/**
 * Contrast of a `#RRGGBB` colour against white.
 *
 * @spec openspec/changes/sales-invoice-document/tasks.md#task-4.1
 */
final class ColourContrast {

	/**
	 * The WCAG AA minimum for normal text.
	 *
	 * @var float
	 */
	public const AA_NORMAL_TEXT = 4.5;

	/**
	 * The contrast ratio of the colour against white (1 to 21), or null when
	 * the value is not a `#RRGGBB` colour.
	 *
	 * @param string $hex The colour, as `#RRGGBB`.
	 *
	 * @return float|null
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-4.1
	 */
	public function ratioAgainstWhite(string $hex): float|null {
		if (preg_match('/^#([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})$/', $hex, $match) !== 1) {
			return null;
		}

		$luminance = (0.2126 * $this->channel(hex: $match[1]))
			+ (0.7152 * $this->channel(hex: $match[2]))
			+ (0.0722 * $this->channel(hex: $match[3]));

		return (1.05 / ($luminance + 0.05));

	}//end ratioAgainstWhite()

	/**
	 * Whether the colour reaches the AA ratio for normal text against white.
	 * A value that is not a colour does not.
	 *
	 * @param string $hex The colour, as `#RRGGBB`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-4.1
	 */
	public function passesOnWhite(string $hex): bool {
		$ratio = $this->ratioAgainstWhite(hex: $hex);

		return ($ratio !== null && $ratio >= self::AA_NORMAL_TEXT);

	}//end passesOnWhite()

	/**
	 * The linear value of one sRGB channel.
	 *
	 * @param string $hex Two hex digits.
	 *
	 * @return float
	 */
	private function channel(string $hex): float {
		$value = (hexdec($hex) / 255);
		if ($value <= 0.04045) {
			return ($value / 12.92);
		}

		return ((($value + 0.055) / 1.055) ** 2.4);

	}//end channel()
}//end class
