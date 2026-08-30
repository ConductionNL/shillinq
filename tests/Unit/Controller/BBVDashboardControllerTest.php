<?php

/**
 * Unit tests for BBVDashboardController (slice 09 fiscal scoping + IDOR mask).
 *
 * Verifies the controller derives administrationId from the session when
 * the client omits it, masks cross-tenant requests as an empty envelope,
 * and surfaces the resolved fiscal-year window under the `scope` key so
 * the Vue dashboard can render the "FY YYYY" label without re-deriving
 * the boundary (REQ-BBVW-006 / REQ-MA-001 / ADR-005).
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
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-09-fiscal-audit/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BBVDashboardController;
use OCA\Shillinq\Dashboard\BBVComplianceWidget;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ComplianceService;
use OCA\Shillinq\Service\FiscalYearContextService;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the slice-09 fiscal-year + administration scoping on the dashboard envelope.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BBVDashboardControllerTest extends TestCase {
	/**
	 * Build a recording widget stub that captures the buildEnvelope args
	 * and returns a deterministic envelope. The real BBVComplianceWidget
	 * is `final` and cannot be doubled — but slice 09 only depends on the
	 * widget through `buildEnvelope(?int, ?string)` so a hand-rolled
	 * subclass-shaped helper is sufficient.
	 *
	 * @param array<string,mixed>|null $envelope Envelope to return; null = default.
	 *
	 * @return BBVComplianceWidget
	 */
	private function buildWidgetStub(?array $envelope = null): BBVComplianceWidget {
		// Build a real BBVComplianceWidget with stub deps that always
		// return empty data — its buildEnvelope() will then produce an
		// empty envelope deterministically (no OR interaction).
		$container = $this->createMock(ContainerInterface::class);
		$logger = $this->createMock(LoggerInterface::class);

		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->getMock();
		$settings->method('isOpenRegisterAvailable')->willReturn(false);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isLocalCacheAvailable')->willReturn(false);
		$cache = $this->createMock(ICache::class);
		$cacheFactory->method('createLocal')->willReturn($cache);

		$compliance = new ComplianceService($container, $settings, $cacheFactory, $logger);

		return new BBVComplianceWidget($container, $settings, $compliance, $logger);
	}//end buildWidgetStub()

	/**
	 * Build the controller with default-mock collaborators.
	 *
	 * @param IRequest&MockObject $request Request mock.
	 * @param IUserSession&MockObject $userSession User session mock.
	 * @param BBVComplianceWidget $widget Widget instance.
	 * @param AdministrationContextService&MockObject $administrationContext Admin context mock.
	 * @param FiscalYearContextService&MockObject $fiscalYearContext Fiscal year context mock.
	 *
	 * @return BBVDashboardController
	 */
	private function buildController(
		IRequest&MockObject $request,
		IUserSession&MockObject $userSession,
		BBVComplianceWidget $widget,
		AdministrationContextService&MockObject $administrationContext,
		FiscalYearContextService&MockObject $fiscalYearContext,
	): BBVDashboardController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new BBVDashboardController(
			request: $request,
			userSession: $userSession,
			l10n: $l10n,
			widget: $widget,
			administrationContext: $administrationContext,
			fiscalYearContext: $fiscalYearContext,
		);

	}//end buildController()

	/**
	 * Authenticate the user session.
	 *
	 * @param IUserSession&MockObject $userSession Mock.
	 *
	 * @return void
	 */
	private function authenticate(IUserSession&MockObject $userSession): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('controller');
		$userSession->method('getUser')->willReturn($user);

	}//end authenticate()

	/**
	 * Anonymous callers MUST be rejected (hydra-gate-no-admin-idor).
	 *
	 * @return void
	 */
	public function testAnonymousIsRejected(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$widget = $this->buildWidgetStub();
		$administrationContext = $this->createMock(AdministrationContextService::class);
		$fiscalYearContext = $this->createMock(FiscalYearContextService::class);

		$userSession->method('getUser')->willReturn(null);

		$response = $this->buildController(
			$request,
			$userSession,
			$widget,
			$administrationContext,
			$fiscalYearContext
		)->index();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousIsRejected()

	/**
	 * When the client omits administrationId, the controller derives it
	 * from the session context (REQ-MA-001 default scope).
	 *
	 * @return void
	 */
	public function testServerDerivesAdministrationFromSession(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$widget = $this->buildWidgetStub();
		$administrationContext = $this->createMock(AdministrationContextService::class);
		$fiscalYearContext = $this->createMock(FiscalYearContextService::class);

		$this->authenticate($userSession);
		$request->method('getParam')->willReturn(null);

		$administrationContext->method('buildContext')->willReturn(
			[
				'userId' => 'controller',
				'administrations' => [],
				'activeAdministrationId' => 'adm-werk-001',
			]
		);
		$administrationContext->method('canAccess')
			->with('adm-werk-001')->willReturn(true);

		$fiscalYearContext->method('resolveActiveWindow')->willReturn(
			[
				'administrationId' => 'adm-werk-001',
				'fiscalYear' => 2026,
				'startDate' => '2026-01-01',
				'endDate' => '2027-01-01',
			]
		);

		$response = $this->buildController(
			$request,
			$userSession,
			$widget,
			$administrationContext,
			$fiscalYearContext
		)->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('adm-werk-001', $data['scope']['administrationId']);
		self::assertSame(2026, $data['scope']['fiscalYear']);
		self::assertSame('2026-01-01', $data['scope']['startDate']);
		self::assertSame('2027-01-01', $data['scope']['endDate']);

	}//end testServerDerivesAdministrationFromSession()

	/**
	 * Cross-tenant administrationId values resolve to an empty envelope —
	 * never a 403 / 404 (REQ-MA-001 mask).
	 *
	 * @return void
	 */
	public function testCrossTenantRequestReturnsEmptyEnvelope(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$widget = $this->buildWidgetStub();
		$administrationContext = $this->createMock(AdministrationContextService::class);
		$fiscalYearContext = $this->createMock(FiscalYearContextService::class);

		$this->authenticate($userSession);
		$request->method('getParam')->willReturnMap(
			[
				['administrationId', null, 'adm-someone-else'],
				['fiscalYear', null, null],
			]
		);

		$administrationContext->method('canAccess')
			->with('adm-someone-else')->willReturn(false);

		$response = $this->buildController(
			$request,
			$userSession,
			$widget,
			$administrationContext,
			$fiscalYearContext
		)->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame([], $data['programmes']);
		self::assertSame([], $data['mappings']);
		self::assertNull($data['scope']['fiscalYear']);

	}//end testCrossTenantRequestReturnsEmptyEnvelope()

	/**
	 * When the user has no accessible administration, the envelope is empty.
	 *
	 * @return void
	 */
	public function testNoAccessibleAdministrationReturnsEmptyEnvelope(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$widget = $this->buildWidgetStub();
		$administrationContext = $this->createMock(AdministrationContextService::class);
		$fiscalYearContext = $this->createMock(FiscalYearContextService::class);

		$this->authenticate($userSession);
		$request->method('getParam')->willReturn(null);

		$administrationContext->method('buildContext')->willReturn(
			[
				'userId' => 'controller',
				'administrations' => [],
				'activeAdministrationId' => null,
			]
		);
		$administrationContext->method('canAccess')->willReturn(false);

		$response = $this->buildController(
			$request,
			$userSession,
			$widget,
			$administrationContext,
			$fiscalYearContext
		)->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertNull($data['scope']['administrationId']);
		self::assertNull($data['scope']['fiscalYear']);

	}//end testNoAccessibleAdministrationReturnsEmptyEnvelope()
}//end class
