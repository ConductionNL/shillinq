<?php

/**
 * Unit tests for CsrdEsrsGuard.
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
 * @spec openspec/changes/bookkeeping-csrd-esrs/specs/bookkeeping-csrd-esrs/index.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\CsrdEsrsGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CsrdEsrsGuard lifecycle preconditions.
 *
 * Covers REQ-CSR-001 (materiality submit/approve), REQ-CSR-002 (data-point
 * source + restatement controls) and REQ-CSR-004 (opinion gated on findings).
 * All guards fail closed; inline-object cases never touch the container.
 */
class CsrdEsrsGuardTest extends TestCase {

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
	 * @var CsrdEsrsGuard
	 */
	private CsrdEsrsGuard $guard;

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

		$this->guard = new CsrdEsrsGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * A materiality assessment with a complete consultation may be submitted
	 * (REQ-CSR-001).
	 *
	 * @return void
	 */
	public function testMaterialityWithConsultationCanSubmit(): void {
		$object = [
			'stakeholderGroupsConsulted' => [
				['group' => 'employees', 'consultationMethod' => 'survey', 'date' => '2025-09-15'],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canSubmitMateriality(assessmentId: 'ma-1', object: $object));

	}//end testMaterialityWithConsultationCanSubmit()

	/**
	 * A materiality assessment with no consultation cannot be submitted
	 * (REQ-CSR-001 fail-closed).
	 *
	 * @return void
	 */
	public function testMaterialityWithoutConsultationCannotSubmit(): void {
		$object = ['stakeholderGroupsConsulted' => []];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmitMateriality(assessmentId: 'ma-2', object: $object));

	}//end testMaterialityWithoutConsultationCannotSubmit()

	/**
	 * A consultation row missing its date denies submission (REQ-CSR-001).
	 *
	 * @return void
	 */
	public function testMaterialityConsultationMissingDateCannotSubmit(): void {
		$object = [
			'stakeholderGroupsConsulted' => [
				['group' => 'employees', 'consultationMethod' => 'survey', 'date' => ''],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmitMateriality(assessmentId: 'ma-3', object: $object));

	}//end testMaterialityConsultationMissingDateCannotSubmit()

	/**
	 * Approval requires an approver and a rationale on every non-material topic
	 * (REQ-CSR-001).
	 *
	 * @return void
	 */
	public function testMaterialityApprovalRequiresApproverAndRationale(): void {
		$object = [
			'approver' => 'cfo-user',
			'doubleMaterialityMatrixSnapshot' => [
				'E1' => ['material' => true],
				'S4' => ['material' => false, 'rationale' => 'No end-consumer health/safety events.'],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canApproveMateriality(assessmentId: 'ma-4', object: $object));

	}//end testMaterialityApprovalRequiresApproverAndRationale()

	/**
	 * A non-material topic without a rationale blocks approval (REQ-CSR-001).
	 *
	 * @return void
	 */
	public function testNonMaterialTopicWithoutRationaleBlocksApproval(): void {
		$object = [
			'approver' => 'cfo-user',
			'doubleMaterialityMatrixSnapshot' => [
				'S4' => ['material' => false, 'rationale' => ''],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApproveMateriality(assessmentId: 'ma-5', object: $object));

	}//end testNonMaterialTopicWithoutRationaleBlocksApproval()

	/**
	 * Approval without an approver is denied (REQ-CSR-001).
	 *
	 * @return void
	 */
	public function testMaterialityApprovalWithoutApproverDenied(): void {
		$object = [
			'approver' => '',
			'doubleMaterialityMatrixSnapshot' => ['E1' => ['material' => true]],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApproveMateriality(assessmentId: 'ma-6', object: $object));

	}//end testMaterialityApprovalWithoutApproverDenied()

	/**
	 * A data point with a source reference may be approved (REQ-CSR-002).
	 *
	 * @return void
	 */
	public function testDataPointWithSourceCanApprove(): void {
		$object = ['sourceReference' => 'docudesk:invoice-123'];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canApproveDataPoint(dataPointId: 'dp-1', object: $object));

	}//end testDataPointWithSourceCanApprove()

	/**
	 * A data point without a source reference cannot be approved (REQ-CSR-002).
	 *
	 * @return void
	 */
	public function testDataPointWithoutSourceCannotApprove(): void {
		$object = ['sourceReference' => '   '];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApproveDataPoint(dataPointId: 'dp-2', object: $object));

	}//end testDataPointWithoutSourceCannotApprove()

	/**
	 * A restatement needs both restatedFrom and a rationale (REQ-CSR-002 / D8).
	 *
	 * @return void
	 */
	public function testRestatementRequiresFromAndRationale(): void {
		$ok = ['restatedFrom' => 'dp-prior', 'restatementRationale' => 'Q4 fleet decommissioning.'];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canRestateDataPoint(dataPointId: 'dp-3', object: $ok));

		$missingRationale = ['restatedFrom' => 'dp-prior', 'restatementRationale' => ''];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canRestateDataPoint(dataPointId: 'dp-4', object: $missingRationale));

	}//end testRestatementRequiresFromAndRationale()

	/**
	 * An opinion may issue only when every finding is resolved/accepted
	 * (REQ-CSR-004).
	 *
	 * @return void
	 */
	public function testOpinionIssuesOnlyWhenFindingsClosed(): void {
		$closed = [
			'findings' => [
				['esrsArea' => 'E1', 'severity' => 'medium', 'description' => 'x', 'status' => 'resolved'],
				['esrsArea' => 'E1', 'severity' => 'low', 'description' => 'y', 'status' => 'accepted-risk'],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canIssueOpinion(engagementId: 'ae-1', object: $closed));

		$open = [
			'findings' => [
				['esrsArea' => 'E1', 'severity' => 'high', 'description' => 'z', 'status' => 'open'],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canIssueOpinion(engagementId: 'ae-2', object: $open));

	}//end testOpinionIssuesOnlyWhenFindingsClosed()

	/**
	 * An engagement with no findings may issue its opinion (REQ-CSR-004).
	 *
	 * @return void
	 */
	public function testOpinionIssuesWithNoFindings(): void {
		$object = ['findings' => []];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canIssueOpinion(engagementId: 'ae-3', object: $object));

	}//end testOpinionIssuesWithNoFindings()
}//end class
