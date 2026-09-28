<?php

/**
 * A bank feed pull becomes a statement, and an exact payment books itself.
 *
 * Driven from the real ObjectCreatedEvent through BankfeedSyncedListener, the
 * real intake, booker, manual match and settlement services, over an in-memory
 * store and an engine that obeys the merged register's declared lifecycles.
 * Every statement and line written is validated against the merged schema.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Bank
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bank-connectors/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Bank;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Listener\BankfeedSyncedListener;
use OCA\Shillinq\Listener\ReconciliationMatchSettlementListener;
use OCA\Shillinq\Service\Bank\BankfeedIntakeService;
use OCA\Shillinq\Service\Bank\ExactMatchBooker;
use OCA\Shillinq\Service\Bank\InvoiceSettlementService;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCA\Shillinq\Service\Bank\StatementIntakeService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * REQ-BCON-002, REQ-BCON-003.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BankfeedIntakeServiceTest extends TestCase {
	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * Every save.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * The listener under test.
	 *
	 * @var BankfeedSyncedListener
	 */
	private BankfeedSyncedListener $listener;

	/**
	 * Twelve transactions in the Berlin Group shape: one pays VF-2026-0877
	 * exactly, one is an ambiguous subscription, ten are card spend.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function transactions(): array {
		$rows = [
			[
				'transactionId' => 'tx-1', 'endToEndId' => 'E2E-0877', 'bookingDate' => '2026-09-28', 'valueDate' => '2026-09-28',
				'transactionAmount' => ['amount' => '1210.00', 'currency' => 'EUR'], 'debtorName' => 'Bakkerij De Korenaar',
				'debtorAccount' => ['iban' => 'NL02ABNA0123456789'], 'remittanceInformationUnstructured' => 'Betaling VF-2026-0877',
			],
			[
				'transactionId' => 'tx-2', 'endToEndId' => 'E2E-ABO', 'valueDate' => '2026-09-28',
				'transactionAmount' => ['amount' => '99.95', 'currency' => 'EUR'], 'debtorName' => 'Klant', 'remittanceInformationUnstructured' => 'abonnement',
			],
		];
		for ($i = 3; $i <= 12; $i++) {
			$rows[] = [
				'transactionId' => 'tx-' . $i, 'endToEndId' => 'E2E-CARD-' . $i, 'valueDate' => '2026-09-27',
				'transactionAmount' => ['amount' => '-' . $i . '.50', 'currency' => 'EUR'], 'creditorName' => 'Winkel ' . $i,
				'remittanceInformationUnstructured' => 'Pinbetaling',
			];
		}

		return $rows;

	}//end transactions()

	/**
	 * Seed and wire.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryObjectServiceStub(
			[
				'BankAccount' => [
					['id' => 'ba-ing', 'iban' => 'NL20INGB0001234567', 'ledgerAccountNumber' => '1100', 'administrationId' => 'adm-gv', 'bankConnectionId' => '5a0e2f35-6c1c-4a7e-9d3a-1f7d2b9c8e11'],
				],
				'bankfeed_batch' => [
					['id' => 'batch-uuid-1', 'accountIban' => 'NL20INGB0001234567', 'transactionCount' => 12, 'transactions' => self::transactions()],
				],
				'ARInvoice' => [
					['id' => 'ar-0877', 'invoiceNumber' => 'VF-2026-0877', 'lifecycleState' => 'issued', 'grossAmount' => 1210.00, 'administrationId' => 'adm-gv'],
					['id' => 'ar-abo-1', 'invoiceNumber' => 'VF-2026-0901', 'lifecycleState' => 'issued', 'grossAmount' => 99.95, 'administrationId' => 'adm-gv'],
					['id' => 'ar-abo-2', 'invoiceNumber' => 'VF-2026-0902', 'lifecycleState' => 'issued', 'grossAmount' => 99.95, 'administrationId' => 'adm-gv'],
				],
			],
			$this->saved
		);

		$settings = $this->createConfiguredMock(SettingsService::class, ['getRegisterSlug' => 'shillinq']);
		$logger = $this->createMock(LoggerInterface::class);
		$settlementListener = null;
		$engine = new LifecycleFaithfulTransitionEngine(
			store: $this->store,
			schemas: ['ReconciliationMatch', 'BankStatementLine', 'ARInvoice', 'APTransaction'],
			onRan: function (array $record, array $object) use (&$settlementListener): void {
				if ($record['schema'] !== 'ReconciliationMatch') {
					return;
				}

				$entity = new ObjectEntity();
				$entity->setObject($object);
				$entity->setSchema('ReconciliationMatch');
				$settlementListener->handle(new ObjectTransitionedEvent($entity, $record['action'], $record['from'], $record['to'], null, 'shillinq', 'ReconciliationMatch'));
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($engine);
		$runner = new ObjectTransitionRunner(container: $container);

		$settlementListener = new ReconciliationMatchSettlementListener(
			settlement: new InvoiceSettlementService(objectService: $this->store, transitions: $runner, settings: $settings, logger: $logger),
			schemas: $this->createConfiguredMock(ListenerSchemaResolver::class, ['matchesSchema' => true]),
			logger: $logger,
		);
		$matches = new ManualMatchService(objectService: $this->store, transitions: $runner, settings: $settings, logger: $logger);
		$intake = new BankfeedIntakeService(
			objectService: $this->store,
			intake: new StatementIntakeService(objectService: $this->store, settings: $settings, logger: $logger),
			booker: new ExactMatchBooker(objectService: $this->store, matches: $matches, settings: $settings, logger: $logger),
			settings: $settings,
			logger: $logger,
		);
		$this->listener = new BankfeedSyncedListener(
			schemaResolver: $this->createConfiguredMock(ListenerSchemaResolver::class, ['matchesRegisterAndSchema' => true]),
			intake: $intake,
			logger: $logger,
		);

	}//end setUp()

	/**
	 * The synced CloudEvent object integriq writes.
	 *
	 * @param string $type The CloudEvent type.
	 *
	 * @return ObjectCreatedEvent
	 */
	private static function syncedEvent(string $type = BankfeedSyncedListener::TYPE_SYNCED): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setObject(
			[
				'type' => $type,
				'data' => [
					'connectionId' => '5a0e2f35-6c1c-4a7e-9d3a-1f7d2b9c8e11',
					'accountIban' => 'NL20 INGB 0001 2345 67',
					'since' => '2026-09-27T06:00:00+00:00',
					'until' => '2026-09-28T06:00:00+00:00',
					'transactionCount' => 12,
					'batchUri' => '/apps/openregister/api/objects/integriq/bankfeed_batch/batch-uuid-1',
				],
			]
		);
		return new ObjectCreatedEvent($entity);

	}//end syncedEvent()

	/**
	 * Rows written for one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(string $schema): array {
		return array_map(static fn ($e): array => $e->getObject(), $this->store->setSchema($schema)->findAll([]) === [] ? [] : array_map(fn (array $r) => $this->store->find($r['id'], schema: $schema), $this->store->setSchema($schema)->findAll([])));

	}//end rows()

	/**
	 * Scenario: twelve transactions arrive from the morning pull.
	 *
	 * @return void
	 */
	public function testTwelveTransactionsArriveAsOneStatement(): void {
		$this->listener->handle(self::syncedEvent());

		$statements = $this->rows('BankStatement');
		self::assertCount(1, $statements);
		self::assertSame('feed', $statements[0]['statementSource']);
		self::assertSame('NL20INGB0001234567', $statements[0]['bankAccountIban']);
		self::assertCount(12, $this->rows('BankStatementLine'));
		self::assertSame('2026-09-28T06:00:00+00:00', $this->store->find('ba-ing', schema: 'BankAccount')->getObject()['lastSyncAt']);

		foreach ($this->saved as $save) {
			if (in_array($save['schema'], ['BankStatement', 'BankStatementLine', 'ReconciliationMatch'], true) === true) {
				// The in-memory store mints `obj-N` ids where OpenRegister mints
				// uuids; give the references the shape the engine would.
				$object = array_map(
					static fn ($value) => (is_string($value) === true && preg_match('/^obj-(\d+)$/', $value, $m) === 1)
						? sprintf('00000000-0000-4000-8000-%012d', (int)$m[1]) : $value,
					$save['object']
				);
				self::assertSame([], RegisterSchema::errors(slug: $save['schema'], object: $object), $save['schema']);
			}
		}

	}//end testTwelveTransactionsArriveAsOneStatement()

	/**
	 * Scenario: the same batch arrives twice.
	 *
	 * @return void
	 */
	public function testTheSameBatchTwiceWritesOneStatement(): void {
		$this->listener->handle(self::syncedEvent());
		$this->listener->handle(self::syncedEvent());

		self::assertCount(1, $this->rows('BankStatement'));
		self::assertCount(12, $this->rows('BankStatementLine'));

	}//end testTheSameBatchTwiceWritesOneStatement()

	/**
	 * Scenario: a customer payment settles its invoice as it arrives.
	 *
	 * @return void
	 */
	public function testExactPaymentSettlesItsInvoice(): void {
		$this->listener->handle(self::syncedEvent());

		self::assertSame('paid', $this->store->find('ar-0877', schema: 'ARInvoice')->getObject()['lifecycleState']);
		$matches = $this->rows('ReconciliationMatch');
		self::assertCount(1, $matches);
		self::assertSame(ExactMatchBooker::ACTOR, $matches[0]['confirmedBy']);
		self::assertSame('Booked on arrival by system:bankfeed: exact amount EUR 1,210.00 and reference VF-2026-0877', $matches[0]['resolutionReason']);

	}//end testExactPaymentSettlesItsInvoice()

	/**
	 * Scenario: an ambiguous payment waits for a person.
	 *
	 * @return void
	 */
	public function testAmbiguousPaymentWaits(): void {
		$this->listener->handle(self::syncedEvent());

		self::assertSame('issued', $this->store->find('ar-abo-1', schema: 'ARInvoice')->getObject()['lifecycleState']);
		self::assertSame('issued', $this->store->find('ar-abo-2', schema: 'ARInvoice')->getObject()['lifecycleState']);
		$unmatched = array_filter($this->rows('BankStatementLine'), static fn (array $l): bool => $l['status'] === 'unmatched');
		self::assertCount(11, $unmatched);

	}//end testAmbiguousPaymentWaits()

	/**
	 * A second batch repeating transactions already on file for the account writes no new lines.
	 *
	 * @return void
	 */
	public function testRepeatedEndToEndReferencesAreSkipped(): void {
		$this->listener->handle(self::syncedEvent());
		$this->store->setSchema('bankfeed_batch')->saveObject(['id' => 'batch-uuid-2', 'transactions' => self::transactions()]);
		$event = self::syncedEvent();
		$payload = $event->getObject()->getObject();
		$payload['data']['batchUri'] = '/apps/openregister/api/objects/integriq/bankfeed_batch/batch-uuid-2';
		$event->getObject()->setObject($payload);

		$this->listener->handle($event);

		self::assertCount(2, $this->rows('BankStatement'));
		self::assertCount(12, $this->rows('BankStatementLine'));

	}//end testRepeatedEndToEndReferencesAreSkipped()

	/**
	 * Another CloudEvent type is ignored.
	 *
	 * @return void
	 */
	public function testOtherCloudEventsAreIgnored(): void {
		$this->listener->handle(self::syncedEvent(type: 'nl.conduction.payment.status'));

		self::assertSame([], $this->rows('BankStatement'));

	}//end testOtherCloudEventsAreIgnored()

	/**
	 * A reference must match as a whole word: VF-2026-087 is not VF-2026-0877.
	 *
	 * @return void
	 */
	public function testReferenceMatchesWholeWordsOnly(): void {
		self::assertSame('VF-2026-0877', ExactMatchBooker::referenceFound(text: 'betaling vf-2026-0877.', invoice: ['invoiceNumber' => 'VF-2026-0877']));
		self::assertSame('', ExactMatchBooker::referenceFound(text: 'betaling vf-2026-08771', invoice: ['invoiceNumber' => 'VF-2026-0877']));

	}//end testReferenceMatchesWholeWordsOnly()
}//end class
