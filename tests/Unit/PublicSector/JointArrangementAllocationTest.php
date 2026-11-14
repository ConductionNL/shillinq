<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PublicSector;

use DomainException;
use OCA\Shillinq\Lifecycle\Action\JointArrangementAllocationAction;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\PublicSector\JointArrangementAllocation;
use OCA\Shillinq\Service\PublicSector\PublicSectorRecords;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Bank\LifecycleFaithfulTransitionEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * REQ-GRC-007 / REQ-GRC-008: a joint arrangement allocation run over the real
 * GRAllocationRun lifecycle, real-shaped GL lines and participants, with the
 * run and its invoices validated against the merged register schema.
 *
 * @covers \OCA\Shillinq\Service\PublicSector\JointArrangementAllocation
 * @covers \OCA\Shillinq\Lifecycle\Action\JointArrangementAllocationAction
 * @uses   \OCA\Shillinq\Service\PublicSector\PublicSectorRecords
 * @uses   \OCA\Shillinq\Service\LedgerActuals
 * @uses   \OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class JointArrangementAllocationTest extends TestCase {

	private const KEY = '55555555-0001-4000-8000-000000000001';

	private const RUN = '66666666-0001-4000-8000-000000000001';

	private const CUSTOMERS = [
		'a' => '77777777-0001-4000-8000-000000000001',
		'b' => '77777777-0002-4000-8000-000000000002',
		'c' => '77777777-0003-4000-8000-000000000003',
	];

	private InMemoryObjectServiceStub $store;

	private LifecycleFaithfulTransitionEngine $engine;

	/**
	 * Seed a joint arrangement: three active participants at 50, 30 and 20
	 * percent, one archived, a key over accounts 4100 and 4101, and EUR
	 * 100,000.01 posted on them in the first quarter.
	 *
	 * @param array<string,mixed> $runOverrides Fields of the seeded draft run.
	 *
	 * @return void
	 */
	private function seed(array $runOverrides = []): void {
		$this->store = new InMemoryObjectServiceStub(
			[
				'GRVerdeelsleutel' => [
					['id' => self::KEY, 'keyName' => 'Shared services', 'costClusterAccountNumbers' => ['4100', ' 4101 ', ''], 'verdelingsType' => 'vast-percentage', 'lineNumber' => 1],
				],
				'GRDeelnemer'      => [
					['id' => 'p-c', 'participantType' => 'municipality', 'participantName' => 'Gemeente C', 'share' => 0.2, 'actief' => true, 'lifecycleState' => 'active', 'customerId' => self::CUSTOMERS['c']],
					['id' => 'p-a', 'participantType' => 'municipality', 'participantName' => 'Gemeente A', 'share' => 0.5, 'actief' => true, 'lifecycleState' => 'active', 'customerId' => self::CUSTOMERS['a']],
					['id' => 'p-b', 'participantType' => 'municipality', 'participantName' => 'Gemeente B', 'share' => 0.3, 'actief' => true, 'lifecycleState' => 'active', 'customerId' => self::CUSTOMERS['b']],
					['id' => 'p-old', 'participantType' => 'municipality', 'participantName' => 'Gemeente Oud', 'share' => 0.4, 'actief' => true, 'lifecycleState' => 'archived'],
					['id' => 'p-off', 'participantType' => 'province', 'participantName' => 'Provincie', 'share' => 0.4, 'actief' => false, 'lifecycleState' => 'active'],
				],
				'GLTransaction'    => [
					['id' => 'tx-1', 'administrationId' => 'adm-gr', 'state' => 'posted', 'postingDate' => '2026-01-15'],
					['id' => 'tx-2', 'administrationId' => 'adm-gr', 'state' => 'posted', 'postingDate' => '2026-03-31T10:00:00+00:00'],
					['id' => 'tx-april', 'administrationId' => 'adm-gr', 'state' => 'posted', 'postingDate' => '2026-04-01'],
					['id' => 'tx-draft', 'administrationId' => 'adm-gr', 'state' => 'draft', 'postingDate' => '2026-02-01'],
					['id' => 'tx-other', 'administrationId' => 'adm-other', 'state' => 'posted', 'postingDate' => '2026-02-01'],
				],
				'GLLine'           => [
					['transactionId' => 'tx-1', 'accountNumber' => '4100', 'side' => 'debit', 'amount' => 60000.00],
					['transactionId' => 'tx-1', 'accountNumber' => '1100', 'side' => 'credit', 'amount' => 60000.00],
					['transactionId' => 'tx-2', 'accountNumber' => '4101', 'side' => 'debit', 'amount' => 40500.01],
					['transactionId' => 'tx-2', 'accountNumber' => '4101', 'side' => 'credit', 'amount' => 500.00],
					['transactionId' => 'tx-2', 'accountNumber' => '4100', 'side' => 'debit', 'amount' => 999.00, 'eliminationFlag' => true],
					['transactionId' => 'tx-april', 'accountNumber' => '4100', 'side' => 'debit', 'amount' => 1000.00],
					['transactionId' => 'tx-draft', 'accountNumber' => '4100', 'side' => 'debit', 'amount' => 1000.00],
					['transactionId' => 'tx-other', 'accountNumber' => '4100', 'side' => 'debit', 'amount' => 1000.00],
				],
				'GRAllocationRun'  => [
					array_merge(['id' => self::RUN, 'administrationId' => 'adm-gr', 'keyId' => self::KEY, 'periodFrom' => '2026-01-01', 'periodTo' => '2026-03-31', 'invoiceDate' => '2026-04-10', 'paymentTermDays' => 14, 'state' => 'draft'], $runOverrides),
				],
				'ARInvoice'        => [],
			]
		);

		$action = new JointArrangementAllocationAction($this->allocation());
		$store = $this->store;
		$this->engine = new LifecycleFaithfulTransitionEngine(
			$this->store,
			['GRAllocationRun'],
			static function (array $record, array $object) use ($action, $store): void {
				$declared = RegisterSchema::schema($record['schema'])['x-openregister-lifecycle']['transitions'][$record['action']];
				foreach (($declared['actions'] ?? []) as $step) {
					if ($step['action'] === JointArrangementAllocationAction::class) {
						$object = $action->execute($object, [], ($step['actionParameters'] ?? []), $step['action']);
						$store->setSchema($record['schema'])->saveObject($object);
					}
				}
			}
		);

	}//end seed()

	private function records(): PublicSectorRecords {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturnCallback(fn (): object => $this->engine);
		return new PublicSectorRecords($this->store, new ObjectTransitionRunner(container: $container), $location);

	}//end records()

	private function allocation(): JointArrangementAllocation {
		return new JointArrangementAllocation($this->records());

	}//end allocation()

	private function transition(string $action): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturnCallback(fn (): object => $this->engine);
		(new ObjectTransitionRunner(container: $container))->run(objectId: self::RUN, action: $action);

	}//end transition()

	private function get(string $schema, string $id): array {
		return $this->store->setSchema($schema)->find($id)->getObject();

	}//end get()

	/**
	 * The participant lines as [name, amount].
	 *
	 * @param array<string,mixed> $run The run.
	 *
	 * @return list<array{0: string, 1: float}>
	 */
	private static function amounts(array $run): array {
		return array_map(static fn (array $line): array => [$line['participantName'], $line['amount']], $run['lines']);

	}//end amounts()

	/**
	 * Errors of a payload against the real merged register schema; the stub's
	 * obj-N ids stand in for uuids.
	 *
	 * @param string              $schema The schema slug.
	 * @param array<string,mixed> $row    The payload.
	 *
	 * @return list<string>
	 */
	private function registerErrors(string $schema, array $row): array {
		unset($row['id']);
		foreach ($row as $key => $value) {
			if (is_string($value) === true && preg_match('/^obj-\d+$/', $value) === 1) {
				$row[$key] = sprintf('00000000-0000-4000-8000-%012d', (int)substr($value, 4));
			}
		}

		return RegisterSchema::errors($schema, $row);

	}//end registerErrors()

	/**
	 * The register declares the run's executor on each transition, and the
	 * fields a participant and an invoice need for it.
	 */
	public function testTheTransitionsDeclareTheirExecutor(): void {
		$transitions = RegisterSchema::schema('GRAllocationRun')['x-openregister-lifecycle']['transitions'];
		foreach (['propose' => ['draft', 'draft'], 'confirm' => ['draft', 'confirmed'], 'invoice' => ['confirmed', 'invoiced']] as $name => [$from, $to]) {
			$this->assertSame($from, $transitions[$name]['from']);
			$this->assertSame($to, $transitions[$name]['to']);
			$this->assertSame(JointArrangementAllocationAction::class, $transitions[$name]['actions'][0]['action']);
			$this->assertSame($name, $transitions[$name]['actions'][0]['actionParameters']['step']);
		}

		$this->assertArrayHasKey('customerId', RegisterSchema::schema('GRDeelnemer')['properties']);
		$this->assertArrayHasKey('allocationRunId', RegisterSchema::schema('ARInvoice')['properties']);

	}//end testTheTransitionsDeclareTheirExecutor()

	/**
	 * Scenario: three active participants at 50, 30 and 20 percent and EUR
	 * 100,000.01 posted on the key's cost clusters in the first quarter split
	 * into EUR 50,000.01, 30,000.00 and 20,000.00.
	 */
	public function testAProposedRunSplitsTheClusterCostsToTheCent(): void {
		$this->seed();
		$this->assertSame([], $this->registerErrors('GRAllocationRun', $this->get('GRAllocationRun', self::RUN)));

		$this->transition('propose');

		$run = $this->get('GRAllocationRun', self::RUN);
		$this->assertSame('draft', $run['state']);
		$this->assertSame(100000.01, $run['total']);
		$this->assertSame(
			[['Gemeente A', 50000.01], ['Gemeente B', 30000.0], ['Gemeente C', 20000.0]],
			self::amounts($run)
		);
		$this->assertSame(['p-a', 'p-b', 'p-c'], array_column($run['lines'], 'participantId'));
		$this->assertSame([0.5, 0.3, 0.2], array_column($run['lines'], 'share'));
		$this->assertSame(10000001, (int)round(array_sum(array_column($run['lines'], 'amount')) * 100));
		$this->assertSame('Shared services', $run['keyName']);
		$this->assertSame('Shared services 2026-01-01 – 2026-03-31', $run['name']);
		$this->assertSame([], $this->registerErrors('GRAllocationRun', $run));

	}//end testAProposedRunSplitsTheClusterCostsToTheCent()

	/**
	 * Shares that do not add up to one are scaled, and a credit total splits
	 * as credits that still add up to the cent.
	 */
	public function testSharesAreScaledAndACreditTotalSplitsToTheCent(): void {
		$this->seed();
		foreach (['p-a' => 2, 'p-b' => 1] as $id => $share) {
			$participant = $this->get('GRDeelnemer', $id);
			$participant['share'] = $share;
			$this->store->setSchema('GRDeelnemer')->saveObject($participant);
		}

		$participant = $this->get('GRDeelnemer', 'p-c');
		$participant['share'] = 0;
		$this->store->setSchema('GRDeelnemer')->saveObject($participant);
		$this->store->setSchema('GLLine')->saveObject(['transactionId' => 'tx-1', 'accountNumber' => '4100', 'side' => 'credit', 'amount' => 100000.11]);

		$run = $this->allocation()->propose($this->get('GRAllocationRun', self::RUN));

		$this->assertSame(-0.1, $run['total']);
		$this->assertSame([['Gemeente A', -0.07], ['Gemeente B', -0.03]], self::amounts($run));
		$this->assertSame([0.666667, 0.333333], array_column($run['lines'], 'share'));

	}//end testSharesAreScaledAndACreditTotalSplitsToTheCent()

	/**
	 * Scenario: a confirmed run with three lines invoices each participant
	 * with a draft invoice naming the run, and the same key and quarter cannot
	 * be proposed again.
	 */
	public function testAConfirmedRunInvoicesEachParticipantOnce(): void {
		$this->seed();
		$this->transition('propose');
		$this->transition('confirm');
		$this->assertSame('confirmed', $this->get('GRAllocationRun', self::RUN)['state']);

		$this->transition('invoice');

		$run = $this->get('GRAllocationRun', self::RUN);
		$this->assertSame('invoiced', $run['state']);
		$invoices = $this->store->setSchema('ARInvoice')->findAll();
		$this->assertCount(3, $invoices);
		$this->assertSame(array_column($run['lines'], 'invoiceId'), array_column($invoices, 'id'));
		$this->assertSame([self::CUSTOMERS['a'], self::CUSTOMERS['b'], self::CUSTOMERS['c']], array_column($invoices, 'customerId'));
		$this->assertSame([50000.01, 30000.0, 20000.0], array_column($invoices, 'grossAmount'));
		$this->assertSame(['GR-202601-66666666-01', 'GR-202601-66666666-02', 'GR-202601-66666666-03'], array_column($invoices, 'invoiceNumber'));
		foreach ($invoices as $invoice) {
			$this->assertSame(self::RUN, $invoice['allocationRunId']);
			$this->assertSame('draft', $invoice['lifecycleState']);
			$this->assertSame('adm-gr', $invoice['administrationId']);
			$this->assertSame('2026-04-10', $invoice['invoiceDate']);
			$this->assertSame('2026-04-24', $invoice['dueDate']);
			$this->assertSame('2026-04', $invoice['periodId']);
			$this->assertSame(0.0, $invoice['vatAmount']);
			$this->assertSame($invoice['grossAmount'], $invoice['netAmount']);
			$this->assertSame('Bijdrage Shared services 2026-01-01 t/m 2026-03-31', $invoice['invoiceLines'][0]['itemName']);
			$this->assertSame('O', $invoice['invoiceLines'][0]['vatCategory']);
			$this->assertSame([], $this->registerErrors('ARInvoice', $invoice));
		}

		$again = $this->allocation()->invoice($run);
		$this->assertSame($run['lines'], $again['lines'], 'a line with an invoice keeps it');
		$this->assertCount(3, $this->store->setSchema('ARInvoice')->findAll());

		$second = ['id' => 'run-2', 'administrationId' => 'adm-gr', 'keyId' => self::KEY, 'periodFrom' => '2026-01-01', 'periodTo' => '2026-03-31', 'state' => 'draft'];
		try {
			$this->allocation()->propose($second);
			$this->fail('a second run for the same key and quarter was proposed');
		} catch (DomainException $e) {
			$this->assertSame('This key and period already have a confirmed allocation run: Shared services 2026-01-01 – 2026-03-31.', $e->getMessage());
		}

		$nextQuarter = $this->allocation()->propose(array_merge($second, ['periodFrom' => '2026-04-01', 'periodTo' => '2026-06-30']));
		$this->assertSame(1000.0, $nextQuarter['total']);

	}//end testAConfirmedRunInvoicesEachParticipantOnce()

	/**
	 * A second draft for a confirmed key and period cannot be confirmed either,
	 * and a run that was never proposed cannot be confirmed.
	 */
	public function testConfirmRefusesAnEmptyRunAndASecondRun(): void {
		$this->seed(['state' => 'confirmed', 'name' => '', 'lines' => [['participantId' => 'p-a', 'amount' => 1.0]]]);
		$draft = ['id' => 'run-2', 'administrationId' => 'adm-gr', 'keyId' => self::KEY, 'periodFrom' => '2026-01-01', 'periodTo' => '2026-03-31', 'state' => 'draft', 'lines' => [['participantId' => 'p-a', 'amount' => 1.0]]];

		try {
			$this->allocation()->confirm($draft);
			$this->fail('a second run for the same key and quarter was confirmed');
		} catch (DomainException $e) {
			$this->assertSame('This key and period already have a confirmed allocation run: ' . self::RUN . '.', $e->getMessage());
		}

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Propose the run before confirming it.');
		$this->allocation()->confirm(array_merge($draft, ['lines' => [], 'periodFrom' => '2026-04-01', 'periodTo' => '2026-06-30']));

	}//end testConfirmRefusesAnEmptyRunAndASecondRun()

	/**
	 * A participant without a customer stops the invoicing before any invoice
	 * is written, naming the participant.
	 */
	public function testAParticipantWithoutACustomerStopsTheInvoicing(): void {
		$this->seed();
		$participant = $this->get('GRDeelnemer', 'p-b');
		unset($participant['customerId']);
		$this->store->setSchema('GRDeelnemer')->saveObject($participant);
		$run = $this->allocation()->propose($this->get('GRAllocationRun', self::RUN));

		try {
			$this->allocation()->invoice($run);
			$this->fail('invoiced a participant without a customer');
		} catch (DomainException $e) {
			$this->assertSame('Link a customer to these participants before invoicing: Gemeente B.', $e->getMessage());
		}

		$this->assertSame([], $this->store->setSchema('ARInvoice')->findAll());

	}//end testAParticipantWithoutACustomerStopsTheInvoicing()

	/**
	 * Without an invoice date the invoices are dated today, due after 30 days;
	 * a zero line gets no invoice.
	 */
	public function testInvoiceDefaultsAndAZeroLine(): void {
		$this->seed();
		$run = $this->get('GRAllocationRun', self::RUN);
		unset($run['invoiceDate'], $run['paymentTermDays']);
		$run['lines'] = [
			['participantId' => 'p-a', 'participantName' => 'Gemeente A', 'share' => 0.5, 'amount' => 10.0],
			['participantId' => 'p-b', 'participantName' => 'Gemeente B', 'share' => 0.5, 'amount' => 0.0],
		];

		$invoiced = $this->allocation()->invoice($run);

		$invoices = $this->store->setSchema('ARInvoice')->findAll();
		$this->assertCount(1, $invoices);
		$this->assertSame($invoices[0]['id'], $invoiced['lines'][0]['invoiceId']);
		$this->assertArrayNotHasKey('invoiceId', $invoiced['lines'][1]);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $invoices[0]['invoiceDate']);
		$this->assertSame(
			(new \DateTimeImmutable($invoices[0]['invoiceDate']))->modify('+30 days')->format('Y-m-d'),
			$invoices[0]['dueDate']
		);

	}//end testInvoiceDefaultsAndAZeroLine()

	/**
	 * A run without its fields, with an unknown key or with no participant to
	 * split over is refused with the reason.
	 *
	 * @dataProvider refusedRuns
	 *
	 * @param array<string,mixed> $overrides The run fields changed.
	 * @param string              $message   The refusal.
	 * @param bool                $noShares  Whether every participant is archived first.
	 */
	public function testAnIncompleteRunIsRefused(array $overrides, string $message, bool $noShares = false): void {
		$this->seed();
		if ($noShares === true) {
			foreach (['p-a', 'p-b', 'p-c'] as $id) {
				$participant = $this->get('GRDeelnemer', $id);
				$participant['lifecycleState'] = 'archived';
				$this->store->setSchema('GRDeelnemer')->saveObject($participant);
			}
		}

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage($message);
		$this->allocation()->propose(array_merge($this->get('GRAllocationRun', self::RUN), $overrides));

	}//end testAnIncompleteRunIsRefused()

	/**
	 * @return array<string, array{0: array<string,mixed>, 1: string, 2?: bool}>
	 */
	public static function refusedRuns(): array {
		return [
			'no administration'  => [['administrationId' => ''], 'Choose the administration and the allocation key of the run.'],
			'no key'             => [['keyId' => ''], 'Choose the administration and the allocation key of the run.'],
			'not a date'         => [['periodTo' => '2026-02-30'], 'Enter the period of the run as two dates'],
			'backwards'          => [['periodFrom' => '2026-04-01'], 'Enter the period of the run as two dates'],
			'unknown key'        => [['keyId' => '55555555-0009-4000-8000-000000000009'], 'The allocation key of the run was not found.'],
			'no participant'     => [[], 'There is no active participant with a share to split the costs over.', true],
		];

	}//end refusedRuns()

	/**
	 * An undeclared step is refused rather than guessed.
	 */
	public function testAnUnknownStepIsRefused(): void {
		$this->seed();
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('The allocation run step is not known.');
		(new JointArrangementAllocationAction($this->allocation()))->execute(['id' => self::RUN], [], ['step' => 'post'], 'x');

	}//end testAnUnknownStepIsRefused()
}//end class
