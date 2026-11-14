<?php

/**
 * Unit tests for ReportCatalogue: the registry is well-formed and looked up by id.
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;


use OCA\Shillinq\Reporting\ReportCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Reporting\ReportCatalogue
 */
final class ReportCatalogueTest extends TestCase {

	public function testEveryReportIdIsUnique(): void {
		$ids = array_column(ReportCatalogue::all(), 'id');

		self::assertSame(array_values(array_unique($ids)), $ids);
		self::assertCount(18, $ids);
	}//end testEveryReportIdIsUnique()

	public function testEveryReportIsInAKnownCategoryAndEveryCategoryHasAReport(): void {
		$used = array_unique(array_column(ReportCatalogue::all(), 'category'));

		self::assertSame([], array_values(array_diff($used, array_keys(ReportCatalogue::CATEGORIES))));
		self::assertSame([], array_values(array_diff(array_keys(ReportCatalogue::CATEGORIES), $used)));
	}//end testEveryReportIsInAKnownCategoryAndEveryCategoryHasAReport()

	public function testADocumentReportHasATemplateAndADataReportHasNone(): void {
		foreach (ReportCatalogue::all() as $report) {
			self::assertContains($report['kind'], ['data', 'document'], $report['id']);
			self::assertNotSame([], $report['formats'], $report['id']);
			if ($report['kind'] === 'document') {
				self::assertStringStartsWith('shillinq-', (string)$report['templateId'], $report['id']);
				continue;
			}

			self::assertNull($report['templateId'], $report['id']);
		}
	}//end testADocumentReportHasATemplateAndADataReportHasNone()

	public function testAReportIsFoundByItsId(): void {
		self::assertSame(
			['id' => 'xaf', 'label' => 'XAF auditbestand (Auditfile Financieel)', 'category' => 'audit-file', 'kind' => 'data', 'formats' => ['xml'], 'templateId' => null, 'description' => 'Nederlands Auditfile Financieel (XAF 3.2, Belastingdienst/XBRL Nederland).'],
			ReportCatalogue::byId('xaf')
		);
		self::assertSame('shillinq-jaarrekening', ReportCatalogue::byId('annual-accounts')['templateId']);
	}//end testAReportIsFoundByItsId()

	public function testAnUnknownIdIsNull(): void {
		self::assertNull(ReportCatalogue::byId('unknown-report'));
		self::assertNull(ReportCatalogue::byId(''));
		self::assertNull(ReportCatalogue::byId('XAF'), 'ids are matched exactly');
	}//end testAnUnknownIdIsNull()
}//end class
