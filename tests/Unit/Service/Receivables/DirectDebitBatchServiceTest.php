<?php

/**
 * Unit tests for DirectDebitBatchService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Receivables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-a-batch-is-proposed-from-open-invoices-with-a-mandate-req-sdd-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Receivables;

use DateTime;
use OCA\Shillinq\Service\Receivables\DirectDebitBatchService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SDD-011: a draft batch from the open invoices with a mandate.
 *
 * @covers \OCA\Shillinq\Service\Receivables\DirectDebitBatchService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 * @uses   \OCA\Shillinq\Service\SettingsService
 */
class DirectDebitBatchServiceTest extends TestCase {

	private const ADMIN = 'adm-1';

	/**
	 * Everything the store wrote.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * A mandate of the administration.
	 *
	 * @param string               $id     The id.
	 * @param array<string, mixed> $fields Fields over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function mandate(string $id, array $fields=[]): array {
		return $fields + [
			'id' => $id,
			'mandateReference' => 'MR-' . $id,
			'creditorIdentifier' => 'NL98ZZZ999999999999',
			'scheme' => 'CORE',
			'type' => 'recurring',
			'status' => 'active',
			'signedAt' => '2026-01-05T10:00:00+01:00',
			'signedBy' => 'Klant',
			'debtorIban' => 'NL91ABNA0417164300',
			'debtorName' => 'Klant ' . $id,
			'debtorAccountType' => 'consumer',
			'administrationId' => self::ADMIN,
		];

	}//end mandate()

	/**
	 * An open direct debit invoice.
	 *
	 * @param string               $id     The id.
	 * @param array<string, mixed> $fields Fields over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function invoice(string $id, array $fields=[]): array {
		return $fields + [
			'id' => $id,
			'invoiceNumber' => 'F-2026-' . $id,
			'customerId' => 'cust-' . $id,
			'administrationId' => self::ADMIN,
			'dueDate' => '2026-10-10',
			'currency' => 'EUR',
			'lifecycleState' => 'issued',
			'paymentMethod' => 'direct_debit',
			'grossAmount' => 500.0,
			'paidAmount' => 0.0,
		];

	}//end invoice()

	/**
	 * The fixture: the October run of the spec's scenario and its edges.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function records(): array {
		return [
			'SepaMandate' => [
				self::mandate(id: 'm-new'),
				self::mandate(id: 'm-used', fields: ['lastUsedAt' => '2026-09-15']),
				self::mandate(id: 'm-cancelled', fields: ['status' => 'cancelled']),
				self::mandate(id: 'm-ended', fields: ['lastCollectionDate' => '2026-09-30']),
				self::mandate(id: 'm-b2b', fields: ['scheme' => 'B2B', 'type' => 'oneoff', 'debtorAccountType' => 'business']),
				self::mandate(id: 'm-elsewhere', fields: ['administrationId' => 'adm-2']),
			],
			'CustomerMaster' => [
				['id' => 'c-obj-118', 'customerId' => 'cust-118', 'defaultMandateId' => 'm-new', 'administrationId' => self::ADMIN],
				['id' => 'cust-119', 'customerId' => 'K-119', 'defaultMandateId' => 'm-cancelled', 'administrationId' => self::ADMIN],
			],
			'ARInvoice' => [
				// By the customer's default mandate, matched on the customer number: first collection.
				self::invoice(id: '118', fields: ['dueDate' => '2026-10-01', 'grossAmount' => 750.0]),
				// Customer matched on the object id; the mandate is cancelled.
				self::invoice(id: '119', fields: ['customerId' => 'cust-119']),
				// Its own mandate, already used: recurring; 250 of 1000 paid.
				self::invoice(id: '120', fields: ['directDebitMandateId' => 'm-used', 'grossAmount' => 1000.0, 'paidAmount' => 250.0]),
				// The same new mandate again in this run: the second is recurring.
				self::invoice(id: '121', fields: ['directDebitMandateId' => 'm-new', 'amountDue' => 125.5, 'dueDate' => '2026-10-12']),
				self::invoice(id: '122', fields: ['directDebitMandateId' => 'm-ended']),
				self::invoice(id: '123', fields: ['directDebitMandateId' => 'm-b2b']),
				self::invoice(id: '124', fields: ['directDebitMandateId' => 'm-new', 'currency' => 'USD']),
				self::invoice(id: '125', fields: ['directDebitMandateId' => 'm-new', 'grossAmount' => 100.0, 'paidAmount' => 100.0]),
				self::invoice(id: '126', fields: ['directDebitMandateId' => 'm-new']),
				self::invoice(id: '127', fields: ['customerId' => 'nobody']),
				self::invoice(id: '128', fields: ['directDebitMandateId' => 'm-elsewhere']),
				// Not collected by direct debit, not open, or another administration: never read as candidates.
				self::invoice(id: '130', fields: ['directDebitMandateId' => 'm-new', 'paymentMethod' => 'bank_transfer']),
				self::invoice(id: '131', fields: ['directDebitMandateId' => 'm-new', 'lifecycleState' => 'paid']),
				self::invoice(id: '132', fields: ['directDebitMandateId' => 'm-new', 'administrationId' => 'adm-2']),
			],
			'DirectDebitCollection' => [
				['id' => 'dc-old', 'invoiceId' => '126', 'mandateId' => 'm-new', 'status' => 'scheduled', 'administrationId' => self::ADMIN],
				['id' => 'dc-rejected', 'invoiceId' => '120', 'mandateId' => 'm-used', 'status' => 'rejected', 'administrationId' => self::ADMIN],
			],
		];

	}//end records()

	/**
	 * The service over the fixture, with the clock at 2026-10-01 09:30 UTC.
	 *
	 * @param array<string, list<array<string, mixed>>>|null $records The store's records; the default fixture when null.
	 *
	 * @return DirectDebitBatchService
	 */
	private function service(?array $records=null): DirectDebitBatchService {
		$this->saved = [];
		$store = new InMemoryObjectServiceStub(data: ($records ?? self::records()), saveSink: $this->saved, findAllRendersEntities: true);
		$location = $this->createConfiguredMock(originalClassName: RegisterLocation::class, configuration: ['getRegisterSlug' => 'shillinq']);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-01T09:30:00+00:00'));

		return new DirectDebitBatchService(objectService: $store, registerLocation: $location, time: $time);

	}//end service()

