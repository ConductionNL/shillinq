<?php

/**
 * Tests for how BbvJaarstukkenReportGenerator picks its BbvStatement.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\Generator\BbvJaarstukkenReportGenerator;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins the statement choice: explicit id, administration, fiscal year, document type, first.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BbvStatementResolutionTest extends TestCase {

	/**
	 * Resolve the statement for a context over the given BbvStatement rows.
	 *
	 * @param array<int,array<string,mixed>> $rows    The BbvStatement rows.
	 * @param array<string,mixed>            $context The report context.
	 *
	 * @return array<string,mixed>
	 */
	private function resolve(array $rows, array $context): array {
		$store = new InMemoryObjectServiceStub(['BbvStatement' => $rows]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($store): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $store;
				}

				throw new RuntimeException('not available: ' . $id);
			}
		);

		$method = new ReflectionMethod(BbvJaarstukkenReportGenerator::class, 'resolveStatement');
		$method->setAccessible(true);

		return $method->invoke(new BbvJaarstukkenReportGenerator($container), $context);

	}//end resolve()

	/**
	 * The statements of two administrations and years.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function rows(): array {
		return [
			['id' => 'st-a-2025-beg', 'administrationId' => 'adm-a', 'fiscalYear' => 2025, 'documentType' => 'begroting'],
			['id' => 'st-a-2026-beg', 'administrationId' => 'adm-a', 'fiscalYear' => 2026, 'documentType' => 'begroting'],
			['id' => 'st-a-2024-js', 'administrationId' => 'adm-a', 'fiscalYear' => 2024, 'documentType' => 'jaarstukken'],
			['id' => 'st-b-2026-js', 'administrationId' => 'adm-b', 'fiscalYear' => '2026', 'documentType' => 'jaarstukken'],
		];

	}//end rows()

	/**
	 * An explicit statement id wins, under each of its keys; an unknown id falls through.
	 *
	 * @return void
	 */
	public function testAnExplicitIdWins(): void {
		self::assertSame('st-a-2025-beg', $this->resolve(self::rows(), ['statementId' => 'st-a-2025-beg', 'period' => '2026'])['id']);
		self::assertSame('st-a-2025-beg', $this->resolve(self::rows(), ['bbvStatementId' => 'st-a-2025-beg'])['id']);
		self::assertSame('st-a-2025-beg', $this->resolve(self::rows(), ['objectId' => 'st-a-2025-beg'])['id']);
		self::assertSame('st-b-2026-js', $this->resolve(self::rows(), ['statementId' => 'missing', 'administrationId' => 'adm-b'])['id']);

	}//end testAnExplicitIdWins()

	/**
	 * The fiscal year of the period picks within the administration. Every
	 * digit of the period counts, so '2026-12' reads as year 202612 and does
	 * not match 2026 (current behaviour, pinned).
	 *
	 * @return void
	 */
	public function testTheFiscalYearPicksWithinTheAdministration(): void {
		self::assertSame('st-a-2026-beg', $this->resolve(self::rows(), ['administrationId' => 'adm-a', 'period' => '2026'])['id']);
		self::assertSame('st-a-2025-beg', $this->resolve(self::rows(), ['administrationId' => 'adm-a', 'period' => 'FY 2025'])['id']);
		self::assertSame('st-a-2024-js', $this->resolve(self::rows(), ['administrationId' => 'adm-a', 'period' => '2026-12'])['id']);

	}//end testTheFiscalYearPicksWithinTheAdministration()

	/**
	 * Without a year match (or a usable period) a jaarstukken document is preferred, then the first.
	 *
	 * @return void
	 */
	public function testJaarstukkenThenFirstWithoutAYearMatch(): void {
		self::assertSame('st-a-2024-js', $this->resolve(self::rows(), ['administrationId' => 'adm-a', 'period' => '2030'])['id']);
		self::assertSame('st-a-2024-js', $this->resolve(self::rows(), ['administrationId' => 'adm-a', 'period' => 'q4'])['id']);
		self::assertSame('st-a-2024-js', $this->resolve(self::rows(), ['administrationId' => 'adm-a'])['id']);

		$begrotingen = [self::rows()[0], self::rows()[1]];
		self::assertSame('st-a-2025-beg', $this->resolve($begrotingen, ['period' => '2030'])['id']);

	}//end testJaarstukkenThenFirstWithoutAYearMatch()

	/**
	 * An administration without statements falls back to all statements; none at all gives [].
	 *
	 * @return void
	 */
	public function testAnEmptyAdministrationFallsBackToAll(): void {
		self::assertSame('st-a-2026-beg', $this->resolve(self::rows(), ['administrationId' => 'adm-z', 'period' => '2026'])['id']);
		self::assertSame('st-b-2026-js', $this->resolve(self::rows(), ['administrationId' => 'adm-b', 'period' => '2026'])['id']);
		self::assertSame([], $this->resolve([], ['administrationId' => 'adm-z', 'period' => '2026']));
		self::assertSame([], $this->resolve([], []));

	}//end testAnEmptyAdministrationFallsBackToAll()
}//end class
