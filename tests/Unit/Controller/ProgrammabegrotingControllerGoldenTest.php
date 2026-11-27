<?php

/**
 * Golden test for the ProgrammabegrotingController actions.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ProgrammabegrotingController;
use OCA\Shillinq\Service\ProgrammabegrotingService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every action, allowed and refused, with every read, service call and log line in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class ProgrammabegrotingControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Run one action.
	 *
	 * @param string              $action The action.
	 * @param array<string,mixed> $params The request parameters.
	 * @param array<string,mixed> $setup  user (bool), fail (bool).
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function act(string $action, array $params, array $setup=[]): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($params): mixed {
				$this->calls[] = ['getParam', $key, $default];
				return ($params[$key] ?? $default);
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if (($setup['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$fail    = ($setup['fail'] ?? false);
		$service = $this->createMock(ProgrammabegrotingService::class);
		foreach (['sluitendStatus', 'iv3Export', 'jsonExport'] as $method) {
			$service->method($method)->willReturnCallback(
				function (string $administrationId, string $budgetId) use ($method, $fail): mixed {
					$this->calls[] = [$method, $administrationId, $budgetId];
					if ($fail === true) {
						throw new RuntimeException('ledger down');
					}

					return ['from' => $method];
				}
			);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		$response = (new ProgrammabegrotingController($request, $service, $session, $logger))->$action();
		return [$response->getStatus(), $response->getData()];
	}//end act()

	/**
	 * No session is a 401 before any read; the budget id is checked before the administration id.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$budget = [400, ['error' => 'begroting_id is required and must be a valid identifier']];
		$admin  = [400, ['error' => 'administration_id is required and must be a valid identifier']];
		foreach (['sluitend', 'iv3', 'jsonExport'] as $action) {
			$this->calls = [];
			$actual      = $this->act(action: $action, params: [], setup: ['user' => false]);
			self::assertSame(expected: [401, ['error' => 'Authentication required']], actual: $actual);
			self::assertSame(expected: $budget, actual: $this->act(action: $action, params: ['begroting_id' => 'b/1']));
			self::assertSame(expected: $budget, actual: $this->act(action: $action, params: ['administration_id' => 'a1']));
			$actual = $this->act(action: $action, params: ['begroting_id' => 'b1', 'administration_id' => str_repeat('a', 65)]);
			self::assertSame(expected: $admin, actual: $actual);
			self::assertSame(expected: $admin, actual: $this->act(action: $action, params: ['begroting_id' => 'b1']));
			$reads = [['getParam', 'begroting_id', ''], ['getParam', 'administration_id', '']];
			self::assertSame(expected: array_merge($reads, $reads, $reads, $reads), actual: $this->calls);
		}
	}//end testRefusals()

	/**
	 * Each action answers its service result; a failure is a 500 naming it, logged with both ids.
	 *
	 * @return void
	 */
	public function testAnswers(): void {
		$params = ['begroting_id' => ' b1 ', 'administration_id' => ' a1 '];
		self::assertSame(expected: [200, ['from' => 'sluitendStatus']], actual: $this->act(action: 'sluitend', params: $params));
		self::assertSame(expected: [200, ['data' => ['from' => 'iv3Export']]], actual: $this->act(action: 'iv3', params: $params));
		self::assertSame(expected: [200, ['from' => 'jsonExport']], actual: $this->act(action: 'jsonExport', params: $params));
		$failures = [
			['sluitend', 'Failed to compute sluitend status'],
			['iv3', 'Failed to produce iv3 export'],
			['jsonExport', 'Failed to produce JSON export'],
		];
		foreach ($failures as [$action, $failure]) {
			self::assertSame(expected: [500, ['error' => $failure]], actual: $this->act(action: $action, params: $params, setup: ['fail' => true]));
			$log = ['error', 'ProgrammabegrotingController: ' . $failure, ['budgetId' => 'b1', 'administrationId' => 'a1', 'exception' => 'ledger down']];
			self::assertSame(expected: $log, actual: $this->calls[count($this->calls) - 1]);
		}

		self::assertSame(expected: ['sluitendStatus', 'a1', 'b1'], actual: $this->calls[2]);
		self::assertSame(expected: ['iv3Export', 'a1', 'b1'], actual: $this->calls[5]);
	}//end testAnswers()
}//end class
