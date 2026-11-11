<?php

/**
 * Golden tests for RelationController's scope check: refusals, the membership check, the period and the role.
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
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\RelationController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Relation\RelationBothSidesService;
use OCA\Shillinq\Service\Relation\RelationLinkService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins what the shared scope check answers, allowed and refused, and what it reads in which order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class RelationControllerScopeGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Run the scope check once.
	 *
	 * @param array<string,mixed> $setup user, params, context, member (list of accessible ids).
	 *
	 * @return array<string,mixed>|JSONResponse
	 */
	private function scope(array $setup=[]): array|JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($setup): mixed {
				$this->calls[] = ['getParam', $key, $default];
				return ($setup['params'][$key] ?? $default);
			}
		);
		$context = $this->createMock(AdministrationContextService::class);
		$user    = 'u1';
		if (array_key_exists('user', $setup) === true) {
			$user = $setup['user'];
		}

		$context->method('currentUserId')->willReturnCallback(
			function () use ($user): ?string {
				$this->calls[] = ['currentUserId'];
				return $user;
			}
		);
		$context->method('buildContext')->willReturnCallback(
			function () use ($setup): array {
				$this->calls[] = ['buildContext'];
				return ($setup['context'] ?? ['activeAdministrationId' => 'a1', 'administrations' => [['administrationId' => 'a1', 'role' => 'inkijker']]]);
			}
		);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return in_array($administrationId, ($setup['member'] ?? ['a1']), true);
			}
		);
		$controller = new RelationController(
			$request,
			$context,
			$this->createMock(RelationLinkService::class),
			$this->createMock(RelationBothSidesService::class)
		);
		$method     = new ReflectionMethod(RelationController::class, 'authorizeScope');
		$method->setAccessible(true);

		return $method->invoke($controller);
	}//end scope()

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
	 * 401 first; no administration, or a foreign one, is the masked 404 before the dates are read.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		self::assertSame(expected: [401, ['error' => 'Not authenticated']], actual: self::answer($this->scope(setup: ['user' => null])));
		self::assertSame(expected: [['currentUserId']], actual: $this->calls);
		$this->calls = [];
		$actual = self::answer($this->scope(setup: ['context' => ['activeAdministrationId' => ' ']]));
		self::assertSame(expected: [404, ['error' => 'Not found']], actual: $actual);
		self::assertSame(expected: [['currentUserId'], ['buildContext'], ['getParam', 'administrationId', '']], actual: $this->calls);
		$this->calls = [];
		$actual = self::answer($this->scope(setup: ['params' => ['administrationId' => ' a2 ']]));
		self::assertSame(expected: [404, ['error' => 'Not found']], actual: $actual);
		self::assertSame(expected: ['canAccess', 'a2'], actual: end($this->calls));
	}//end testRefusals()

	/**
	 * The period defaults to the current year, takes the day of an ISO moment, and must be ordered.
	 *
	 * @return void
	 */
	public function testPeriod(): void {
		$bad = [400, ['error' => 'Dates must read YYYY-MM-DD, the first before the last']];
		self::assertSame(expected: $bad, actual: self::answer($this->scope(setup: ['params' => ['from' => '2026-02-01', 'to' => '2026-01-31']])));
		self::assertSame(expected: $bad, actual: self::answer($this->scope(setup: ['params' => ['from' => '2026/01/01']])));
		self::assertSame(expected: $bad, actual: self::answer($this->scope(setup: ['params' => ['to' => 'x']])));
		$year  = date('Y');
		$scope = $this->scope();
		self::assertSame(expected: [$year . '-01-01', $year . '-12-31'], actual: [$scope['from'], $scope['to']]);
		$scope = $this->scope(setup: ['params' => ['from' => '2026-03-01T00:00:00Z', 'to' => '2026-03-01T23:59:59+02:00']]);
		self::assertSame(expected: ['2026-03-01', '2026-03-01'], actual: [$scope['from'], $scope['to']]);
	}//end testPeriod()

	/**
	 * The scope: the role of the chosen administration decides the sides and linking; the last matching entry wins.
	 *
	 * @return void
	 */
	public function testScopeAndRole(): void {
		$context = [
			'activeAdministrationId' => 'a1',
			'administrations' => [
				['administrationId' => 'a2', 'role' => 'debiteurenadmin'],
				['administrationId' => 'a2', 'role' => 'crediteurenadmin'],
				['administrationId' => 'a1', 'role' => 'eigenaar'],
			],
		];
		$this->calls = [];
		$params = ['administrationId' => 'a2', 'from' => '2026-01-01', 'to' => '2026-12-31'];
		$scope  = $this->scope(setup: ['context' => $context, 'params' => $params, 'member' => ['a2']]);
		self::assertSame(
			expected: [
				'userId' => 'u1',
				'administrationId' => 'a2',
				'from' => '2026-01-01',
				'to' => '2026-12-31',
				'sides' => ['sent' => false, 'received' => true],
				'mayLink' => true,
			],
			actual: $scope
		);
		self::assertSame(
			expected: [
				['currentUserId'],
				['buildContext'],
				['getParam', 'administrationId', ''],
				['canAccess', 'a2'],
				['getParam', 'from', date('Y') . '-01-01'],
				['getParam', 'to', date('Y') . '-12-31'],
			],
			actual: $this->calls
		);
		$scope = $this->scope(setup: ['context' => ['activeAdministrationId' => ' a1 ']]);
		$actual = [$scope['administrationId'], $scope['sides'], $scope['mayLink']];
		self::assertSame(expected: ['a1', ['sent' => false, 'received' => false], false], actual: $actual);
		$scope = $this->scope();
		self::assertSame(expected: [['sent' => true, 'received' => true], false], actual: [$scope['sides'], $scope['mayLink']]);
	}//end testScopeAndRole()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
