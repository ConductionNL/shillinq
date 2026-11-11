<?php

/**
 * Characterisation tests for RuleAuditReportGenerator.
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

use OCA\Shillinq\Reporting\Generator\RuleAuditReportGenerator;
use OCA\Shillinq\Service\RuleAuditService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins the exact CSV the rule-audit generator writes.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RuleAuditReportGeneratorTest extends TestCase {

	/**
	 * A full audit report renders summary, severity, type and top-rule blocks.
	 *
	 * @return void
	 */
	public function testFullReportCsv(): void {
		$audit = $this->createMock(RuleAuditService::class);
		$audit->expects(self::once())->method('audit')->with(['period' => '2026-Q1'])->willReturn(
			[
				'catalogueVersion' => 'v3',
				'corpusTotal' => 120,
				'machineCheckable' => 80,
				'enforceableRules' => 75,
				'coveragePct' => 62.5,
				'objectsChecked' => 10,
				'objectsCompliant' => 7,
				'objectsWithViolations' => 3,
				'violationsBySeverity' => ['mandatory' => 4, 'recommended' => 1],
				'types' => [
					'ARInvoice' => [
						'checked' => 3,
						'compliant' => 2,
						'withViolations' => 1,
						'violations' => 5,
					],
					'Empty' => ['compliant' => 0],
					'Skipped' => 'not-an-array',
				],
				'topViolatedRules' => [
					['ruleId' => 'R-1', 'count' => 5],
					['count' => 2],
					7,
				],
			]
		);
		$file = $this->generator($audit)->generate(['period' => '2026-Q1'], 'csv');

		$expected = implode(
			"\n",
			[
				'section,metric,value',
				'summary,catalogueVersion,v3',
				'summary,corpusTotal,120',
				'summary,machineCheckable,80',
				'summary,enforceableRules,75',
				'summary,coveragePct,62.5',
				'summary,objectsChecked,10',
				'summary,objectsCompliant,7',
				'summary,objectsWithViolations,3',
				'severity,mandatory,4',
				'severity,conditional,0',
				'severity,recommended,1',
				'',
				'objectType,checked,compliant,withViolations,violations,compliancePct',
				'ARInvoice,3,2,1,5,66.70',
				'Empty,0,0,0,0,0.00',
				'',
				'topViolatedRule,count',
				'R-1,5',
				',2',
				'',
			]
		);
		self::assertSame($expected, $file->content);
		self::assertSame('rule-audit-2026-Q1.csv', $file->fileName);
		self::assertSame('text/csv', $file->mimeType);
		self::assertSame('csv', $file->format);

	}//end testFullReportCsv()

	/**
	 * A failing audit logs one warning and renders the empty report.
	 *
	 * @return void
	 */
	public function testFailedAuditRendersEmptyReport(): void {
		$audit = $this->createMock(RuleAuditService::class);
		$audit->method('audit')->willThrowException(new \RuntimeException('boom'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('RuleAuditReportGenerator: audit failed: boom');

		$file = $this->generator($audit, $logger)->generate(['period' => '2026'], 'csv');

		self::assertSame(
			"section,metric,value\nsummary,catalogueVersion,\nsummary,corpusTotal,0\nsummary,machineCheckable,0\n"
			."summary,enforceableRules,0\nsummary,coveragePct,0\nsummary,objectsChecked,0\nsummary,objectsCompliant,0\n"
			."summary,objectsWithViolations,0\nseverity,mandatory,0\nseverity,conditional,0\nseverity,recommended,0\n\n"
			."objectType,checked,compliant,withViolations,violations,compliancePct\n\ntopViolatedRule,count\n",
			$file->content
		);

	}//end testFailedAuditRendersEmptyReport()

	/**
	 * Build the generator over a container that serves the given audit service.
	 *
	 * @param RuleAuditService $audit The audit service.
	 * @param LoggerInterface|null $logger The logger, or a fresh mock.
	 *
	 * @return RuleAuditReportGenerator
	 */
	private function generator(RuleAuditService $audit, LoggerInterface|null $logger = null): RuleAuditReportGenerator {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(RuleAuditService::class)->willReturn($audit);

		return new RuleAuditReportGenerator($container, ($logger ?? $this->createMock(LoggerInterface::class)));

	}//end generator()
}//end class
