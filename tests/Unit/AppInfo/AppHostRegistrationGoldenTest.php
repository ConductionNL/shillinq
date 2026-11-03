<?php

/**
 * Golden test for the AppHost generic-controller and settings wiring.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\AppInfo;

use OCA\Shillinq\AppInfo\AppHostRegistration;
use OCA\Shillinq\AppInfo\OpenRegisterAutoloader;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use stdClass;

/**
 * Pins every service the AppHost wiring registers: its name, the class its
 * factory builds and the named arguments it passes.
 *
 * The OpenRegister generic classes are replaced by recording doubles, declared
 * in a separate process so no other test sees them.
 *
 * @covers \OCA\Shillinq\AppInfo\AppHostRegistration
 * @uses   \OCA\Shillinq\AppInfo\OpenRegisterAutoloader
 * @uses   \OCA\Shillinq\Service\Pipelinq\CustomerBridgeMetricsService
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[RunTestsInSeparateProcesses]
final class AppHostRegistrationGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/app-host-registration.json';

	private const GENERICS = [
		'OCA\\OpenRegister\\AppHost\\Controller\\GenericDashboardController',
		'OCA\\OpenRegister\\AppHost\\Controller\\GenericHealthController',
		'OCA\\OpenRegister\\AppHost\\Controller\\GenericMetricsController',
		'OCA\\OpenRegister\\AppHost\\Settings\\GenericAdminSettings',
		'OCA\\OpenRegister\\AppHost\\Settings\\GenericSettingsSection',
	];

	/**
	 * The wiring registers the same services, built the same way.
	 *
	 * @return void
	 */
	public function testTheWiringMatchesTheGolden(): void {
		foreach (self::GENERICS as $class) {
			$this->declareRecordingDouble(class: $class);
		}

		eval(
			'namespace OCA\\OpenRegister\\AppHost; final class Bootstrap { public static array $calls = [];'
			. ' public static function aliasStoreController(...$args): void { self::$calls[] = $args; } }'
		);

		$registered = [];
		$context    = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$registered): void {
				$registered[] = [$name, $factory];
			}
		);

		(new AppHostRegistration())->register(context: $context);
		OpenRegisterAutoloader::unregister();

		$actual = [];
		foreach ($registered as [$name, $factory]) {
			$actual[] = ['service' => $name, 'built' => $this->describe(value: $factory($this->container()))];
		}

		foreach (\OCA\OpenRegister\AppHost\Bootstrap::$calls as $call) {
			$call['context'] = get_class($call['context']) === get_class($context);
			$actual[]        = ['storeAlias' => $call];
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		self::assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);

	}//end testTheWiringMatchesTheGolden()

	/**
	 * A container whose every entry is a marker naming the id it was asked for.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id): object {
				if (class_exists($id) === true) {
					return (new ReflectionClass($id))->newInstanceWithoutConstructor();
				}

				$marker     = new stdClass();
				$marker->id = $id;
				return $marker;
			}
		);
		return $container;

	}//end container()

	/**
	 * Describe a built service or one of its arguments as plain data.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The description.
	 */
	private function describe(mixed $value): mixed {
		if ($value instanceof stdClass && isset($value->id) === true) {
			return 'get:' . $value->id;
		}

		if (is_object($value) === true && property_exists($value, 'recordedArgs') === true) {
			$args = [];
			foreach ($value->recordedArgs as $key => $arg) {
				$args[$key] = $this->describe(value: $arg);
			}

			return ['class' => get_class($value), 'args' => $args];
		}

		if (is_object($value) === true) {
			return 'instance:' . get_class($value);
		}

		return $value;

	}//end describe()

	/**
	 * Declare a class that records the named arguments it is built with.
	 *
	 * @param string $class The fully qualified class name.
	 *
	 * @return void
	 */
	private function declareRecordingDouble(string $class): void {
		if (class_exists($class) === true) {
			return;
		}

		$cut = strrpos($class, '\\');
		eval(
			'namespace ' . substr($class, 0, $cut) . '; final class ' . substr($class, $cut + 1)
			. ' { public array $recordedArgs; public function __construct(...$args) { $this->recordedArgs = $args; } }'
		);

	}//end declareRecordingDouble()
}//end class
