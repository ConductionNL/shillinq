<?php

/**
 * Unit tests for VarianceGate.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-cycle-count/specs/inventory-cycle-count/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\VarianceGate;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for VarianceGate.
 *
 * Covers REQ-ICC-002 (partial-count scope), REQ-ICC-003 (line derivation),
 * REQ-ICC-004 (variance threshold flagging), REQ-ICC-005 (reason-code
 * validity on post), and REQ-ICC-006 (lifecycle transition gates).
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
// phpcs:disable Squiz.PHP.DisallowInlineIf
class VarianceGateTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock ObjectService.
	 *
	 * @var object&MockObject
	 */
	private object $objectService;

	/**
	 * The guard under test.
	 *
	 * @var VarianceGate
	 */
	private VarianceGate $gate;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Generic ObjectService stub: setRegister/setSchema return self;
		// find/findAll are overridden per-test.
		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$this->objectService = new class {

			/** @var string current schema set on the chain */
			public string $currentSchema = '';

			/** @var array<int,array<string,mixed>> line rows returned by findAll */
			public array $lines = [];

			/** @var array<int,array<string,mixed>> reason rows returned by findAll */
			public array $reasons = [];

			/** Set OR register; chainable. */
			public function setRegister(string $register): self {
				return $this;
			}

			/** Set OR schema; chainable. */
			public function setSchema(string $schema): self {
				$this->currentSchema = $schema;
				return $this;
			}

			/** Stubbed findAll returning per-schema fixture data. */
			public function findAll(array $args = []): array {
				if ($this->currentSchema === 'InventoryCycleCountLine') {
					return $this->lines;
				}

				if ($this->currentSchema === 'InventoryVarianceReason') {
					return $this->reasons;
				}

				return [];
			}

			/** Stubbed find returning empty. */
			public function find(array $args = []): array {
				return [];
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

		$this->container->method('get')->willReturn($this->objectService);
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->gate = new VarianceGate(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Full counts always pass scope check per REQ-ICC-002.
	 *
	 * @return void
	 */
	public function testFullCountScopeAlwaysValid(): void {
		self::assertTrue(
			$this->gate->requireValidScope(
				['countType' => 'full', 'countId' => 'CC-2026-05-00001']
			)
		);

	}//end testFullCountScopeAlwaysValid()

	/**
	 * Partial count without locationFilter or categoryFilter is rejected
	 * per REQ-ICC-002 + REQ-ICC-008.
	 *
	 * @return void
	 */
	public function testPartialCountWithoutScopeRejected(): void {
		self::assertFalse(
			$this->gate->requireValidScope(
				[
					'countType' => 'partial',
					'countId' => 'CC-2026-05-00001',
				]
			)
		);

	}//end testPartialCountWithoutScopeRejected()

	/**
	 * Partial count with locationFilter passes per REQ-ICC-002.
	 *
	 * @return void
	 */
	public function testPartialCountWithLocationPasses(): void {
		self::assertTrue(
			$this->gate->requireValidScope(
				[
					'countType' => 'partial',
					'locationFilter' => 'loc-w01',
					'countId' => 'CC-2026-05-00001',
				]
			)
		);

	}//end testPartialCountWithLocationPasses()

	/**
	 * Partial count with categoryFilter passes per REQ-ICC-002.
	 *
	 * @return void
	 */
	public function testPartialCountWithCategoryPasses(): void {
		self::assertTrue(
			$this->gate->requireValidScope(
				[
					'countType' => 'partial',
					'categoryFilter' => 'electronics',
					'countId' => 'CC-2026-05-00001',
				]
			)
		);

	}//end testPartialCountWithCategoryPasses()

	/**
	 * Unknown countType is rejected (fail-closed) per REQ-ICC-002.
	 *
	 * @return void
	 */
	public function testUnknownCountTypeRejected(): void {
		self::assertFalse(
			$this->gate->requireValidScope(
				[
					'countType' => 'random',
					'countId' => 'CC-2026-05-00001',
				]
			)
		);

	}//end testUnknownCountTypeRejected()

	/**
	 * A count whose lines are all under-threshold posts cleanly per REQ-ICC-004.
	 *
	 * @return void
	 */
	public function testCountWithNoFlaggedLinesPosts(): void {
		// Line under 5% threshold (variance 1 / 100 = 1%).
		$this->objectService->lines = [
			[
				'lineId' => 'CC-2026-05-00001-001',
				'expectedQuantity' => 100,
				'countedQuantity' => 99,
				'unitCost' => 40.0,
			],
		];

		self::assertTrue(
			$this->gate->requireReasonsOnPost(
				[
					'countId' => 'CC-2026-05-00001',
					'administrationId' => 'adm-consultancy-nl',
				]
			)
		);

	}//end testCountWithNoFlaggedLinesPosts()

	/**
	 * A flagged line without a reasonCode denies the post per REQ-ICC-004 +
	 * REQ-ICC-005.
	 *
	 * @return void
	 */
	public function testFlaggedLineWithoutReasonDeniesPost(): void {
		// Variance 6 / 100 = 6% > 5% threshold → flagged.
		$this->objectService->lines = [
			[
				'lineId' => 'CC-2026-05-00001-001',
				'expectedQuantity' => 100,
				'countedQuantity' => 94,
				'unitCost' => 40.0,
				'reasonCode' => null,
			],
		];
		$this->objectService->reasons = [
			['reasonId' => 'DMG'],
		];

		self::assertFalse(
			$this->gate->requireReasonsOnPost(
				[
					'countId' => 'CC-2026-05-00001',
					'administrationId' => 'adm-consultancy-nl',
				]
			)
		);

	}//end testFlaggedLineWithoutReasonDeniesPost()

	/**
	 * A flagged line with a valid active reasonCode passes per REQ-ICC-005.
	 *
	 * @return void
	 */
	public function testFlaggedLineWithActiveReasonPosts(): void {
		$this->objectService->lines = [
			[
				'lineId' => 'CC-2026-05-00001-001',
				'expectedQuantity' => 100,
				'countedQuantity' => 94,
				'unitCost' => 40.0,
				'reasonCode' => 'DMG',
			],
		];
		$this->objectService->reasons = [
			['reasonId' => 'DMG'],
			['reasonId' => 'OBS'],
		];

		self::assertTrue(
			$this->gate->requireReasonsOnPost(
				[
					'countId' => 'CC-2026-05-00001',
					'administrationId' => 'adm-consultancy-nl',
				]
			)
		);

	}//end testFlaggedLineWithActiveReasonPosts()

	/**
	 * A flagged line whose reasonCode is INACTIVE (not in active set) denies
	 * the post per REQ-ICC-005 ("inactive reason code cannot be selected").
	 *
	 * @return void
	 */
	public function testFlaggedLineWithInactiveReasonDeniesPost(): void {
		$this->objectService->lines = [
			[
				'lineId' => 'CC-2026-05-00001-001',
				'expectedQuantity' => 100,
				'countedQuantity' => 94,
				'unitCost' => 40.0,
				'reasonCode' => 'OBSOLETE-CODE',
			],
		];
		// OBSOLETE-CODE not in the active set.
		$this->objectService->reasons = [
			['reasonId' => 'DMG'],
		];

		self::assertFalse(
			$this->gate->requireReasonsOnPost(
				[
					'countId' => 'CC-2026-05-00001',
					'administrationId' => 'adm-consultancy-nl',
				]
			)
		);

	}//end testFlaggedLineWithInactiveReasonDeniesPost()

	/**
	 * A line crossing the absolute value threshold (variance 50 * 15 = 750 >
	 * 500) is flagged even when % variance would be tolerable per REQ-ICC-004.
	 *
	 * @return void
	 */
	public function testValueThresholdFlagsExpensiveItems(): void {
		// 50 / 100 = 50% which is > 5%, but the test is asserting BOTH thresholds work;
		// we want to check the value-only path: pick qty variance under % but with
		// expensive unit cost. expected 1000, counted 950 → 5% exactly (not >), but
		// value variance is 50 * 15 = 750 > 500.
		$this->objectService->lines = [
			[
				'lineId' => 'CC-2026-05-00001-001',
				'expectedQuantity' => 1000,
				'countedQuantity' => 950,
				'unitCost' => 15.00,
				'reasonCode' => null,
			],
		];
		$this->objectService->reasons = [['reasonId' => 'DMG']];

		// 5% of 1000 = 50; absolute variance is 50, which is NOT > 50, so qty rule
		// does not fire. Value variance 750 > 500 → flagged.
		self::assertFalse(
			$this->gate->requireReasonsOnPost(
				[
					'countId' => 'CC-2026-05-00001',
					'administrationId' => 'adm-consultancy-nl',
				]
			)
		);

	}//end testValueThresholdFlagsExpensiveItems()

	/**
	 * An empty count (no lines yet) posts trivially per REQ-ICC-006 — the
	 * VarianceGate has no flagged-line work to do.
	 *
	 * @return void
	 */
	public function testEmptyCountPosts(): void {
		$this->objectService->lines = [];
		$this->objectService->reasons = [];

		self::assertTrue(
			$this->gate->requireReasonsOnPost(
				[
					'countId' => 'CC-2026-05-00001',
					'administrationId' => 'adm-consultancy-nl',
				]
			)
		);

	}//end testEmptyCountPosts()

	/**
	 * Missing countId or administrationId denies the post (fail-closed).
	 *
	 * @return void
	 */
	public function testMissingFieldsDenyPost(): void {
		self::assertFalse(
			$this->gate->requireReasonsOnPost(['administrationId' => 'adm-1'])
		);
		self::assertFalse(
			$this->gate->requireReasonsOnPost(['countId' => 'CC-2026-05-00001'])
		);

	}//end testMissingFieldsDenyPost()

	/**
	 * recalculateLine recomputes expected/counted/variance/requiresReason
	 * for a small-variance line per REQ-ICC-003 + REQ-ICC-004.
	 *
	 * @return void
	 */
	public function testRecalculateLineUnderThreshold(): void {
		$line = [
			'expectedQuantity' => 100,
			'countedQuantity' => 99,
			'unitCost' => 40.00,
		];

		$refreshed = $this->gate->recalculateLine($line);

		self::assertSame(4000.00, $refreshed['expectedValue']);
		self::assertSame(3960.00, $refreshed['countedValue']);
		self::assertSame(-1.00, $refreshed['quantityVariance']);
		self::assertSame(-40.00, $refreshed['valueVariance']);
		self::assertFalse($refreshed['requiresReason']);

	}//end testRecalculateLineUnderThreshold()

	/**
	 * recalculateLine flips requiresReason=true for a line over % threshold
	 * per REQ-ICC-004.
	 *
	 * @return void
	 */
	public function testRecalculateLineFlagsOverPctThreshold(): void {
		$line = [
			'expectedQuantity' => 100,
			'countedQuantity' => 94,
			'unitCost' => 40.00,
		];

		$refreshed = $this->gate->recalculateLine($line);

		self::assertSame(-6.00, $refreshed['quantityVariance']);
		self::assertSame(-240.00, $refreshed['valueVariance']);
		self::assertTrue($refreshed['requiresReason']);

	}//end testRecalculateLineFlagsOverPctThreshold()

	/**
	 * recalculateLine flips requiresReason=true for a line over absolute
	 * value threshold per REQ-ICC-004 — qty variance OK but value variance
	 * exceeds EUR 500.
	 *
	 * @return void
	 */
	public function testRecalculateLineFlagsOverValueThreshold(): void {
		$line = [
			'expectedQuantity' => 1000,
			'countedQuantity' => 950,
			'unitCost' => 15.00,
		];

		$refreshed = $this->gate->recalculateLine($line);

		// qty variance -50, |value variance| 750 > 500 → flagged.
		self::assertSame(-50.00, $refreshed['quantityVariance']);
		self::assertSame(-750.00, $refreshed['valueVariance']);
		self::assertTrue($refreshed['requiresReason']);

	}//end testRecalculateLineFlagsOverValueThreshold()

	/**
	 * recalculateLine returns null variance + false requiresReason when
	 * counted is null.
	 *
	 * @return void
	 */
	public function testRecalculateLineNullCountedNotFlagged(): void {
		$line = [
			'expectedQuantity' => 100,
			'countedQuantity' => null,
			'unitCost' => 40.00,
		];

		$refreshed = $this->gate->recalculateLine($line);

		self::assertNull($refreshed['countedValue']);
		self::assertNull($refreshed['quantityVariance']);
		self::assertNull($refreshed['valueVariance']);
		self::assertFalse($refreshed['requiresReason']);

	}//end testRecalculateLineNullCountedNotFlagged()
}//end class