	/**
	 * A batch per scheme and sequence type with a collection per invoice; the rest named with the reason.
	 *
	 * @return void
	 */
	public function testAProposalCollectsWhatHasAnActiveMandateAndNamesWhatItSkipped(): void {
		$result = $this->service()->propose(administrationId: self::ADMIN, collectionDate: '2026-10-15');

		$batches = [];
		foreach ($result['batches'] as $batch) {
			$batches[$batch['scheme'] . '-' . $batch['sequenceType']] = $batch;
		}

		$this->assertSame(['B2B-OOFF', 'CORE-FRST', 'CORE-RCUR'], array_keys($batches));

		$first = $batches['CORE-FRST'];
		$this->assertSame('draft', $first['status']);
		$this->assertSame('2026-10-15', $first['requestedCollectionDate']);
		$this->assertSame(1, $first['collectionCount']);
		$this->assertSame(750.0, $first['controlSum']);
		$this->assertSame('DD-20261001093000-CORE-FRST', $first['messageId']);
		$this->assertSame('2026-10-01T09:30:00+00:00', $first['creationDateTime']);
		$this->assertSame(['118'], array_column($first['collections'], 'invoiceId'));
		$this->assertSame('m-new', $first['collections'][0]['mandateId']);
		$this->assertSame('DD-F-2026-118', $first['collections'][0]['endToEndId']);

		$recurring = $batches['CORE-RCUR'];
		$this->assertSame(['120', '121'], array_column($recurring['collections'], 'invoiceId'));
		$this->assertSame([750.0, 125.5], array_column($recurring['collections'], 'amount'));
		$this->assertSame(875.5, $recurring['controlSum']);
		$this->assertSame(2, $recurring['collectionCount']);

		$this->assertSame(['123'], array_column($batches['B2B-OOFF']['collections'], 'invoiceId'));

		foreach ($result['batches'] as $batch) {
			foreach ($batch['collections'] as $collection) {
				$this->assertSame($batch['id'], $collection['submittedInBatchId']);
				$this->assertSame('scheduled', $collection['status']);
				$this->assertSame('2026-10-15', $collection['requestedCollectionDate']);
				$this->assertSame(self::ADMIN, $collection['administrationId']);
			}
		}

		$reasons = [];
		foreach ($result['skipped'] as $skip) {
			$reasons[$skip['invoiceNumber']] = [$skip['reason'], $skip['detail']];
		}

		ksort($reasons);
		$this->assertSame(
			[
				'F-2026-119' => ['mandate-inactive', 'cancelled'],
				'F-2026-122' => ['mandate-ended', '2026-09-30'],
				'F-2026-124' => ['not-euro', ''],
				'F-2026-125' => ['nothing-due', ''],
				'F-2026-126' => ['already-collected', ''],
				'F-2026-127' => ['no-mandate', ''],
				'F-2026-128' => ['no-mandate', ''],
			],
			$reasons
		);

	}//end testAProposalCollectsWhatHasAnActiveMandateAndNamesWhatItSkipped()

