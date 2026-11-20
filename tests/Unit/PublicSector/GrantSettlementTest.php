<?php

/**
 * REQ-SUBV-011 / REQ-SUBV-012: settling a determined grant and storing its
 * accountability report, over an in-memory store, with every record written
 * validated against the merged register schema.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PublicSector;

use DomainException;
use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\Shillinq\Lifecycle\Action\SettleGrantAction;
use OCA\Shillinq\Service\PublicSector\GrantSettlement;
use OCA\Shillinq\Service\SubsidieVerantwoordingService;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Service/InMemoryObjectService.php';

/**
 * Tests for GrantSettlement and the determine transition's action.
 *
 * @covers \OCA\Shillinq\Service\PublicSector\GrantSettlement
 * @covers \OCA\Shillinq\Lifecycle\Action\SettleGrantAction
 * @uses   \OCA\Shillinq\Service\SubsidieVerantwoordingService
 * @uses   \OCA\Shillinq\Support\RegisterSlug
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class GrantSettlementTest extends TestCase {

	/**
	 * The in-memory store.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $store;

	/**
	 * The settlement under test.
	 *
	 * @var GrantSettlement
	 */
	private GrantSettlement $settlement;

	/**
	 * A fresh store and the settlement over it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryObjectService();
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === 'register') {
					return 'shillinq';
				}

				return $default;
			}
		);
		$this->settlement = new GrantSettlement(
			appConfig: $config,
			objectService: new DuckObjectServiceAdapter($this->store),
			verantwoording: new SubsidieVerantwoordingService(appConfig: $config)
		);

	}//end setUp()

	/**
	 * A grant given to a sports club, paid out EUR 10,000 and being determined.
	 *
	 * @param array<string,mixed> $overrides Fields to change.
	 *
	 * @return array<string,mixed>
	 */
	private static function grant(array $overrides = []): array {
		return array_merge(
			[
				'id'                      => 'grant-1',
				'administrationId'        => 'gem-almelo',
				'direction'               => 'outgoing',
				'subsidyNumber'           => 'SUB-2026-014',
				'counterpartyName'        => 'Sportclub Almelo',
				'counterpartyId'          => '5c0a1e2b-0001-4000-8000-00000000c001',
				'schemeName'              => 'Subsidieregeling sport 2026',
				'requestDate'             => '2026-01-10',
				'requestedAmount'         => 10000.0,
				'grantedAmount'           => 10000.0,
				'paidOutAmount'           => 10000.0,
				'determinedAmount'        => 8500.0,
				'determinationDate'       => '2026-10-01',
				'prestatieverantwoording' => 'Two of the three tournaments were held.',
				'state'                   => 'determined',
			],
			$overrides
		);

	}//end grant()

	/**
	 * The scenario: paid EUR 10,000, determined EUR 8,500. The grant shows EUR
	 * 1,500 reclaimed and a draft invoice of EUR 1,500 to the club names it.
	 *
	 * @return void
	 */
	public function testDeterminingBelowWhatWasPaidWritesADraftReclaimInvoiceThatNamesTheGrant(): void {
		$settled = $this->settlement->settle(grant: self::grant());

		self::assertSame(1500.0, $settled['reclaimedAmount']);
		$invoices = $this->store->dump('ARInvoice');
		self::assertCount(1, $invoices);
		$invoice = $invoices[0];
		self::assertSame($invoice['id'], $settled['reclaimInvoiceId']);
		self::assertSame('draft', $invoice['lifecycleState']);
		self::assertSame('5c0a1e2b-0001-4000-8000-00000000c001', $invoice['customerId']);
		self::assertSame(1500.0, $invoice['grossAmount']);
		self::assertSame(1500.0, $invoice['invoiceLines'][0]['netAmount']);
		self::assertSame('Terugvordering subsidie SUB-2026-014 (Subsidieregeling sport 2026)', $invoice['invoiceLines'][0]['itemName']);
		self::assertSame('grant-1', $invoice['linkedObjects'][0]['id']);
		self::assertSame('Subsidie', $invoice['linkedObjects'][0]['schema']);
		self::assertSame('2026-10-01', $invoice['invoiceDate']);
		self::assertSame('2026-10-31', $invoice['dueDate']);
		self::assertSame([], $this->store->dump('APTransaction'));
		self::assertSame([], RegisterSchema::errors('ARInvoice', array_diff_key($invoice, ['id' => true])));

	}//end testDeterminingBelowWhatWasPaidWritesADraftReclaimInvoiceThatNamesTheGrant()

	/**
	 * A grant received pays back through a draft payable to the granting body.
	 *
	 * @return void
	 */
	public function testAGrantReceivedPaysBackThroughADraftPayable(): void {
		$settled = $this->settlement->settle(
			grant: self::grant(
				['direction' => 'incoming', 'counterpartyId' => '5c0a1e2b-0002-4000-8000-00000000c002', 'paidOutAmount' => 50000.10, 'determinedAmount' => 42000.0]
			)
		);

		self::assertSame(8000.1, $settled['reclaimedAmount']);
		self::assertArrayNotHasKey('reclaimInvoiceId', $settled);
		$payables = $this->store->dump('APTransaction');
		self::assertCount(1, $payables);
		self::assertSame($payables[0]['id'], $settled['reclaimPayableId']);
		self::assertSame('5c0a1e2b-0002-4000-8000-00000000c002', $payables[0]['vendorId']);
		self::assertSame(8000.1, $payables[0]['totalAmount']);
		self::assertSame('draft', $payables[0]['state']);
		self::assertSame([], $this->store->dump('ARInvoice'));
		self::assertSame([], RegisterSchema::errors('APTransaction', array_diff_key($payables[0], ['id' => true])));

	}//end testAGrantReceivedPaysBackThroughADraftPayable()

	/**
	 * Determined at or above what was paid out: nothing is reclaimed and
	 * nothing is written.
	 *
	 * @return void
	 */
	public function testNothingIsReclaimedWhenNoMoreWasPaidThanDetermined(): void {
		$settled = $this->settlement->settle(grant: self::grant(['paidOutAmount' => 8500.0]));
		self::assertSame(0.0, $settled['reclaimedAmount']);

		$settled = $this->settlement->settle(grant: self::grant(['paidOutAmount' => null]));
		self::assertSame(0.0, $settled['reclaimedAmount']);
		self::assertSame([], $this->store->dump('ARInvoice'));
		self::assertSame([], $this->store->dump('APTransaction'));

	}//end testNothingIsReclaimedWhenNoMoreWasPaidThanDetermined()

	/**
	 * A grant whose reclaim was already written gets no second one.
	 *
	 * @return void
	 */
	public function testAGrantIsReclaimedOnce(): void {
		$settled = $this->settlement->settle(grant: self::grant());
		$again = $this->settlement->settle(grant: $settled);

		self::assertSame($settled['reclaimInvoiceId'], $again['reclaimInvoiceId']);
		self::assertCount(1, $this->store->dump('ARInvoice'));

	}//end testAGrantIsReclaimedOnce()

	/**
	 * A reclaim with nobody to address it to, and a determination without an
	 * amount, are refused rather than written half.
	 *
	 * @return void
	 */
	public function testAReclaimWithoutCounterpartyAndADeterminationWithoutAmountAreRefused(): void {
		try {
			$this->settlement->settle(grant: self::grant(['counterpartyId' => '  ']));
			self::fail('A reclaim without a counterparty was written.');
		} catch (DomainException $e) {
			self::assertStringContainsString('counterparty', $e->getMessage());
		}

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Enter the determined amount');
		$this->settlement->settle(grant: self::grant(['determinedAmount' => '']));

	}//end testAReclaimWithoutCounterpartyAndADeterminationWithoutAmountAreRefused()

	/**
	 * The determine transition declares the action, and the action returns the
	 * settled grant.
	 *
	 * @return void
	 */
	public function testTheDetermineTransitionRunsTheSettlement(): void {
		$declared = RegisterSchema::schema('Subsidie')['x-openregister-lifecycle']['transitions']['vaststellen'];
		self::assertSame('determined', $declared['to']);
		self::assertContains(SettleGrantAction::class, array_column($declared['actions'], 'action'));

		$action = new SettleGrantAction(settlement: $this->settlement);
		$context = new LifecycleActionContext(objectData: self::grant(), previousData: [], parameters: [], actionName: SettleGrantAction::class);
		$result = $action->execute($context);

		self::assertSame(1500.0, $result['reclaimedAmount']);
		self::assertSame('Sportclub Almelo', $result['counterpartyName']);
		self::assertCount(1, $this->store->dump('ARInvoice'));

	}//end testTheDetermineTransitionRunsTheSettlement()

	/**
	 * The scenario: a grant of EUR 150,000. A report is stored for it and an
	 * auditor statement is marked as required; reporting again stores nothing new.
	 *
	 * @return void
	 */
	public function testAReportOnALargeGrantIsStoredWithARequiredAuditorStatement(): void {
		$grant = self::grant(['grantedAmount' => 150000.0, 'decisionDate' => '2026-03-15']);
		$result = $this->settlement->report(grant: $grant);

		self::assertTrue($result['auditorStatementRequired']);
		$reports = $this->store->dump('SubsidieVerantwoording');
		self::assertCount(1, $reports);
		self::assertSame($reports[0]['id'], $result['verantwoordingId']);
		self::assertSame('SUB-2026-014', $reports[0]['grantId']);
		self::assertSame(150000.0, $reports[0]['awardedAmount']);
		self::assertSame('draft', $reports[0]['status']);
		self::assertStringStartsWith('2026-03-15 to ', $reports[0]['reportingPeriod']);
		self::assertSame([], RegisterSchema::errors('SubsidieVerantwoording', array_diff_key($reports[0], ['id' => true])));

		$statements = $this->store->dump('AuditorStatement');
		self::assertCount(1, $statements);
		self::assertSame($statements[0]['id'], $result['auditorStatementId']);
		self::assertSame('pending', $statements[0]['status']);
		self::assertSame([], RegisterSchema::errors('AuditorStatement', array_diff_key($statements[0], ['id' => true])));

		$again = $this->settlement->report(grant: $grant);
		self::assertSame($result, $again);
		self::assertCount(1, $this->store->dump('SubsidieVerantwoording'));
		self::assertCount(1, $this->store->dump('AuditorStatement'));

	}//end testAReportOnALargeGrantIsStoredWithARequiredAuditorStatement()

	/**
	 * A small grant's report asks for no auditor statement; a grant without a
	 * number cannot be reported on.
	 *
	 * @return void
	 */
	public function testASmallGrantNeedsNoAuditorStatementAndAnUnnumberedGrantIsRefused(): void {
		$result = $this->settlement->report(grant: self::grant());

		self::assertFalse($result['auditorStatementRequired']);
		self::assertNull($result['auditorStatementId']);
		self::assertSame([], $this->store->dump('AuditorStatement'));

		$this->expectException(DomainException::class);
		$this->settlement->report(grant: self::grant(['subsidyNumber' => '']));

	}//end testASmallGrantNeedsNoAuditorStatementAndAnUnnumberedGrantIsRefused()
}//end class
