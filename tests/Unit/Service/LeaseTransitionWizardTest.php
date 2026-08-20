<?php

/**
 * Unit tests for LeaseTransitionWizard (skeleton).
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
use OCA\Shillinq\Service\LeaseTransitionWizard;
use PHPUnit\Framework\TestCase;

/**
 * Tests the IFRS 16 transition wizard skeleton — method selection, practical-
 * expedient elections, and the per-lease recognition aggregation.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LeaseTransitionWizardTest extends TestCase {

	/**
	 * The wizard under test.
	 *
	 * @var LeaseTransitionWizard
	 */
	private LeaseTransitionWizard $wizard;

	/**
	 * Set up the wizard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$calculator = new LeaseAmortizationCalculator();
		$this->wizard = new LeaseTransitionWizard(
			recognitionService: new LeaseRecognitionService(calculator: $calculator),
			calculator: $calculator,
		);

	}//end setUp()

	/**
	 * Build a baseline lease fixture.
	 *
	 * @param array<string,mixed> $overrides Optional field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function leaseFixture(array $overrides = []): array {
		$lease = [
			'leaseNumber' => 'VH-T-001',
			'assetClass' => 'vehicle',
			'classification' => 'IFRS16-capitalised',
			'nonCancellableTermMonths' => 36,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 1000.0,
			'paymentCurrency' => 'EUR',
			'ibrPercent' => 4.0,
		];

		return array_merge($lease, $overrides);
	}//end leaseFixture()

	/**
	 * Modified-retrospective: recognises every lease, no opening RE adjustment.
	 *
	 * @return void
	 */
	public function testModifiedRetrospectiveZeroRetainedEarnings(): void {
		$payload = $this->wizard->compute(
			leases: [$this->leaseFixture()],
			method: 'modified-retrospective',
			transitionDate: '2026-01-01',
		);

		self::assertSame('modified-retrospective', $payload['method']);
		self::assertSame(1, count($payload['recognitions']));
		self::assertGreaterThan(0.0, $payload['totalLeaseLiability']);
		self::assertGreaterThan(0.0, $payload['totalRouAsset']);
		self::assertSame(0.0, $payload['openingRetainedEarningsAdjustment']);

	}//end testModifiedRetrospectiveZeroRetainedEarnings()

	/**
	 * Full-retrospective records the catch-up as opening retained-earnings adjustment.
	 *
	 * @return void
	 */
	public function testFullRetrospectiveCarriesRetainedEarningsAdjustment(): void {
		// Restoration obligation creates a positive RoU-vs-liability delta that
		// becomes the retained-earnings catch-up under full retrospective.
		$lease = $this->leaseFixture(
			[
				'initialDirectCosts' => 1500.0,
				'restorationObligation' => ['estimatedCost' => 2000.0, 'discountRate' => 4.0],
			]
		);

		$payload = $this->wizard->compute(
			leases: [$lease],
			method: 'full-retrospective',
			transitionDate: '2026-01-01',
		);

		self::assertSame('full-retrospective', $payload['method']);
		self::assertNotSame(0.0, $payload['openingRetainedEarningsAdjustment']);

	}//end testFullRetrospectiveCarriesRetainedEarningsAdjustment()

	/**
	 * The short-term-exempt-at-transition expedient excludes those leases.
	 *
	 * @return void
	 */
	public function testShortTermExpedientExcludesExemptLeases(): void {
		$payload = $this->wizard->compute(
			leases: [
				$this->leaseFixture(),
				$this->leaseFixture(
					[
						'leaseNumber' => 'IT-T-001',
						'classification' => 'short-term-exempt',
					]
				),
			],
			method: 'modified-retrospective',
			transitionDate: '2026-01-01',
			practicalExpedients: ['short-term-exempt-at-transition' => true],
		);

		self::assertSame(1, count($payload['recognitions']));
		self::assertTrue($payload['practicalExpedients']['short-term-exempt-at-transition']);

	}//end testShortTermExpedientExcludesExemptLeases()

	/**
	 * The disclosure note seed mentions the method, lease count and expedient elections.
	 *
	 * @return void
	 */
	public function testDisclosureSeedMentionsKeyFacts(): void {
		$payload = $this->wizard->compute(
			leases: [$this->leaseFixture()],
			method: 'modified-retrospective',
			transitionDate: '2026-01-01',
			practicalExpedients: ['single-discount-rate-by-class' => true],
		);

		self::assertStringContainsString('modified-retrospective', $payload['disclosureNoteSeed']);
		self::assertStringContainsString('2026-01-01', $payload['disclosureNoteSeed']);
		self::assertStringContainsString('single-discount-rate-by-class', $payload['disclosureNoteSeed']);

	}//end testDisclosureSeedMentionsKeyFacts()

	/**
	 * Unknown method defaults to modified-retrospective (fail-safe).
	 *
	 * @return void
	 */
	public function testUnknownMethodFallsBackToModifiedRetrospective(): void {
		$payload = $this->wizard->compute(
			leases: [$this->leaseFixture()],
			method: 'bogus',
			transitionDate: '2026-01-01',
		);

		self::assertSame('modified-retrospective', $payload['method']);

	}//end testUnknownMethodFallsBackToModifiedRetrospective()
}//end class
