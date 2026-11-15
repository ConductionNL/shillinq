<?php

/**
 * Golden test for the AansluitingController actions.
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
 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\AansluitingController;
use OCA\Shillinq\Service\AansluitingService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every action, allowed and refused, and every session read, parameter read, service call and log line in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class AansluitingControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string,mixed> $params The request parameters.
	 * @param array<string,mixed> $setup  users (list of bool, one per getUser call; default always true), fail (bool).
	 *
	 * @return AansluitingController
	 */
	private function controller(array $params=[], array $setup=[]): AansluitingController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($params): mixed {
				$this->calls[] = ['getParam', $key, $default];
				return ($params[$key] ?? $default);
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u1');
		$users   = ($setup['users'] ?? null);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use ($user, &$users): ?IUser {
				$this->calls[] = ['getUser'];
				if ($users === null) {
					return $user;
				}

				if (array_shift($users) === true) {
					return $user;
				}

				return null;
			}
		);
		$fail    = ($setup['fail'] ?? false);
		$service = $this->createMock(AansluitingService::class);
		foreach (['compute', 'explain', 'resolve', 'reopen'] as $method) {
			$service->method($method)->willReturnCallback(
				function (string ...$args) use ($method, $fail): array {
					$this->calls[] = array_merge([$method], $args);
					if ($fail === true) {
						throw new RuntimeException('store down');
					}

					return ['done' => $method];
				}
			);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		return new AansluitingController($request, $service, $session, $logger);
	}//end controller()

	/**
	 * Status and body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private static function answer(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * No session is a 401 and a blank or malformed path id a 400, on every action, before any parameter read.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$actions = ['compute' => 'reconciliationId', 'explain' => 'resultId', 'resolve' => 'resultId', 'reopen' => 'resultId'];
		foreach ($actions as $action => $label) {
			$this->calls = [];
			$actual      = self::answer($this->controller(setup: ['users' => [false]])->$action('r1'));
			self::assertSame(expected: [401, ['error' => 'Authentication required']], actual: $actual);
			self::assertSame(expected: [400, ['error' => $label . ' is required']], actual: self::answer($this->controller()->$action('')));
			$actual = self::answer($this->controller()->$action('r/1'));
			self::assertSame(expected: [400, ['error' => $label . ' must be a valid identifier']], actual: $actual);
			self::assertSame(expected: [['getUser'], ['getUser'], ['getUser']], actual: $this->calls);
		}
	}//end testRefusals()

	/**
	 * Compute checks the period after the path id; explain needs a reason text, read after the code.
	 *
	 * @return void
	 */
	public function testParameterRefusals(): void {
		self::assertSame(expected: [400, ['error' => 'period_id is required']], actual: self::answer($this->controller()->compute('r1')));
		$actual = self::answer($this->controller(params: ['period_id' => 'Q 2'])->compute('r1'));
		self::assertSame(expected: [400, ['error' => 'period_id must be a valid identifier']], actual: $actual);
		$actual = self::answer($this->controller(params: ['reason_code' => 'timing', 'reason_text' => '  '])->explain('x1'));
		self::assertSame(expected: [400, ['error' => 'reason_text is required']], actual: $actual);
		$expected = [
			['getUser'],
			['getParam', 'period_id', ''],
			['getUser'],
			['getParam', 'period_id', ''],
			['getUser'],
			['getParam', 'reason_code', ''],
			['getParam', 'reason_text', ''],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testParameterRefusals()

	/**
	 * Each action's service call, with the trimmed parameters and the acting user ('unknown' once the session is gone).
	 *
	 * @return void
	 */
	public function testCalls(): void {
		$actual = self::answer($this->controller(params: ['period_id' => ' 2026-Q2 '])->compute('r1'));
		self::assertSame(expected: [200, ['done' => 'compute']], actual: $actual);
		$params = ['reason_code' => ' timing ', 'reason_text' => ' late '];
		self::assertSame(expected: [200, ['done' => 'explain']], actual: self::answer($this->controller(params: $params)->explain('x1')));
		self::assertSame(expected: [200, ['done' => 'resolve']], actual: self::answer($this->controller()->resolve('x2')));
		$actual = self::answer($this->controller(params: ['reason' => ' redo '], setup: ['users' => [true, false]])->reopen('x3'));
		self::assertSame(expected: [200, ['done' => 'reopen']], actual: $actual);
		$expected = [
			['getUser'],
			['getParam', 'period_id', ''],
			['compute', 'r1', '2026-Q2'],
			['getUser'],
			['getParam', 'reason_code', ''],
			['getParam', 'reason_text', ''],
			['getUser'],
			['explain', 'x1', 'timing', 'late', 'u1'],
			['getUser'],
			['getUser'],
			['resolve', 'x2', 'u1'],
			['getUser'],
			['getParam', 'reason', ''],
			['getUser'],
			['reopen', 'x3', 'unknown', 'redo'],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testCalls()

	/**
	 * A failed service call is a 500 naming the action, logged with the action's context.
	 *
	 * @return void
	 */
	public function testFailures(): void {
		$cases = [
			['compute', 'r1', 'compute aansluiting', ['reconciliationId' => 'r1', 'periodId' => 'p1']],
			['explain', 'x1', 'explain aansluiting result', ['resultId' => 'x1']],
			['resolve', 'x1', 'resolve aansluiting result', ['resultId' => 'x1']],
			['reopen', 'x1', 'reopen aansluiting result', ['resultId' => 'x1']],
		];
		$params = ['period_id' => 'p1', 'reason_code' => 'c', 'reason_text' => 't'];
		foreach ($cases as [$action, $id, $label, $context]) {
			$this->calls = [];
			$actual      = self::answer($this->controller(params: $params, setup: ['fail' => true])->$action($id));
			self::assertSame(expected: [500, ['error' => 'Failed to ' . $label]], actual: $actual);
			$log = ['error', 'AansluitingController: failed to ' . $label, $context + ['exception' => 'store down']];
			self::assertSame(expected: $log, actual: $this->calls[count($this->calls) - 1]);
		}
	}//end testFailures()
}//end class
