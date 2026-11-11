<?php

/**
 * Golden test for the WidgetSettingsController key actions.
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

use OCA\Shillinq\Controller\WidgetSettingsController;
use OCA\Shillinq\Service\WidgetAuthService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins every action's 400, its service call with the acting user, and the status for each result.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class WidgetSettingsControllerGoldenTest extends TestCase {

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
	 * @param array<string,mixed> $setup  user (bool), success (mixed).
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
			$user->method('getUID')->willReturn('admin');
		}

		$session->method('getUser')->willReturn($user);
		$success = ($setup['success'] ?? true);
		$auth    = $this->createMock(WidgetAuthService::class);
		foreach (['createApiKey', 'rotateApiKey', 'revokeApiKey'] as $method) {
			$auth->method($method)->willReturnCallback(
				function (string ...$args) use ($method, $success): array {
					$this->calls[] = array_merge([$method], $args);
					return ['success' => $success, 'key' => 'k'];
				}
			);
		}

		$response = (new WidgetSettingsController($request, $auth, $session))->$action();
		return [$response->getStatus(), $response->getData()];
	}//end act()

	/**
	 * A blank id is a 400 before any service call.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$both = [400, ['success' => false, 'message' => 'businessId and administrationId are required.']];
		self::assertSame(expected: $both, actual: $this->act(action: 'create', params: ['businessId' => ' b1 ', 'administrationId' => ' ']));
		self::assertSame(expected: $both, actual: $this->act(action: 'create', params: ['administrationId' => 'a1']));
		$one = [400, ['success' => false, 'message' => 'businessId is required.']];
		self::assertSame(expected: $one, actual: $this->act(action: 'rotate', params: ['businessId' => '  ']));
		self::assertSame(expected: $one, actual: $this->act(action: 'revoke', params: []));
		$expected = [
			['getParam', 'businessId', ''],
			['getParam', 'administrationId', ''],
			['getParam', 'businessId', ''],
			['getParam', 'administrationId', ''],
			['getParam', 'businessId', ''],
			['getParam', 'businessId', ''],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testRefusals()

	/**
	 * Each action calls its service with the trimmed ids and the acting user; only success === true is a 200.
	 *
	 * @return void
	 */
	public function testCalls(): void {
		$params = ['businessId' => ' b1 ', 'administrationId' => ' a1 '];
		self::assertSame(expected: [200, ['success' => true, 'key' => 'k']], actual: $this->act(action: 'create', params: $params));
		self::assertSame(expected: [400, ['success' => 1, 'key' => 'k']], actual: $this->act(action: 'rotate', params: $params, setup: ['success' => 1]));
		$actual = $this->act(action: 'revoke', params: $params, setup: ['user' => false, 'success' => false]);
		self::assertSame(expected: [400, ['success' => false, 'key' => 'k']], actual: $actual);
		$expected = [
			['getParam', 'businessId', ''],
			['getParam', 'administrationId', ''],
			['createApiKey', 'a1', 'b1', 'admin'],
			['getParam', 'businessId', ''],
			['rotateApiKey', 'b1', 'admin'],
			['getParam', 'businessId', ''],
			['revokeApiKey', 'b1', 'unknown'],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testCalls()
}//end class
