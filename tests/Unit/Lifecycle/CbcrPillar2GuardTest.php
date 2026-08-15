<?php

/**
 * Unit tests for CbcrPillar2Guard.
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
 * @spec openspec/changes/bookkeeping-cbcr-pillar2/specs/bookkeeping-cbcr-pillar2/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\CbcrPillar2Guard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CbcrPillar2Guard lifecycle preconditions.
 *
 * Covers REQ-CBC-002 (summary reconcile completeness), REQ-CBC-006 (QDMTT
 * priority over IIR + QDMTT submission) and REQ-CBC-010 (CbCR reconciliation
 * sign-off). All guards fail closed; inline-object cases never touch the container.
 */
class CbcrPillar2GuardTest extends TestCase {

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
	 * The guard under test.
	 *
	 * @var CbcrPillar2Guard
	 */
	private CbcrPillar2Guard $guard;

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
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new CbcrPillar2Guard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * A jurisdiction summary whose totalRevenue ties out may be reconciled
	 * (REQ-CBC-002).
	 *
	 * @return void
	 */
	public function testSummaryThatTiesOutCanReconcile(): void {
		$object = [
			'period' => 'FY2026',
			'jurisdiction' => 'DE',
			'unrelatedPartyRevenue' => 173000000,
			'relatedPartyRevenue' => 42000000,
			'totalRevenue' => 215000000,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canReconcileSummary(summaryId: 's-1', object: $object));

	}//end testSummaryThatTiesOutCanReconcile()

	/**
	 * A jurisdiction summary whose totalRevenue does not tie out is denied
	 * (REQ-CBC-002 fail-closed).
	 *
	 * @return void
	 */
	public function testSummaryThatDoesNotTieOutCannotReconcile(): void {
		$object = [
			'period' => 'FY2026',
			'jurisdiction' => 'DE',
			'unrelatedPartyRevenue' => 173000000,
			'relatedPartyRevenue' => 42000000,
			'totalRevenue' => 200000000,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canReconcileSummary(summaryId: 's-2', object: $object));

	}//end testSummaryThatDoesNotTieOutCannotReconcile()

