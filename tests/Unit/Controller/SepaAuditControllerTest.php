<?php

/**
 * Unit tests for SepaAuditController.
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
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\SepaAuditController;
use OCA\Shillinq\Service\SepaAuditService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the per-mandate audit-dossier export (REQ-SDD-010).
 *
 * Asserts the ZIP download shape (body, filename, content type), the 401
 * body-guard, the 404 that masks a mandate outside the caller's accessible
 * administrations rather than confirming its existence, and the 500 path
 * that leaks no stack trace.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SepaAuditControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock SepaAuditService.
	 *
	 * @var SepaAuditService&MockObject
	 */
	private SepaAuditService&MockObject $auditService;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up shared fixtures — authenticated by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->auditService = $this->createMock(SepaAuditService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

	}//end setUp()

	/**
	 * Build the controller over the current mocks.
	 *
	 * @return SepaAuditController
	 */
	private function controller(): SepaAuditController {
		return new SepaAuditController(
			$this->request,
			$this->auditService,
			$this->userSession,
			$this->logger,
		);

	}//end controller()

	/**
	 * exportMandate() streams the assembled dossier as a ZIP download.
	 *
	 * @return void
	 */
	public function testExportMandateReturnsZipDownload(): void {
		$this->auditService->expects($this->once())
			->method('buildMandateDossier')
			->willReturnCallback(
				static function (string $mandateId): array {
					self::assertSame('mnd-1', $mandateId);
					return ['data' => 'PK-zip-bytes', 'filename' => 'sepa-mandate-mnd-1.zip'];
				}
			);

		$response = $this->controller()->exportMandate('mnd-1');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('PK-zip-bytes', $response->render());
		self::assertStringContainsString(
			'sepa-mandate-mnd-1.zip',
			(string)$response->getHeaders()['Content-Disposition']
		);

	}//end testExportMandateReturnsZipDownload()

	/**
	 * A mandate the caller may not read answers HTTP 404 with a stable error
	 * code rather than leaking its existence (ADR-005 / REQ-MA-001).
	 *
	 * @return void
	 */
	public function testExportMandateInaccessibleReturns404(): void {
		$this->auditService->method('buildMandateDossier')->willReturn(null);

		$response = $this->controller()->exportMandate('mnd-other-tenant');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('mandate_not_found', $response->getData()['error']);

	}//end testExportMandateInaccessibleReturns404()

	/**
	 * exportMandate() rejects an anonymous caller with HTTP 401 and never
	 * assembles a dossier.
	 *
	 * @return void
	 */
	public function testExportMandateAnonymousReturns401(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->auditService->expects($this->never())->method('buildMandateDossier');

		$response = $this->controller()->exportMandate('mnd-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testExportMandateAnonymousReturns401()

	/**
	 * An assembly failure yields HTTP 500 with a stable error code and no
	 * stack trace.
	 *
	 * @return void
	 */
	public function testExportMandateFailureReturns500WithoutStackTrace(): void {
		$this->auditService->method('buildMandateDossier')
			->willThrowException(new \RuntimeException('/srv/data/appdata/sepa/pain008.xml unreadable'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller()->exportMandate('mnd-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('export_failed', $response->getData()['error']);
		self::assertStringNotContainsStringIgnoringCase(
			'appdata',
			(string)json_encode($response->getData())
		);

	}//end testExportMandateFailureReturns500WithoutStackTrace()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
