<?php

/**
 * Unit tests for WmoJaarrekeningBijlageService.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-13
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\WmoJaarrekeningBijlageService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the WMO jaarrekening-bijlage composer (REQ-WMO-004).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WmoJaarrekeningBijlageServiceTest extends TestCase {

	/**
	 * Service under test.
	 */
	private WmoJaarrekeningBijlageService $svc;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->svc = new WmoJaarrekeningBijlageService();

	}//end setUp()

	/**
	 * Compose builds a per-activity bijlage row with compliance color + ratio.
	 */
	public function testComposeBuildsActivityRows(): void {
		$bijlage = $this->svc->compose([
			'fiscalYear' => '2025',
			'administrationId' => 'adm-tilburg',
			'activities' => [
				['id' => 'ca-001', 'code' => 'MO-SP-014', 'name' => 'Dansschool', 'isExempted' => false],
				['id' => 'ca-002', 'code' => 'MO-SP-016', 'name' => 'Kantine', 'isExempted' => true],
			],
			'definitiefIkpByActivity' => [
				'ca-001' => ['totalCost' => 87_500.00],
				'ca-002' => ['totalCost' => 56_000.00],
			],
			'omzetByActivity' => [
				'ca-001' => 92_000.00,
				'ca-002' => 50_000.00,
			],
			'priorYearIkpByActivity' => [
				'ca-001' => ['totalCost' => 80_000.00],
			],
			'priorYearOmzetByActivity' => [
				'ca-001' => 81_000.00,
			],
			'abbByActivity' => [
				'ca-002' => ['reference' => 'R-2023-184'],
			],
			'manualOverridesByActivity' => [
				'ca-001' => 2,
				'ca-002' => 0,
			],
		]);

		self::assertCount(2, $bijlage['activities']);
		self::assertSame('groen', $bijlage['activities'][0]['complianceColor']);
		self::assertSame('rood', $bijlage['activities'][1]['complianceColor']);
		self::assertSame('R-2023-184', $bijlage['activities'][1]['abbReference']);
		self::assertNull($bijlage['activities'][0]['abbReference']);
		self::assertSame(2, $bijlage['activities'][0]['manualOverrides']);

		$summary = $bijlage['summary'];
		self::assertSame(2, $summary['total']);
		self::assertSame(1, $summary['compliant']);
		self::assertSame(1, $summary['nonCompliant']);

	}//end testComposeBuildsActivityRows()

	/**
	 * validateCompliance reports overall + per-bucket counts.
	 */
	public function testValidateCompliance(): void {
		$bijlage = ['activities' => [['compliant' => true], ['compliant' => false], ['compliant' => true]]];
		$result = $this->svc->summariseCompliance($bijlage);
		self::assertSame(2, $result['compliant']);
		self::assertSame(1, $result['nonCompliant']);
		self::assertFalse($result['overallCompliant']);

	}//end testValidateCompliance()

	/**
	 * toMarkdown produces a table + samenvatting line.
	 */
	public function testToMarkdown(): void {
		$bijlage = $this->svc->compose([
			'fiscalYear' => '2025',
			'administrationId' => 'adm',
			'activities' => [['id' => 'x', 'code' => 'X', 'name' => 'X', 'isExempted' => false]],
			'definitiefIkpByActivity' => ['x' => ['totalCost' => 10.0]],
			'omzetByActivity' => ['x' => 20.0],
		]);
		$md = $this->svc->toMarkdown($bijlage);
		self::assertStringContainsString('# WMO-bijlage jaarrekening 2025', $md);
		self::assertStringContainsString('Samenvatting', $md);

	}//end testToMarkdown()

}//end class
