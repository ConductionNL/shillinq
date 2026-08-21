<?php

/**
 * Contract tests for SpendAnalyticsController::spend().
 *
 * Pins the wire contract of
 * `GET /api/analytics/spend?administration_id=...&dimension=...`: the 200
 * success shape (dimension + label + groups + total), the 400 rejections
 * (missing / malformed administration_id, unknown dimension), the 401
 * anonymous gate, and — the point of the file — the 404 membership guard.
 *
 * The membership tests are POSITIVE CONTROLS, not green ticks. Before the
 * gate-7 fix this endpoint took no administration at all and returned OR's
 * unscoped aggregate to any authenticated Nextcloud user, so
 * testSpendMasksForeignAdministrationAsNotFound() could not have passed on the
 * previous controller: there was no administration_id to refuse and canAccess()
 * was never called. Both assertions below fail if the guard is removed —
 * the status assertion because the body reverts to 200, and the
 * `expects(never())` on the service because the aggregation would run.
 *
 * @contract exercises spendAnalytics#spend (GET /api/analytics/spend)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\SpendAnalyticsController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SpendAnalyticsService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Controller\SpendAnalyticsController
 *
 * @uses \OCA\Shillinq\Service\AdministrationContextService
 * @uses \OCA\Shillinq\Service\SpendAnalyticsService
 */