	/**
	 * What the proposal writes is valid against the shipped register fragments.
	 *
	 * @return void
	 */
	public function testEveryWrittenBatchAndCollectionIsValidAgainstTheRegister(): void {
		$this->service()->propose(administrationId: self::ADMIN, collectionDate: '2026-10-15');

		$schemas = array_column($this->saved, 'schema');
		$this->assertSame(3, count(array_keys($schemas, 'DirectDebitBatch', true)));
		$this->assertSame(4, count(array_keys($schemas, 'DirectDebitCollection', true)));
		foreach ($this->saved as $write) {
			$object = $write['object'];
			unset($object['id']);
			$this->assertSame([], RegisterSchema::errors($write['schema'], $object), $write['schema']);
		}

	}//end testEveryWrittenBatchAndCollectionIsValidAgainstTheRegister()

	/**
	 * A mandate an earlier collection already used is collected as recurring, even when its own lastUsedAt is empty.
	 *
	 * @return void
	 */
	public function testAMandateUsedByAnEarlierCollectionIsCollectedAsRecurring(): void {
		$records = [
			'SepaMandate' => [
				self::mandate(id: 'm-a'),
				self::mandate(id: 'm-b'),
			],
			'CustomerMaster' => [],
			'ARInvoice' => [
				self::invoice(id: '201', fields: ['directDebitMandateId' => 'm-a', 'grossAmount' => 40.0]),
				self::invoice(id: '202', fields: ['directDebitMandateId' => 'm-b', 'grossAmount' => 60.0]),
			],
			'DirectDebitCollection' => [
				// Succeeded on another invoice: m-a has been used.
				['id' => 'dc-1', 'invoiceId' => '190', 'mandateId' => 'm-a', 'status' => 'succeeded', 'administrationId' => self::ADMIN],
				// Rejected collections do not count as a use of m-b.
				['id' => 'dc-2', 'invoiceId' => '191', 'mandateId' => 'm-b', 'status' => 'rejected', 'administrationId' => self::ADMIN],
				// A used state without a mandate id marks nothing.
				['id' => 'dc-3', 'invoiceId' => '192', 'mandateId' => ' ', 'status' => 'submitted', 'administrationId' => self::ADMIN],
			],
		];

		$result = $this->service(records: $records)->propose(administrationId: self::ADMIN, collectionDate: '2026-10-15');

		$this->assertSame(expected: [], actual: $result['skipped']);
		$this->assertCount(expectedCount: 2, haystack: $result['batches']);

		$byType = [];
		foreach ($result['batches'] as $batch) {
			$byType[$batch['sequenceType']] = $batch;
		}

		ksort(array: $byType);
		$this->assertSame(expected: ['FRST', 'RCUR'], actual: array_keys(array: $byType));
		$this->assertSame(expected: ['202'], actual: array_column(array: $byType['FRST']['collections'], column_key: 'invoiceId'));
		$this->assertSame(expected: ['m-b'], actual: array_column(array: $byType['FRST']['collections'], column_key: 'mandateId'));
		$this->assertSame(expected: ['201'], actual: array_column(array: $byType['RCUR']['collections'], column_key: 'invoiceId'));
		$this->assertSame(expected: ['m-a'], actual: array_column(array: $byType['RCUR']['collections'], column_key: 'mandateId'));
		$this->assertSame(expected: 40.0, actual: $byType['RCUR']['controlSum']);
		$this->assertSame(expected: 'DD-20261001093000-CORE-RCUR', actual: $byType['RCUR']['messageId']);

	}//end testAMandateUsedByAnEarlierCollectionIsCollectedAsRecurring()

	/**
	 * Nothing to collect writes nothing and answers no batch.
	 *
	 * @return void
	 */
	public function testNothingToCollectWritesNothing(): void {
		$result = $this->service()->propose(administrationId: 'adm-empty', collectionDate: '2026-10-15');

		$this->assertSame(['batches' => [], 'skipped' => []], $result);
		$this->assertSame([], $this->saved);

	}//end testNothingToCollectWritesNothing()
}//end class