	/**
	 * A summary missing its period is denied (REQ-CBC-002 fail-closed).
	 *
	 * @return void
	 */
	public function testSummaryMissingPeriodCannotReconcile(): void {
		$object = ['jurisdiction' => 'DE', 'totalRevenue' => 0];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canReconcileSummary(summaryId: 's-3', object: $object));

	}//end testSummaryMissingPeriodCannotReconcile()

	/**
	 * An NL-resident low-taxed computation with a QDMTT allocation may be
	 * approved — QDMTT priority is honoured (REQ-CBC-006).
	 *
	 * @return void
	 */
	public function testNlLowTaxedComputationWithQdmttCanApprove(): void {
		$object = [
			'jurisdiction' => 'NL',
			'etrJurisdiction' => 0.12,
			'minimumRate' => 0.15,
			'topUpTaxAmount' => 180000,
			'qdmttAmount' => 180000,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canApproveComputation(computationId: 'c-1', object: $object));

	}//end testNlLowTaxedComputationWithQdmttCanApprove()

	/**
	 * An NL-resident low-taxed computation that takes IIR without a QDMTT
	 * allocation is denied — QDMTT must come first (REQ-CBC-006 fail-closed).
	 *
	 * @return void
	 */
	public function testNlLowTaxedComputationWithoutQdmttCannotApprove(): void {
		$object = [
			'jurisdiction' => 'NL',
			'etrJurisdiction' => 0.12,
			'minimumRate' => 0.15,
			'topUpTaxAmount' => 180000,
			'qdmttAmount' => 0,
			'iirAmount' => 180000,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApproveComputation(computationId: 'c-2', object: $object));

	}//end testNlLowTaxedComputationWithoutQdmttCannotApprove()

	/**
	 * A non-NL low-taxed computation is not subject to the NL QDMTT-priority gate
	 * and may be approved (REQ-CBC-006).
	 *
	 * @return void
	 */
	public function testNonNlLowTaxedComputationCanApprove(): void {
		$object = [
			'jurisdiction' => 'BM',
			'etrJurisdiction' => 0.0,
			'minimumRate' => 0.15,
			'topUpTaxAmount' => 1170000,
			'qdmttAmount' => 0,
			'iirAmount' => 1170000,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canApproveComputation(computationId: 'c-3', object: $object));

	}//end testNonNlLowTaxedComputationCanApprove()

	/**
	 * A well-taxed NL computation (ETR above the minimum) has no top-up and may
	 * be approved (REQ-CBC-006).
	 *
	 * @return void
	 */
	public function testWellTaxedNlComputationCanApprove(): void {
		$object = [
			'jurisdiction' => 'NL',
			'etrJurisdiction' => 0.22,
			'minimumRate' => 0.15,
			'topUpTaxAmount' => 0,
			'qdmttAmount' => 0,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canApproveComputation(computationId: 'c-4', object: $object));

	}//end testWellTaxedNlComputationCanApprove()

	/**
	 * A complete QDMTT return may be submitted (REQ-CBC-006).
	 *
	 * @return void
	 */
	public function testCompleteQdmttReturnCanSubmit(): void {
		$object = [
			'period' => 'FY2026',
			'entity' => 'cbcr-sample-entity-nl-upe',
			'qdmttPayable' => 180000,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canSubmitQdmtt(returnId: 'q-1', object: $object));

	}//end testCompleteQdmttReturnCanSubmit()

	/**
	 * A QDMTT return missing its entity is denied (REQ-CBC-006 fail-closed).
	 *
	 * @return void
	 */
	public function testQdmttReturnMissingEntityCannotSubmit(): void {
		$object = ['period' => 'FY2026', 'qdmttPayable' => 180000];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmitQdmtt(returnId: 'q-2', object: $object));

	}//end testQdmttReturnMissingEntityCannotSubmit()

	/**
	 * A QDMTT return with a negative payable is denied (REQ-CBC-006 fail-closed).
	 *
	 * @return void
	 */
	public function testQdmttReturnNegativePayableCannotSubmit(): void {
		$object = ['period' => 'FY2026', 'entity' => 'e-1', 'qdmttPayable' => -5];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmitQdmtt(returnId: 'q-3', object: $object));

	}//end testQdmttReturnNegativePayableCannotSubmit()

	/**
	 * A CbCR return whose residual is within EUR 1M may be reconciled without
	 * explicit items (REQ-CBC-010).
	 *
	 * @return void
	 */
	public function testCbcrReturnSmallResidualCanReconcile(): void {
		$object = ['reconciliationResidual' => 500000, 'reconciliationItems' => []];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canReconcileCbcrReturn(returnId: 'r-1', object: $object));

	}//end testCbcrReturnSmallResidualCanReconcile()

	/**
	 * A CbCR return with a residual over EUR 1M and no explanation is denied
	 * (REQ-CBC-010 fail-closed).
	 *
	 * @return void
	 */
	public function testCbcrReturnLargeUnexplainedResidualCannotReconcile(): void {
		$object = ['reconciliationResidual' => 3000000, 'reconciliationItems' => []];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canReconcileCbcrReturn(returnId: 'r-2', object: $object));

	}//end testCbcrReturnLargeUnexplainedResidualCannotReconcile()

	/**
	 * A CbCR return with a residual over EUR 1M that is explained by an item may
	 * be reconciled (REQ-CBC-010).
	 *
	 * @return void
	 */
	public function testCbcrReturnLargeExplainedResidualCanReconcile(): void {
		$object = [
			'reconciliationResidual' => 3000000,
			'reconciliationItems' => [
				['category' => 'jv-pro-rata', 'amount' => 3000000, 'description' => 'Joint-venture proportional consolidation.'],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canReconcileCbcrReturn(returnId: 'r-3', object: $object));

	}//end testCbcrReturnLargeExplainedResidualCanReconcile()
}//end class
