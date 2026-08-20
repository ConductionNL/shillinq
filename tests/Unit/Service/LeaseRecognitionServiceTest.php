<?php

/**
 * Unit tests for LeaseRecognitionService.
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
 * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-accounting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\LeaseAmortizationCalculator;
use OCA\Shillinq\Service\LeaseRecognitionService;
use PHPUnit\Framework\TestCase;

/**
 * Tests RoU asset / lease liability recognition at commencement (REQ-LA-001),
 * the restoration-obligation split (REQ-LA-005), and that the recognition
 * journal lines always balance so the GL accepts them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LeaseRecognitionServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var LeaseRecognitionService
	 */
	private LeaseRecognitionService $service;

	/**
	 * Set up the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new LeaseRecognitionService(new LeaseAmortizationCalculator());

	}//end setUp()

	/**
	 * Recognition produces an RoU debit and a liability credit that balance (REQ-LA-001).
	 *
	 * @return void
	 */
	public function testRecogniseBalances(): void {
		$lease = [
			'@self' => ['slug' => 'lease-1'],
			'assetClass' => 'vehicle',
			'nonCancellableTermMonths' => 36,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 1000.0,
			'ibrPercent' => 4.0,
			'initialDirectCosts' => 500.0,
			'classification' => 'IFRS16-capitalised',
			'administrationId' => 'adm-1',
			'extensionOptions' => [],
		];

		$payload = $this->service->recognise($lease);

		self::assertEqualsWithDelta(33870.77, $payload['liability'], 1.0);
		self::assertEqualsWithDelta(($payload['liability'] + 500.0), $payload['rouAsset'], 0.01);
		self::assertTrue($this->service->linesBalance($payload['journalLines']));

		// RoU subtype maps the asset class.
		self::assertSame('rou-vehicles', $payload['journalLines'][0]['leaseAccountSubtype']);
		self::assertSame('lease-liability-noncurrent', $payload['journalLines'][1]['leaseAccountSubtype']);

	}//end testRecogniseBalances()

	/**
	 * A restoration obligation adds a third (credit) line and still balances (REQ-LA-005).
	 *
	 * @return void
	 */
	public function testRestorationObligationProducesThirdLine(): void {
		$lease = [
			'@self' => ['slug' => 'lease-re'],
			'assetClass' => 'real-estate',
			'nonCancellableTermMonths' => 60,
			'paymentFrequency' => 'quarterly',
			'paymentTiming' => 'in-advance',
			'basePaymentAmount' => 95000.0,
			'ibrPercent' => 3.85,
			'restorationObligation' => ['estimatedCost' => 75000.0, 'discountRate' => 0.045],
			'classification' => 'IFRS16-capitalised',
			'administrationId' => 'adm-1',
			'extensionOptions' => [],
		];

		$payload = $this->service->recognise($lease);

		self::assertCount(3, $payload['journalLines']);
		self::assertSame('lease-restoration-obligation', $payload['journalLines'][2]['leaseAccountSubtype']);
		self::assertGreaterThan(0.0, $payload['restorationPv']);
		self::assertTrue($this->service->linesBalance($payload['journalLines']));

	}//end testRestorationObligationProducesThirdLine()

	/**
	 * Recognition builds the RoU fixed-asset record (design.md D3).
	 *
	 * @return void
	 */
	public function testFixedAssetRecord(): void {
		$lease = [
			'@self' => ['slug' => 'lease-it'],
			'assetClass' => 'IT-hardware',
			'nonCancellableTermMonths' => 36,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 500.0,
			'ibrPercent' => 4.0,
			'classification' => 'IFRS16-capitalised',
			'administrationId' => 'adm-9',
			'extensionOptions' => [],
		];

		$asset = $this->service->recognise($lease)['fixedAsset'];

		self::assertTrue($asset['isRouAsset']);
		self::assertSame('lease-it', $asset['sourceLease']);
		self::assertSame('RoU-IT-hardware', $asset['assetType']);
		self::assertSame('straight-line', $asset['depreciationMethod']);
		self::assertSame('adm-9', $asset['administrationId']);

	}//end testFixedAssetRecord()

	/**
	 * Unbalanced lines are rejected by the balance check.
	 *
	 * @return void
	 */
	public function testLinesBalanceRejectsImbalance(): void {
		self::assertFalse(
			$this->service->linesBalance(
				[
					['side' => 'debit', 'amount' => 100.0],
					['side' => 'credit', 'amount' => 90.0],
				]
			)
		);

	}//end testLinesBalanceRejectsImbalance()
}//end class
