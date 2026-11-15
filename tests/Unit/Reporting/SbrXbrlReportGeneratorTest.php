<?php

/**
 * Unit tests for SbrXbrlReportGenerator: the sbr-xbrl report.
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
 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Kernel\Model\GeneratedFile;
use OCA\Shillinq\Reporting\Generator\SbrXbrlReportGenerator;
use OCA\Shillinq\Service\Sbr\AnnualAccountsXbrlBuilder;
use OCA\Shillinq\Service\Sbr\SbrRecords;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SbrXbrlReportGenerator::class)]
#[UsesClass(GeneratedFile::class)]
final class SbrXbrlReportGeneratorTest extends TestCase {

	public function testItAnswersTheSbrXbrlReportInXbrl(): void {
		$this->assertSame('sbr-xbrl', SbrXbrlReportGenerator::reportType());
		$this->assertSame(['xbrl'], SbrXbrlReportGenerator::supportedFormats());
	}

	public function testTheStatementEndingInTheYearIsBuiltAndItsXmlReturned(): void {
		$records = $this->createMock(SbrRecords::class);
		$records->expects($this->never())->method('find');
		$records->expects($this->once())->method('findAll')->with('FinancialStatement', ['administrationId' => 'adm-1'])->willReturn([
			['id' => 'fs-2024', 'reportingPeriodEnd' => '2024-12-31'],
			['id' => 'fs-2025', 'reportingPeriodEnd' => '2025-12-31T00:00:00Z'],
		]);
		$builder = $this->createMock(AnnualAccountsXbrlBuilder::class);
		$builder->expects($this->once())->method('build')
			->with(['id' => 'fs-2025', 'reportingPeriodEnd' => '2025-12-31T00:00:00Z'], 'adm-1')
			->willReturn(['id' => 'xbrl-1', 'instanceNumber' => 'KVK-2025-small', 'instanceXml' => '<xbrli:xbrl/>']);

		$file = (new SbrXbrlReportGenerator($records, $builder))->generate(['period' => '2025', 'administrationId' => 'adm-1'], 'xbrl');

		$this->assertSame('sbr-xbrl-KVK-2025-small.xbrl', $file->fileName);
		$this->assertSame('application/xml', $file->mimeType);
		$this->assertSame('xbrl', $file->format);
		$this->assertSame('<xbrli:xbrl/>', $file->content);
	}

	public function testANamedStatementIsUsed(): void {
		$records = $this->createMock(SbrRecords::class);
		$records->expects($this->once())->method('find')->with('FinancialStatement', 'fs-9')->willReturn(['id' => 'fs-9']);
		$records->expects($this->never())->method('findAll');
		$builder = $this->createMock(AnnualAccountsXbrlBuilder::class);
		$builder->expects($this->once())->method('build')->with(['id' => 'fs-9'], '')->willReturn(['instanceXml' => 'x']);

		$file = (new SbrXbrlReportGenerator($records, $builder))->generate(['period' => '2025/Q4', 'statementId' => 'fs-9'], 'xbrl');

		$this->assertSame('sbr-xbrl-2025-Q4.xbrl', $file->fileName);
	}

	public function testAPeriodWithoutAStatementIsRefused(): void {
		$records = $this->createMock(SbrRecords::class);
		$records->method('findAll')->willReturn([['id' => 'fs-2024', 'reportingPeriodEnd' => '2024-12-31']]);
		$builder = $this->createMock(AnnualAccountsXbrlBuilder::class);
		$builder->expects($this->never())->method('build');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No financial statement ends in 2025. Prepare the annual accounts first.');

		(new SbrXbrlReportGenerator($records, $builder))->generate(['period' => '2025', 'administrationId' => 'adm-1'], 'xbrl');
	}

	public function testAnotherFormatIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The SBR filing is XBRL only, not pdf.');

		(new SbrXbrlReportGenerator($this->createMock(SbrRecords::class), $this->createMock(AnnualAccountsXbrlBuilder::class)))->generate(['period' => '2025'], 'pdf');
	}
}
