<?php

/**
 * Unit tests for CcmFindingGuard.
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
 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\CcmFindingGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CcmFindingGuard lifecycle preconditions.
 *
 * Covers REQ-CCM-004 (finding dismiss/confirm require a mandatory rationale) and
 * REQ-CCM-006 (report approval requires an approver + an executive summary). All
 * guards fail closed; inline-object cases never touch the container.
 */
class CcmFindingGuardTest extends TestCase {
	// phpcs:disable CustomSniffs.Functions.NamedParameters

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
	 * @var CcmFindingGuard
	 */
	private CcmFindingGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new CcmFindingGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * A finding with a rationale may be dismissed (REQ-CCM-004).
	 *
	 * @return void
	 */
	public function testFindingWithRationaleCanDismiss(): void {
		$object = ['resolutionRationale' => 'Reviewed; recurring exempted consolidation JE.'];
		self::assertTrue($this->guard->canDismiss(findingId: 'f-1', object: $object));

	}//end testFindingWithRationaleCanDismiss()

	/**
	 * A finding without a rationale cannot be dismissed (REQ-CCM-004 fail-closed).
	 *
	 * @return void
	 */
	public function testFindingWithoutRationaleCannotDismiss(): void {
		self::assertFalse($this->guard->canDismiss(findingId: 'f-2', object: ['resolutionRationale' => '']));
		self::assertFalse($this->guard->canDismiss(findingId: 'f-3', object: ['resolutionRationale' => '   ']));
		self::assertFalse($this->guard->canDismiss(findingId: 'f-4', object: []));

	}//end testFindingWithoutRationaleCannotDismiss()

	/**
	 * A finding with a rationale may be confirmed/escalated (REQ-CCM-004).
	 *
	 * @return void
	 */
	public function testFindingWithRationaleCanConfirm(): void {
		$object = ['resolutionRationale' => 'Confirmed control deficiency; escalating to CFO.'];
		self::assertTrue($this->guard->canConfirm(findingId: 'f-5', object: $object));

	}//end testFindingWithRationaleCanConfirm()

	/**
	 * A finding without a rationale cannot be confirmed (REQ-CCM-004 fail-closed).
	 *
	 * @return void
	 */
	public function testFindingWithoutRationaleCannotConfirm(): void {
		self::assertFalse($this->guard->canConfirm(findingId: 'f-6', object: ['resolutionRationale' => '']));
		self::assertFalse($this->guard->canConfirm(findingId: 'f-7', object: []));

	}//end testFindingWithoutRationaleCannotConfirm()

	/**
	 * A report with an approver and an executive summary may be approved
	 * (REQ-CCM-006).
	 *
	 * @return void
	 */
	public function testReportWithApproverAndSummaryCanApprove(): void {
		$object = ['approver' => 'chair-user', 'executiveSummary' => 'Q1 controls operated effectively.'];
		self::assertTrue($this->guard->canApproveReport(reportId: 'r-1', object: $object));

	}//end testReportWithApproverAndSummaryCanApprove()

	/**
	 * A report missing an approver or an executive summary cannot be approved
	 * (REQ-CCM-006 fail-closed).
	 *
	 * @return void
	 */
	public function testReportMissingApproverOrSummaryCannotApprove(): void {
		self::assertFalse($this->guard->canApproveReport(reportId: 'r-2', object: ['approver' => '', 'executiveSummary' => 'x']));
		self::assertFalse($this->guard->canApproveReport(reportId: 'r-3', object: ['approver' => 'chair', 'executiveSummary' => '']));
		self::assertFalse($this->guard->canApproveReport(reportId: 'r-4', object: []));

	}//end testReportMissingApproverOrSummaryCannotApprove()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
