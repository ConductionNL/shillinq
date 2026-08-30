<?php

/**
 * Unit tests for CBSSubmissionController.
 *
 * Covers the security-endpoint-guards fix for the worst confirmed finding
 * in that change: `create()`/`update()`/`destroy()`/`generate()` (plus
 * `index()`/`show()`, found during the same code read) were guarded only
 * by `requireUser()` — authentication, not authorization — so any
 * authenticated user could read or mutate another organization's
 * statutory CBS filing. Every test below proves BOTH directions per
 * REQ-004: the unauthorized caller is rejected, and the legitimate caller
 * still succeeds exactly as before.
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
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\CBSSubmissionController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\CBSExportService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CBSSubmissionController's authorization guards.
 *
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 */
class CBSSubmissionControllerTest extends TestCase {

	/**
	 * IRequest stub for parameter reads.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Export service stub (not exercised by the guard tests).
	 *
	 * @var CBSExportService&MockObject
	 */
	private CBSExportService&MockObject $exportService;

	/**
	 * App config stub.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Fake ObjectService — in-memory CBSSubmission store.
	 *
	 * @var object
	 */
	private object $fakeObjectService;

	/**
	 * Per-request query/body param overrides.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	/**
	 * Build the fake stack for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->exportService = $this->createMock(CBSExportService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default = null): mixed {
				return $this->params[$key] ?? $default;
			}
		);
		$this->request->method('getParams')->willReturnCallback(
			fn (): array => $this->params
		);

		$this->fakeObjectService = $this->buildFakeObjectService();
	}//end setUp()

	/**
	 * Build a user session mock for a given uid (or anonymous when null).
	 *
	 * @param string|null $uid The acting uid, or null for anonymous.
	 *
	 * @return IUserSession&MockObject
	 */
	private function buildUserSession(?string $uid): IUserSession&MockObject {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end buildUserSession()

	/**
	 * Build an AdministrationContextService stub whose canAccess() only
	 * grants the given administration id(s).
	 *
	 * @param list<string> $accessible The administration ids the caller may access.
	 *
	 * @return AdministrationContextService&MockObject
	 */
	private function buildAdministrationContext(array $accessible): AdministrationContextService&MockObject {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => in_array($administrationId, $accessible, true)
		);
		return $context;
	}//end buildAdministrationContext()

	/**
	 * Build the controller under test.
	 *
	 * @param IUserSession $userSession The session to inject.
	 * @param AdministrationContextService $administrationContext The membership seam to inject.
	 * @param bool $isAdmin Whether the acting user is a Nextcloud admin (bypasses the guard).
	 *
	 * @return CBSSubmissionController
	 */
	private function buildController(
		IUserSession $userSession,
		AdministrationContextService $administrationContext,
		bool $isAdmin = false,
	): CBSSubmissionController {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		return new CBSSubmissionController(
			$this->request,
			$this->exportService,
			$this->appConfig,
			$userSession,
			$this->createMock(LoggerInterface::class),
			new DuckObjectServiceAdapter($this->fakeObjectService),
			$administrationContext,
			$groupManager,
		);
	}//end buildController()

