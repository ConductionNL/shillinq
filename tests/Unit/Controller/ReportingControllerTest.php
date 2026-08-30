<?php

/**
 * Unit tests for ReportingController.
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
 * @spec exclude No canonical requirement exists for the reporting capability — see #525.
 *       The controller under test carries the same knowingly-dangling exclusion; pointing
 *       this test at a spec the code breaks would report a conformance nobody agreed to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ReportingController;
use OCA\Shillinq\Reporting\ReportCatalogue;
use OCA\Shillinq\Reporting\ReportGenerationService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Reporting & Compliance HTTP surface.
 *
 * Covers the catalogue endpoint (grouping contract), the generated-report
 * listing (which is ALWAYS scoped to the caller's administration memberships —
 * it used to be an optional filter and leaked every tenant's report ids), and
 * the download endpoint's authorisation + streaming behaviour (ADR-005 /
 * REQ-MA-001).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ReportingControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock ReportGenerationService.
	 *
	 * @var ReportGenerationService&MockObject
	 */
	private ReportGenerationService&MockObject $service;

	/**
	 * Mock AdministrationContextService (the RBAC guard).
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * The user the session reports; null models an anonymous caller.
	 *
	 * @var IUser|null
	 */
	private ?IUser $currentUser = null;

	/**
	 * The controller under test.
	 *
	 * @var ReportingController
	 */
	private ReportingController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->service = $this->createMock(ReportGenerationService::class);
		$this->context = $this->createMock(AdministrationContextService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->currentUser = $user;

		// Resolved per call so a test can drop the session to model anonymity.
		$this->userSession->method('getUser')->willReturnCallback(
			function (): ?IUser {
				return $this->currentUser;
			}
		);

		$this->controller = new ReportingController(
			request: $this->request,
			userSession: $this->userSession,
			service: $this->service,
			context: $this->context,
		);

	}//end setUp()

	/**
	 * Configure request params from a key => value map.
	 *
	 * @param array<string,mixed> $map Param map.
	 *
	 * @return void
	 */
	private function withParams(array $map): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($map): mixed {
				return ($map[$key] ?? $default);
			}
		);

	}//end withParams()

	/**
	 * The catalogue endpoint returns every category, each holding its own reports.
	 *
	 * @return void
	 */
	public function testTypesReturnsCatalogueGroupedByCategory(): void {
		$response = $this->controller->types();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertSame(ReportCatalogue::CATEGORIES, $data['categories']);
		self::assertSame(array_keys(ReportCatalogue::CATEGORIES), array_keys($data['groups']));

		// Every catalogued report lands in exactly one group, and nothing is lost.
		$grouped = 0;
		foreach ($data['groups'] as $categoryId => $reports) {
			foreach ($reports as $report) {
				self::assertSame($categoryId, $report['category']);
				$grouped++;
			}
		}

		self::assertCount($grouped, ReportCatalogue::all());

	}//end testTypesReturnsCatalogueGroupedByCategory()

	/**
	 * The catalogue endpoint refuses an anonymous caller with HTTP 401.
	 *
	 * @return void
	 */
	public function testTypesRejectsAnonymousCaller(): void {
		$this->currentUser = null;

		$response = $this->controller->types();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'not-logged-in'], $response->getData());

	}//end testTypesRejectsAnonymousCaller()

	/**
	 * Generating a report succeeds for a member of the target administration
	 * (positive direction — security-endpoint-guards re-verification, verdict
	 * ALREADY-GUARDED: the controller already checked canAccess() before this
	 * change).
	 *
	 * @return void
	 */
	public function testGenerateByMemberOfTargetAdministrationSucceeds(): void {
		$this->withParams(
			[
				'reportType' => 'vat-return',
				'period' => '2026-Q2',
				'administrationId' => 'adm-1',
				'format' => 'pdf',
			]
		);
		$this->context->method('canAccess')->willReturn(true);
		$this->service->expects($this->once())
			->method('generate')
			->with('vat-return', '2026-Q2', 'adm-1', 'pdf')
			->willReturn(['id' => 'gen-1', 'administrationId' => 'adm-1', 'reportType' => 'vat-return']);

		$response = $this->controller->generate();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('gen-1', $response->getData()['id']);

	}//end testGenerateByMemberOfTargetAdministrationSucceeds()

	/**
	 * NEGATIVE CONTROL: generating a report against an administration the
	 * caller is not a member of is masked as 404, and the service is never
	 * called (security-endpoint-guards, REQ-001).
	 *
	 * @return void
	 */
	public function testGenerateForForeignAdministrationIsForbidden(): void {
		$this->withParams(
			[
				'reportType' => 'vat-return',
				'period' => '2026-Q2',
				'administrationId' => 'adm-other',
				'format' => 'pdf',
			]
		);
		$this->context->method('canAccess')->willReturn(false);
		$this->service->expects($this->never())->method('generate');

		$response = $this->controller->generate();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'not-found'], $response->getData());

	}//end testGenerateForForeignAdministrationIsForbidden()

	/**
	 * The generate endpoint refuses an anonymous caller with HTTP 401 before
	 * any administration check runs.
	 *
	 * @return void
	 */
	public function testGenerateRejectsAnonymousCaller(): void {
		$this->currentUser = null;
		$this->service->expects($this->never())->method('generate');

		$response = $this->controller->generate();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testGenerateRejectsAnonymousCaller()

	/**
	 * Generating with no administrationId at all is left to the service (a
	 * caller-scoped report, e.g. stored under the caller's own Files home) —
	 * it is not a bypass since no OTHER tenant's scope is ever named or
	 * checked against.
	 *
	 * @return void
	 */
	public function testGenerateWithNoAdministrationIdSkipsTheGuardAndReachesTheService(): void {
		$this->withParams(['reportType' => 'vat-return', 'period' => '2026-Q2', 'format' => 'pdf']);
		$this->context->expects($this->never())->method('canAccess');
		$this->service->expects($this->once())
			->method('generate')
			->with('vat-return', '2026-Q2', '', 'pdf')
			->willReturn(['id' => 'gen-2']);

		$response = $this->controller->generate();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());

	}//end testGenerateWithNoAdministrationIdSkipsTheGuardAndReachesTheService()

	/**
	 * Without an explicit administrationId the listing is scoped to the caller's
	 * memberships — one service query per accessible administration, never a
	 * whole-instance listing (ADR-005 / REQ-MA-001).
	 *
	 * @return void
	 */
	public function testGeneratedScopesListingToMemberships(): void {
		$this->withParams([]);
		$this->context->method('accessibleAdministrationIds')->willReturn(['adm-1', 'adm-2']);

		$seen = [];
		$this->service->expects($this->exactly(2))
			->method('listGenerated')
			->willReturnCallback(
				static function (array $filters) use (&$seen): array {
					$seen[] = $filters['administrationId'];
					return [['id' => 'rep-' . $filters['administrationId']]];
				}
			);

		$response = $this->controller->generated();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['adm-1', 'adm-2'], $seen);
		self::assertSame(
			[['id' => 'rep-adm-1'], ['id' => 'rep-adm-2']],
			$response->getData()['reports']
		);

	}//end testGeneratedScopesListingToMemberships()

	/**
	 * An explicit administrationId may only narrow the scope: one the caller has
	 * no membership for is masked as 404 and never queried.
	 *
	 * @return void
	 */
	public function testGeneratedRejectsForeignAdministrationWith404(): void {
		$this->withParams(['administrationId' => 'adm-other']);
		$this->context->method('canAccess')->willReturn(false);
		$this->service->expects($this->never())->method('listGenerated');

		$response = $this->controller->generated();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'not-found'], $response->getData());

	}//end testGeneratedRejectsForeignAdministrationWith404()

	/**
	 * An explicit, permitted administrationId narrows the listing to that one id.
	 *
	 * @return void
	 */
	public function testGeneratedNarrowsToRequestedAdministration(): void {
		$this->withParams(['administrationId' => 'adm-1', 'reportType' => 'vat-return']);
		$this->context->method('canAccess')->willReturn(true);
		// The membership set is wider than the request; the request must narrow it.
		$this->context->method('accessibleAdministrationIds')->willReturn(['adm-1', 'adm-2']);

		$this->service->expects($this->once())
			->method('listGenerated')
			->willReturnCallback(
				static function (array $filters): array {
					return [['id' => 'rep-1', 'reportType' => $filters['reportType'], 'administrationId' => $filters['administrationId']]];
				}
			);

		$response = $this->controller->generated();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$reports = $response->getData()['reports'];
		self::assertCount(1, $reports);
		self::assertSame('adm-1', $reports[0]['administrationId']);
		self::assertSame('vat-return', $reports[0]['reportType']);

	}//end testGeneratedNarrowsToRequestedAdministration()

	/**
	 * The listing endpoint refuses an anonymous caller with HTTP 401.
	 *
	 * @return void
	 */
	public function testGeneratedRejectsAnonymousCaller(): void {
		$this->currentUser = null;

		$response = $this->controller->generated();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testGeneratedRejectsAnonymousCaller()

	/**
	 * A blank download id is a validation error, not a lookup.
	 *
	 * @return void
	 */
	public function testDownloadBlankIdReturns400(): void {
		$this->service->expects($this->never())->method('findRecord');

		$response = $this->controller->download('   ');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'missing-id'], $response->getData());

	}//end testDownloadBlankIdReturns400()

	/**
	 * An unknown record id is masked as 404 and never resolves a file.
	 *
	 * @return void
	 */
	public function testDownloadUnknownRecordReturns404(): void {
		$this->service->method('findRecord')->willReturn(null);
		$this->service->expects($this->never())->method('resolveRecordFile');

		$response = $this->controller->download('rep-404');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testDownloadUnknownRecordReturns404()

	/**
	 * A record belonging to another tenant is masked as 404 — never confirmed,
	 * and its file is never resolved (ADR-005 / REQ-MA-001).
	 *
	 * @return void
	 */
	public function testDownloadForeignRecordReturns404WithoutResolvingFile(): void {
		$this->service->method('findRecord')->willReturn(['id' => 'rep-1', 'administrationId' => 'adm-other']);
		$this->context->method('canAccess')->willReturn(false);
		$this->service->expects($this->never())->method('resolveRecordFile');

		$response = $this->controller->download('rep-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'not-found'], $response->getData());

	}//end testDownloadForeignRecordReturns404WithoutResolvingFile()

	/**
	 * An authorised record whose stored file has vanished yields 404, not a 500.
	 *
	 * @return void
	 */
	public function testDownloadMissingFileReturns404(): void {
		$this->service->method('findRecord')->willReturn(['id' => 'rep-1', 'administrationId' => 'adm-1']);
		$this->context->method('canAccess')->willReturn(true);
		$this->service->method('resolveRecordFile')->willReturn(null);

		$response = $this->controller->download('rep-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testDownloadMissingFileReturns404()

	/**
	 * An unreadable file is reported as 404 rather than leaking a storage error.
	 *
	 * @return void
	 */
	public function testDownloadUnreadableFileReturns404(): void {
		$this->service->method('findRecord')->willReturn(['id' => 'rep-1', 'administrationId' => 'adm-1']);
		$this->context->method('canAccess')->willReturn(true);

		$file = $this->createMock(File::class);
		$file->method('getContent')->willThrowException(new \RuntimeException('storage offline'));
		$this->service->method('resolveRecordFile')->willReturn($file);

		$response = $this->controller->download('rep-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'not-readable'], $response->getData());
		self::assertStringNotContainsStringIgnoringCase(
			'storage offline',
			(string)json_encode($response->getData())
		);

	}//end testDownloadUnreadableFileReturns404()

	/**
	 * The happy path streams the stored file with its own name and mimetype.
	 *
	 * @return void
	 */
	public function testDownloadStreamsStoredFile(): void {
		$this->service->method('findRecord')->willReturn(['id' => 'rep-1', 'administrationId' => 'adm-1']);
		$this->context->method('canAccess')->willReturn(true);

		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('col-a;col-b');
		$file->method('getName')->willReturn('trial-balance-2026-Q2.csv');
		$file->method('getMimeType')->willReturn('text/csv');
		$this->service->method('resolveRecordFile')->willReturn($file);

		$response = $this->controller->download('rep-1');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('col-a;col-b', $response->render());
		self::assertStringContainsString(
			'trial-balance-2026-Q2.csv',
			(string)$response->getHeaders()['Content-Disposition']
		);

	}//end testDownloadStreamsStoredFile()

	/**
	 * The download endpoint refuses an anonymous caller with HTTP 401.
	 *
	 * @return void
	 */
	public function testDownloadRejectsAnonymousCaller(): void {
		$this->currentUser = null;
		$this->service->expects($this->never())->method('findRecord');

		$response = $this->controller->download('rep-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testDownloadRejectsAnonymousCaller()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
