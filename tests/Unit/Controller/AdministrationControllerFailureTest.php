<?php

/**
 * Unit tests for AdministrationController's refusal and failure paths.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\AdministrationController;
use OCA\Shillinq\Service\AdministrationArchivalService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TypeError;

/**
 * Malformed identifiers are refused before the context service is asked,
 * and a context or archival service that fails yields a logged 500 whose
 * body does not carry the cause.
 *
 * @covers \OCA\Shillinq\Controller\AdministrationController
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AdministrationControllerFailureTest extends TestCase {

	/**
	 * Request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Context service double.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Archival service double.
	 *
	 * @var AdministrationArchivalService&MockObject
	 */
	private AdministrationArchivalService&MockObject $archival;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Controller under test.
	 *
	 * @var AdministrationController
	 */
	private AdministrationController $controller;

	/**
	 * Set up a logged-in caller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request  = $this->createMock(IRequest::class);
		$this->context  = $this->createMock(AdministrationContextService::class);
		$this->archival = $this->createMock(AdministrationArchivalService::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
		$this->context->method('currentUserId')->willReturn('controller');

		$this->controller = new AdministrationController(
			request: $this->request,
			context: $this->context,
			archival: $this->archival,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Expect exactly one error log with this message and cause.
	 *
	 * @param string $message The log message.
	 * @param string $cause   The exception message.
	 *
	 * @return void
	 */
	private function expectLogged(string $message, string $cause): void {
		$this->logger->expects($this->once())->method('error')->with($message, ['exception' => $cause]);
	}//end expectLogged()

	/**
	 * A context that cannot be built is a logged 500.
	 *
	 * @return void
	 */
	public function testAFailedContextIsALoggedServerError(): void {
		$this->context->method('buildContext')->willThrowException(new RuntimeException('register offline'));
		$this->expectLogged('AdministrationController: failed to build context', 'register offline');

		$response = $this->controller->context();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to resolve administration context'], $response->getData());
	}//end testAFailedContextIsALoggedServerError()

	/**
	 * A malformed switch target is refused without asking the context.
	 *
	 * @return void
	 */
	public function testAMalformedSwitchTargetIsABadRequest(): void {
		$this->request->method('getParam')->with('administrationId', '')->willReturn('adm 1; drop');
		$this->context->expects($this->never())->method('resolveSwitchTarget');

		$response = $this->controller->switch();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'administrationId is required'], $response->getData());
	}//end testAMalformedSwitchTargetIsABadRequest()

	/**
	 * A switch target that cannot be resolved is a logged 500.
	 *
	 * @return void
	 */
	public function testAFailedSwitchIsALoggedServerError(): void {
		$this->request->method('getParam')->willReturn(' adm-1 ');
		$this->context->expects($this->once())->method('resolveSwitchTarget')->with('adm-1')->willThrowException(new TypeError('bad row'));
		$this->expectLogged('AdministrationController: failed to resolve switch target', 'bad row');

		$response = $this->controller->switch();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to switch administration'], $response->getData());
	}//end testAFailedSwitchIsALoggedServerError()

	/**
	 * Malformed ids are refused by the export-scope and writable-status reads.
	 *
	 * @return void
	 */
	public function testMalformedIdsAreRefusedByTheReads(): void {
		$this->context->expects($this->never())->method('canAccess');

		foreach (['exportScope', 'writableStatus'] as $read) {
			foreach (['', '   ', str_repeat('x', 65), '../adm'] as $id) {
				$response = $this->controller->$read($id);

				$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $read . ' ' . $id);
				$this->assertSame(['error' => 'Invalid administration id'], $response->getData());
			}
		}
	}//end testMalformedIdsAreRefusedByTheReads()

	/**
	 * An access check that fails is a logged 500 on the export scope.
	 *
	 * @return void
	 */
	public function testAFailedExportAccessCheckIsALoggedServerError(): void {
		$this->context->method('canAccess')->willThrowException(new RuntimeException('groups unavailable'));
		$this->expectLogged('AdministrationController: failed to check export access', 'groups unavailable');

		$response = $this->controller->exportScope('adm-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to resolve export scope'], $response->getData());
	}//end testAFailedExportAccessCheckIsALoggedServerError()

	/**
	 * An access check that fails is a logged 500 on the writable status, and the archival is never asked.
	 *
	 * @return void
	 */
	public function testAFailedWritableAccessCheckIsALoggedServerError(): void {
		$this->context->method('canAccess')->willThrowException(new RuntimeException('groups unavailable'));
		$this->archival->expects($this->never())->method('assertWritableById');
		$this->expectLogged('AdministrationController: failed to check writable status access', 'groups unavailable');

		$response = $this->controller->writableStatus('adm-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to resolve writable status'], $response->getData());
	}//end testAFailedWritableAccessCheckIsALoggedServerError()

	/**
	 * An archival check that fails other than by refusing is a logged 500, not "read-only".
	 *
	 * @return void
	 */
	public function testAFailedArchivalCheckIsNotReportedAsReadOnly(): void {
		$this->context->method('canAccess')->with('adm-1')->willReturn(true);
		$this->archival->method('assertWritableById')->willThrowException(new TypeError('status missing'));
		$this->expectLogged('AdministrationController: failed to resolve writable status', 'status missing');

		$response = $this->controller->writableStatus('adm-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to resolve writable status'], $response->getData());
	}//end testAFailedArchivalCheckIsNotReportedAsReadOnly()
}//end class