	/**
	 * Duck-typed in-memory ObjectService double, wrapped in
	 * {@see DuckObjectServiceAdapter} by buildController() so it satisfies
	 * the ADR-084 `ObjectServiceInterface` the controller is constructed
	 * against — the established pattern in this suite (see commit
	 * ef9c8fa5, "wire the seeded OpenRegister store back into 128 test
	 * classes") rather than hand-implementing the full ~25-method contract
	 * per test file.
	 *
	 * @return object
	 */
	private function buildFakeObjectService(): object {
		return new class() {
			public array $records = [
				'CBSSubmission' => [
					[
						'id' => 'cbs-001',
						'administrationId' => 'adm-1',
						'status' => 'draft',
						'reportingPeriodStartDate' => '2026-01-01',
						'reportingPeriodEndDate' => '2026-03-31',
						'organizationLegalName' => 'Alice BV',
						'kvkNumber' => '12345678',
						'taxIdentificationNumber' => 'NL123456789B01',
						'currency' => 'EUR',
					],
				],
			];

			public array $deleted = [];

			public array $saved = [];

			private string $currentSchema = '';

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}

			public function find(string $id, ?string $register = null, ?string $schema = null): mixed {
				foreach (($this->records[$this->currentSchema] ?? []) as $row) {
					if (($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				throw new \RuntimeException('not found');
			}

			public function findAll(array $config = []): array {
				$rows = ($this->records[$this->currentSchema] ?? []);
				$filters = ($config['filters'] ?? []);
				$matched = [];
				foreach ($rows as $row) {
					$ok = true;
					foreach ($filters as $field => $value) {
						if (($row[$field] ?? null) !== $value) {
							$ok = false;
							break;
						}
					}

					if ($ok === true) {
						$matched[] = $row;
					}
				}

				return $matched;
			}

			public function saveObject(array $object, ?string $register = null, ?string $schema = null): mixed {
				$object['id'] ??= 'cbs-new';
				$this->saved[] = $object;
				return $object;
			}

			public function deleteObject(string $id): bool {
				$this->deleted[] = $id;
				return true;
			}
		};
	}//end buildFakeObjectService()

	/**
	 * A member of the submission's own administration can delete its
	 * draft submission (positive direction, spec scenario "A user can
	 * delete their own administration's draft CBS submission").
	 *
	 * @return void
	 */
	public function testDestroyByOwnAdministrationMemberSucceeds(): void {
		$controller = $this->buildController(
			$this->buildUserSession('alice'),
			$this->buildAdministrationContext(['adm-1']),
		);

		$response = $controller->destroy('cbs-001');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['cbs-001'], $this->fakeObjectService->deleted);
	}//end testDestroyByOwnAdministrationMemberSucceeds()

	/**
	 * NEGATIVE CONTROL / spec scenario "A user cannot delete another
	 * organization's CBS submission": a user with no membership in the
	 * submission's administration is rejected with 403, and nothing is
	 * deleted. Before this change's guard was added, this exact call
	 * returned 200 and deleted the record — see design.md's verdict
	 * table for the pre-fix evidence.
	 *
	 * @return void
	 */
	public function testDestroyByNonMemberIsForbidden(): void {
		$controller = $this->buildController(
			$this->buildUserSession('mallory'),
			$this->buildAdministrationContext(['adm-2']),
		);

		$response = $controller->destroy('cbs-001');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->fakeObjectService->deleted, 'The submission must NOT be deleted');
	}//end testDestroyByNonMemberIsForbidden()

