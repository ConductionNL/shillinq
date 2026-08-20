<?php

/**
 * Unit tests for GRIRReconciliationController.
 *
 * Correctness proof for tasks.md Task 17 (REQ-GLTAX-003 / shillinq#424).
 * `GRIRClearingService::reconcileGRIRSaldoForPeriod()` was fully implemented
 * and integration-tested but had no route, no controller and no CLI command:
 * the class is dependency-injected (the GRIRClearingListener calls its two
 * POSTING methods), yet THIS method was never called. The test drives the new
 * endpoint against the REAL GRIRClearingService over an in-memory
 * ObjectService, and asserts the saldo an operator now sees.
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
 * @spec openspec/changes/revive-gl-tax-capabilities/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\GRIRReconciliationController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\GRIRClearingService;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Service/InMemoryObjectService.php';

/**
 * Tests the GR/IR period-end saldo endpoint.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class GRIRReconciliationControllerTest extends TestCase {

	/**
	 * The in-memory ObjectService backing the real service.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $objects;

	/**
	 * Set up the in-memory GL lines on the GR/IR clearing account.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = new InMemoryObjectService();
		$this->objects->seed(
			'GLLine',
			[
				[
					'id' => 'glline-1',
					'accountNumber' => '2910',
					'side' => 'credit',
					'amount' => 18500.40,
					'periodId' => '2026-Q2',
					'administrationId' => 'adm-1',
				],
			]
		);

	}//end setUp()

	/**
	 * Build the controller with the given access + session posture.
	 *
	 * @param boolean $canAccess Whether the caller can see the administration.
	 * @param boolean $loggedIn Whether a user session exists.
	 * @param array<string,string> $params The request parameters.
	 *
	 * @return GRIRReconciliationController
	 */
	private function controller(bool $canAccess, bool $loggedIn, array $params): GRIRReconciliationController {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				if ($key === GRIRClearingService::CFG_GR_IR_CLEARING_ACCOUNT) {
					return '2910';
				}

				if ($key === 'register') {
					return 'shillinq';
				}

				return $default;
			}
		);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturn($canAccess);

		$service = new GRIRClearingService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($this->objects),
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $name, $default = '') use ($params) {
				return ($params[$name] ?? $default);
			}
		);

		$userSession = $this->createMock(IUserSession::class);
		$user = null;
		if ($loggedIn === true) {
			$user = $this->createMock(IUser::class);
		}

		$userSession->method('getUser')->willReturn($user);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new GRIRReconciliationController(
			request: $request,
			grirClearingService: $service,
			administrationContext: $administrationContext,
			userSession: $userSession,
			logger: $this->createMock(LoggerInterface::class),
			l10n: $l10n,
		);

	}//end controller()

	/**
	 * The endpoint returns the reconciliation envelope for the period
	 * (REQ-GLTAX-003).
	 *
	 * @return void
	 */
	public function testSaldoReturnsTheReconciliationEnvelope(): void {
		$controller = $this->controller(
			canAccess: true,
			loggedIn: true,
			params: ['administrationId' => 'adm-1', 'periodId' => '2026-Q2']
		);

		$response = $controller->saldo();
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertSame('2026-Q2', $data['periodId']);
		self::assertSame('2910', $data['clearingAccount']);
		self::assertSame(0, $data['debitCents']);
		self::assertSame(1850040, $data['creditCents']);
		self::assertSame(-1850040, $data['saldoCents'], 'Goods received but never invoiced leave a credit saldo.');
		self::assertFalse($data['balanced']);

	}//end testSaldoReturnsTheReconciliationEnvelope()

	/**
	 * An anonymous caller gets 401 (ADR-005).
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsUnauthorized(): void {
		$controller = $this->controller(
			canAccess: true,
			loggedIn: false,
			params: ['administrationId' => 'adm-1', 'periodId' => '2026-Q2']
		);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->saldo()->getStatus());

	}//end testAnonymousCallerIsUnauthorized()

	/**
	 * A cross-tenant administration is masked as 404 (ADR-005).
	 *
	 * @return void
	 */
	public function testCrossTenantAdministrationIsMaskedAsNotFound(): void {
		$controller = $this->controller(
			canAccess: false,
			loggedIn: true,
			params: ['administrationId' => 'adm-other', 'periodId' => '2026-Q2']
		);

		self::assertSame(Http::STATUS_NOT_FOUND, $controller->saldo()->getStatus());

	}//end testCrossTenantAdministrationIsMaskedAsNotFound()

	/**
	 * A missing periodId is a 400 (REQ-GLTAX-003).
	 *
	 * @return void
	 */
	public function testMissingPeriodIdIsABadRequest(): void {
		$controller = $this->controller(
			canAccess: true,
			loggedIn: true,
			params: ['administrationId' => 'adm-1']
		);

		self::assertSame(Http::STATUS_BAD_REQUEST, $controller->saldo()->getStatus());

	}//end testMissingPeriodIdIsABadRequest()

	/**
	 * A non-"not found" `\RuntimeException` from the service no longer
	 * reaches the client as raw exception text (ADR-050 / security-endpoint-
	 * guards REQ-003) — it is mapped to a stable slug + localized message,
	 * and the real exception is logged server-side only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 */
	public function testInvalidRuntimeExceptionDoesNotLeakRawExceptionText(): void {
		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturn(true);

		$service = $this->createMock(GRIRClearingService::class);
		$service->method('reconcileGRIRSaldoForPeriod')->willThrowException(
			new \RuntimeException('SQLSTATE[42S02]: internal detail the client must never see')
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $name, $default = '') {
				$params = ['administrationId' => 'adm-1', 'periodId' => '2026-Q2'];
				return ($params[$name] ?? $default);
			}
		);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$controller = new GRIRReconciliationController(
			request: $request,
			grirClearingService: $service,
			administrationContext: $administrationContext,
			userSession: $userSession,
			logger: $logger,
			l10n: $l10n,
		);

		$response = $controller->saldo();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('grir-saldo-invalid-request', $response->getData()['error']);
		self::assertStringNotContainsStringIgnoringCase(
			'SQLSTATE',
			(string)json_encode($response->getData())
		);

	}//end testInvalidRuntimeExceptionDoesNotLeakRawExceptionText()
}//end class
