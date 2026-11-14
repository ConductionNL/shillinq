<?php

/**
 * Unit tests for which FinancialStatement the annual accounts report is built from.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.Commenting.VariableComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\Generator\AnnualAccountsReportGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * A named statement id wins; otherwise the first statement whose period contains the asked period, else the first.
 *
 * @covers \OCA\Shillinq\Reporting\Generator\AnnualAccountsReportGenerator
 * @uses   \OCA\Shillinq\Reporting\Generator\AbstractDocumentReportGenerator
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class AnnualAccountsStatementResolutionTest extends TestCase {

	/**
	 * Every read the store received.
	 *
	 * @var list<array{0: string, 1: mixed}>
	 */
	private array $reads = [];

	/**
	 * The statement the generator resolves for a context over these stored statements.
	 *
	 * @param array<string, mixed>              $context    The report context.
	 * @param list<array<string, mixed>>        $statements What findAll() answers.
	 * @param array<string, array<string, mixed>> $byId     What find() answers per id.
	 *
	 * @return array<string, mixed>
	 */
	private function resolve(array $context, array $statements, array $byId = []): array {
		$reads = &$this->reads;
		$store = new class ($statements, $byId, $reads) {
			public function __construct(private readonly array $statements, private readonly array $byId, private array &$reads) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->reads[] = ['schema', $schema];
				return $this;
			}

			public function findAll(array $config = []): array {
				$this->reads[] = ['findAll', $config];
				return $this->statements;
			}

			public function find(string $id): ?array {
				$this->reads[] = ['find', $id];
				return ($this->byId[$id] ?? null);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($store): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $store;
				}

				throw new \RuntimeException('No service ' . $id);
			}
		);

		$method = new ReflectionMethod(AnnualAccountsReportGenerator::class, 'resolveStatement');

		return $method->invoke(new AnnualAccountsReportGenerator($container), $context);
	}//end resolve()

	/**
	 * Statements for 2024 and 2025.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function statements(): array {
		return [
			['id' => 'fs-2024', 'reportingPeriodStart' => '2024-01-01', 'reportingPeriodEnd' => '2024-12-31'],
			['id' => 'fs-2025', 'reportingPeriodStart' => '2025-01-01', 'reportingPeriodEnd' => '2025-12-31'],
		];
	}//end statements()

	public function testANamedStatementIsLoadedDirectly(): void {
		$statement = $this->resolve(['statementId' => 'fs-x', 'period' => '2025'], self::statements(), ['fs-x' => ['id' => 'fs-x']]);

		$this->assertSame(['id' => 'fs-x'], $statement);
		$this->assertSame([['schema', 'FinancialStatement'], ['find', 'fs-x']], $this->reads, 'No list is read when the named one exists');
	}//end testANamedStatementIsLoadedDirectly()

	public function testTheFinancialStatementIdAliasIsHonoured(): void {
		$this->assertSame(['id' => 'fs-y'], $this->resolve(['financialStatementId' => 'fs-y'], [], ['fs-y' => ['id' => 'fs-y']]));
	}//end testTheFinancialStatementIdAliasIsHonoured()

	public function testAMissingNamedStatementFallsBackToThePeriod(): void {
		$statement = $this->resolve(['statementId' => 'gone', 'period' => '2025'], self::statements());

		$this->assertSame('fs-2025', $statement['id']);
		$this->assertSame(['find', 'gone'], $this->reads[1]);
		$this->assertSame(['findAll', ['limit' => 10000]], $this->reads[3]);
	}//end testAMissingNamedStatementFallsBackToThePeriod()

	public function testThePeriodMatchesTheStartOrTheEnd(): void {
		$this->assertSame('fs-2025', $this->resolve(['period' => '2025-12'], self::statements())['id']);
		$this->assertSame('fs-2024', $this->resolve(['period' => '2024-01'], self::statements())['id']);
	}//end testThePeriodMatchesTheStartOrTheEnd()

	public function testWithoutAMatchingPeriodTheFirstStatementIsUsed(): void {
		$this->assertSame('fs-2024', $this->resolve(['period' => '2030'], self::statements())['id']);
		$this->assertSame('fs-2024', $this->resolve([], self::statements())['id']);
	}//end testWithoutAMatchingPeriodTheFirstStatementIsUsed()

	public function testWithoutStatementsTheResultIsEmpty(): void {
		$this->assertSame([], $this->resolve(['period' => '2025'], []));
	}//end testWithoutStatementsTheResultIsEmpty()
}//end class
