<?php

/**
 * Unit tests for InnovatieboxSbrExportService.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\InnovatieboxSbrExportService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the SBR/XBRL hand-off + docudesk PDF render context (REQ-IBA-006, task 8.1).
 *
 * Pure-logic — no OpenRegister dependency exercised.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InnovatieboxSbrExportServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var InnovatieboxSbrExportService
	 */
	private InnovatieboxSbrExportService $svc;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->svc = new InnovatieboxSbrExportService();

	}//end setUp()

	/**
	 * Afpelmethode export emits one SBR row per qualifying asset and a stable
	 * instanceRef + the totals that contribute to Vpb-aangifte regel 23.
	 *
	 * @return void
	 */
	public function testAfpelmethodeRendersPerAssetRowsAndTotals(): void {
		$aggregation = [
			'data' => [
				[
					'qualifying_asset_id' => 'asset-1',
					'name' => 'Slimme routeringsalgoritme',
					'winst_voor_nexus' => 800000.0,
					'nexus' => 1.0,
					'winst_na_nexus' => 800000.0,
					'tariff' => 0.09,
					'vpb_impact' => 72000.0,
				],
				[
					'qualifying_asset_id' => 'asset-2',
					'name' => 'Voorspellingsmodel',
					'winst_voor_nexus' => 200000.0,
					'nexus' => 0.866,
					'winst_na_nexus' => 173200.0,
					'tariff' => 0.09,
					'vpb_impact' => 15588.0,
				],
			],
			'totals' => [
				'qualifying_profit_for_nexus' => 1000000.0,
				'qualifying_profit_after_nexus' => 973200.0,
				'vpb_on_innovation_share' => 87588.0,
				'benefit_innovation_box' => 170600.0,
			],
		];

		$payload = $this->svc->toSbrInstancePayload(
			$aggregation,
			'adm-mkb-1',
			2026
		);

		$this->assertSame('VPB-XX-2026', $payload['taxonomyVersion']);
		$this->assertSame('Vpb-Innovatiebox', $payload['collectie']);
		$this->assertSame('2026', $payload['identificerendePeriode']);
		$this->assertSame('adm-mkb-1', $payload['administration']);
		$this->assertSame('per_asset_afpelmethode', $payload['gekozenMethode']);
		$this->assertNull($payload['forfaitairLine']);
		$this->assertCount(2, $payload['perAssetRows']);
		$this->assertSame('asset-1', $payload['perAssetRows'][0]['qualifying_asset_id']);
		$this->assertSame(800000.0, $payload['perAssetRows'][0]['winst_voor_nexus']);
		$this->assertSame(0.866, $payload['perAssetRows'][1]['nexus_percent']);
		$this->assertSame(973200.0, $payload['regel23_kwalifWinst']);
		$this->assertSame(87588.0, $payload['regel23_vpbInnovatie']);
		$this->assertSame(170600.0, $payload['regel23_voordeel']);
		$this->assertSame('VPB-XX-2026-adm-mkb-1-2026', $payload['instanceRef']);

	}//end testAfpelmethodeRendersPerAssetRowsAndTotals()

	/**
	 * Forfaitair election collapses the export to a single line and sets the
	 * cap-applied flag when the pre-cap qualifying profit exceeds EUR 25k.
	 *
	 * @return void
	 */
	public function testForfaitairCollapsesToCappedLine(): void {
		$aggregation = [
			'data' => [],
			'totals' => [
				'qualifying_profit_for_nexus' => 125000.0,
				'qualifying_profit_after_nexus' => 25000.0,
				'vpb_on_innovation_share' => 2250.0,
				'benefit_innovation_box' => 4950.0,
			],
		];

		$payload = $this->svc->toSbrInstancePayload(
			$aggregation,
			'adm-mkb-1',
			2026,
			'flat_rate_25pct'
		);

		$this->assertSame([], $payload['perAssetRows']);
		$this->assertNotNull($payload['forfaitairLine']);
		$this->assertSame(125000.0, $payload['forfaitairLine']['kwalifVoorCap']);
		$this->assertSame(25000.0, $payload['forfaitairLine']['kwalifNaCap']);
		$this->assertSame(25000, $payload['forfaitairLine']['capEur']);
		$this->assertTrue($payload['forfaitairLine']['capApplied']);

	}//end testForfaitairCollapsesToCappedLine()

	/**
	 * The deterministic instanceRef is stable across calls and strips unsafe
	 * characters from the administration scope.
	 *
	 * @return void
	 */
	public function testInstanceRefIsDeterministicAndSafe(): void {
		$ref1 = $this->svc->deriveInstanceRef('adm/mkb 1!', 2026);
		$ref2 = $this->svc->deriveInstanceRef('adm/mkb 1!', 2026);

		$this->assertSame($ref1, $ref2);
		$this->assertSame('VPB-XX-2026-admmkb1-2026', $ref1);

	}//end testInstanceRefIsDeterministicAndSafe()

	/**
	 * toPdfRenderContext returns the per-asset rows + totals in the shape the
	 * docudesk template expects, mirroring the SBR payload numerics.
	 *
	 * @return void
	 */
	public function testPdfRenderContextMirrorsSbrNumerics(): void {
		$aggregation = [
			'data' => [
				[
					'qualifying_asset_id' => 'asset-1',
					'name' => 'IP A',
					'winst_voor_nexus' => 100.0,
					'nexus' => 0.5,
					'winst_na_nexus' => 50.0,
					'tariff' => 0.09,
					'vpb_impact' => 4.5,
				],
			],
			'totals' => [
				'qualifying_profit_for_nexus' => 100.0,
				'qualifying_profit_after_nexus' => 50.0,
				'vpb_on_innovation_share' => 4.5,
				'benefit_innovation_box' => 21.3,
			],
		];

		$ctx = $this->svc->toPdfRenderContext($aggregation, 'adm-x', 2026);

		$this->assertSame(2026, $ctx['financialYear']);
		$this->assertSame('adm-x', $ctx['administrationId']);
		$this->assertSame('per_asset_afpelmethode', $ctx['method']);
		$this->assertCount(1, $ctx['perAsset']);
		$this->assertSame('IP A', $ctx['perAsset'][0]['name']);
		$this->assertSame(50.0, $ctx['totals']['winst_na_nexus']);
		$this->assertSame(21.3, $ctx['totals']['voordeel']);
		$this->assertNull($ctx['flatRate']);

	}//end testPdfRenderContextMirrorsSbrNumerics()
}//end class
