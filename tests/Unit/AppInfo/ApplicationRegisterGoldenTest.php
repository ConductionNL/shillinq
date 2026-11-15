<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\AppInfo;

use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Pins every call Application::register() makes on the registration context,
 * in order, with its string arguments.
 *
 * @coversNothing
 */
final class ApplicationRegisterGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/golden/application-register.json';

	public function testRegisterCallsMatchTheRecording(): void {
		$calls = [];
		$context = $this->createMock(IRegistrationContext::class);
		foreach (get_class_methods(IRegistrationContext::class) as $method) {
			$context->method($method)->willReturnCallback(static function (...$args) use (&$calls, $method): void {
				$calls[] = [$method, array_map(static fn ($arg): string => is_string($arg) || is_int($arg) || is_bool($arg) ? var_export($arg, true) : get_debug_type($arg), $args)];
			});
		}

		(new \ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($calls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}
		self::assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $calls);
	}
}
