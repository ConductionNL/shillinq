<?php

/**
 * Wire-contract tests for GET /api/leases/disclosure (lease#disclosure).
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
 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\LeaseController;
use OCA\Shillinq\Service\LeaseDisclosureService;
use OCA\Shillinq\Service\LeasePaymentScheduleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The wire contract of the disclosure endpoint (gate-25 contract-coverage).
 *
 * REQ-LD-004 makes the materialised disclosure table exportable to PDF, CSV
 * and XBRL, and the format is chosen by a query parameter rather than by a
 * separate route, so the endpoint's REPRESENTATION NEGOTIATION is part of its
 * contract, not an implementation detail. What a caller can rely on:
 *
 *  - unauthenticated  -> 401, and the service is never reached;
 *  - blank/malformed administration_id or fiscal_period -> 400;
 *  - an unknown `format` -> 400, NOT a silent fall-through to the JSON
 *    default (a typo'd `?format=cvs` must not hand the operator a different
 *    representation than the one they asked for);
 *  - an unknown `language` -> 400;
 *  - `csv` -> a real file download, not a JSON envelope;
 *  - `pdf` / `xbrl` -> a JSON envelope carrying an explicit pending status,
 *    because the docudesk PDF pipeline and the ESEF iXBRL wrapper are
 *    separate changes and an unsigned HTML preview must not be dressed up as
 *    a signed disclosure note;
 *  - a service failure -> 500 with no internals in the body.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LeaseDisclosureContractTest extends TestCase {
	/**
	 * A representative materialised disclosure table.
	 *
	 * @var array<string,mixed>
	 */
	private const TABLE = [
		'fiscalPeriod' => '2026-Q1',
		'rightOfUseAssets' => 125000.0,
		'leaseLiabilities' => 118500.0,
		'maturityAnalysis' => [],
	];

	/**
	 * Build the controller with request params, auth state and a service double.
	 *
	 * @param array<string,mixed> $params Request parameters.
	 * @param bool $loggedIn Whether a session user exists.
	 * @param LeaseDisclosureService|null $service Optional service double.
	 *
	 * @return LeaseController The controller under test.
	 */
	private function buildController(
		array $params,
		bool $loggedIn = true,
		?LeaseDisclosureService $service = null,
	): LeaseController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(
			$loggedIn === true ? $this->createMock(IUser::class) : null
		);

		return new LeaseController(
			request: $request,
			scheduleService: $this->createMock(LeasePaymentScheduleService::class),
			disclosureService: ($service ?? $this->buildDisclosureService()),
			userSession: $userSession,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end buildController()

	/**
	 * A disclosure service that materialises TABLE and renders each format.
	 *
	 * @return LeaseDisclosureService The service double.
	 */
	private function buildDisclosureService(): LeaseDisclosureService {
		$service = $this->createMock(LeaseDisclosureService::class);
		$service->method('generateForPeriod')->willReturn(self::TABLE);
		$service->method('exportToCSV')->willReturn("fiscalPeriod,rightOfUseAssets\n2026-Q1,125000\n");
		$service->method('exportDisclosureNoteToPDF')->willReturn(
			['status' => 'pending-pdf-pipeline', 'html' => '<h1>Lease disclosure</h1>']
		);
		$service->method('exportToXBRL')->willReturn(
			['status' => 'pending-sbr-xbrl-reporting', 'facts' => []]
		);

		return $service;
	}//end buildDisclosureService()

	/**
	 * Valid query parameters for the happy path.
	 *
	 * @param array<string,mixed> $overrides Values to replace.
	 *
	 * @return array<string,mixed> The parameter set.
	 */
	private function params(array $overrides = []): array {
		return array_merge(
			['administration_id' => 'adm-1', 'fiscal_period' => '2026-Q1'],
			$overrides
		);
	}//end params()

	/**
	 * Anonymous callers get 401, and the service is never consulted.
	 *
	 * @return void
	 */
	public function testUnauthenticatedCallerGets401AndNeverReachesTheService(): void {
		$service = $this->createMock(LeaseDisclosureService::class);
		$service->expects($this->never())->method('generateForPeriod');

		$response = $this->buildController($this->params(), false, $service)->disclosure();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testUnauthenticatedCallerGets401AndNeverReachesTheService()

	/**
	 * A blank or malformed scope identifier is rejected with 400.
	 *
	 * @return void
	 */
	public function testMissingOrMalformedIdentifiersAreRejected(): void {
		foreach (
			[
				['administration_id' => ''],
				['fiscal_period' => ''],
				['administration_id' => '../etc/passwd'],
			] as $bad
		) {
			$response = $this->buildController($this->params($bad))->disclosure();

			self::assertInstanceOf(JSONResponse::class, $response);
			self::assertSame(
				Http::STATUS_BAD_REQUEST,
				$response->getStatus(),
				'Expected 400 for ' . json_encode($bad)
			);
		}

	}//end testMissingOrMalformedIdentifiersAreRejected()

	/**
	 * An unknown format 400s rather than silently serving JSON.
	 *
	 * This is the assertion that matters most: a fall-through would return
	 * 200 with the JSON table for `?format=cvs`, so the caller would believe
	 * they had asked for CSV and received it.
	 *
	 * @return void
	 */
	public function testUnknownFormatIsRejectedRatherThanFallingBackToJson(): void {
		$response = $this->buildController($this->params(['format' => 'cvs']))->disclosure();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('format must be one of', (string)($response->getData()['error'] ?? ''));

	}//end testUnknownFormatIsRejectedRatherThanFallingBackToJson()

	/**
	 * An unknown language 400s.
	 *
	 * @return void
	 */
	public function testUnknownLanguageIsRejected(): void {
		$response = $this->buildController($this->params(['language' => 'fr']))->disclosure();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('language must be one of', (string)($response->getData()['error'] ?? ''));

	}//end testUnknownLanguageIsRejected()

	/**
	 * The default representation is the JSON disclosure table at 200.
	 *
	 * @return void
	 */
	public function testDefaultFormatReturnsTheJsonTable(): void {
		$response = $this->buildController($this->params())->disclosure();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(self::TABLE, $response->getData());

	}//end testDefaultFormatReturnsTheJsonTable()

	/**
	 * `csv` streams a real download named for the period — not a JSON body.
	 *
	 * @return void
	 */
	public function testCsvReturnsAFileDownloadNamedForThePeriod(): void {
		$response = $this->buildController($this->params(['format' => 'csv']))->disclosure();

		self::assertInstanceOf(
			DataDownloadResponse::class,
			$response,
			'csv must stream a file; a JSON envelope would make the browser render it inline.'
		);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertStringContainsString('2026-Q1', (string)$response->getHeaders()['Content-Disposition']);
		self::assertStringContainsString('fiscalPeriod', (string)$response->render());

	}//end testCsvReturnsAFileDownloadNamedForThePeriod()

	/**
	 * `pdf` and `xbrl` return an honest pending envelope, not a file.
	 *
	 * Emitting a `.pdf` download over unrendered HTML would misrepresent an
	 * unsigned preview as the signed disclosure note REQ-LD-004(1) asks for,
	 * so the pending status is part of the contract and is asserted as such.
	 *
	 * @return void
	 */
	public function testPdfAndXbrlReturnAnExplicitPendingEnvelope(): void {
		foreach (['pdf' => 'pending-pdf-pipeline', 'xbrl' => 'pending-sbr-xbrl-reporting'] as $format => $status) {
			$response = $this->buildController($this->params(['format' => $format]))->disclosure();

			self::assertInstanceOf(
				JSONResponse::class,
				$response,
				$format . ' must not be served as a file download while its pipeline is pending.'
			);
			self::assertSame(Http::STATUS_OK, $response->getStatus());
			self::assertSame(
				$status,
				($response->getData()['status'] ?? null),
				$format . ' must declare its pending status rather than imply a finished artefact.'
			);
		}

	}//end testPdfAndXbrlReturnAnExplicitPendingEnvelope()

	/**
	 * A service failure is a 500 that leaks no internals.
	 *
	 * @return void
	 */
	public function testServiceFailureIs500WithoutLeakingInternals(): void {
		$service = $this->createMock(LeaseDisclosureService::class);
		$service->method('generateForPeriod')->willThrowException(
			new \RuntimeException('SQLSTATE[42S02]: Base table or view not found: oc_shillinq_leases')
		);

		$response = $this->buildController($this->params(), true, $service)->disclosure();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsString('SQLSTATE', json_encode($response->getData()));

	}//end testServiceFailureIs500WithoutLeakingInternals()
}//end class
