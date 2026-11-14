<?php

/**
 * Golden tests for TaxPaymentController::reconcile: check order, bodies, arguments and log line.
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-35
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\TaxPaymentController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\TaxPaymentReconciliationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins every answer of the reconcile action, allowed and refused, and what reaches the service.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class TaxPaymentControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Call reconcile once.
	 *
	 * @param string                      $id         The path id.
	 * @param mixed                       $admin      The administration_id parameter.
	 * @param bool                        $loggedIn   Whether a user is logged in.
	 * @param bool                        $member     What canAccess answers.
	 * @param array<string,mixed>|\Throwable $outcome What the service answers, or throws.
	 *
	 * @return JSONResponse
	 */
	private function reconcile(
		string $id,
		mixed $admin,
		bool $loggedIn=true,
		bool $member=true,
		array|\Throwable $outcome=['matched' => true]
	): JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($admin): mixed {
				$this->calls[] = ['getParam', $key, $default];
				return $admin;
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($loggedIn === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($member): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return $member;
			}
		);
		$service = $this->createMock(TaxPaymentReconciliationService::class);
		$service->method('reconcile')->willReturnCallback(
			function (string $administrationId, string $paymentId) use ($outcome): array {
				$this->calls[] = ['reconcile', $administrationId, $paymentId];
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				return $outcome;
			}
		);
		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		return (new TaxPaymentController($request, $service, $session, $context, $logger))->reconcile($id);
	}//end reconcile()

	/**
	 * Status and body of a response.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private static function answer(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * Refusals in check order: 401, then the two 400s, then the masked 404; none reaches the service.
	 *
	 * @return void
	 */
	public function testRefusalsInOrder(): void {
		$response = $this->reconcile(id: '!', admin: '!', loggedIn: false);
		self::assertSame(expected: [401, ['error' => 'Authentication required']], actual: self::answer($response));
		self::assertSame(expected: [], actual: $this->calls);
		$badAdmin = [400, ['error' => 'administration_id must be a valid administration identifier']];
		self::assertSame(expected: $badAdmin, actual: self::answer($this->reconcile(id: '!', admin: '  ')));
		self::assertSame(expected: $badAdmin, actual: self::answer($this->reconcile(id: 'p1', admin: str_repeat('a', 65))));
		$badId = [400, ['error' => 'id must be a valid payment identifier']];
		self::assertSame(expected: $badId, actual: self::answer($this->reconcile(id: '   ', admin: 'adm')));
		self::assertSame(expected: $badId, actual: self::answer($this->reconcile(id: str_repeat('p', 129), admin: 'adm')));
		self::assertSame(expected: $badId, actual: self::answer($this->reconcile(id: 'p/1', admin: 'adm', member: false)));
		self::assertSame(expected: [], actual: array_filter($this->calls, fn (array $c): bool => $c[0] !== 'getParam'));
		self::assertSame(expected: ['getParam', 'administration_id', ''], actual: $this->calls[0]);
	}//end testRefusalsInOrder()

	/**
	 * A foreign administration is a 404 after the format checks and never reaches the service.
	 *
	 * @return void
	 */
	public function testForeignAdministrationMasked(): void {
		$response = $this->reconcile(id: ' p.1 ', admin: ' adm-1 ', member: false);
		self::assertSame(expected: [404, ['error' => 'Administration not found']], actual: self::answer($response));
		self::assertSame(expected: [['getParam', 'administration_id', ''], ['canAccess', 'adm-1']], actual: $this->calls);
	}//end testForeignAdministrationMasked()

	/**
	 * A member gets the service answer as is, for the trimmed ids; 64 / 128 characters are accepted.
	 *
	 * @return void
	 */
	public function testAllowedPassesTrimmedIds(): void {
		$result = ['matched' => false, 'variance' => 1.5, 'extra' => ['x']];
		$response = $this->reconcile(id: ' ' . str_repeat('p', 128), admin: str_repeat('a', 64) . ' ', outcome: $result);
		self::assertSame(expected: [200, $result], actual: self::answer($response));
		self::assertSame(
			expected: [
				['getParam', 'administration_id', ''],
				['canAccess', str_repeat('a', 64)],
				['reconcile', str_repeat('a', 64), str_repeat('p', 128)],
			],
			actual: $this->calls
		);
	}//end testAllowedPassesTrimmedIds()

	/**
	 * A service failure is a 500 without detail and one error line with its context.
	 *
	 * @return void
	 */
	public function testServiceFailure(): void {
		$response = $this->reconcile(id: 'p1', admin: 'adm', outcome: new \RuntimeException('gl down'));
		self::assertSame(expected: [500, ['error' => 'Failed to reconcile payment']], actual: self::answer($response));
		self::assertSame(
			expected: [
				'error',
				'TaxPaymentController: failed to reconcile payment',
				['administrationId' => 'adm', 'paymentId' => 'p1', 'exception' => 'gl down'],
			],
			actual: end($this->calls)
		);
	}//end testServiceFailure()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
