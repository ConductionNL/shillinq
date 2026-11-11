<?php

/**
 * Golden characterisation test for AcmReportGenerator::compose and toXml.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AcmReportGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the full composed record and its XML for a mixed input set.
 *
 * Regenerate with ACM_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AcmReportGeneratorGoldenTest extends TestCase {

	/**
	 * Golden fixture path.
	 *
	 * @var string
	 */
	private const GOLDEN = __DIR__.'/fixtures/acm-report-compose.golden.json';

	/**
	 * Compose and toXml match the golden output.
	 *
	 * @return void
	 */
	public function testComposeAndXmlMatchGolden(): void {
		$svc = new AcmReportGenerator();
		$input = [
			'period' => '2026-YTD',
			'administrationId' => 'adm-gem',
			'activities' => [
				[
					'id' => 'a1',
					'code' => 'PARK',
					'name' => 'Parkeren',
				],
				[
					'_id' => 'a2',
					'code' => 'MARKT',
					'name' => 'Markt & <kraam>',
					'isExempted' => true,
					'exemptionDecisionId' => 'ABB-7',
				],
				[
					'code' => 'ZAAL',
					'isExempted' => true,
				],
				['code' => 'LEEG'],
				'not-an-array',
			],
			'ikpRecords' => [
				'a1' => ['totalCost' => 1000.0],
				'a2' => ['totalCost' => 300.0],
				'ZAAL' => ['totalCost' => 0],
			],
			'omzetByActivity' => [
				'a1' => 1234.5,
				'a2' => 100,
				'ZAAL' => 50,
			],
			'abbList' => [
				[
					'reference' => 'ABB-7',
					'rationale' => '  '.str_repeat('Algemeen belang. ', 20).'  ',
				],
				['rationale' => 'kort'],
				42,
			],
			'summary' => 'Samenvatting',
			'manualOverrides' => '3',
		];

		$report = $svc->compose($input);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $report['generatedAt']);
		$report['generatedAt'] = '<masked>';
		$actual = [
			'report' => $report,
			'xml' => $svc->toXml($report),
			'minimal' => $this->masked($svc->compose(['period' => '2025-Q4', 'activities' => [], 'administrationId' => 7])),
		];

		if (getenv('ACM_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(json_encode($golden, JSON_PRESERVE_ZERO_FRACTION), json_encode($actual, JSON_PRESERVE_ZERO_FRACTION));

	}//end testComposeAndXmlMatchGolden()

	/**
	 * Mask the wall-clock field.
	 *
	 * @param array<string,mixed> $report Report.
	 *
	 * @return array<string,mixed>
	 */
	private function masked(array $report): array {
		$report['generatedAt'] = '<masked>';
		return $report;

	}//end masked()
}//end class
