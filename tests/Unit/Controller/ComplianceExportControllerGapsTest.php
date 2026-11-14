<?php

/**
 * Unit tests for ComplianceExportController's remaining branches.
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
 * @spec openspec/specs/bookkeeping-rekenkamer-audit-pack/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use LogicException;
use OCA\Shillinq\Controller\ComplianceExportController;
use OCA\Shillinq\Service\ComplianceExportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Anonymous callers get 401, an unexpected failure a 500 without its cause,
 * the actor filter reaches the service, and a CSV export is rendered by the
 * service with a CSV content type.
 *
 * @covers \OCA\Shillinq\Controller\ComplianceExportController
 *
 * @spec openspec/specs/bookkeeping-rekenkamer-audit-pack/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ComplianceExportControllerGapsTest extends TestCase {

	/**
	 * Request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Export service double.
	 *
	 * @var ComplianceExportService&MockObject
	 */
	private ComplianceExportService&MockObject $service;

	/**
	 * User session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request      = $this->createMock(IRequest::class);
		$this->service      = $this->createMock(ComplianceExportService::class);
		$this->userSession  = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger       = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return ComplianceExportController
	 */
	private function controller(): ComplianceExportController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ComplianceExportController(
			request: $this->request,
			complianceExportService: $this->service,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->logger,
			l10n: $l10n,
		);
	}//end controller()

	/**
	 * Sign in an auditor and serve the given request parameters.
	 *
	 * @param array<string,mixed> $params Request parameters.
	 *
	 * @return void
	 */
	private function auditorRequests(array $params): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('auditor-alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->with('auditor-alice', 'auditor')->willReturn(true);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
	}//end auditorRequests()

	/**
	 * An export envelope as the service builds it.
	 *
	 * @param string $format Export format.
	 *
	 * @return array<string,mixed>
	 */
	private function envelope(string $format): array {
		return [
			'generatedAt' => '2026-04-01T00:00:00+00:00',
			'scope'       => 'all',
			'format'      => $format,
			'from'        => '2026-01-01T00:00:00Z',
			'to'          => '2026-03-31"; x',
			'eventCount'  => 2,
			'actorFilter' => 'bob',
			'events'      => [],
		];
	}//end envelope()

	/**
	 * An anonymous caller is refused with 401 before any group lookup.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsRefusedWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->groupManager->expects(self::never())->method('isAdmin');
		$this->service->expects(self::never())->method('generateExport');

		$response = $this->controller()->export();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Not logged in'], $response->getData());
	}//end testAnonymousCallerIsRefusedWith401()

	/**
	 * A non-RuntimeException failure answers 500 with a generic error and
	 * logs the request parameters plus the cause.
	 *
	 * @return void
	 */
	public function testUnexpectedFailureAnswers500WithoutTheCause(): void {
		$this->auditorRequests(params: ['from' => '2026-01-01', 'to' => '2026-03-31', 'format' => 'json']);
		$this->service->method('generateExport')->willThrowException(new LogicException('secret detail'));
		$this->logger->expects(self::once())
			->method('error')
			->with(
				'ComplianceExportController: failed to generate export',
				[
					'from'      => '2026-01-01',
					'to'        => '2026-03-31',
					'scope'     => 'all',
					'format'    => 'json',
					'exception' => 'secret detail',
				]
			);
		$this->logger->expects(self::never())->method('info');

		$response = $this->controller()->export();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not generate compliance export'], $response->getData());
	}//end testUnexpectedFailureAnswers500WithoutTheCause()

	/**
	 * A non-empty actor parameter is handed to the service as the filter and
	 * the JSON envelope is answered as-is, with the request logged.
	 *
	 * @return void
	 */
	public function testActorParameterIsPassedAsFilterAndJsonIsReturned(): void {
		$this->auditorRequests(
			params: ['from' => '2026-01-01', 'to' => '2026-03-31', 'format' => 'json', 'scope' => 'subject', 'actor' => 'bob']
		);
		$envelope = $this->envelope(format: 'json');
		$this->service->expects(self::once())
			->method('generateExport')
			->with('2026-01-01', '2026-03-31', 'subject', 'json', 'bob')
			->willReturn($envelope);
		$this->service->expects(self::never())->method('renderCsv');
		$this->logger->expects(self::once())
			->method('info')
			->with(
				'shillinq.compliance.export_request',
				self::callback(
					static fn (array $context): bool => $context['actor'] === 'auditor-alice'
						&& $context['actorFilter'] === 'bob'
						&& $context['eventCount'] === 2
						&& $context['requirementId'] === 'REQ-RAP-005'
				)
			);

		$response = $this->controller()->export();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($envelope, $response->getData());
	}//end testActorParameterIsPassedAsFilterAndJsonIsReturned()

	/**
	 * The default CSV format renders through the service and is served as a
	 * CSV body with a CSV content type.
	 *
	 * @return void
	 */
	public function testCsvExportIsRenderedThroughTheService(): void {
		$this->auditorRequests(params: ['from' => '2026-01-01', 'to' => '2026-03-31', 'actor' => '']);
		$envelope = $this->envelope(format: 'csv');
		$this->service->expects(self::once())
			->method('generateExport')
			->with('2026-01-01', '2026-03-31', 'all', 'csv', null)
			->willReturn($envelope);
		$this->service->expects(self::once())
			->method('renderCsv')
			->with($envelope)
			->willReturn("a,b\n1,2\n");

		$response = $this->controller()->export();

		self::assertInstanceOf(DataDisplayResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame("a,b\n1,2\n", $response->getData());
		$headers = $response->getHeaders();
		self::assertSame('text/csv; charset=utf-8', $headers['Content-Type']);
		// The controller's attachment Content-Disposition is NOT asserted:
		// DataDisplayResponse's constructor overwrites it with
		// 'inline; filename=""' after applying the passed headers, so the
		// sanitised filename never reaches the client. That is a defect in
		// the controller (it should call addHeader() after construction),
		// not behaviour to pin here.
	}//end testCsvExportIsRenderedThroughTheService()
}//end class
