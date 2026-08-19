<?php

/**
 * Unit tests for CashflowExportController — the route half of REQ-CF-016
 * (#865).
 *
 * The double for the export service is HAND-WRITTEN (see
 * {@see RecordingCashflowExportService} at the bottom of this file) rather
 * than a `createMock()`. A PHPUnit mock cannot observe a named argument — it
 * resolves a call against its own signature and then invokes the return
 * callback positionally — so an argument expectation over this app's
 * named-argument call style measures the double, not the controller. The fake
 * records what it was actually asked for and the tests assert on the bytes
 * that came back.
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
 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\CashflowExportController;
use OCA\Shillinq\Service\CashflowExportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Covers the cashflow PDF export endpoint.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CashflowExportControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Hand-written export-service fake.
	 *
	 * @var RecordingCashflowExportService
	 */
	private RecordingCashflowExportService $exportService;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Set up shared fixtures — authenticated by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->exportService = new RecordingCashflowExportService();
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

	}//end setUp()

	/**
	 * Build the controller over the current doubles.
	 *
	 * @return CashflowExportController
	 */
	private function controller(): CashflowExportController {
		return new CashflowExportController(
			$this->request,
			$this->exportService,
			$this->userSession,
			new NullLogger(),
		);
	}//end controller()

	/**
	 * The happy path streams the renderer's bytes as a PDF download with the
	 * renderer's own filename.
	 *
	 * @return void
	 */
	public function testExportPdfStreamsThePdfAsADownload(): void {
		$this->exportService->export = [
			'filename' => 'cashflow-hz-2026-w22-2026-08-17.pdf',
			'mimeType' => 'application/pdf',
			'payload' => "%PDF-1.7\nfake-body\n%%EOF",
		];

		$response = $this->controller()->exportPdf();

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame("%PDF-1.7\nfake-body\n%%EOF", $response->render());
		self::assertSame(1, $this->exportService->calls);
		self::assertStringContainsString(
			'cashflow-hz-2026-w22-2026-08-17.pdf',
			(string)$response->getHeaders()['Content-Disposition']
		);
		self::assertStringContainsString(
			'application/pdf',
			(string)$response->getHeaders()['Content-Type']
		);

	}//end testExportPdfStreamsThePdfAsADownload()

	/**
	 * An anonymous caller is refused before the service is consulted.
	 *
	 * @return void
	 */
	public function testExportPdfRequiresAuthentication(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->userSession = $session;

		$response = $this->controller()->exportPdf();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(0, $this->exportService->calls, 'the export ran for an anonymous caller');

	}//end testExportPdfRequiresAuthentication()

	/**
	 * No forecast to export answers 404 with a stable code — never an empty
	 * 200 PDF, which a bank would read as "this business has no cashflow".
	 *
	 * @return void
	 */
	public function testExportPdfAnswers404WhenThereIsNoHorizon(): void {
		$this->exportService->export = null;

		$response = $this->controller()->exportPdf();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'no_cashflow_horizon'], $response->getData());

	}//end testExportPdfAnswers404WhenThereIsNoHorizon()

	/**
	 * A failure inside the export answers 500 with a stable code and leaks no
	 * exception text to the caller.
	 *
	 * @return void
	 */
	public function testExportPdfAnswers500WithoutLeakingTheException(): void {
		$this->exportService->throw = new RuntimeException('SQLSTATE[42P01] relation "oc_openregister_objects" does not exist');

		$response = $this->controller()->exportPdf();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'export_failed'], $response->getData());
		self::assertStringNotContainsString('SQLSTATE', json_encode($response->getData()));

	}//end testExportPdfAnswers500WithoutLeakingTheException()

}//end class

/**
 * Hand-written CashflowExportService double.
 *
 * Deliberately overrides the constructor without calling the parent's: the
 * parent's promoted dependencies are never touched because every method that
 * would read them is overridden here.
 *
 * phpcs:disable
 */
final class RecordingCashflowExportService extends CashflowExportService {

	/**
	 * What buildHorizonExport() answers.
	 *
	 * @var array{filename:string,mimeType:string,payload:string}|null
	 */
	public ?array $export = null;

	/**
	 * When set, buildHorizonExport() throws it.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $throw = null;

	/**
	 * How many times buildHorizonExport() was called.
	 *
	 * @var integer
	 */
	public int $calls = 0;

	/**
	 * Build the double.
	 */
	public function __construct() {
		// Intentionally does NOT call parent::__construct(): this fake answers
		// buildHorizonExport() itself and never reaches a dependency.
	}//end __construct()

	/**
	 * Record the call and answer the configured result.
	 *
	 * @return array{filename:string,mimeType:string,payload:string}|null
	 */
	public function buildHorizonExport(): ?array {
		$this->calls++;
		if ($this->throw !== null) {
			throw $this->throw;
		}

		return $this->export;
	}//end buildHorizonExport()
}//end class
