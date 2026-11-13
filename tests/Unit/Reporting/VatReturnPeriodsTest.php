<?php

/**
 * Unit tests for how the VAT return file finds its return by period.
 *
 * A report period is a quarter (2026-Q3), a month (2026-07) or a year
 * (2026). The file is built from the filed return of that period, else its
 * draft; a period of any other shape, or one without a return, is refused.
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
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\GeneratedFile;
use OCA\Shillinq\Reporting\Generator\VatReturnReportGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Period matching of the VAT return file.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(VatReturnReportGenerator::class)]
#[UsesClass(GeneratedFile::class)]
final class VatReturnPeriodsTest extends TestCase {

	/**
	 * The generator over fixed returns and no declarations.
	 *
	 * @param list<array<string, mixed>> $returns The BtwAangifte rows.
	 *
	 * @return VatReturnReportGenerator
	 */
	private function generator(array $returns): VatReturnReportGenerator {
		$objectService = new class(['BtwAangifte' => $returns]) {
			private string $schema = '';

			/**
			 * @param array<string, list<mixed>> $rows The rows.
			 */
			public function __construct(
				private readonly array $rows,
			) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return list<mixed>
			 */
			public function findAll(array $config): array {
				return $this->rows[$this->schema] ?? [];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new VatReturnReportGenerator($container, new NullLogger());
	}//end generator()

	/**
	 * The return number and status the file was built from.
	 *
	 * @param VatReturnReportGenerator $generator The generator.
	 * @param string                   $period    The report period.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function builtFrom(VatReturnReportGenerator $generator, string $period): array {
		$xml = simplexml_load_string($generator->generate(['period' => $period, 'administrationId' => 'adm-1'], 'xml')->content);
		return [(string)$xml['aangifte'], (string)$xml['status']];
	}//end builtFrom()

	/**
	 * It registers as the XML-only VAT return.
	 *
	 * @return void
	 */
	public function testItIsTheXmlOnlyVatReturn(): void {
		self::assertSame('vat-return', VatReturnReportGenerator::reportType());
		self::assertSame(['xml'], VatReturnReportGenerator::supportedFormats());
	}//end testItIsTheXmlOnlyVatReturn()

	/**
	 * A month period finds the month's return, and a filed one wins over a draft.
	 *
	 * @return void
	 */
	public function testAMonthFindsItsFiledReturnBeforeItsDraft(): void {
		$generator = $this->generator(
			[
				['returnNumber' => 'M-06', 'period' => 'month', 'periodYear' => 2026, 'periodNumber' => 6, 'statusCode' => 'filed'],
				['returnNumber' => 'M-07-draft', 'period' => 'month', 'periodYear' => 2026, 'periodNumber' => 7],
				['returnNumber' => 'M-07-filed', 'period' => 'month', 'periodYear' => 2026, 'periodNumber' => 7, 'statusCode' => 'filed'],
				['returnNumber' => 'Q-03', 'period' => 'quarter', 'periodYear' => 2026, 'periodNumber' => 3, 'statusCode' => 'filed'],
			]
		);

		self::assertSame(['M-07-filed', 'filed'], $this->builtFrom($generator, '2026-07'));
		self::assertSame(['Q-03', 'filed'], $this->builtFrom($generator, '2026-Q3'));
	}//end testAMonthFindsItsFiledReturnBeforeItsDraft()

	/**
	 * A year period ignores the period number and takes the first draft when nothing is filed.
	 *
	 * @return void
	 */
	public function testAYearTakesTheFirstDraftWhateverItsNumber(): void {
		$generator = $this->generator(
			[
				['returnNumber' => 'Y-2025', 'period' => 'year', 'periodYear' => 2025, 'statusCode' => 'filed'],
				['returnNumber' => 'Y-2026-a', 'period' => 'year', 'periodYear' => 2026, 'periodNumber' => 9, 'statusCode' => 'draft'],
				['returnNumber' => 'Y-2026-b', 'period' => 'year', 'periodYear' => 2026, 'statusCode' => 'draft'],
			]
		);

		self::assertSame(['Y-2026-a', 'draft'], $this->builtFrom($generator, '2026'));
	}//end testAYearTakesTheFirstDraftWhateverItsNumber()

	/**
	 * A period of another shape is refused as unprepared, without matching any return.
	 *
	 * @return void
	 */
	public function testAnUnreadablePeriodIsRefused(): void {
		$generator = $this->generator([['returnNumber' => 'any', 'period' => 'month', 'periodYear' => 2026, 'periodNumber' => 7, 'statusCode' => 'filed']]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No VAT return has been prepared for 2026-7.');
		$generator->generate(['period' => '2026-7'], 'xml');
	}//end testAnUnreadablePeriodIsRefused()
}//end class
