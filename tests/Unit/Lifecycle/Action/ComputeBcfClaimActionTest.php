<?php

/**
 * Unit tests for ComputeBcfClaimAction.
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
 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-bcf-vat-compensation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\ComputeBcfClaimAction;
use OCA\Shillinq\Service\BcfClaimService;
use OCA\Shillinq\Service\BcfCompensationCalculator;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Service/InMemoryObjectService.php';

/**
 * ComputeBcfClaimAction unit tests (REQ-BCF-010).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ComputeBcfClaimActionTest extends TestCase {

	/**
	 * Build the action over the real service and calculator and a seeded store.
	 *
	 * @param InMemoryObjectService $os The seeded store.
	 *
	 * @return ComputeBcfClaimAction
	 */
	private function action(InMemoryObjectService $os): ComputeBcfClaimAction {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new ComputeBcfClaimAction(
			claims: new BcfClaimService(
				appConfig: $appConfig,
				calculator: new BcfCompensationCalculator(),
				objectService: new DuckObjectServiceAdapter($os),
			),
		);

	}//end action()

	/**
	 * The spec scenario: EUR 21,000 at 100 percent and EUR 4,200 at 50 percent
	 * make EUR 23,100 compensable, with both accounts in the breakdown, and the
	 * claim to save validates against the merged BcfClaim schema.
	 *
	 * @return void
	 */
	public function testAControllerComputesTheThirdQuarterClaim(): void {
		$os = new InMemoryObjectService();
		$line = ['transactionId' => 'tx-1', 'side' => 'debit', 'periodId' => '2026-Q3'];
		$os->setSchema('GLTransaction')->saveObject(['id' => 'tx-1', 'administrationId' => 'adm-1', 'periodId' => '2026-Q3']);
		$os->setSchema('GLLine')->saveObject($line + ['accountNumber' => '4300', 'amount' => 21000.0]);
		$os->setSchema('GLLine')->saveObject($line + ['accountNumber' => '4310', 'amount' => 4200.0]);
		$mapping = ['administrationId' => 'adm-1', 'bcfCompensable' => true];
		$os->setSchema('BbvAccountMapping')->saveObject($mapping + ['accountNumber' => '4300', 'compensablePercentage' => 100]);
		$os->setSchema('BbvAccountMapping')->saveObject($mapping + ['accountNumber' => '4310', 'compensablePercentage' => 50]);

		$claim = [
			'administrationId' => 'adm-1',
			'claimQuarter' => '2026-Q3',
			'periodYear' => 2026,
			'periodQuarter' => 3,
			'claimNumber' => 'BCF-2026-Q3',
			'state' => 'draft',
		];
		$saved = $this->action($os)->execute($claim, $claim, [], 'compute');

		self::assertSame(23100.0, $saved['totalCompensableAmount']);
		// The approval-threshold guard on submit reads totalClaimAmount.
		self::assertSame(23100.0, $saved['totalClaimAmount']);
		self::assertSame(['4300', '4310'], array_column($saved['breakdown'], 'accountNumber'));
		self::assertSame('draft', $saved['state']);
		self::assertSame([], RegisterSchema::errors('BcfClaim', $saved));

	}//end testAControllerComputesTheThirdQuarterClaim()

	/**
	 * A claim without an administration or quarter is refused, not computed empty.
	 *
	 * @return void
	 */
	public function testAClaimWithoutAQuarterIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$claim = ['administrationId' => 'adm-1', 'state' => 'draft'];
		$this->action(new InMemoryObjectService())->execute($claim, $claim, [], 'compute');

	}//end testAClaimWithoutAQuarterIsRefused()
}//end class
