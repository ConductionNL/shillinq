<?php

/**
 * Characterisation tests for the record lookup BudgetBlocker and MandateEnforcer share.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-voorzieningen-claims/specs/bookkeeping-voorzieningen-claims/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\BudgetBlocker;
use OCA\Shillinq\Lifecycle\MandateEnforcer;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins the private findMany()/findOne() of both commitment guards: the
 * register slug, the query shape with and without a limit, the re-indexed
 * rows, a non-array answer, and the debug-logged fail-soft empty result.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CommitmentGuardLookupTest extends TestCase {

	/**
	 * Reads made.
	 *
	 * @var list<string>
	 */
	private array $reads = [];

	/**
	 * Debug lines.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $debug = [];

	/**
	 * The guards and their log names.
	 *
	 * @return array<string, array{0: string, 1: string}> Cases.
	 */
	public static function guards(): array {
		return [
			'budget blocker'   => [BudgetBlocker::class, 'BudgetBlocker'],
			'mandate enforcer' => [MandateEnforcer::class, 'MandateEnforcer'],
		];

	}//end guards()

	/**
	 * Build a guard whose object service answers $answer (or throws it).
	 *
	 * @param string $class  The guard class.
	 * @param mixed  $answer What findAll() answers, or a Throwable.
	 *
	 * @return object The guard.
	 */
	private function guard(string $class, mixed $answer): object {
		$test = $this;
		$objectService = new class($test, $answer) {
			public function __construct(
				private readonly CommitmentGuardLookupTest $test,
				private readonly mixed $answer,
			) {
			}

			public function setRegister(string $register): self {
				$this->test->read('register ' . $register);
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->test->read('schema ' . $schema);
				return $this;
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return mixed
			 */
			public function findAll(array $query): mixed {
				$this->test->read('findAll ' . json_encode($query));
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn('');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->debug[] = [$message, $context];
			}
		);

		if ($class === BudgetBlocker::class) {
			return new BudgetBlocker($container, $appConfig, $logger, new MandateEnforcer($container, $appConfig, $logger));
		}

		return new MandateEnforcer($container, $appConfig, $logger);

	}//end guard()

	/**
	 * Record one read.
	 *
	 * @param string $read The read.
	 *
	 * @return void
	 */
	public function read(string $read): void {
		$this->reads[] = $read;

	}//end read()

	/**
	 * Call a private method.
	 *
	 * @param object      $guard  The guard.
	 * @param string      $method The method.
	 * @param list<mixed> $args   The arguments.
	 *
	 * @return mixed The result.
	 */
	private function call(object $guard, string $method, array $args): mixed {
		$reflection = new ReflectionMethod($guard, $method);
		$reflection->setAccessible(true);

		return $reflection->invokeArgs($guard, $args);

	}//end call()

	/**
	 * Every branch of the lookup.
	 *
	 * @param string $class The guard class.
	 * @param string $name  The log name.
	 *
	 * @return void
	 */
	#[DataProvider('guards')]
	public function testLookup(string $class, string $name): void {
		$rows = ['x' => ['id' => 'a'], 'y' => ['id' => 'b']];
		self::assertSame([['id' => 'a'], ['id' => 'b']], $this->call($this->guard($class, $rows), 'findMany', ['S', ['k' => 'v']]));
		self::assertSame([['id' => 'a'], ['id' => 'b']], $this->call($this->guard($class, $rows), 'findMany', ['S', [], 0]));
		self::assertSame(['id' => 'a'], $this->call($this->guard($class, $rows), 'findOne', ['T', ['n' => 1]]));
		self::assertNull($this->call($this->guard($class, []), 'findOne', ['T', ['n' => 2]]));
		self::assertSame([], $this->call($this->guard($class, 'not-a-list'), 'findMany', ['U', [], 5]));
		self::assertSame(
			[
				'register shillinq', 'schema S', 'findAll {"filters":{"k":"v"}}',
				'register shillinq', 'schema S', 'findAll {"filters":[]}',
				'register shillinq', 'schema T', 'findAll {"filters":{"n":1},"limit":1}',
				'register shillinq', 'schema T', 'findAll {"filters":{"n":2},"limit":1}',
				'register shillinq', 'schema U', 'findAll {"filters":[],"limit":5}',
			],
			$this->reads
		);
		self::assertSame([], $this->debug);

		self::assertSame([], $this->call($this->guard($class, new RuntimeException('schema missing')), 'findMany', ['V', []]));
		self::assertNull($this->call($this->guard($class, new RuntimeException('schema missing')), 'findOne', ['V', []]));
		self::assertSame(
			array_fill(0, 2, [$name . ': schema lookup unavailable — treating as absent', ['schema' => 'V', 'exception' => 'schema missing']]),
			$this->debug
		);

	}//end testLookup()
}//end class
