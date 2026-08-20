<?php

/**
 * Unit tests for InnovatieboxController.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\InnovatieboxController;
use OCA\Shillinq\Service\DoorsnijdingsVerbodValidator;
use OCA\Shillinq\Service\InnovatieboxAggregationService;
use OCA\Shillinq\Service\InnovatieboxSbrExportService;
use OCA\Shillinq\Service\NexusCalculationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the read-only innovatiebox-administratie API controller.
 *
 * Covers REQ-IBA-006 (aggregation contract), REQ-IBA-009 (scenario validation),
 * REQ-IBA-004 (doorsnijdingsverbod) and the 500 fail path without a stack trace.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InnovatieboxControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock aggregation service.
	 *
	 * @var InnovatieboxAggregationService&MockObject
	 */
	private InnovatieboxAggregationService&MockObject $aggregation;

	/**
	 * Real nexus service (pure logic — no need to mock).
	 *
	 * @var NexusCalculationService
	 */
	private NexusCalculationService $nexus;

	/**
	 * Mock doorsnijdingsverbod validator.
	 *
	 * @var DoorsnijdingsVerbodValidator&MockObject
	 */
	private DoorsnijdingsVerbodValidator&MockObject $doorsnijden;

	/**
	 * Real SBR/PDF export service (pure logic — no need to mock).
	 *
	 * @var InnovatieboxSbrExportService
	 */
	private InnovatieboxSbrExportService $sbrExport;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The controller under test.
	 *
	 * @var InnovatieboxController
	 */
	private InnovatieboxController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->aggregation = $this->createMock(InnovatieboxAggregationService::class);
		$this->nexus = new NexusCalculationService();
		$this->doorsnijden = $this->createMock(DoorsnijdingsVerbodValidator::class);
		$this->sbrExport = new InnovatieboxSbrExportService();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new InnovatieboxController(
			request: $this->request,
			aggregation: $this->aggregation,
			nexus: $this->nexus,
			doorsnijden: $this->doorsnijden,
			sbrExport: $this->sbrExport,
			userSession: $this->userSession,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Map request params from an associative array.
	 *
	 * @param array<string,mixed> $params The param map.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);

	}//end withParams()

	/**
	 * A missing administration_id yields HTTP 400 (REQ-IBA-008).
	 *
	 * @return void
	 */
	public function testAggregationMissingAdministrationReturns400(): void {
		$this->withParams(['financialYear' => '2024']);
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->aggregation()->getStatus());

	}//end testAggregationMissingAdministrationReturns400()

	/**
	 * A non-4-digit boekjaar yields HTTP 400.
	 *
	 * @return void
	 */
	public function testAggregationBadYearReturns400(): void {
		$this->withParams(['administration_id' => 'adm-1', 'financialYear' => '24']);
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->aggregation()->getStatus());

	}//end testAggregationBadYearReturns400()

	/**
	 * A valid aggregation request returns HTTP 200 with the service result (REQ-IBA-006).
	 *
	 * @return void
	 */
	public function testAggregationValidReturns200(): void {
		$this->withParams(['administration_id' => 'adm-1', 'financialYear' => '2024']);
		$payload = ['data' => [], 'total' => 0, 'totals' => ['vpb_regel_23' => 0.0, 'benefit_innovation_box' => 0.0]];
		$this->aggregation->expects($this->once())
			->method('aggregate')
			->with('adm-1', 2024)
			->willReturn($payload);

		$response = $this->controller->aggregation();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testAggregationValidReturns200()

	/**
	 * An aggregation service exception yields HTTP 500 without a stack trace (ADR-005).
	 *
	 * @return void
	 */
	public function testAggregationServiceFailureReturns500(): void {
		$this->withParams(['administration_id' => 'adm-1', 'financialYear' => '2024']);
		$this->aggregation->method('aggregate')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->aggregation();
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Failed to compute innovatiebox aggregation'], $response->getData());

	}//end testAggregationServiceFailureReturns500()

	/**
	 * The scenario endpoint validates non-negative numeric inputs (REQ-IBA-009).
	 *
	 * @return void
	 */
	public function testScenarioRejectsNonNumeric(): void {
		$this->withParams(
			[
				'own_rd_cost' => 'abc',
				'uitbesteed_derden' => '0',
				'uitbesteed_verbonden' => '0',
			]
		);
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->scenario()->getStatus());

	}//end testScenarioRejectsNonNumeric()

	/**
	 * The scenario endpoint computes the nexusbreuk without persisting (REQ-IBA-009).
	 *
	 * @return void
	 */
	public function testScenarioReturnsNexusBreak(): void {
		$this->withParams(
			[
				'own_rd_cost' => '500000',
				'uitbesteed_derden' => '0',
				'uitbesteed_verbonden' => '300000',
			]
		);
		$response = $this->controller->scenario();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame(0.8125, $data['nexusFractionApplied']);

	}//end testScenarioReturnsNexusBreak()

	/**
	 * The doorsnijdingsverbod endpoint returns the validator result (REQ-IBA-004).
	 *
	 * @return void
	 */
	public function testDoorsnijdingsverbodReturnsFindings(): void {
		$this->withParams(['administration_id' => 'adm-1', 'financialYear' => '2024']);
		$payload = ['findings' => [], 'blocking' => false, 'total' => 0];
		$this->doorsnijden->expects($this->once())
			->method('validateNoDuplication')
			->with('adm-1', 2024)
			->willReturn($payload);

		$response = $this->controller->doorsnijdingsverbod();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testDoorsnijdingsverbodReturnsFindings()
}//end class
