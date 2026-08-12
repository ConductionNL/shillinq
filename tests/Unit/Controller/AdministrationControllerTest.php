<?php

/**
 * Unit tests for AdministrationController.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-11
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

/**
 * Tests the context / switcher / export-scope API including masked-404 IDOR guards.
 *
 * Covers REQ-MA-001 (masked 404 on non-membership), REQ-MA-003 (context + switch),
 * REQ-MA-007 (export scope access guard).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AdministrationControllerTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock context service.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Mock archival service.
	 *
	 * @var AdministrationArchivalService&MockObject
	 */
	private AdministrationArchivalService&MockObject $archival;

	/**
	 * Controller under test.
	 *
	 * @var AdministrationController
	 */
	private AdministrationController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->archival = $this->createMock(AdministrationArchivalService::class);
		$this->controller = new AdministrationController(
			request: $this->request,
			context: $this->context,
			archival: $this->archival,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * The context endpoint returns 401 for an anonymous user.
	 *
	 * @return void
	 */
	public function testContextRequiresAuthentication(): void {
		$this->context->method('currentUserId')->willReturn(null);
		$response = $this->controller->context();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testContextRequiresAuthentication()

	/**
	 * The context endpoint returns the resolved administration context (REQ-MA-003).
	 *
	 * @return void
	 */
	public function testContextReturnsAdministrations(): void {
		$this->context->method('currentUserId')->willReturn('controller');
		$this->context->method('buildContext')->willReturn(
			[
				'userId' => 'controller',
				'administrations' => [['administrationId' => 'adm-werk-001', 'role' => 'controller']],
				'activeAdministrationId' => 'adm-werk-001',
			]
		);

		$response = $this->controller->context();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('adm-werk-001', $data['activeAdministrationId']);

	}//end testContextReturnsAdministrations()

	/**
	 * Switching to an accessible administration succeeds (REQ-MA-003).
	 *
	 * @return void
	 */
	public function testSwitchToAccessibleAdministration(): void {
		$this->context->method('currentUserId')->willReturn('controller');
		$this->request->method('getParam')->willReturn('adm-werk-001');
		$this->context->method('resolveSwitchTarget')->with('adm-werk-001')->willReturn('adm-werk-001');

		$response = $this->controller->switch();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('adm-werk-001', $response->getData()['activeAdministrationId']);

	}//end testSwitchToAccessibleAdministration()

	/**
	 * Switching to an administration the user has no membership for is masked as 404 (REQ-MA-001).
	 *
	 * @return void
	 */
	public function testSwitchToForbiddenAdministrationMasked404(): void {
		$this->context->method('currentUserId')->willReturn('controller');
		$this->request->method('getParam')->willReturn('adm-secret-999');
		$this->context->method('resolveSwitchTarget')->willReturn(null);

		$response = $this->controller->switch();
		// Masked as 404, NOT 403 — existence of other tenants is not disclosed.
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testSwitchToForbiddenAdministrationMasked404()

	/**
	 * A blank administrationId is rejected with 400.
	 *
	 * @return void
	 */
	public function testSwitchRejectsBlankId(): void {
		$this->context->method('currentUserId')->willReturn('controller');
		$this->request->method('getParam')->willReturn('');

		$response = $this->controller->switch();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testSwitchRejectsBlankId()

	/**
	 * Export scope is returned only for an accessible administration (REQ-MA-007).
	 *
	 * @return void
	 */
	public function testExportScopeForAccessibleAdministration(): void {
		$this->context->method('currentUserId')->willReturn('controller');
		$this->context->method('canAccess')->with('adm-werk-001')->willReturn(true);

		$response = $this->controller->exportScope('adm-werk-001');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('adm-werk-001', $data['administrationId']);
		self::assertSame('xaf-3.2', $data['format']);

	}//end testExportScopeForAccessibleAdministration()

	/**
	 * Export scope for an inaccessible administration is masked as 404 (REQ-MA-001).
	 *
	 * @return void
	 */
	public function testExportScopeForbiddenMasked404(): void {
		$this->context->method('currentUserId')->willReturn('controller');
		$this->context->method('canAccess')->willReturn(false);

		$response = $this->controller->exportScope('adm-secret-999');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testExportScopeForbiddenMasked404()

	/**
	 * writableStatus returns 200 + writable=true when the administration is active (REQ-MA-007).
	 *
	 * @return void
	 */
	public function testWritableStatusActiveAdministration(): void {
		$this->context->method('currentUserId')->willReturn('controller');
		$this->context->method('canAccess')->with('adm-werk-001')->willReturn(true);
		$this->archival->expects(self::once())
			->method('assertWritableById')
			->with('adm-werk-001');

		$response = $this->controller->writableStatus('adm-werk-001');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['writable']);

	}//end testWritableStatusActiveAdministration()

	/**
	 * writableStatus returns 200 + writable=false when the administration is archived.
	 *
	 * @return void
	 */
	public function testWritableStatusArchivedAdministration(): void {
		$this->context->method('currentUserId')->willReturn('controller');
		$this->context->method('canAccess')->with('adm-werk-001')->willReturn(true);
		$this->archival->expects(self::once())
			->method('assertWritableById')
			->with('adm-werk-001')
			->willThrowException(new RuntimeException('administratie gearchiveerd (status=gearchiveerd)'));

		$response = $this->controller->writableStatus('adm-werk-001');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['writable']);
		self::assertStringContainsString('gearchiveerd', $data['message']);

	}//end testWritableStatusArchivedAdministration()

	/**
	 * writableStatus masks a non-membership as 404 (REQ-MA-001).
	 *
	 * @return void
	 */
	public function testWritableStatusForbiddenMasked404(): void {
		$this->context->method('currentUserId')->willReturn('controller');
		$this->context->method('canAccess')->willReturn(false);

		$response = $this->controller->writableStatus('adm-secret-999');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testWritableStatusForbiddenMasked404()

	/**
	 * writableStatus rejects malformed ids with a 400.
	 *
	 * @return void
	 */
	public function testWritableStatusRejectsBadId(): void {
		$this->context->method('currentUserId')->willReturn('controller');

		$response = $this->controller->writableStatus('not a valid id!');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testWritableStatusRejectsBadId()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
