<?php

/**
 * Unit tests for BackfillArInvoiceProvenance.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/recurring-invoicing/spec.md (REQ-RIN-011)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\BackfillArInvoiceProvenance;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An OpenRegister double over two schemas, with JSON-property filters, paging and audit logs.
 *
 * @SuppressWarnings(PHPMD.CamelCaseParameterName) -- _rbac/_multitenancy mirror OR's API.
 */
final class BackfillInvoiceStore {
	/**
	 * Rows by schema, then uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $rows = ['ARInvoice' => [], 'RecurringInvoiceProfile' => []];

	/**
	 * Audit-trail rows by object uuid.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	public array $logs = [];

	/**
	 * Uuids whose audit trail cannot be read.
	 *
	 * @var array<int, string>
	 */
	public array $logsDown = [];

	/**
	 * Every save.
	 *
	 * @var array<int, array{object: array<string, mixed>, uuid: string|null, schema: string}>
	 */
	public array $saved = [];

	/**
	 * The schema set last.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * @param string|int $register Unused.
	 *
	 * @return static
	 */
	public function setRegister(string|int $register): static {
		return $this;
	}//end setRegister()

	/**
	 * @param string|int $schema The schema.
	 *
	 * @return static
	 */
	public function setSchema(string|int $schema): static {
		$this->schema = (string)$schema;
		return $this;
	}//end setSchema()

	/**
	 * @param array<string, mixed> $config Filters, limit and offset.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = []): array {
		$matches = [];
		foreach ($this->rows[$this->schema] ?? [] as $uuid => $row) {
			foreach (($config['filters'] ?? []) as $key => $value) {
				if (($row[$key] ?? null) !== $value) {
					continue 2;
				}
			}

			$matches[] = $row + ['id' => $uuid, '@self' => ['id' => $uuid]];
		}

		return array_slice($matches, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? count($matches)));
	}//end findAll()

	/**
	 * @param string $uuid The object.
	 * @param array $filters Unused.
	 * @param bool $_rbac Unused.
	 * @param bool $_multitenancy Unused.
	 *
	 * @return array<int, mixed>
	 */
	public function getLogs(string $uuid, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
		if (in_array($uuid, $this->logsDown, true) === true) {
			throw new RuntimeException('audit trail unavailable');
		}

		return $this->logs[$uuid] ?? [];
	}//end getLogs()

	/**
	 * @param array<string, mixed> $object The object.
	 * @param array|null $extend Unused.
	 * @param mixed $register Unused.
	 * @param mixed $schema The schema.
	 * @param string|null $uuid The uuid to update.
	 * @param bool $_rbac Unused.
	 * @param bool $_multitenancy Unused.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		mixed $register = null,
		mixed $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true
	): array {
		$target = (string)($schema ?? $this->schema);
		$this->saved[] = ['object' => $object, 'uuid' => $uuid, 'schema' => $target];
		$this->rows[$target][(string)$uuid] = $object;

		return $object + ['id' => $uuid];
	}//end saveObject()
}//end class

/**
 * An audit-trail entity, the shape OpenRegister's getLogs() returns.
 */
final class BackfillAuditEntry {
	/**
	 * @param string $action The action.
	 * @param array<string, mixed> $changed The changed fields.
	 */
	public function __construct(private string $action, private array $changed) {
	}//end __construct()

	/**
	 * @return string
	 */
	public function getAction(): string {
		return $this->action;
	}//end getAction()

	/**
	 * @return array<string, mixed>
	 */
	public function getChanged(): array {
		return $this->changed;
	}//end getChanged()
}//end class

/**
 * Invoices saved before ARInvoice 0.16.0 get the provenance that can be derived, once.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/recurring-invoicing/spec.md (REQ-RIN-011)
 */
final class BackfillArInvoiceProvenanceTest extends TestCase {
	private const CUSTOMER = 'contact-0001';

	private const PROFILE = 'a1b2c3d4-0000-4000-8000-000000000001';

	/**
	 * The step over this store.
	 *
	 * @param BackfillInvoiceStore $store The store.
	 *
	 * @return BackfillArInvoiceProvenance
	 */
	private function step(BackfillInvoiceStore $store): BackfillArInvoiceProvenance {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new BackfillArInvoiceProvenance(
			settingsService: $settings,
			logger: new NullLogger(),
			objectService: new DuckObjectServiceAdapter($store),
		);
	}//end step()

