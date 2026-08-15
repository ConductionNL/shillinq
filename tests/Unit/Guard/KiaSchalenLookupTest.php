<?php

/**
 * Unit tests for KiaSchalenLookup.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-investeringsaftrek/specs/bookkeeping-investeringsaftrek/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\KiaSchalenLookup;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for KiaSchalenLookup.
 *
 * Covers REQ-INV-005/006 with the 2026 art. 3.41 piecewise schaal:
 * - tier 1: 0%       (below drempel)
 * - tier 2: 28%      (EUR 2.800 .. EUR 70.602)
 * - tier 3: flat EUR 19.769 (EUR 70.602 .. EUR 130.744)
 * - tier 4: EUR 19.769 - 7,56% x (total - EUR 130.744)
 * - tier 5: 0%       (above plafond)
 * All amounts in EUR cents.
 */
class KiaSchalenLookupTest extends TestCase {
	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var KiaSchalenLookup
	 */
	private KiaSchalenLookup $lookup;

	/**
	 * The 2026 tier table in EUR cents (mirrors investeringsaftrek-kia-tiers-2026.json).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $tiers;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->lookup = new KiaSchalenLookup(logger: $this->logger);

		$this->tiers = [
			['tier' => 1, 'from' => 0,        'tot' => 280000,   'percentage' => 0,     'fixedAmount' => 0],
			['tier' => 2, 'from' => 280000,   'tot' => 7060200,  'percentage' => 28,    'fixedAmount' => null],
			['tier' => 3, 'from' => 7060200,  'tot' => 13074400, 'percentage' => null,  'fixedAmount' => 1976900],
			['tier' => 4, 'from' => 13074400, 'tot' => 39223000, 'percentage' => -7.56, 'fixedAmount' => 1976900],
			['tier' => 5, 'from' => 39223000, 'tot' => null,     'percentage' => 0,     'fixedAmount' => 0],
		];

	}//end setUp()

	/**
	 * REQ-INV-006: below the drempel yields no KIA.
	 *
	 * @return void
	 */
	public function testBelowDrempelIsZero(): void {
		self::assertSame(0, $this->lookup->computeAftrek($this->tiers, 200000));

	}//end testBelowDrempelIsZero()

	/**
	 * REQ-INV-006: zero or negative jaartotaal yields zero.
	 *
	 * @return void
	 */
	public function testZeroJaartotaalIsZero(): void {
		self::assertSame(0, $this->lookup->computeAftrek($this->tiers, 0));
		self::assertSame(0, $this->lookup->computeAftrek($this->tiers, -100));

	}//end testZeroJaartotaalIsZero()

	/**
	 * REQ-INV-006: tier-2 applies 28% of the whole jaartotaal.
	 *
	 * @return void
	 */
	public function testTier2PercentageBand(): void {
		// EUR 50.000 -> 28% = EUR 14.000 = 1400000 cents.
		self::assertSame(1400000, $this->lookup->computeAftrek($this->tiers, 5000000));

	}//end testTier2PercentageBand()

	/**
	 * REQ-INV-006: tier-3 is a flat maximum amount.
	 *
	 * @return void
	 */
	public function testTier3FlatAmount(): void {
		// EUR 100.000 -> flat EUR 19.769 = 1976900 cents.
		self::assertSame(1976900, $this->lookup->computeAftrek($this->tiers, 10000000));

	}//end testTier3FlatAmount()

	/**
	 * REQ-INV-006: tier-4 tapers EUR 19.769 by 7,56% of the excess over EUR 130.744.
	 *
	 * @return void
	 */
	public function testTier4Taper(): void {
		// EUR 200.000: excess over EUR 130.744 = EUR 69.256.
		// 7,56% x 6925600 cents = 523575,36 -> round 523575.
		// 1976900 - 523575 = 1453325 cents.
		self::assertSame(1453325, $this->lookup->computeAftrek($this->tiers, 20000000));

	}//end testTier4Taper()

	/**
	 * REQ-INV-006: above the plafond yields no KIA.
	 *
	 * @return void
	 */
	public function testAbovePlafondIsZero(): void {
		self::assertSame(0, $this->lookup->computeAftrek($this->tiers, 40000000));

	}//end testAbovePlafondIsZero()

	/**
	 * REQ-INV-005: marginal effect is the delta across tier boundaries, not asset x percentage.
	 *
	 * @return void
	 */
	public function testMarginalEffectAcrossTierBoundary(): void {
		// Prior total EUR 60.000 (tier 2): 28% = EUR 16.800 = 1680000.
		// Add EUR 20.000 -> EUR 80.000 (tier 3 flat EUR 19.769 = 1976900).
		// Marginal = 1976900 - 1680000 = 296900.
		$marginal = $this->lookup->marginalEffect($this->tiers, 6000000, 2000000);
		self::assertSame(296900, $marginal);

	}//end testMarginalEffectAcrossTierBoundary()

	/**
	 * REQ-INV-005: marginal effect never goes negative.
	 *
	 * @return void
	 */
	public function testMarginalEffectNeverNegative(): void {
		// Both totals above the plafond -> 0 before, 0 after -> 0 marginal.
		$marginal = $this->lookup->marginalEffect($this->tiers, 40000000, 5000000);
		self::assertSame(0, $marginal);

	}//end testMarginalEffectNeverNegative()

	/**
	 * resolveTier returns the band that contains the jaartotaal.
	 *
	 * @return void
	 */
	public function testResolveTierSelectsCorrectBand(): void {
		$tier = $this->lookup->resolveTier($this->tiers, 8000000);
		self::assertNotNull($tier);
		self::assertSame(3, $tier['tier']);

	}//end testResolveTierSelectsCorrectBand()
}//end class
