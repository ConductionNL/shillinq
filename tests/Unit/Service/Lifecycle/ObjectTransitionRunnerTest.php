<?php

/**
 * Unit tests for ObjectTransitionRunner: transitions through OpenRegister's engine, as the user or as the system.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service\Lifecycle
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

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Lifecycle;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner
 */
class ObjectTransitionRunnerTest extends TestCase {

	/**
	 * A container that holds the given engine, or none.
	 *
	 * @param object|null $engine The engine.
	 *
	 * @return ContainerInterface
	 */
	private function container(?object $engine): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->with(ObjectTransitionRunner::ENGINE_CLASS)->willReturn($engine !== null);
		if ($engine === null) {
			$container->expects($this->never())->method('get');
		} else {
			$container->method('get')->with(ObjectTransitionRunner::ENGINE_CLASS)->willReturn($engine);
		}

		return $container;
	}//end container()

	/**
	 * An engine that records its calls.
	 *
	 * @return object
	 */
	private static function engine(): object {
		return new class {
			/**
			 * Calls received.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Transition as the user.
			 *
			 * @param string $id     Object id.
			 * @param string $action Action.
			 * @param array  $data   Data.
			 *
			 * @return void
			 */
			public function transition(string $id, string $action, array $data): void {
				$this->calls[] = ['transition', $id, $action, $data];
			}

			/**
			 * Transition as the system.
			 *
			 * @param string $id     Object id.
			 * @param string $action Action.
			 * @param string $app    The app acting.
			 * @param array  $data   Data.
			 *
			 * @return array
			 */
			public function transitionAsSystem(string $id, string $action, string $app, array $data): array {
				$this->calls[] = ['transitionAsSystem', $id, $action, $app, $data];
				return ['state' => 'approved'];
			}
		};
	}//end engine()

	/**
	 * A user transition reaches the engine with its id, action and data.
	 *
	 * @return void
	 */
	public function testRunPassesTheTransitionToTheEngine(): void {
		$engine = self::engine();

		(new ObjectTransitionRunner($this->container($engine)))->run('inv-1', 'approve', ['note' => 'ok']);

		$this->assertSame([['transition', 'inv-1', 'approve', ['note' => 'ok']]], $engine->calls);
	}//end testRunPassesTheTransitionToTheEngine()

	/**
	 * A system transition names this app and answers the engine's result.
	 *
	 * @return void
	 */
	public function testRunAsSystemNamesTheAppAndAnswersTheResult(): void {
		$engine = self::engine();

		$result = (new ObjectTransitionRunner($this->container($engine)))->runAsSystem('inv-1', 'approve');

		$this->assertSame(['state' => 'approved'], $result);
		$this->assertSame([['transitionAsSystem', 'inv-1', 'approve', AppIdentity::APP_ID, []]], $engine->calls);
	}//end testRunAsSystemNamesTheAppAndAnswersTheResult()

	/**
	 * Without the engine both refuse, saying so.
	 *
	 * @return void
	 */
	public function testWithoutTheEngineBothRefuse(): void {
		$runner = new ObjectTransitionRunner($this->container(null));

		try {
			$runner->run('inv-1', 'approve');
			$this->fail('ran without an engine');
		} catch (RuntimeException $e) {
			$this->assertSame('OpenRegister TransitionEngine is not available', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister TransitionEngine is not available');
		$runner->runAsSystem('inv-1', 'approve');
	}//end testWithoutTheEngineBothRefuse()

	/**
	 * An engine that cannot act as the system refuses a system transition.
	 *
	 * @return void
	 */
	public function testAnEngineWithoutSystemTransitionsRefuses(): void {
		$engine = new class {
			/**
			 * Transition as the user only.
			 *
			 * @param string $id     Object id.
			 * @param string $action Action.
			 * @param array  $data   Data.
			 *
			 * @return void
			 */
			public function transition(string $id, string $action, array $data): void {
			}
		};

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister TransitionEngine cannot run a transition as the system');
		(new ObjectTransitionRunner($this->container($engine)))->runAsSystem('inv-1', 'approve');
	}//end testAnEngineWithoutSystemTransitionsRefuses()
}//end class
