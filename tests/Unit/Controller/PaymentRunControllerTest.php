<?php

/**
 * Unit tests for PaymentRunController.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/payment-run-sepa-export/specs/payment-run-sepa-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PaymentRunController;
use OCA\Shillinq\PaymentRun\PaymentRunExportService;
use OCA\Shillinq\PaymentRun\PaymentRunReconciliationService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests REQ-SEPA-006 / REQ-SEPA-007 — the ADR-005 authorisation guard.
 */
class PaymentRunControllerTest extends TestCase {
	/**
	 * A fluent ObjectService stub returning a fixed PaymentRun on find().
	 *
	 * @param array<string, mixed>|null $run The run to return (null = not found).
	 *
	 * @return object
	 */
	private function objectServiceReturning(?array $run): object {
		return new class($run) {
			/**
			 * @param array<string,mixed>|null $run The run.
			 */
			public function __construct(
				private ?array $run,
			) {
			}//end __construct()

			public function setRegister(string $r): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $s): self {
				return $this;
			}//end setSchema()

			/**
			 * @param string $id The id.
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id): ?array {
				return $this->run;
			}//end find()
		};
	}//end objectServiceReturning()

	/**
	 * Build the controller with the given collaborators.
	 *
	 * @param array<string, mixed>|null $run The PaymentRun find() result.
	 * @param bool $canAccess canAccess() return value.
	 * @param PaymentRunExportService $export Export service (mock).
	 * @param PaymentRunReconciliationService $reconcile Reconciliation service (mock).
	 * @param IRequest|null $request Optional request stub (default: an
	 *                               unconfigured mock, params/uploads all
	 *                               empty) — pass a stubbed one when a test
	 *                               needs a request param, e.g. reconcile()'s
	 *                               `contents` fallback.
	 *
	 * @return PaymentRunController
	 */
	private function controller(
		?array $run,
		bool $canAccess,
		PaymentRunExportService $export,
		PaymentRunReconciliationService $reconcile,
		?IRequest $request = null,
	): PaymentRunController {
		$objects   = $this->objectServiceReturning($run);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$adminContext = $this->createMock(AdministrationContextService::class);
		$adminContext->method('canAccess')->willReturn($canAccess);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new PaymentRunController(
			($request ?? $this->createMock(IRequest::class)),
			$export,
			$reconcile,
			$adminContext,
			$session,
			$this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($objects),
		);
	}//end controller()

	/**
	 * HAPPY: an authorised export delegates to the service and returns 200.
	 *
	 * @return void
	 */
	public function testAuthorisedExportSucceeds(): void {
		$export = $this->createMock(PaymentRunExportService::class);
		$export->expects(self::once())->method('export')->willReturn(
			['exportedFileRef' => '/x.xml', 'exportedAt' => 'now', 'lifecycleState' => 'exported', 'files' => []]
		);

		$controller = $this->controller(
			['id' => 'pr-1', 'administrationId' => 'adm-1', 'lifecycleState' => 'approved'],
			true,
			$export,
			$this->createMock(PaymentRunReconciliationService::class),
		);

		$response = $controller->export('pr-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('exported', $response->getData()['lifecycleState']);
	}//end testAuthorisedExportSucceeds()

	/**
	 * ERROR: an unauthorised export is rejected (masked 404) BEFORE the service
	 * is invoked.
	 *
	 * @return void
	 */
	public function testUnauthorisedExportRejected(): void {
		$export = $this->createMock(PaymentRunExportService::class);
		$export->expects(self::never())->method('export');

		$controller = $this->controller(
			['id' => 'pr-1', 'administrationId' => 'adm-other', 'lifecycleState' => 'approved'],
			false,
			$export,
			$this->createMock(PaymentRunReconciliationService::class),
		);

		$response = $controller->export('pr-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testUnauthorisedExportRejected()

	/**
	 * EDGE: a non-approved run surfaces the service's not-approved rejection as
	 * a 409 conflict.
	 *
	 * @return void
	 */
	public function testDraftExportReturnsConflict(): void {
		$export = $this->createMock(PaymentRunExportService::class);
		$export->method('export')->willReturn(['error' => 'not-approved', 'state' => 'draft']);

		$controller = $this->controller(
			['id' => 'pr-1', 'administrationId' => 'adm-1', 'lifecycleState' => 'draft'],
			true,
			$export,
			$this->createMock(PaymentRunReconciliationService::class),
		);

		$response = $controller->export('pr-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('not-approved', $response->getData()['error']);
	}//end testDraftExportReturnsConflict()

	/**
	 * ERROR: an unauthorised reconcile is rejected (masked 404) BEFORE the
	 * service is invoked.
	 *
	 * @return void
	 */
	public function testUnauthorisedReconcileRejected(): void {
		$reconcile = $this->createMock(PaymentRunReconciliationService::class);
		$reconcile->expects(self::never())->method('reconcile');

		$controller = $this->controller(
			['id' => 'pr-1', 'administrationId' => 'adm-other', 'lifecycleState' => 'exported'],
			false,
			$this->createMock(PaymentRunExportService::class),
			$reconcile,
		);

		$response = $controller->reconcile('pr-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testUnauthorisedReconcileRejected()

	/**
	 * HAPPY: an authorised reconcile (positive control for the
	 * security-endpoint-guards ADR-005 guard — REQ-004) still delegates to the
	 * service and returns 200 exactly as before the guard existed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-004
	 */
	public function testAuthorisedReconcileSucceeds(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturn(null);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'contents' ? '<Document/>' : $default)
		);

		$reconcile = $this->createMock(PaymentRunReconciliationService::class);
		$reconcile->expects(self::once())->method('reconcile')->willReturn(
			['reconciledAt' => 'now', 'lifecycleState' => 'reconciled', 'matchedCount' => 1, 'unmatched' => []]
		);

		$controller = $this->controller(
			['id' => 'pr-1', 'administrationId' => 'adm-1', 'lifecycleState' => 'exported'],
			true,
			$this->createMock(PaymentRunExportService::class),
			$reconcile,
			$request,
		);

		$response = $controller->reconcile('pr-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('reconciled', $response->getData()['lifecycleState']);
	}//end testAuthorisedReconcileSucceeds()
}//end class
