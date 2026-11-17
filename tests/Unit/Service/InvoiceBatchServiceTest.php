<?php

/**
 * Unit tests for InvoiceBatchService.
 *
 * REQ-SIS-004: one invoice per selected customer from one set of lines, the
 * due date from each customer's payment term, idempotent per batch and
 * customer, optionally issued and sent. OpenRegister is doubled at the
 * object-service boundary, the send at InvoiceBatchSendService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-a-batch-creates-one-invoice-per-customer-req-sis-004
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InvoiceBatchSendService;
use OCA\Shillinq\Service\InvoiceBatchService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IL10N;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The maintenance contract of the spec billed to three customers.
 */
#[CoversClass(InvoiceBatchService::class)]
#[UsesClass(InvoiceBatchSendService::class)]
#[UsesClass(ObjectIdentifier::class)]
class InvoiceBatchServiceTest extends TestCase {

	/**
	 * Stored objects by schema and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Saved objects, in order, as [schema, data].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The schema whose next save fails, or ''.
	 */
	private string $failOn = '';

	private InvoiceBatchSendService&MockObject $sendBatches;

	/**
	 * The service over an in-memory object service.
	 *
	 * @return InvoiceBatchService
	 */
	private function service(): InvoiceBatchService {
		$schema = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function (string|int $value) use (&$schema, $objects): ObjectServiceInterface {
				$schema = (string)$value;
				return $objects;
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id) use (&$schema): ?ObjectEntityInterface {
				$row = ($this->store[$schema][(string)$id] ?? null);
				if ($row === null) {
					throw new RuntimeException('not found');
				}

				return $this->entity(row: $row);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use (&$schema): array {
				$rows = [];
				foreach (($this->store[$schema] ?? []) as $row) {
					$match = true;
					foreach ((array)($config['filters'] ?? []) as $field => $value) {
						$match = ($match === true && (string)($row[$field] ?? '') === (string)$value);
					}

					if ($match === true) {
						$rows[] = $this->entity(row: $row);
					}
				}

				return $rows;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) use (&$schema): ObjectEntityInterface {
				if ($schema === $this->failOn) {
					$this->failOn = '';
					throw new RuntimeException('connection dropped');
				}

				$object['id'] = ($object['id'] ?? ($schema . '-' . count($this->saved)));
				$this->saved[] = [$schema, $object];
				$this->store[$schema][$object['id']] = $object;
				return $this->entity(row: $object);
			}
		);

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'adm-kade');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new InvoiceBatchService($objects, $context, $this->sendBatches, $appConfig, $l10n, new NullLogger());
	}//end service()

	/**
	 * An entity double over a row.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $row): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()

	protected function setUp(): void {
		$this->sendBatches = $this->createMock(InvoiceBatchSendService::class);
		$this->store['CustomerMaster'] = [
			'cust-korenaar' => ['id' => 'cust-korenaar', 'customerId' => 'C-001', 'administrationId' => 'adm-kade', 'legalName' => 'Bakkerij De Korenaar B.V.', 'paymentTermDays' => 14],
			'cust-voorbeeld' => ['id' => 'cust-voorbeeld', 'customerId' => 'C-002', 'administrationId' => 'adm-kade', 'legalName' => 'Gemeente Voorbeeld', 'paymentTermDays' => 30],
			'cust-linde' => ['id' => 'cust-linde', 'customerId' => 'C-003', 'administrationId' => 'adm-kade', 'legalName' => 'Stichting Buurthuis De Linde', 'paymentTermDays' => 21],
			'cust-other' => ['id' => 'cust-other', 'customerId' => 'C-900', 'administrationId' => 'adm-other', 'legalName' => 'Elsewhere B.V.', 'paymentTermDays' => 7],
		];
	}//end setUp()

	/**
	 * The batch request of the spec's scenario.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function request(array $overrides = []): array {
		return array_merge(
			[
				'batchId' => 'BATCH-OKT26',
				'customerIds' => ['cust-korenaar', 'cust-voorbeeld', 'cust-linde'],
				'charge' => [
					'invoiceDate' => '2026-10-01',
					'lines' => [['description' => 'Onderhoudscontract oktober 2026', 'quantity' => 1, 'unitPrice' => 250, 'vatRate' => 21]],
				],
				'issue' => true,
				'send' => true,
			],
			$overrides
		);
	}//end request()

	/**
	 * The stored invoices, keyed by customer code.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function invoicesByCustomer(): array {
		$out = [];
		foreach (($this->store['ARInvoice'] ?? []) as $invoice) {
			$out[(string)$invoice['customerId']] = $invoice;
		}

		return $out;
	}//end invoicesByCustomer()

	public function testThreeCustomersGetThreeIssuedInvoicesDueByTheirOwnTerms(): void {
		$this->sendBatches->expects($this->once())->method('queue')
			->with('adm-kade', $this->lengthOf(3), ['requestedBy' => 'bookkeeper'])
			->willReturn(['id' => 'send-batch-1', 'kind' => 'send']);

		$batch = $this->service()->create(administrationId: 'adm-kade', request: self::request(), requestedBy: 'bookkeeper');

		$invoices = $this->invoicesByCustomer();
		$this->assertCount(3, $invoices);
		$this->assertSame('2026-10-15', $invoices['C-001']['dueDate']);
		$this->assertSame('2026-10-31', $invoices['C-002']['dueDate']);
		$this->assertSame('2026-10-22', $invoices['C-003']['dueDate']);
		foreach ($invoices as $invoice) {
			$this->assertSame('issued', $invoice['lifecycleState']);
			$this->assertSame(250.0, $invoice['netAmount']);
			$this->assertSame(52.5, $invoice['vatAmount']);
			$this->assertSame(302.5, $invoice['grossAmount']);
			$this->assertSame('adm-kade', $invoice['administrationId']);
			$this->assertSame('S', $invoice['invoiceLines'][0]['vatCategory']);
		}

		$this->assertSame('create', $batch['kind']);
		$this->assertSame('done', $batch['state']);
		$this->assertSame('send-batch-1', $batch['sendBatchId']);
		$this->assertSame('bookkeeper', $batch['requestedBy']);
		$this->assertSame(['created', 'created', 'created'], array_column($batch['outcomes'], 'status'));
		$this->assertSame(['cust-korenaar', 'cust-voorbeeld', 'cust-linde'], array_column($batch['outcomes'], 'customerId'));
		$this->assertCount(3, $batch['invoiceIds']);
	}//end testThreeCustomersGetThreeIssuedInvoicesDueByTheirOwnTerms()

	public function testARetriedHalfWrittenBatchWritesOnlyTheMissingInvoice(): void {
		$this->sendBatches->method('queue')->willReturn(['id' => 'send-batch-1']);
		$service = $this->service();

		// The first submission wrote two customers' invoices before the connection dropped.
		$service->create(
			administrationId: 'adm-kade',
			request: self::request(['customerIds' => ['cust-korenaar', 'cust-voorbeeld'], 'send' => false])
		);
		$this->assertCount(2, $this->store['ARInvoice']);
		$written = count(array_filter($this->saved, static fn (array $save): bool => $save[0] === 'ARInvoice'));

		$batch = $service->create(administrationId: 'adm-kade', request: self::request(['send' => false]));

		$after = count(array_filter($this->saved, static fn (array $save): bool => $save[0] === 'ARInvoice'));
		$this->assertSame($written + 1, $after, 'Only the third customer\'s invoice is written.');
		$this->assertCount(3, $this->store['ARInvoice']);
		$this->assertSame(['existing', 'existing', 'created'], array_column($batch['outcomes'], 'status'));
		$this->assertCount(1, $this->store['InvoiceBatch'], 'The retry updates the same batch.');
	}//end testARetriedHalfWrittenBatchWritesOnlyTheMissingInvoice()

	public function testCustomersOutsideTheAdministrationAreSkipped(): void {
		$service = $this->service();
		$this->sendBatches->expects($this->never())->method('queue');

		$batch = $service->create(
			administrationId: 'adm-kade',
			request: self::request(['send' => false, 'issue' => false, 'customerIds' => ['cust-korenaar', 'cust-unknown', 'cust-other']])
		);

		$this->assertSame(['created', 'skipped', 'skipped'], array_column($batch['outcomes'], 'status'));
		$this->assertSame('The customer was not found in this administration.', $batch['outcomes'][2]['reason']);
		$this->assertSame('draft', array_values($this->store['ARInvoice'])[0]['lifecycleState']);
	}//end testCustomersOutsideTheAdministrationAreSkipped()

	public function testAnInvoiceThatCannotBeWrittenIsAFailedOutcome(): void {
		$this->failOn = 'ARInvoice';

		$batch = $this->service()->create(
			administrationId: 'adm-kade',
			request: self::request(['send' => false, 'customerIds' => ['cust-korenaar', 'cust-linde']])
		);

		$this->assertSame(['failed', 'created'], array_column($batch['outcomes'], 'status'));
		$this->assertSame('The invoice could not be written.', $batch['outcomes'][0]['reason']);
		$this->assertCount(1, $batch['invoiceIds']);
	}//end testAnInvoiceThatCannotBeWrittenIsAFailedOutcome()

	public function testTheBatchDueDateWinsOverTheCustomerTerm(): void {
		$this->service()->create(
			administrationId: 'adm-kade',
			request: self::request(['send' => false, 'customerIds' => ['cust-korenaar'], 'charge' => ['invoiceDate' => '2026-10-01', 'dueDate' => '2026-11-30', 'lines' => [['description' => 'X', 'unitPrice' => 10, 'vatRate' => 9]]]])
		);

		$invoice = array_values($this->store['ARInvoice'])[0];
		$this->assertSame('2026-11-30', $invoice['dueDate']);
		$this->assertSame(0.9, $invoice['vatAmount']);
	}//end testTheBatchDueDateWinsOverTheCustomerTerm()

	public function testAnotherAdministrationIsNotFound(): void {
		$this->expectException(OutOfBoundsException::class);
		$this->service()->create(administrationId: 'adm-other', request: self::request());
	}//end testAnotherAdministrationIsNotFound()

	public function testSendingWithoutIssuingIsRefusedBeforeAnythingIsWritten(): void {
		try {
			$this->service()->create(administrationId: 'adm-kade', request: self::request(['issue' => false]));
			$this->fail('A send without issue was accepted.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Only issued invoices can be sent: issue the invoices to send them.', $e->getMessage());
		}

		$this->assertSame([], $this->saved);
	}//end testSendingWithoutIssuingIsRefusedBeforeAnythingIsWritten()

	public function testMoreThanTwoHundredCustomersIsRefused(): void {
		$ids = array_map(static fn (int $i): string => 'cust-' . $i, range(1, 201));
		$this->expectExceptionMessage('A batch bills at most 200 customers.');
		$this->service()->create(administrationId: 'adm-kade', request: self::request(['customerIds' => $ids]));
	}//end testMoreThanTwoHundredCustomersIsRefused()

	public function testABatchReferenceOfAnotherBatchIsRefused(): void {
		$this->store['InvoiceBatch']['b-1'] = ['id' => 'b-1', 'batchId' => 'BATCH-OKT26', 'kind' => 'send', 'administrationId' => 'adm-kade'];
		$this->expectExceptionMessage('This batch reference belongs to another batch.');
		$this->service()->create(administrationId: 'adm-kade', request: self::request(['send' => false]));
	}//end testABatchReferenceOfAnotherBatchIsRefused()

	public function testAChargeWithoutAPricedLineIsRefused(): void {
		$this->expectExceptionMessage('Add at least one line with a description and a price.');
		InvoiceBatchService::normaliseCharge(charge: ['invoiceDate' => '2026-10-01', 'lines' => [['description' => 'Nothing', 'unitPrice' => 0]]]);
	}//end testAChargeWithoutAPricedLineIsRefused()

	public function testADueDateBeforeTheInvoiceDateIsRefused(): void {
		$this->expectExceptionMessage('The due date is a date on or after the invoice date.');
		InvoiceBatchService::normaliseCharge(charge: ['invoiceDate' => '2026-10-01', 'dueDate' => '2026-09-30', 'lines' => [['description' => 'X', 'unitPrice' => 1]]]);
	}//end testADueDateBeforeTheInvoiceDateIsRefused()

	public function testAnInvalidInvoiceDateIsRefused(): void {
		$this->expectExceptionMessage('Enter a valid invoice date.');
		InvoiceBatchService::normaliseCharge(charge: ['invoiceDate' => '2026-02-30', 'lines' => [['description' => 'X', 'unitPrice' => 1]]]);
	}//end testAnInvalidInvoiceDateIsRefused()

	public function testACustomerWithoutATermIsDueAfterThirtyDays(): void {
		$charge = InvoiceBatchService::normaliseCharge(charge: ['invoiceDate' => '2026-10-01', 'lines' => [['description' => 'X', 'unitPrice' => 1]]]);
		$this->assertSame('2026-10-31', InvoiceBatchService::dueDateFor(charge: $charge, customer: []));
		$this->assertSame('2026-10-01', InvoiceBatchService::dueDateFor(charge: $charge, customer: ['paymentTermDays' => 0]));
	}//end testACustomerWithoutATermIsDueAfterThirtyDays()

	public function testTheInvoiceNumberIsTheSameOnEveryRetryAndDiffersPerCustomer(): void {
		$first = InvoiceBatchService::invoiceNumberFor(batchId: 'BATCH-1', customerId: 'cust-a');
		$this->assertSame($first, InvoiceBatchService::invoiceNumberFor(batchId: 'BATCH-1', customerId: 'cust-a'));
		$this->assertNotSame($first, InvoiceBatchService::invoiceNumberFor(batchId: 'BATCH-1', customerId: 'cust-b'));
		$this->assertMatchesRegularExpression('/^BATCH-1-[0-9A-F]{8}$/', $first);
	}//end testTheInvoiceNumberIsTheSameOnEveryRetryAndDiffersPerCustomer()

	public function testVatIsRoundedOncePerRate(): void {
		$totals = InvoiceBatchService::totals(
			lines: [
				['netAmount' => 0.05, 'vatRate' => 21.0],
				['netAmount' => 0.05, 'vatRate' => 21.0],
				['netAmount' => 10.0, 'vatRate' => 9.0],
			]
		);
		$this->assertSame(['netAmount' => 10.1, 'vatAmount' => 0.92, 'grossAmount' => 11.02], $totals);
	}//end testVatIsRoundedOncePerRate()

	/**
	 * A constraint on an array's length.
	 *
	 * @param int $count The length.
	 *
	 * @return \PHPUnit\Framework\Constraint\Callback<mixed>
	 */
	private function lengthOf(int $count): \PHPUnit\Framework\Constraint\Callback {
		return $this->callback(static fn (mixed $value): bool => is_array($value) === true && count($value) === $count);
	}//end lengthOf()
}//end class