final class SpendAnalyticsControllerTest extends TestCase {
	/**
	 * Build the controller with mocked dependencies.
	 *
	 * @param string|null $userId The resolved user id (null = anonymous).
	 * @param string $dimension The dimension query parameter value.
	 * @param array<string,mixed> $servicePayload The service payload to return.
	 * @param string $administrationId The administration_id query parameter value.
	 * @param bool $canAccess The membership verdict AdministrationContextService returns.
	 * @param bool $expectNoAggregation Assert the service is never asked to aggregate.
	 *
	 * @return SpendAnalyticsController
	 */
	private function makeController(
		?string $userId,
		string $dimension,
		array $servicePayload,
		string $administrationId = 'ADM-001',
		bool $canAccess = true,
		bool $expectNoAggregation = false,
	): SpendAnalyticsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($dimension, $administrationId) {
				if ($key === 'dimension') {
					return $dimension;
				}

				if ($key === 'administration_id') {
					return $administrationId;
				}

				return $default;
			}
		);

		$service = $this->createMock(SpendAnalyticsService::class);
		if ($expectNoAggregation === true) {
			// The refusal must happen BEFORE any aggregation is dispatched.
			// Without the guard the controller reaches the data layer, and
			// this expectation is what catches it — a status assertion alone
			// would not prove the read never ran.
			$service->expects($this->never())->method('spendBySupplier');
			$service->expects($this->never())->method('spendByCategory');
			$service->expects($this->never())->method('spendByCostCentre');
			$service->expects($this->never())->method('spendByPeriod');
		} else {
			$service->method('spendBySupplier')->willReturn($servicePayload);
			$service->method('spendByCategory')->willReturn($servicePayload);
			$service->method('spendByCostCentre')->willReturn($servicePayload);
			$service->method('spendByPeriod')->willReturn($servicePayload);
		}

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($userId);
		$context->method('canAccess')->willReturn($canAccess);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$logger = $this->createMock(LoggerInterface::class);

		return new SpendAnalyticsController(
			request: $request,
			service: $service,
			context: $context,
			l10n: $l10n,
			logger: $logger
		);

	}//end makeController()

	/**
	 * Happy path — 200 with the view payload + translated label.
	 */
	public function testSpendReturnsPayloadForValidDimension(): void {
		$payload = [
			'dimension' => 'supplier',
			'groups' => [['key' => 'V1', 'amount' => 150.0]],
			'total' => 150.0,
			'backend' => 'postgres',
		];
		$controller = $this->makeController(userId: 'alice', dimension: 'supplier', servicePayload: $payload);

		$response = $controller->spend();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('supplier', $data['dimension']);
		$this->assertSame('Spend by supplier', $data['label']);
		$this->assertSame(150.0, $data['total']);
		$this->assertSame('V1', $data['groups'][0]['key']);
	}//end testSpendReturnsPayloadForValidDimension()

	/**
	 * Unknown dimension — HTTP 400.
	 */
	public function testSpendRejectsUnknownDimension(): void {
		$controller = $this->makeController(userId: 'alice', dimension: '__nope', servicePayload: []);

		$response = $controller->spend();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}//end testSpendRejectsUnknownDimension()

	/**
	 * Anonymous request — HTTP 401.
	 */
	public function testSpendRejectsAnonymous(): void {
		$controller = $this->makeController(userId: null, dimension: 'supplier', servicePayload: []);

		$response = $controller->spend();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testSpendRejectsAnonymous()

	/**
	 * THE GUARD (gate-7). An authenticated user who holds no membership for the
	 * administration they named gets a masked 404 and NO aggregation is
	 * dispatched.
	 *
	 * This is the positive control for the fix. On the pre-fix controller the
	 * request had no administration_id to refuse, canAccess() was never
	 * consulted, and the aggregation ran and returned 200 — so this test could
	 * not have passed. Both halves fail if the guard is deleted.
	 */
	public function testSpendMasksForeignAdministrationAsNotFound(): void {
		$controller = $this->makeController(
			userId: 'mallory',
			dimension: 'supplier',
			servicePayload: ['dimension' => 'supplier', 'groups' => [], 'total' => 0.0, 'backend' => 'postgres'],
			administrationId: 'ADM-OTHER-TENANT',
			canAccess: false,
			expectNoAggregation: true
		);

		$response = $controller->spend();

		// 404, never 403 — a 403 would confirm the administration exists and
		// turn the endpoint into an enumeration oracle for the tenant list.
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertNotSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('Administration not found', $response->getData()['error']);
	}//end testSpendMasksForeignAdministrationAsNotFound()

	/**
	 * The guard runs before the dimension is even parsed: a member-less caller
	 * cannot use an unknown dimension to learn whether the administration
	 * exists (the 404/400 pair would otherwise be an oracle).
	 */
	public function testSpendRefusesForeignAdministrationBeforeValidatingDimension(): void {
		$controller = $this->makeController(
			userId: 'mallory',
			dimension: '__nope',
			servicePayload: [],
			administrationId: 'ADM-OTHER-TENANT',
			canAccess: false,
			expectNoAggregation: true
		);

		$response = $controller->spend();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testSpendRefusesForeignAdministrationBeforeValidatingDimension()

	/**
	 * A missing administration_id is a 400, not a silent app-wide aggregate.
	 */
	public function testSpendRequiresAdministrationId(): void {
		$controller = $this->makeController(
			userId: 'alice',
			dimension: 'supplier',
			servicePayload: [],
			administrationId: '',
			canAccess: true,
			expectNoAggregation: true
		);

		$response = $controller->spend();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('administration_id is required', $response->getData()['error']);
	}//end testSpendRequiresAdministrationId()

	/**
	 * A malformed administration_id is rejected before it reaches canAccess().
	 */
	public function testSpendRejectsMalformedAdministrationId(): void {
		$controller = $this->makeController(
			userId: 'alice',
			dimension: 'supplier',
			servicePayload: [],
			administrationId: '../../../etc/passwd',
			canAccess: true,
			expectNoAggregation: true
		);

		$response = $controller->spend();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('administration_id must be a valid identifier', $response->getData()['error']);
	}//end testSpendRejectsMalformedAdministrationId()

	/**
	 * The proven administration is handed to the data layer, not merely
	 * checked and dropped. A guard that validates the scope and then queries
	 * without it is the same exposure with a longer method.
	 */
	public function testSpendPassesTheProvenAdministrationIntoTheAggregation(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				if ($key === 'dimension') {
					return 'supplier';
				}

				if ($key === 'administration_id') {
					return 'ADM-042';
				}

				return $default;
			}
		);

		$service = $this->createMock(SpendAnalyticsService::class);
		$service->expects($this->once())
			->method('spendBySupplier')
			->with('ADM-042')
			->willReturn(['dimension' => 'supplier', 'groups' => [], 'total' => 0.0, 'backend' => 'postgres']);

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('alice');
		$context->method('canAccess')->willReturn(true);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$controller = new SpendAnalyticsController(
			request: $request,
			service: $service,
			context: $context,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class)
		);

		$response = $controller->spend();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testSpendPassesTheProvenAdministrationIntoTheAggregation()

	/**
	 * The THREE GL-BACKED dimensions must receive the proven administration
	 * too (REQ-GLS-003).
	 *
	 * They used to be dispatched with NO argument at all, because `GLLine`
	 * declared no administration property and a parameter the service could
	 * not honour would have read as a scope that was applied. Written to fail
	 * on that controller in the most direct way available: it did not pass the
	 * value, so `->with('ADM-042')` cannot match.
	 *
	 * @dataProvider glBackedDimensions
	 *
	 * @param string $dimension The dimension query parameter.
	 * @param string $method The service method it must dispatch to.
	 *
	 * @return void
	 */
	public function testGlBackedDimensionsAlsoReceiveTheProvenAdministration(string $dimension, string $method): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($dimension) {
				if ($key === 'dimension') {
					return $dimension;
				}

				if ($key === 'administration_id') {
					return 'ADM-042';
				}

				return $default;
			}
		);

		$service = $this->createMock(SpendAnalyticsService::class);
		$service->expects($this->once())
			->method($method)
			->with('ADM-042')
			->willReturn(['dimension' => $dimension, 'groups' => [], 'total' => 0.0, 'backend' => 'postgres']);

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('alice');
		$context->method('canAccess')->willReturn(true);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$controller = new SpendAnalyticsController(
			request: $request,
			service: $service,
			context: $context,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->assertSame(Http::STATUS_OK, $controller->spend()->getStatus());
	}//end testGlBackedDimensionsAlsoReceiveTheProvenAdministration()

	/**
	 * The three GLLine-sourced dimensions and their service methods.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function glBackedDimensions(): array {
		return [
			'category' => ['category', 'spendByCategory'],
			'costCentre' => ['costCentre', 'spendByCostCentre'],
			'period' => ['period', 'spendByPeriod'],
		];
	}//end glBackedDimensions()
}//end class
