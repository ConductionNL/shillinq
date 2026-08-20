<?php

/**
 * Unit tests for OssReturnFormatter.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-btw-oss-eu/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\OssReturnFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Covers REQ-OSS-005 (submission format + finalisation precondition) and REQ-OSS-016.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class OssReturnFormatterTest extends TestCase {

	/**
	 * The formatter under test.
	 *
	 * @var OssReturnFormatter
	 */
	private OssReturnFormatter $formatter;

	/**
	 * A representative draft return.
	 *
	 * @var array<string,mixed>
	 */
	private array $draft;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->formatter = new OssReturnFormatter();
		$this->draft = [
			'periodYear' => 2026,
			'periodQuarter' => 'Q2',
			'type' => 'regular',
			'lineItems' => [
				['countryCode' => 'DE', 'rateCategory' => 'standard', 'taxableBase' => 9484.21, 'vatRate' => 19.0, 'vatAmount' => 1802.0],
				['countryCode' => 'FR', 'rateCategory' => 'standard', 'taxableBase' => 7200.0, 'vatRate' => 20.0, 'vatAmount' => 1440.0],
			],
			'totalTaxableBase' => 16684.21,
			'totalVatAmount' => 3242.0,
		];

	}//end setUp()

	/**
	 * Finalisation requires an active registration with an OSS-identifier (REQ-OSS-005).
	 *
	 * @return void
	 */
	public function testCanFinalize(): void {
		$active = ['registrationStatus' => 'active', 'ossIdentifier' => 'NL1B01'];
		self::assertTrue($this->formatter->canFinalize($this->draft, $active));

		$voluntary = ['registrationStatus' => 'voluntaryBelowThreshold', 'ossIdentifier' => 'NL1B01'];
		self::assertTrue($this->formatter->canFinalize($this->draft, $voluntary));

		// Inactive / missing registration -> oss.registration.invalid.
		self::assertFalse($this->formatter->canFinalize($this->draft, ['registrationStatus' => 'deregistered', 'ossIdentifier' => 'NL1B01']));
		self::assertFalse($this->formatter->canFinalize($this->draft, ['registrationStatus' => 'active']));

	}//end testCanFinalize()

	/**
	 * The XML payload carries the identifier, period (YYYY-Qn), countries and EUR amounts (REQ-OSS-005).
	 *
	 * @return void
	 */
	public function testToXmlIsWellFormedAndComplete(): void {
		$xml = $this->formatter->toXml($this->draft, 'NL123456789B01', 'NL00BANK0123456789');

		// Well-formed: parsing succeeds (LIBXML_NONET keeps it network-free).
		$doc = new \DOMDocument();
		self::assertTrue($doc->loadXML($xml, LIBXML_NONET));

		self::assertStringContainsString('<OSSIdentifier>NL123456789B01</OSSIdentifier>', $xml);
		self::assertStringContainsString('<Period>2026-Q2</Period>', $xml);
		self::assertStringContainsString('<CountryCode>DE</CountryCode>', $xml);
		self::assertStringContainsString('<VatAmount>1802.00</VatAmount>', $xml);
		self::assertStringContainsString('<TotalVatAmount>3242.00</TotalVatAmount>', $xml);

	}//end testToXmlIsWellFormedAndComplete()

	/**
	 * The CSV payload has a header, one row per line, and a totals row (REQ-OSS-005).
	 *
	 * @return void
	 */
	public function testToCsv(): void {
		$csv = $this->formatter->toCsv($this->draft, 'NL123456789B01');
		$lines = array_values(array_filter(explode("\n", $csv), static fn ($l) => $l !== ''));

		// Header + 2 line rows + totals.
		self::assertCount(4, $lines);
		self::assertStringStartsWith('ossIdentifier,period,countryCode', $lines[0]);
		self::assertStringContainsString('DE,standard', $lines[1]);
		self::assertStringStartsWith('TOTAL,', $lines[3]);
		self::assertStringContainsString('3242.00', $lines[3]);

	}//end testToCsv()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