	/**
	 * An anonymous caller is rejected with 401 before the administration
	 * guard even runs.
	 *
	 * @return void
	 */
	public function testDestroyByAnonymousIsUnauthorized(): void {
		$controller = $this->buildController(
			$this->buildUserSession(null),
			$this->buildAdministrationContext([]),
		);

		$response = $controller->destroy('cbs-001');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->fakeObjectService->deleted);
	}//end testDestroyByAnonymousIsUnauthorized()

	/**
	 * Update by the owning administration's member still works (positive
	 * direction, no regression to the existing draft/status business
	 * rules).
	 *
	 * @return void
	 */
	public function testUpdateByOwnAdministrationMemberSucceeds(): void {
		$this->params = ['status' => 'draft', 'description' => 'updated'];
		$controller = $this->buildController(
			$this->buildUserSession('alice'),
			$this->buildAdministrationContext(['adm-1']),
		);

		$response = $controller->update('cbs-001');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testUpdateByOwnAdministrationMemberSucceeds()

	/**
	 * Update by a non-member is forbidden (negative direction).
	 *
	 * @return void
	 */
	public function testUpdateByNonMemberIsForbidden(): void {
		$this->params = ['status' => 'draft'];
		$controller = $this->buildController(
			$this->buildUserSession('mallory'),
			$this->buildAdministrationContext(['adm-2']),
		);

		$response = $controller->update('cbs-001');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testUpdateByNonMemberIsForbidden()

	/**
	 * Create is allowed when the caller is a member of the
	 * administration named in the request body (positive direction).
	 *
	 * @return void
	 */
	public function testCreateByMemberOfTargetAdministrationSucceeds(): void {
		$this->params = [
			'reportingPeriodStartDate' => '2026-04-01',
			'reportingPeriodEndDate' => '2026-06-30',
			'organizationLegalName' => 'Alice BV',
			'kvkNumber' => '12345678',
			'taxIdentificationNumber' => 'NL123456789B01',
			'administrationId' => 'adm-1',
		];
		$controller = $this->buildController(
			$this->buildUserSession('alice'),
			$this->buildAdministrationContext(['adm-1']),
		);

		$response = $controller->create();
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testCreateByMemberOfTargetAdministrationSucceeds()

	/**
	 * Create is rejected when the caller names an administration they are
	 * not a member of — an authenticated user could otherwise plant a
	 * filing under another organization's administrationId (negative
	 * direction).
	 *
	 * @return void
	 */
	public function testCreateForForeignAdministrationIsForbidden(): void {
		$this->params = [
			'reportingPeriodStartDate' => '2026-04-01',
			'reportingPeriodEndDate' => '2026-06-30',
			'organizationLegalName' => 'Mallory Inc',
			'kvkNumber' => '87654321',
			'taxIdentificationNumber' => 'NL987654321B01',
			'administrationId' => 'adm-1',
		];
		$controller = $this->buildController(
			$this->buildUserSession('mallory'),
			$this->buildAdministrationContext(['adm-2']),
		);

		$response = $controller->create();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->fakeObjectService->saved);
	}//end testCreateForForeignAdministrationIsForbidden()

	/**
	 * show() is readable by a member of the submission's administration.
	 *
	 * @return void
	 */
	public function testShowByOwnAdministrationMemberSucceeds(): void {
		$controller = $this->buildController(
			$this->buildUserSession('alice'),
			$this->buildAdministrationContext(['adm-1']),
		);

		$response = $controller->show('cbs-001');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testShowByOwnAdministrationMemberSucceeds()

	/**
	 * show() is forbidden for a non-member — found during this change's
	 * code read, beyond the audit's originally-named 4 methods.
	 *
	 * @return void
	 */
	public function testShowByNonMemberIsForbidden(): void {
		$controller = $this->buildController(
			$this->buildUserSession('mallory'),
			$this->buildAdministrationContext(['adm-2']),
		);

		$response = $controller->show('cbs-001');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testShowByNonMemberIsForbidden()

	/**
	 * index() with no explicit filter scopes results to the caller's own
	 * accessible administrations rather than returning every tenant's
	 * submissions.
	 *
	 * @return void
	 */
	public function testIndexScopesToAccessibleAdministrations(): void {
		$this->fakeObjectService->records['CBSSubmission'][] = [
			'id' => 'cbs-002',
			'administrationId' => 'adm-2',
			'status' => 'draft',
		];

		$controller = $this->buildController(
			$this->buildUserSession('alice'),
			$this->buildAdministrationContext(['adm-1']),
		);

		$response = $controller->index();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$ids = array_column($response->getData()['submissions'], 'id');
		$this->assertSame(['cbs-001'], $ids, 'Only the caller\'s own administration\'s submission is listed');
	}//end testIndexScopesToAccessibleAdministrations()

	/**
	 * generate() is forbidden for a non-member (negative direction).
	 *
	 * @return void
	 */
	public function testGenerateByNonMemberIsForbidden(): void {
		$controller = $this->buildController(
			$this->buildUserSession('mallory'),
			$this->buildAdministrationContext(['adm-2']),
		);

		$response = $controller->generate('cbs-001');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testGenerateByNonMemberIsForbidden()

	/**
	 * A Nextcloud admin bypasses the per-administration membership check —
	 * matching the established pattern in
	 * `BookingNotificationController::authorizeBookingAccess()`. Without
	 * this, an admin with no `AdministrationMembership` record of their own
	 * (the default state for the Nextcloud admin account — see
	 * tests/e2e/ci-seed.sh's note that "the setup wizard does not create"
	 * one) would be locked out of every administration's filings, which
	 * would break the back-office admin surface and the e2e suite that
	 * runs as that account.
	 *
	 * @return void
	 */
	public function testDestroyByAdminBypassesMembershipCheck(): void {
		$controller = $this->buildController(
			$this->buildUserSession('admin'),
			$this->buildAdministrationContext([]), // no memberships at all
			isAdmin: true,
		);

		$response = $controller->destroy('cbs-001');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['cbs-001'], $this->fakeObjectService->deleted);
	}//end testDestroyByAdminBypassesMembershipCheck()

	/**
	 * An admin's unfiltered index() call sees every administration's
	 * submissions, not just an empty list from having no memberships.
	 *
	 * @return void
	 */
	public function testIndexByAdminSeesAllAdministrations(): void {
		$this->fakeObjectService->records['CBSSubmission'][] = [
			'id' => 'cbs-002',
			'administrationId' => 'adm-2',
			'status' => 'draft',
		];

		$controller = $this->buildController(
			$this->buildUserSession('admin'),
			$this->buildAdministrationContext([]),
			isAdmin: true,
		);

		$response = $controller->index();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$ids = array_column($response->getData()['submissions'], 'id');
		sort($ids);
		$this->assertSame(['cbs-001', 'cbs-002'], $ids);
	}//end testIndexByAdminSeesAllAdministrations()

}//end class
