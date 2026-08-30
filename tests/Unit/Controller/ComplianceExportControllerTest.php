<?php

/**
 * Unit tests for ComplianceExportController.
 *
 * Covers security-endpoint-guards REQ-003: `export()`'s
 * `catch (RuntimeException $e)` branch used to return
 * `['error' => $e->getMessage()]`, leaking raw exception text (which can
 * include filter/validation detail) straight to the client. These tests
 * prove the fixed shape — a stable kebab-case slug plus a localized
 * message, with the real exception logged server-side instead.
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
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ComplianceExportController;
use OCA\Shillinq\Service\ComplianceExportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the RBAC-scoped compliance export endpoint's error handling.
 *
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
 */
final class ComplianceExportControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock export service.
	 *
	 * @var ComplianceExportService&MockObject
	 */
	private ComplianceExportService&MockObject $complianceExportService;

	/**
	 * Mock IUserSession, authenticated as an auditor by default.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock IGroupManager, granting the auditor group by default.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Set up shared fixtures — an authenticated auditor caller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->request = $this->createMock(IRequest::class);
		$this->complianceExportService = $this->createMock(ComplianceExportService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('auditor-alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) {
				$params = ['from' => '2026-01-01', 'to' => '2026-03-31'];
				return ($params[$key] ?? $default);
			}
		);

	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return ComplianceExportController
	 */
	private function controller(): ComplianceExportController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new ComplianceExportController(
			$this->request,
			$this->complianceExportService,
			$this->userSession,
			$this->groupManager,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$l10n,
		);

	}//end controller()

	/**
	 * A validation RuntimeException from the export service answers a
	 * stable slug and a localized message — never the raw exception text
	 * (REQ-003).
	 *
	 * @return void
	 */
	public function testExportValidationFailureDoesNotLeakExceptionText(): void {
		$this->complianceExportService->method('generateExport')->willThrowException(
			new RuntimeException('Invalid date range: from (2026-03-31) is after to (2026-01-01) [actor=auditor-alice]')
		);

		$response = $this->controller()->export();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

		$data = $response->getData();
		self::assertSame('compliance-export-invalid-request', $data['error']);
		self::assertArrayHasKey('message', $data);
		self::assertStringNotContainsString('auditor-alice', json_encode($data));
		self::assertStringNotContainsString('2026-03-31', json_encode($data));

	}//end testExportValidationFailureDoesNotLeakExceptionText()

	/**
	 * A non-auditor, non-admin caller is rejected with 403 before the
	 * export service is ever consulted (pre-existing guard, re-verified
	 * still intact after the leak fix).
	 *
	 * @return void
	 */
	public function testExportRejectsNonAuditorCaller(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$response = $this->controller()->export();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testExportRejectsNonAuditorCaller()
}//end class
