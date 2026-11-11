<?php

/**
 * Golden test for PreferencesController.
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
 * @spec openspec/specs/apphost-adoption/spec.md#requirement-mechanical-boilerplate-served-by-apphost-generics
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PreferencesController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins both actions, allowed and refused, and every session and config call in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class PrefsControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param bool   $loggedIn Whether a user is logged in.
	 * @param string $stored   What getUserValue answers.
	 *
	 * @return PreferencesController
	 */
	private function controller(bool $loggedIn=true, string $stored=''): PreferencesController {
		$user = null;
		if ($loggedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturnCallback(
				function (): string {
					$this->calls[] = ['getUID'];
					return 'u1';
				}
			);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use ($user): ?IUser {
				$this->calls[] = ['getUser'];
				return $user;
			}
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			function (string $userId, string $appName, string $key, mixed $default='') use ($stored): string {
				$this->calls[] = ['get', $userId, $appName, $key, $default];
				return $stored;
			}
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $userId, string $appName, string $key, mixed $value): void {
				$this->calls[] = ['set', $userId, $appName, $key, $value];
			}
		);
		$config->method('deleteUserValue')->willReturnCallback(
			function (string $userId, string $appName, string $key): void {
				$this->calls[] = ['delete', $userId, $appName, $key];
			}
		);

		return new PreferencesController($this->createMock(IRequest::class), $config, $session);
	}//end controller()

	/**
	 * No session is a 401 and a bad key a 400, before any config call.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$empty = 'Invalid key: must contain at least one alphanumeric character or hyphen.';
		$long  = 'Key too long: maximum 64 characters (after stripping non-alphanumeric characters).';
		foreach (['getPreference', 'setPreference'] as $action) {
			$this->calls = [];
			$response    = $this->controller(loggedIn: false)->$action('k');
			self::assertSame(expected: [401, ['message' => 'Not logged in']], actual: [$response->getStatus(), $response->getData()]);
			$response = $this->controller()->$action('!!_ ');
			self::assertSame(expected: [400, ['message' => $empty]], actual: [$response->getStatus(), $response->getData()]);
			$response = $this->controller()->$action(str_repeat('A', 65));
			self::assertSame(expected: [400, ['message' => $long]], actual: [$response->getStatus(), $response->getData()]);
			self::assertSame(expected: [['getUser'], ['getUser'], ['getUser']], actual: $this->calls);
		}
	}//end testRefusals()

	/**
	 * A read answers the canonical key and the stored value, null when blank.
	 *
	 * @return void
	 */
	public function testGet(): void {
		$response = $this->controller(stored: 'dark')->getPreference('My_Theme-1');
		self::assertSame(expected: [200, ['key' => 'mytheme-1', 'value' => 'dark']], actual: [$response->getStatus(), $response->getData()]);
		$response = $this->controller()->getPreference(str_repeat('a', 64));
		self::assertSame(expected: [200, ['key' => str_repeat('a', 64), 'value' => null]], actual: [$response->getStatus(), $response->getData()]);
		$expected = [
			['getUser'],
			['getUID'],
			['get', 'u1', 'shillinq', 'mytheme-1', ''],
			['getUser'],
			['getUID'],
			['get', 'u1', 'shillinq', str_repeat('a', 64), ''],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testGet()

	/**
	 * A write stores the value; an empty value deletes it and answers null.
	 *
	 * @return void
	 */
	public function testSet(): void {
		$response = $this->controller()->setPreference('Theme', ' dark ');
		self::assertSame(expected: [200, ['key' => 'theme', 'value' => ' dark ']], actual: [$response->getStatus(), $response->getData()]);
		$response = $this->controller()->setPreference('Theme');
		self::assertSame(expected: [200, ['key' => 'theme', 'value' => null]], actual: [$response->getStatus(), $response->getData()]);
		$expected = [
			['getUser'],
			['getUID'],
			['set', 'u1', 'shillinq', 'theme', ' dark '],
			['getUser'],
			['getUID'],
			['delete', 'u1', 'shillinq', 'theme'],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testSet()
}//end class