	/**
	 * A monthly hosting profile, invoiced on the 5th, generated up to 2026-09.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function profile(array $overrides = []): array {
		return $overrides + [
			'customerReference' => self::CUSTOMER,
			'administrationId' => 'adm-1',
			'frequency' => 'monthly',
			'interval' => 1,
			'invoiceDay' => 5,
			'startDate' => '2026-01-01',
			'lastBillingPeriod' => '2026-09',
			'documentLanguage' => 'nl',
			'status' => 'active',
			'lines' => [
				['description' => 'Hosting {period}', 'quantity' => 1, 'unitPrice' => 100, 'vatCode' => 21, 'revenueAccount' => '8000'],
				['description' => 'Support', 'quantity' => 2, 'unitPrice' => 25, 'vatCode' => 21, 'revenueAccount' => '8010'],
			],
		];
	}//end profile()

	/**
	 * An invoice the generator wrote before 0.16.0: no provenance, no line accounts.
	 *
	 * @param string $date The invoice date.
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function generated(string $date, array $overrides = []): array {
		return $overrides + [
			'customerId' => self::CUSTOMER,
			'administrationId' => 'adm-1',
			'invoiceDate' => $date,
			'netAmount' => 150.0,
			'vatAmount' => 31.5,
			'grossAmount' => 181.5,
			'lifecycleState' => 'issued',
			'invoiceLines' => [
				['lineId' => '1', 'itemName' => 'Hosting', 'netAmount' => 100.0],
				['lineId' => '2', 'itemName' => 'Support', 'netAmount' => 50.0],
			],
		];
	}//end generated()

	/**
	 * Run the step once and return what it said.
	 *
	 * @param BackfillInvoiceStore $store The store.
	 *
	 * @return array<int, string>
	 */
	private function runStep(BackfillInvoiceStore $store): array {
		$messages = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $message) use (&$messages): void {
			$messages[] = $message;
		});
		$output->method('warning')->willReturnCallback(static function (string $message) use (&$messages): void {
			$messages[] = 'WARNING ' . $message;
		});

		$this->step(store: $store)->run(output: $output);

