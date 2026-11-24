<?php

/**
 * Unit tests for ColourContrast.
 *
 * REQ-SID-001: an invoice layout's primary colour reaches 4.5:1 against
 * white. The ratios are the WCAG 2.x reference values.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sales\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.1
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sales\InvoiceDocument;

use OCA\Shillinq\Service\Sales\InvoiceDocument\ColourContrast;
use PHPUnit\Framework\TestCase;

/**
 * The contrast of a hex colour against white.
 */
final class ColourContrastTest extends TestCase {

	/**
	 * Black on white is 21:1 and white on white is 1:1, the two ends of the scale.
	 *
	 * @return void
	 */
	public function testTheScaleRunsFromOneToTwentyOne(): void {
		$contrast = new ColourContrast();

		self::assertEqualsWithDelta(21.0, $contrast->ratioAgainstWhite('#000000'), 0.001);
		self::assertEqualsWithDelta(1.0, $contrast->ratioAgainstWhite('#FFFFFF'), 0.001);
	}//end testTheScaleRunsFromOneToTwentyOne()

	/**
	 * The spec's colour #1B4F72 and the schema defaults pass; the reference
	 * grey #777777 (4.48:1) just fails and #767676 (4.54:1) just passes.
	 *
	 * @return void
	 */
	public function testTheAaThresholdSitsBetweenTheTwoReferenceGreys(): void {
		$contrast = new ColourContrast();

		self::assertTrue($contrast->passesOnWhite('#1B4F72'));
		self::assertTrue($contrast->passesOnWhite('#0F4C81'));
		self::assertTrue($contrast->passesOnWhite('#b8461b'));
		self::assertTrue($contrast->passesOnWhite('#767676'));
		self::assertFalse($contrast->passesOnWhite('#777777'));
		self::assertFalse($contrast->passesOnWhite('#FFD700'));
	}//end testTheAaThresholdSitsBetweenTheTwoReferenceGreys()

	/**
	 * A value that is not a #RRGGBB colour has no ratio and does not pass.
	 *
	 * @return void
	 */
	public function testANonColourHasNoRatio(): void {
		$contrast = new ColourContrast();

		self::assertNull($contrast->ratioAgainstWhite('navy'));
		self::assertNull($contrast->ratioAgainstWhite('#FFF'));
		self::assertFalse($contrast->passesOnWhite('#12345G'));
	}//end testANonColourHasNoRatio()
}//end class
