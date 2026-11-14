<?php

/**
 * Unit tests for ComputeFidoQuarterAction.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-wet-fido-treasury/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\ComputeFidoQuarterAction;
use OCA\Shillinq\Service\PublicSector\FidoQuarter;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Service/InMemoryObjectService.php';

/**
 * ComputeFidoQuarterAction unit tests (REQ-FDO-011).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ComputeFidoQuarterActionTest extends TestCase {

	/**
	 * Build the action over the real FidoQuarter and a store.
	 *
	 * @param InMemoryObjectService $os The store.
	 *
	 * @return ComputeFidoQuarterAction
	 */
	private function action(InMemoryObjectService $os): ComputeFidoQuarterAction {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new ComputeFidoQuarterAction(
			quarters: new FidoQuarter(appConfig: $appConfig, objectService: new DuckObjectServiceAdapter($os)),
		);

	}//end action()

	/**
	 * Compute on the 2026-Q3 report keeps both snapshots on it, and the report
	 * to save validates against the merged schema.
	 *
	 * @return void
	 */
	public function testComputeKeepsBothSnapshotsOnTheReport(): void {
		$os = new InMemoryObjectService();
		$os->setSchema('KasgeldLimiet')->saveObject(
			['id' => 'kl', 'auditYear' => 2026, 'organisationId' => 'gem-1', 'baseBudget' => 100000000.0, 'percentage' => 8.5]
		);
		$os->setSchema('Lening')->saveObject(
			[
				'organisationId' => 'gem-1',
				'type' => 'kasgeld',
				'principal' => 6000000.0,
				'issueDate' => '2026-06-01',
				'maturityDate' => '2026-12-01',
				'status' => 'recorded',
			]
		);

		$report = ['auditYear' => 2026, 'quarter' => 'Q3', 'organisationId' => 'gem-1', 'status' => 'draft'];
		$saved = $this->action($os)->execute($report, $report, [], 'compute');

		self::assertSame(2500000.0, $saved['cashStatus']['headroom']);
		self::assertSame('binnen-norm', $saved['cashStatus']['status']);
		self::assertSame(20000000.0, $saved['renteRiskStatus']['headroom']);
		self::assertSame('draft', $saved['status']);
		self::assertSame([], RegisterSchema::errors('QuartaalrapportageFido', $saved));

	}//end testComputeKeepsBothSnapshotsOnTheReport()

	/**
	 * A report without a quarter is refused.
	 *
	 * @return void
	 */
	public function testAReportWithoutAQuarterIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$report = ['auditYear' => 2026, 'organisationId' => 'gem-1', 'status' => 'draft'];
		$this->action(new InMemoryObjectService())->execute($report, $report, [], 'compute');

	}//end testAReportWithoutAQuarterIsRefused()
}//end class
