<?php

/**
 * Golden test for the BudgetEditingController write actions.
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

use OCA\Shillinq\Budget\BudgetEditingService;
use OCA\Shillinq\Kernel\Exception\BudgetEditRefusedException;
use OCA\Shillinq\Controller\BudgetEditingController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins saveCell, spread and startNextYear, allowed and refused, with every read and service call in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class BudgetEditingControllerGoldenTest extends TestCase {

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
	 * @param array<string,mixed> $setup  user (bool), member (bool), fail ('refuse'|'throw').
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
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$fail    = ($setup['fail'] ?? '');
		$budgets = $this->createMock(BudgetEditingService::class);
		foreach (['saveCell', 'spread', 'startNextYear'] as $method) {
			$budgets->method($method)->willReturnCallback(
				function (mixed ...$args) use ($method, $fail): array {
					$this->calls[] = array_merge([$method], $args);
					if ($fail === 'refuse') {
						throw new BudgetEditRefusedException('Row %s changed meanwhile.', ['r1']);
					}

					if ($fail === 'throw') {
						throw new RuntimeException('store down');
					}

					return ['done' => $method];
				}
			);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $parameters=[]): string => 'T:' . vsprintf($text, $parameters));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		$response = (new BudgetEditingController($request, $budgets, $context, $session, $l10n, $logger))->$action();
		return [$response->getStatus(), $response->getData()];
	}//end act()

	/**
	 * The shared refusals and each action's own 400 or 422, before any service call.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		foreach (['saveCell', 'spread', 'startNextYear'] as $action) {
			$actual = $this->act(action: $action, params: [], setup: ['user' => false]);
			self::assertSame(expected: [401, ['message' => 'T:Not logged in']], actual: $actual);
			self::assertSame(expected: [404, ['message' => 'T:Administration not found']], actual: $this->act(action: $action, params: []));
			$actual = $this->act(action: $action, params: ['administrationId' => ' a2 '], setup: ['member' => false]);
			self::assertSame(expected: [404, ['message' => 'T:Administration not found']], actual: $actual);
		}

		$twelve = [400, ['message' => 'T:Send the twelve amounts the row showed.']];
		$actual = $this->act(action: 'spread', params: ['administrationId' => 'a1', 'expected' => array_fill(0, 11, 1)]);
		self::assertSame(expected: $twelve, actual: $actual);
		self::assertSame(expected: $twelve, actual: $this->act(action: 'spread', params: ['administrationId' => 'a1', 'expected' => 'x']));
		$percent = [422, ['message' => 'T:Enter a percentage, for example 3.']];
		$actual = $this->act(action: 'startNextYear', params: ['administrationId' => 'a1', 'percentage' => 'three']);
		self::assertSame(expected: $percent, actual: $actual);
		self::assertSame(expected: $percent, actual: $this->act(action: 'startNextYear', params: ['administrationId' => 'a1']));
		$calls = array_filter($this->calls, static fn (array $call): bool => in_array($call[0], ['saveCell', 'spread', 'startNextYear'], true));
		self::assertSame(expected: [], actual: $calls);
	}//end testRefusals()

	/**
	 * Each action's service call with its parameters read and cast in the old order.
	 *
	 * @return void
	 */
	public function testCalls(): void {
		$base = ['administrationId' => ' a1 ', 'annualBudgetId' => ' y1 ', 'ledgerGroupId' => ' g1 '];
		$actual = $this->act(action: 'saveCell', params: $base + ['month' => '3', 'amount' => '120', 'expected' => 7]);
		self::assertSame(expected: [200, ['done' => 'saveCell']], actual: $actual);
		$expected = [
			['getParam', 'administrationId', ''],
			['canAccess', 'a1'],
			['getParam', 'administrationId', ''],
			['getParam', 'annualBudgetId', ''],
			['getParam', 'ledgerGroupId', ''],
			['getParam', 'month', 0],
			['getParam', 'amount', 0],
			['getParam', 'expected', 0],
			['saveCell', 'a1', 'y1', 'g1', 3, 120, 7],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
		$this->calls = [];
		$amounts     = array_combine(range(10, 21), array_merge(['5'], array_fill(0, 11, 1)));
		$actual = $this->act(action: 'spread', params: $base + ['yearly' => '60', 'expected' => $amounts]);
		self::assertSame(expected: [200, ['done' => 'spread']], actual: $actual);
		$expected = [
			['getParam', 'administrationId', ''],
			['canAccess', 'a1'],
			['getParam', 'expected', []],
			['getParam', 'administrationId', ''],
			['getParam', 'annualBudgetId', ''],
			['getParam', 'ledgerGroupId', ''],
			['getParam', 'yearly', 0],
			['spread', 'a1', 'y1', 'g1', 60, array_merge([5], array_fill(0, 11, 1))],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
		$this->calls = [];
		$actual = $this->act(action: 'startNextYear', params: $base + ['percentage' => '2.5']);
		self::assertSame(expected: [201, ['done' => 'startNextYear']], actual: $actual);
		$expected = [
			['getParam', 'administrationId', ''],
			['canAccess', 'a1'],
			['getParam', 'percentage', ''],
			['getParam', 'administrationId', ''],
			['getParam', 'annualBudgetId', ''],
			['startNextYear', 'a1', 'y1', 2.5],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testCalls()

	/**
	 * A refusal is a translated 422 and a failure a logged 500.
	 *
	 * @return void
	 */
	public function testFailures(): void {
		$params = ['administrationId' => 'a1', 'percentage' => 1, 'expected' => array_fill(0, 12, 0)];
		foreach (['saveCell', 'spread', 'startNextYear'] as $action) {
			$actual = $this->act(action: $action, params: $params, setup: ['fail' => 'refuse']);
			self::assertSame(expected: [422, ['message' => 'T:Row r1 changed meanwhile.']], actual: $actual);
			$actual = $this->act(action: $action, params: $params, setup: ['fail' => 'throw']);
			self::assertSame(expected: [500, ['message' => 'T:The budget could not be updated.']], actual: $actual);
			$log = ['error', 'BudgetEditingController: request failed', ['exception' => 'store down']];
			self::assertSame(expected: $log, actual: $this->calls[count($this->calls) - 1]);
		}
	}//end testFailures()
}//end class
