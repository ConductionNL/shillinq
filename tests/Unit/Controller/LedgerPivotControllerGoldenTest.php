<?php

/**
 * Golden test for LedgerPivotController::pivot.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Controller\LedgerPivotController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Pivot\LedgerPivotService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every answer of pivot, allowed and refused, and every call behind it in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class LedgerPivotControllerGoldenTest extends TestCase {

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
	 * @param array<string,mixed> $setup  user, context (array), member (bool), fail (Throwable).
	 *
	 * @return LedgerPivotController
	 */
	private function controller(array $params, array $setup=[]): LedgerPivotController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($params): mixed {
				$this->calls[] = ['getParam', $key, $default];
				if (array_key_exists($key, $params) === true) {
					return $params[$key];
				}

				return $default;
			}
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturnCallback(
			function () use ($setup): ?string {
				$this->calls[] = ['currentUserId'];
				if (array_key_exists('user', $setup) === true) {
					return $setup['user'];
				}

				return 'u1';
			}
		);
		$context->method('buildContext')->willReturnCallback(
			function () use ($setup): array {
				$this->calls[] = ['buildContext'];
				return ($setup['context'] ?? []);
			}
		);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$fail  = ($setup['fail'] ?? null);
		$pivot = $this->createMock(LedgerPivotService::class);
		$pivot->method('pivot')->willReturnCallback(
			function (string $administrationId, string $rowAxis, string $columnAxis, string $from, string $to) use ($fail): array {
				$this->calls[] = ['pivot', $administrationId, $rowAxis, $columnAxis, $from, $to];
				if ($fail !== null) {
					throw $fail;
				}

				return ['cells' => [1]];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		return new LedgerPivotController($request, $context, $pivot, $logger);
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
	 * No session is a 401 before any parameter is read; no administration at all is a 400.
	 *
	 * @return void
	 */
	public function testRefusedBeforeMembership(): void {
		$actual = self::answer($this->controller(params: ['administrationId' => 'a1'], setup: ['user' => null])->pivot());
		self::assertSame(expected: [401, ['error' => 'Not authenticated']], actual: $actual);
		self::assertSame(expected: [['currentUserId']], actual: $this->calls);
		$this->calls = [];
		$actual = self::answer($this->controller(params: ['administrationId' => ' '], setup: ['context' => ['activeAdministrationId' => ' ']])->pivot());
		self::assertSame(expected: [400, ['error' => 'No administration selected']], actual: $actual);
		$actual = self::answer($this->controller(params: [], setup: ['context' => ['activeAdministrationId' => null]])->pivot());
		self::assertSame(expected: [400, ['error' => 'No administration selected']], actual: $actual);
		$visit    = [['currentUserId'], ['getParam', 'administrationId', ''], ['buildContext']];
		$expected = array_merge($visit, $visit);
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testRefusedBeforeMembership()

	/**
	 * A foreign administration, asked for or active, is masked as 404 before the axes are read.
	 *
	 * @return void
	 */
	public function testForeignAdministration(): void {
		$actual = self::answer($this->controller(params: ['administrationId' => ' a2 '], setup: ['member' => false])->pivot());
		self::assertSame(expected: [404, ['error' => 'Not found']], actual: $actual);
		$actual = self::answer($this->controller(params: [], setup: ['member' => false, 'context' => ['activeAdministrationId' => ' a3 ']])->pivot());
		self::assertSame(expected: [404, ['error' => 'Not found']], actual: $actual);
		$expected = [
			['currentUserId'],
			['getParam', 'administrationId', ''],
			['canAccess', 'a2'],
			['currentUserId'],
			['getParam', 'administrationId', ''],
			['buildContext'],
			['canAccess', 'a3'],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testForeignAdministration()

	/**
	 * A member gets the pivot of the raw axes; a refused request is a 400 with its message; a failure is a logged 500.
	 *
	 * @return void
	 */
	public function testMemberAnswers(): void {
		$params = ['administrationId' => 'a1', 'rows' => 'ledger', 'columns' => 7, 'from' => ' 2026-01-01'];
		$actual = self::answer($this->controller(params: $params)->pivot());
		self::assertSame(expected: [200, ['cells' => [1]]], actual: $actual);
		$expected = [
			['currentUserId'],
			['getParam', 'administrationId', ''],
			['canAccess', 'a1'],
			['getParam', 'rows', ''],
			['getParam', 'columns', ''],
			['getParam', 'from', ''],
			['getParam', 'to', ''],
			['pivot', 'a1', 'ledger', '7', ' 2026-01-01', ''],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
		$this->calls = [];
		$refused = $this->controller(params: $params, setup: ['fail' => new InvalidArgumentException('bad axis')])->pivot();
		self::assertSame(expected: [400, ['error' => 'bad axis']], actual: self::answer($refused));
		$failed = $this->controller(params: $params, setup: ['fail' => new RuntimeException('boom')])->pivot();
		self::assertSame(expected: [500, ['error' => 'Pivot unavailable']], actual: self::answer($failed));
		$log = ['error', 'LedgerPivotController: pivot failed', ['exception' => 'boom']];
		self::assertSame(expected: $log, actual: $this->calls[count($this->calls) - 1]);
		self::assertCount(17, $this->calls);
	}//end testMemberAnswers()
}//end class
