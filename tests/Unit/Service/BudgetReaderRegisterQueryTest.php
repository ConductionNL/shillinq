<?php

/**
 * Characterisation tests for the register query the budget readers share.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/budget-projection-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\BbvProgrammeBudgetReader;
use OCA\Shillinq\Service\BudgetGridReader;
use OCA\Shillinq\Service\BudgetProjectionReader;
use OCA\Shillinq\Service\BudgetScenarioReader;
use OCA\Shillinq\Service\BudgetVsActualsReader;
use OCA\Shillinq\Service\CashflowExportService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Pins the private `query()` every budget reader runs against the shillinq
 * register: register slug from app config, schema and property filters
 * passed through, entity rows unwrapped, junk dropped, and a failing read
 * logged under the reader's own name and answered with no rows.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BudgetReaderRegisterQueryTest extends TestCase {

	/**
	 * The readers that carry the query.
	 *
	 * @return array<string,array{0:class-string,1:string}> Cases.
	 */
	public static function readers(): array {
		return [
			'bbv programme budget' => [BbvProgrammeBudgetReader::class, 'BbvProgrammeBudgetReader'],
			'budget grid'          => [BudgetGridReader::class, 'BudgetGridReader'],
			'budget projection'    => [BudgetProjectionReader::class, 'BudgetProjectionReader'],
			'budget scenario'      => [BudgetScenarioReader::class, 'BudgetScenarioReader'],
			'budget vs actuals'    => [BudgetVsActualsReader::class, 'BudgetVsActualsReader'],
			'cashflow export'      => [CashflowExportService::class, 'CashflowExportService'],
		];

	}//end readers()

	/**
	 * Build the reader with the given core services and mocks for the rest.
	 *
	 * @param class-string         $class    The reader class.
	 * @param array<string,object> $services appConfig, logger, objectService.
	 *
	 * @return object The reader.
	 */
	private function reader(string $class, array $services): object {
		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
			$args[$parameter->getName()] = $services[$parameter->getName()] ?? $this->createMock($type->getName());
		}

		return new $class(...$args);

	}//end reader()

	/**
	 * Invoke the private query.
	 *
	 * @param object              $reader  The reader.
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return mixed The rows.
	 */
	private function query(object $reader, string $schema, array $filters): mixed {
		$method = (new ReflectionClass($reader))->getMethod('query');
		$method->setAccessible(true);

		return $method->invoke($reader, $schema, $filters);

	}//end query()

	/**
	 * App config answering the register slug.
	 *
	 * @param string $slug The configured slug.
	 *
	 * @return IAppConfig The config mock.
	 */
	private function config(string $slug): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())
			->method('getValueString')
			->with('shillinq', 'register', 'shillinq')
			->willReturn($slug);

		return $config;

	}//end config()

	/**
	 * Rows are scoped, filtered, unwrapped and cleaned.
	 *
	 * @param class-string $class The reader class.
	 * @param string       $name  The reader's log name.
	 *
	 * @return void
	 */
	#[DataProvider('readers')]
	public function testRowsAreScopedAndUnwrapped(string $class, string $name): void {
		$entity = new class {
			/**
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return ['id' => 'e-1'];
			}
		};
		$opaque = new class {
			/**
			 * @return string
			 */
			public function getObject(): string {
				return 'not-an-array';
			}
		};

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects(self::once())->method('setRegister')->with('books')->willReturnSelf();
		$objectService->expects(self::once())->method('setSchema')->with('GLLine')->willReturnSelf();
		$objectService->expects(self::once())
			->method('findAll')
			->with(['filters' => ['administrationId' => 'a-1']])
			->willReturn([['id' => 'r-1'], 'junk', $entity, $opaque, new \stdClass(), 7]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('error');

		$reader = $this->reader($class, ['appConfig' => $this->config('books'), 'logger' => $logger, 'objectService' => $objectService]);

		self::assertSame([['id' => 'r-1'], ['id' => 'e-1']], $this->query($reader, 'GLLine', ['administrationId' => 'a-1']));

	}//end testRowsAreScopedAndUnwrapped()

	/**
	 * An empty configured slug falls back to `shillinq`.
	 *
	 * @param class-string $class The reader class.
	 * @param string       $name  The reader's log name.
	 *
	 * @return void
	 */
	#[DataProvider('readers')]
	public function testEmptySlugFallsBackToShillinq(string $class, string $name): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects(self::once())->method('setRegister')->with('shillinq')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn([]);

		$reader = $this->reader($class, ['appConfig' => $this->config(''), 'logger' => $this->createMock(LoggerInterface::class), 'objectService' => $objectService]);

		self::assertSame([], $this->query($reader, 'Account', []));

	}//end testEmptySlugFallsBackToShillinq()

	/**
	 * A failing read is logged under the reader's name and yields no rows.
	 *
	 * @param class-string $class The reader class.
	 * @param string       $name  The reader's log name.
	 *
	 * @return void
	 */
	#[DataProvider('readers')]
	public function testFailureIsLoggedAndEmpty(string $class, string $name): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willThrowException(new RuntimeException('schema gone'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('error')
			->with($name . ': failed to query OpenRegister', ['schema' => 'LedgerGroup', 'exception' => 'schema gone']);

		$reader = $this->reader($class, ['appConfig' => $this->config('shillinq'), 'logger' => $logger, 'objectService' => $objectService]);

		self::assertSame([], $this->query($reader, 'LedgerGroup', ['x' => 1]));

	}//end testFailureIsLoggedAndEmpty()
}//end class
