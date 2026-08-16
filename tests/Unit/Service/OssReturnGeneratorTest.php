<?php

/**
 * Unit tests for OssReturnGenerator (pure aggregation).
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\OssReturnGenerator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Covers REQ-OSS-004 (quarterly aggregation, credit-note netting) and REQ-OSS-010.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class OssReturnGeneratorTest extends TestCase {

	/**
	 * The generator under test (container is never touched by aggregate()).
	 *
	 * @var OssReturnGenerator
	 */
	private OssReturnGenerator $generator;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$this->generator = new OssReturnGenerator( $appConfig,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * Build an OSS-eligible document fixture.
	 *
	 * @param string $country Destination country code.
	 * @param float $net Net amount.
	 * @param float $vat VAT amount.
	 * @param float $rate VAT rate percentage.
	 * @param bool $credit True to mark as a credit note.
	 *
	 * @return array<string,mixed>
	 */
	private function doc(string $country, float $net, float $vat, float $rate, bool $credit = false): array {
		$document = [
			'netAmount' => $net,
			'vatAmount' => $vat,
			'ossContext' => [
				'ossEligible' => true,
				'destinationCountry' => $country,
				'appliedRateCategory' => 'standard',
				'appliedVatRate' => $rate,
			],
		];
		if ($credit === true) {
			$document['documentType'] = 'credit-note';
		}

		return $document;
	}//end doc()

	/**
	 * Aggregation groups by (country, rate category) and sums base + VAT (REQ-OSS-004).
	 *
	 * @return void
	 */
	public function testAggregateGroupsByCountryAndRate(): void {
		$documents = [
			$this->doc('DE', 1000.0, 190.0, 19.0),
			$this->doc('DE', 2000.0, 380.0, 19.0),
			$this->doc('FR', 500.0, 100.0, 20.0),
			// Non-OSS document ignored.
			['netAmount' => 9999.0],
		];

		$result = $this->generator->aggregate($documents);
		self::assertCount(2, $result['lineItems']);
		self::assertSame(3500.0, $result['totalTaxableBase']);
		self::assertSame(670.0, $result['totalVatAmount']);

		// DE line is the summed pair.
		$de = array_values(array_filter($result['lineItems'], static fn ($l) => $l['countryCode'] === 'DE'))[0];
		self::assertSame(3000.0, $de['taxableBase']);
		self::assertSame(570.0, $de['vatAmount']);

	}//end testAggregateGroupsByCountryAndRate()

	/**
	 * A credit note nets against the original country/category (REQ-OSS-004 second scenario).
	 *
	 * @return void
	 */
	public function testAggregateNetsCreditNotes(): void {
		$documents = [
			$this->doc('DE', 1000.0, 190.0, 19.0),
			$this->doc('DE', 300.0, 57.0, 19.0, true),
		];

		$result = $this->generator->aggregate($documents);
		self::assertCount(1, $result['lineItems']);
		self::assertSame(700.0, $result['lineItems'][0]['taxableBase']);
		self::assertSame(133.0, $result['lineItems'][0]['vatAmount']);

	}//end testAggregateNetsCreditNotes()

	/**
	 * A correction return references the original period and is type:correction (REQ-OSS-010).
	 *
	 * @return void
	 */
	public function testBuildCorrection(): void {
		$correction = $this->generator->buildCorrection('adm-1', 2026, 'Q3', 'reg-1', '2026-Q1');
		self::assertSame('correction', $correction['type']);
		self::assertSame('2026-Q1', $correction['correctsPeriod']);
		self::assertSame('draft', $correction['status']);

	}//end testBuildCorrection()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
