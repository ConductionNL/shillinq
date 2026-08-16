<?php

/**
 * Unit tests for AccountantPortalController.
 *
 * Proves the security headline of the accountant-portal change (REQ-ACP-003):
 * an administration the authenticated user has no membership for is masked
 * as a 404 (never 403) on both the dashboard and the handover-pack export —
 * mirroring AdministrationExportControllerTest's existing masked-404 proof
 * for the sibling XAF export endpoint. Also proves the handover pack
 * (REQ-ACP-004) bundles every rendered report into a real ZIP and survives a
 * single report type failing to render.
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
 * @spec openspec/changes/accountant-portal/specs/accountant-portal/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\AccountantPortalController;
use OCA\Shillinq\Reporting\ReportGenerationService;
use OCA\Shillinq\Service\AccountantDashboardService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the accountant-portal masked-404 tenant isolation + handover-pack ZIP.
 */
final class AccountantPortalControllerTest extends TestCase {
	/**
	 * Build a controller with the given access decision and dashboard payload.
	 *
	 * @param bool $canAccess Whether the membership guard allows the requested id.
	 * @param string $userId The authenticated user id ('' = anonymous).
	 * @param array<string,mixed> $dashboardData Payload AccountantDashboardService::buildDashboard() returns.
	 *
	 * @return AccountantPortalController
	 */
	private function controller(bool $canAccess, string $userId, array $dashboardData = []): AccountantPortalController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn ($key, $default = null) => $default
		);

		$resolvedUserId = null;
		if ($userId !== '') {
			$resolvedUserId = $userId;
		}

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($resolvedUserId);
		$context->method('canAccess')->willReturn($canAccess);

		$dashboardService = $this->createMock(AccountantDashboardService::class);
		$dashboardService->method('buildDashboard')->willReturn($dashboardData);

		$reports = $this->createMock(ReportGenerationService::class);

		return new AccountantPortalController(
			$request,
			$context,
			$dashboardService,
			$reports,
			new NullLogger(),
		);

	}//end controller()

	/**
	 * Build a controller wired for the handover-pack tests, with a
	 * ReportGenerationService double that renders every requested report type
	 * unless it is listed in $failingTypes.
	 *
	 * @param array<int,string> $failingTypes Report types that should fail (return an error envelope).
	 *
	 * @return AccountantPortalController
	 */
	private function handoverController(array $failingTypes = []): AccountantPortalController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn ($key, $default = null) => $default
		);

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('accountant-1');
		$context->method('canAccess')->willReturn(true);

		$dashboardService = $this->createMock(AccountantDashboardService::class);

		$reports = $this->createMock(ReportGenerationService::class);
		$reports->method('generate')->willReturnCallback(
			function (string $reportType, string $period, string $administrationId, string $format) use ($failingTypes) {
				if (in_array($reportType, $failingTypes, true) === true) {
					return ['error' => 'generation-failed', 'reportType' => $reportType];
				}

				return ['id' => 'rec-' . $reportType, 'fileName' => $reportType . '.' . $format];
			}
		);
		$reports->method('resolveFile')->willReturnCallback(
			function (string $id) {
				$file = $this->createMock(File::class);
				$file->method('getContent')->willReturn('bytes-for-' . $id);
				return $file;
			}
		);

		return new AccountantPortalController(
			$request,
			$context,
			$dashboardService,
			$reports,
			new NullLogger(),
		);

	}//end handoverController()

	/**
	 * A non-member is masked as 404 (never 403) on the dashboard's implicit
	 * per-client scoping is proven by the dashboard simply never including a
	 * non-granted administration — see AccountantDashboardServiceTest. The
	 * controller-level guard under test here is the handover pack.
	 *
	 * @return void
	 */
	public function testDashboardRequiresAuthentication(): void {
		$response = $this->controller(true, '')->dashboard();
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testDashboardRequiresAuthentication()

	/**
	 * An authenticated user gets their dashboard payload back verbatim.
	 *
	 * @return void
	 */
	public function testDashboardReturnsPayload(): void {
		$data = ['userId' => 'accountant-1', 'administrations' => [['administrationId' => 'adm-werk-001']]];
		$response = $this->controller(true, 'accountant-1', $data)->dashboard();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($data, $response->getData());

	}//end testDashboardReturnsPayload()

	/**
	 * SECURITY HEADLINE (REQ-ACP-003): a non-granted administration's
	 * handover pack is masked as 404 — never 403 — never confirming the
	 * administration exists.
	 *
	 * @return void
	 */
	public function testHandoverPackMaskedForNonMember(): void {
		$response = $this->controller(false, 'accountant-1')->handoverPack('BEHEER-secret');
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testHandoverPackMaskedForNonMember()

	/**
	 * An anonymous request to the handover pack is rejected with 401, before
	 * any membership lookup.
	 *
	 * @return void
	 */
	public function testHandoverPackAnonymousRejected(): void {
		$response = $this->controller(true, '')->handoverPack('WERK-001');
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testHandoverPackAnonymousRejected()

	/**
	 * A malformed administration id is rejected with 400 before touching the
	 * data layer.
	 *
	 * @return void
	 */
	public function testHandoverPackMalformedIdRejected(): void {
		$response = $this->controller(true, 'accountant-1')->handoverPack('not a valid id!');
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testHandoverPackMalformedIdRejected()

	/**
	 * A granted administration's handover pack streams a real ZIP containing
	 * every rendered report (REQ-ACP-004).
	 *
	 * @return void
	 */
	public function testHandoverPackStreamsZipWithAllReports(): void {
		if (class_exists(\ZipArchive::class) === false) {
			$this->markTestSkipped('ext-zip not available in this runtime');
		}

		$response = $this->handoverController()->handoverPack('WERK-001');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$tmp = tempnam(sys_get_temp_dir(), 'handover-pack-test-');
		file_put_contents($tmp, $response->render());

		$zip = new \ZipArchive();
		self::assertTrue($zip->open($tmp) === true, 'Handover pack is not a valid ZIP');

		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = (string)$zip->getNameIndex($i);
		}

		$zip->close();
		@unlink($tmp);

		self::assertCount(4, $names);
		foreach (['xaf', 'trial-balance', 'general-ledger', 'vat-return'] as $expected) {
			self::assertTrue(
				(bool)array_filter($names, static fn (string $name): bool => str_starts_with($name, $expected . '/')),
				'Missing ' . $expected . ' entry in handover pack'
			);
		}

	}//end testHandoverPackStreamsZipWithAllReports()

	/**
	 * A single failing report type (e.g. no VAT return on file yet) does not
	 * block the rest of the handover pack (REQ-ACP-004).
	 *
	 * @return void
	 */
	public function testHandoverPackSkipsFailedReport(): void {
		if (class_exists(\ZipArchive::class) === false) {
			$this->markTestSkipped('ext-zip not available in this runtime');
		}

		$response = $this->handoverController(['vat-return'])->handoverPack('NIEUW-001');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$tmp = tempnam(sys_get_temp_dir(), 'handover-pack-test-');
		file_put_contents($tmp, $response->render());

		$zip = new \ZipArchive();
		self::assertTrue($zip->open($tmp) === true);
		self::assertSame(3, $zip->numFiles);
		$zip->close();
		@unlink($tmp);

	}//end testHandoverPackSkipsFailedReport()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