		return $messages;
	}//end run()

	/**
	 * A generated invoice gets its profile, its period and its line accounts from the profile.
	 *
	 * @return void
	 */
	public function testAGeneratedInvoiceGetsItsProfilePeriodAndLineAccounts(): void {
		$store = new BackfillInvoiceStore();
		$store->rows['RecurringInvoiceProfile'][self::PROFILE] = $this->profile();
		$store->rows['ARInvoice']['inv-march'] = $this->generated(date: '2026-03-05');

		$this->runStep(store: $store);

		$invoice = $store->rows['ARInvoice']['inv-march'];
		self::assertSame(self::PROFILE, $invoice['recurringProfileId']);
		self::assertSame('2026-03', $invoice['billingPeriod']);
		self::assertSame('8000', $invoice['invoiceLines'][0]['glAccount']);
		self::assertSame('8010', $invoice['invoiceLines'][1]['glAccount']);
		self::assertSame('Hosting', $invoice['invoiceLines'][0]['itemName']);
		self::assertArrayNotHasKey('id', $invoice);
		self::assertArrayNotHasKey('@self', $invoice);
		self::assertSame('inv-march', $store->saved[0]['uuid']);
		self::assertSame('ARInvoice', $store->saved[0]['schema']);
	}//end testAGeneratedInvoiceGetsItsProfilePeriodAndLineAccounts()

	/**
	 * The day is clamped like the generator clamps it: invoiceDay 31 lands on 28 February.
	 *
	 * @return void
	 */
	public function testTheInvoiceDayIsClampedToTheMonth(): void {
		$store = new BackfillInvoiceStore();
		$store->rows['RecurringInvoiceProfile'][self::PROFILE] = $this->profile(['invoiceDay' => 31]);
		$store->rows['ARInvoice']['inv-feb'] = $this->generated(date: '2026-02-28');

		$this->runStep(store: $store);

		self::assertSame('2026-02', $store->rows['ARInvoice']['inv-feb']['billingPeriod']);
	}//end testTheInvoiceDayIsClampedToTheMonth()

	/**
	 * What does not fit the profile is left alone: another day, another amount, a period never generated.
	 *
	 * @return void
	 */
	public function testAnInvoiceThatDoesNotFitTheProfileIsLeftAlone(): void {
		$store = new BackfillInvoiceStore();
		$store->rows['RecurringInvoiceProfile'][self::PROFILE] = $this->profile();
		$store->rows['ARInvoice']['inv-other-day'] = $this->generated(date: '2026-03-20');
		$store->rows['ARInvoice']['inv-other-amount'] = $this->generated(date: '2026-04-05', overrides: ['netAmount' => 99.0]);
		$store->rows['ARInvoice']['inv-future'] = $this->generated(date: '2026-10-05');
		$store->rows['ARInvoice']['inv-before-start'] = $this->generated(date: '2025-12-05');
		$store->rows['ARInvoice']['inv-other-admin'] = $this->generated(date: '2026-05-05', overrides: ['administrationId' => 'adm-2']);

		$this->runStep(store: $store);

		self::assertSame([], $store->saved);
	}//end testAnInvoiceThatDoesNotFitTheProfileIsLeftAlone()

	/**
	 * Two profiles that both fit make the invoice ambiguous, so it is left alone.
	 *
	 * @return void
	 */
	public function testAnInvoiceTwoProfilesFitIsLeftAlone(): void {
		$store = new BackfillInvoiceStore();
		$store->rows['RecurringInvoiceProfile'][self::PROFILE] = $this->profile();
		$store->rows['RecurringInvoiceProfile']['profile-twin'] = $this->profile();
		$store->rows['ARInvoice']['inv-march'] = $this->generated(date: '2026-03-05');

		$messages = $this->runStep(store: $store);

		self::assertSame([], $store->saved);
		self::assertStringContainsString('1 ambiguous', implode(' ', $messages));
	}//end testAnInvoiceTwoProfilesFitIsLeftAlone()

	/**
	 * A value already there is never overwritten; only the gaps are filled.
	 *
	 * @return void
	 */
	public function testAValueAlreadyThereIsNeverOverwritten(): void {
		$store = new BackfillInvoiceStore();
		$store->rows['RecurringInvoiceProfile'][self::PROFILE] = $this->profile();
		$invoice = $this->generated(date: '2026-06-05', overrides: ['recurringProfileId' => self::PROFILE]);
		$invoice['invoiceLines'][0]['glAccount'] = '8100';
		$store->rows['ARInvoice']['inv-june'] = $invoice;

		$this->runStep(store: $store);

		$saved = $store->rows['ARInvoice']['inv-june'];
		self::assertSame(self::PROFILE, $saved['recurringProfileId']);
		self::assertSame('2026-06', $saved['billingPeriod']);
		self::assertSame('8100', $saved['invoiceLines'][0]['glAccount']);
		self::assertSame('8010', $saved['invoiceLines'][1]['glAccount']);
	}//end testAValueAlreadyThereIsNeverOverwritten()

	/**
	 * A quick draft gets its customer reference and line accounts from its create audit entry.
	 *
	 * @return void
	 */
	public function testAQuickDraftGetsItsReferenceAndAccountsFromItsAudit(): void {
		$store = new BackfillInvoiceStore();
		$store->rows['ARInvoice']['inv-draft'] = [
			'invoiceNumber' => 'DRAFT-20260310-101010',
			'customerId' => self::CUSTOMER,
			'invoiceDate' => '2026-03-10',
			'lifecycleState' => 'draft',
			'invoiceLines' => [['lineId' => '1', 'itemName' => 'Advies'], ['lineId' => '2', 'itemName' => 'Reis']],
		];
		$store->logs['inv-draft'] = [
			['action' => 'update', 'changed' => ['lifecycleState' => ['old' => null, 'new' => 'draft']]],
			new BackfillAuditEntry(
				action: 'create',
				changed: [
					'customerReference' => ['old' => null, 'new' => 'PO-12'],
					'invoiceLines' => ['old' => null, 'new' => [['lineId' => '1', 'glAccount' => '8020'], ['lineId' => '2']]],
				]
			),
		];

		$this->runStep(store: $store);

		$saved = $store->rows['ARInvoice']['inv-draft'];
		self::assertSame('PO-12', $saved['customerReference']);
		self::assertSame('8020', $saved['invoiceLines'][0]['glAccount']);
		self::assertArrayNotHasKey('glAccount', $saved['invoiceLines'][1]);
	}//end testAQuickDraftGetsItsReferenceAndAccountsFromItsAudit()

	/**
	 * The older quick draft wrote `lines` with a lineNumber; its account still reaches the line.
	 *
	 * @return void
	 */
	public function testTheOlderQuickDraftLinesShapeIsRead(): void {
		$store = new BackfillInvoiceStore();
		$store->rows['ARInvoice']['inv-draft'] = [
			'invoiceNumber' => 'DRAFT-20260310-101010',
			'customerReference' => 'KEEP',
			'invoiceLines' => [['lineId' => '1', 'itemName' => 'Advies']],
		];
		$store->logs['inv-draft'] = [
			['action' => 'create', 'changed' => [
				'customerReference' => ['old' => null, 'new' => 'PO-OLD'],
				'lines' => ['old' => null, 'new' => [['lineNumber' => 1, 'glAccount' => '8030']]],
			]],
		];

		$this->runStep(store: $store);

		$saved = $store->rows['ARInvoice']['inv-draft'];
		self::assertSame('KEEP', $saved['customerReference']);
		self::assertSame('8030', $saved['invoiceLines'][0]['glAccount']);
	}//end testTheOlderQuickDraftLinesShapeIsRead()

	/**
	 * An audit trail that cannot be read skips that draft and nothing else.
	 *
	 * @return void
	 */
	public function testAnUnreadableAuditSkipsOnlyThatDraft(): void {
		$store = new BackfillInvoiceStore();
		$store->rows['RecurringInvoiceProfile'][self::PROFILE] = $this->profile();
		$store->rows['ARInvoice']['inv-draft'] = ['invoiceNumber' => 'DRAFT-20260310-101010', 'invoiceLines' => []];
		$store->rows['ARInvoice']['inv-march'] = $this->generated(date: '2026-03-05');
		$store->logsDown = ['inv-draft'];

		$messages = $this->runStep(store: $store);

		self::assertCount(1, $store->saved);
		self::assertSame('inv-march', $store->saved[0]['uuid']);
		self::assertStringNotContainsString('WARNING', implode(' ', $messages));
	}//end testAnUnreadableAuditSkipsOnlyThatDraft()

	/**
	 * A second run saves nothing.
	 *
	 * @return void
	 */
	public function testASecondRunSavesNothing(): void {
		$store = new BackfillInvoiceStore();
		$store->rows['RecurringInvoiceProfile'][self::PROFILE] = $this->profile();
		$store->rows['ARInvoice']['inv-march'] = $this->generated(date: '2026-03-05');
		$store->rows['ARInvoice']['inv-draft'] = ['invoiceNumber' => 'DRAFT-20260310-101010', 'invoiceLines' => [['lineId' => '1']]];
		$store->logs['inv-draft'] = [['action' => 'create', 'changed' => ['customerReference' => ['old' => null, 'new' => 'PO-12']]]];

		$this->runStep(store: $store);
		self::assertCount(2, $store->saved);

		$this->runStep(store: $store);
		self::assertCount(2, $store->saved);
	}//end testASecondRunSavesNothing()

	/**
	 * A read failure warns and never throws, so the upgrade goes on.
	 *
	 * @return void
	 */
	public function testAFailureWarnsAndNeverThrows(): void {
		$store = new class extends \stdClass {
			/**
			 * @param string|int $register Unused.
			 *
			 * @return static
			 */
			public function setRegister(string|int $register): static {
				return $this;
			}

			/**
			 * @param string|int $schema Unused.
			 *
			 * @return static
			 */
			public function setSchema(string|int $schema): static {
				return $this;
			}

			/**
			 * @param array<string, mixed> $config Unused.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = []): array {
				throw new RuntimeException('ARInvoice is not imported yet');
			}
		};

		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$step = new BackfillArInvoiceProvenance(settingsService: $settings, logger: new NullLogger(), objectService: new DuckObjectServiceAdapter($store));
		$step->run(output: $output);
	}//end testAFailureWarnsAndNeverThrows()
}//end class
