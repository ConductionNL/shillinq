<?php

/**
 * Dutch-only outputs refuse other jurisdictions (ledger-foreign-legislation REQ-LFL-004)
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Jurisdiction
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

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Jurisdiction;

use OCA\Shillinq\Service\IcpCalculator;
use OCA\Shillinq\Service\IcpService;
use OCA\Shillinq\Service\Jurisdiction\JurisdictionRefusedException;
use OCA\Shillinq\Service\VATReturnService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The Dutch VAT return, its SBR submission and the ICP return refuse a Belgian administration.
 *
 * @covers \OCA\Shillinq\Service\VATReturnService
 * @covers \OCA\Shillinq\Service\IcpService
 * @uses   \OCA\Shillinq\Service\Jurisdiction\AdministrationJurisdiction
 * @uses   \OCA\Shillinq\Service\Jurisdiction\JurisdictionRefusedException
 * @uses   \OCA\Shillinq\Service\IcpCalculator
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 * @uses   \OCA\Shillinq\Service\Vat\VatLineStamper
 * @uses   \OCA\Shillinq\Service\Vat\VatReturnBox
 * @uses   \OCA\Shillinq\Service\VatReturnScope
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */
final class DutchOutputsRefuseOtherJurisdictionsTest extends TestCase {

	/**
	 * The store: a Dutch holding, its Belgian subsidiary, and a draft VAT return of each.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * Set up the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryObjectServiceStub(
			[
				'Administration' => [
					['id' => 'a-nl', 'administrationCode' => 'HVD', 'name' => 'Holding Van Dijk B.V.', 'jurisdiction' => 'NL'],
					['id' => 'a-be', 'administrationCode' => 'VDS', 'name' => 'Van Dijk Services BV/SRL', 'jurisdiction' => 'BE'],
				],
				'BtwAangifte' => [
					['id' => 'r-nl', 'administrationId' => 'HVD', 'statusCode' => 'draft', 'totalVATCollected' => 0.0, 'totalVATPaid' => 0.0],
					['id' => 'r-be', 'administrationId' => 'VDS', 'statusCode' => 'draft', 'totalVATCollected' => 0.0, 'totalVATPaid' => 0.0],
				],
			]
		);
	}//end setUp()

	/**
	 * App config answering every key with its default.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default=''): string => $default);
		return $config;
	}//end appConfig()

	/**
	 * The VAT return service over the store.
	 *
	 * @return VATReturnService
	 */
	private function vatReturns(): VATReturnService {
		return new VATReturnService(appConfig: $this->appConfig(), logger: new NullLogger(), objectService: $this->store);
	}//end vatReturns()

	/**
	 * A Dutch VAT return is not created for the Belgian subsidiary, and is for the holding.
	 *
	 * @return void
	 */
	public function testADutchVatReturnIsNotCreatedForABelgianAdministration(): void {
		try {
			$this->vatReturns()->createReturn(administrationId: 'VDS', period: 'quarter', periodYear: 2026, periodNumber: 3, regime: 'standard');
			$this->fail('A Dutch VAT return was created for a Belgian administration.');
		} catch (JurisdictionRefusedException $e) {
			$this->assertSame('BE', $e->getJurisdiction());
			$this->assertStringContainsString('kept under BE rules', $e->getMessage());
		}

		$this->assertCount(2, $this->store->findAll());

		$created = $this->vatReturns()->createReturn(administrationId: 'HVD', period: 'quarter', periodYear: 2026, periodNumber: 3, regime: 'standard');
		$this->assertSame('HVD', $created['administrationId']);
	}//end testADutchVatReturnIsNotCreatedForABelgianAdministration()

	/**
	 * A Belgian administration's return is not submitted over SBR; a Dutch one is.
	 *
	 * @return void
	 */
	public function testABelgianReturnIsNotSubmittedOverSbr(): void {
		try {
			$this->vatReturns()->submitReturn(returnId: 'r-be', userId: 'alice');
			$this->fail('A Belgian administration\'s return was submitted.');
		} catch (JurisdictionRefusedException $e) {
			$this->assertSame('Dutch VAT return submission (SBR)', $e->getOutput());
		}

		$this->assertSame('draft', $this->store->find(id: 'r-be', schema: 'BtwAangifte')->getObject()['statusCode']);
		$this->assertSame('submitted', $this->vatReturns()->submitReturn(returnId: 'r-nl', userId: 'alice')['statusCode']);
	}//end testABelgianReturnIsNotSubmittedOverSbr()

	/**
	 * The ICP return (ledger, reconciliation, periodicity and the supplies behind the audit export)
	 * refuses the Belgian administration and serves the Dutch one.
	 *
	 * @return void
	 */
	public function testTheIcpReturnRefusesABelgianAdministration(): void {
		$icp = new IcpService(appConfig: $this->appConfig(), calculator: new IcpCalculator(), objectService: $this->store);

		foreach (['ledger', 'reconcile', 'periodicityCheck', 'suppliesInPeriod'] as $method) {
			try {
				$icp->{$method}('VDS', '2026-Q3');
				$this->fail($method . ' served a Belgian administration.');
			} catch (JurisdictionRefusedException $e) {
				$this->assertSame('Dutch ICP return', $e->getOutput());
			}
		}

		$this->assertSame(0, $icp->ledger(administrationId: 'HVD', period: '2026-Q3')['supplyCount']);
	}//end testTheIcpReturnRefusesABelgianAdministration()
}//end class
