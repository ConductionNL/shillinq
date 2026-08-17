<?php

/**
 * Unit tests for InvoiceGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/invoice-from-time-and-expense/specs/invoice-from-time-and-expense/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\InvoiceGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for InvoiceGuard.
 *
 * Covers REQ-ITE-005/007 and design D3/D6/D9: post requires lines, model-
 * consistent mandatory lines, completed milestone for milestone model, and a
 * source-id deduplication check that prevents double-invoicing. Cancel is
 * draft-only.
 */
class InvoiceGuardTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var InvoiceGuard
	 */
	private InvoiceGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new InvoiceGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->buildObjectServiceStub(bySchema: [])),
		);

	}//end setUp()

	/**
	 * Rebuild the guard on a schema-aware ObjectService stub.
	 *
	 * The store is injected through the constructor since ADR-084, so the guard
	 * has to be rebuilt whenever a test seeds different records.
	 *
	 * @param array<int,mixed> $invoices Invoice records returned for the Invoice schema.
	 * @param array<int,mixed> $lines InvoiceLine records returned for the InvoiceLine schema.
	 *
	 * @return void
	 */
	private function wireObjectService(array $invoices, array $lines): void {
		$store = $this->buildObjectServiceStub(bySchema: ['Invoice' => $invoices, 'InvoiceLine' => $lines]);
		$this->container->method('get')->willReturn($store);

		$this->guard = new InvoiceGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end wireObjectService()

	/**
	 * A T&M draft with lines and unique source ids may post (REQ-ITE-005).
	 *
	 * @return void
	 */
	public function testTAndMDraftWithUniqueSourcesCanPost(): void {
		$invoice = [
			'id' => 'inv-1',
			'billingModel' => 't_and_m',
			'state' => 'draft',
			'timeEntryIds' => ['time-1', 'time-2'],
			'expenseIds' => ['exp-1'],
		];
		$this->wireObjectService(
			invoices: [$invoice],
			lines: [['id' => 'l1', 'invoiceId' => 'inv-1', 'sourceType' => 'time_entry', 'costAmount' => 600000]]
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canPost(invoiceId: 'inv-1', object: $invoice));

	}//end testTAndMDraftWithUniqueSourcesCanPost()

	/**
	 * A draft with no lines cannot post (REQ-ITE-002).
	 *
	 * @return void
	 */
	public function testDraftWithoutLinesCannotPost(): void {
		$invoice = [
			'id' => 'inv-empty',
			'billingModel' => 't_and_m',
			'state' => 'draft',
			'timeEntryIds' => [],
			'expenseIds' => [],
		];
		$this->wireObjectService(invoices: [$invoice], lines: []);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canPost(invoiceId: 'inv-empty', object: $invoice));

	}//end testDraftWithoutLinesCannotPost()

	/**
	 * A retainer invoice without the mandatory retainer_charge line cannot post (design D3).
	 *
	 * @return void
	 */
	public function testRetainerWithoutRetainerLineCannotPost(): void {
		$invoice = [
			'id' => 'inv-ret',
			'billingModel' => 'retainer',
			'state' => 'draft',
			'timeEntryIds' => ['time-9'],
			'expenseIds' => [],
		];
		// Only an overage time line, no retainer_charge line.
		$this->wireObjectService(
			invoices: [$invoice],
			lines: [['id' => 'l1', 'invoiceId' => 'inv-ret', 'sourceType' => 'time_entry', 'costAmount' => 100000]]
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canPost(invoiceId: 'inv-ret', object: $invoice));

	}//end testRetainerWithoutRetainerLineCannotPost()

	/**
	 * A retainer invoice with the retainer_charge line present may post (design D3).
	 *
	 * @return void
	 */
	public function testRetainerWithRetainerLineCanPost(): void {
		$invoice = [
			'id' => 'inv-ret2',
			'billingModel' => 'retainer',
			'state' => 'draft',
			'timeEntryIds' => ['time-9'],
			'expenseIds' => [],
		];
		$this->wireObjectService(
			invoices: [$invoice],
			lines: [
				['id' => 'l1', 'invoiceId' => 'inv-ret2', 'sourceType' => 'retainer_charge', 'costAmount' => 300000],
				['id' => 'l2', 'invoiceId' => 'inv-ret2', 'sourceType' => 'time_entry', 'costAmount' => 100000],
			]
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canPost(invoiceId: 'inv-ret2', object: $invoice));

	}//end testRetainerWithRetainerLineCanPost()

	/**
	 * A milestone invoice without a completed-milestone line cannot post (design D6).
	 *
	 * @return void
	 */
	public function testMilestoneWithoutCompletionCannotPost(): void {
		$invoice = [
			'id' => 'inv-ms',
			'billingModel' => 'milestone',
			'state' => 'draft',
			'timeEntryIds' => [],
			'expenseIds' => [],
		];
		$this->wireObjectService(
			invoices: [$invoice],
			lines: [
				[
					'id' => 'l1',
					'invoiceId' => 'inv-ms',
					'sourceType' => 'milestone',
					'costAmount' => 2500000,
					'modelSpecificFields' => ['milestoneId' => 'ms-1'],
				],
			]
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canPost(invoiceId: 'inv-ms', object: $invoice));

	}//end testMilestoneWithoutCompletionCannotPost()

	/**
	 * A milestone invoice with a completed milestone may post (design D6).
	 *
	 * @return void
	 */
	public function testMilestoneWithCompletionCanPost(): void {
		$invoice = [
			'id' => 'inv-ms2',
			'billingModel' => 'milestone',
			'state' => 'draft',
			'timeEntryIds' => [],
			'expenseIds' => [],
		];
		$this->wireObjectService(
			invoices: [$invoice],
			lines: [
				[
					'id' => 'l1',
					'invoiceId' => 'inv-ms2',
					'sourceType' => 'milestone',
					'costAmount' => 2500000,
					'modelSpecificFields' => ['milestoneId' => 'ms-1', 'milestoneCompletedAt' => '2026-05-20'],
				],
			]
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canPost(invoiceId: 'inv-ms2', object: $invoice));

	}//end testMilestoneWithCompletionCanPost()

	/**
	 * Double-invoicing is denied when a time source id overlaps a posted invoice
	 * (REQ-ITE-007 / design D9).
	 *
	 * @return void
	 */
	public function testOverlappingSourceIdIsDenied(): void {
		$newInvoice = [
			'id' => 'inv-new',
			'billingModel' => 't_and_m',
			'state' => 'draft',
			'timeEntryIds' => ['time-2', 'time-3'],
			'expenseIds' => [],
		];
		$postedInvoice = [
			'id' => 'inv-posted',
			'billingModel' => 't_and_m',
			'state' => 'posted',
			'timeEntryIds' => ['time-1', 'time-2'],
			'expenseIds' => [],
		];
		$this->wireObjectService(
			invoices: [$newInvoice, $postedInvoice],
			lines: [['id' => 'l1', 'invoiceId' => 'inv-new', 'sourceType' => 'time_entry', 'costAmount' => 200000]]
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canPost(invoiceId: 'inv-new', object: $newInvoice));

	}//end testOverlappingSourceIdIsDenied()

	/**
	 * A cancelled invoice's source ids are released and do not block re-invoicing
	 * (REQ-ITE-007).
	 *
	 * @return void
	 */
	public function testCancelledInvoiceSourceIdsAreReleased(): void {
		$newInvoice = [
			'id' => 'inv-redo',
			'billingModel' => 't_and_m',
			'state' => 'draft',
			'timeEntryIds' => ['time-2'],
			'expenseIds' => [],
		];
		$cancelledInvoice = [
			'id' => 'inv-old',
			'billingModel' => 't_and_m',
			'state' => 'cancelled',
			'timeEntryIds' => ['time-2'],
			'expenseIds' => [],
		];
		$this->wireObjectService(
			invoices: [$newInvoice, $cancelledInvoice],
			lines: [['id' => 'l1', 'invoiceId' => 'inv-redo', 'sourceType' => 'time_entry', 'costAmount' => 100000]]
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canPost(invoiceId: 'inv-redo', object: $newInvoice));

	}//end testCancelledInvoiceSourceIdsAreReleased()

	/**
	 * A fixed-fee invoice with no time/expense sources skips dedup and may post
	 * (design D5).
	 *
	 * @return void
	 */
	public function testFixedFeeWithoutSourcesCanPost(): void {
		$invoice = [
			'id' => 'inv-fixed',
			'billingModel' => 'fixed_fee',
			'state' => 'draft',
			'timeEntryIds' => [],
			'expenseIds' => [],
		];
		$this->wireObjectService(
			invoices: [$invoice],
			lines: [['id' => 'l1', 'invoiceId' => 'inv-fixed', 'sourceType' => 'fixed_fee', 'costAmount' => 5000000]]
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canPost(invoiceId: 'inv-fixed', object: $invoice));

	}//end testFixedFeeWithoutSourcesCanPost()

	/**
	 * Only a draft invoice may be cancelled (REQ-ITE-007).
	 *
	 * @return void
	 */
	public function testCancelDraftIsAllowed(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canCancel(invoiceId: 'inv-1', object: ['state' => 'draft']));

	}//end testCancelDraftIsAllowed()

	/**
	 * A posted invoice cannot be cancelled — corrections use a credit note (REQ-ITE-007).
	 *
	 * @return void
	 */
	public function testCancelPostedIsDenied(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canCancel(invoiceId: 'inv-2', object: ['state' => 'posted']));

	}//end testCancelPostedIsDenied()

	/**
	 * An exception in the post path fails closed (returns false, logs error).
	 *
	 * @return void
	 */
	public function testPostExceptionFailsClosed(): void {
		$invoice = [
			'id' => 'inv-err',
			'billingModel' => 't_and_m',
			'state' => 'draft',
			'timeEntryIds' => ['time-1'],
			'expenseIds' => [],
			// Inline lines so resolveLines short-circuits before the store is read.
			'lines' => [['sourceType' => 'time_entry', 'costAmount' => 100000]],
		];

		// The store itself is the failure now that it is injected rather than
		// pulled from the container: the source-id uniqueness check reads it.
		$exploding = new class {

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Refuse every read, as an unavailable ObjectService would.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('ObjectService unavailable');
			}//end findAll()
		};

		$this->container->method('get')
			->willThrowException(new \RuntimeException('ObjectService unavailable'));

		$this->guard = new InvoiceGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($exploding),
		);

		$this->logger->expects($this->once())->method('error');

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canPost(invoiceId: 'inv-err', object: $invoice));

	}//end testPostExceptionFailsClosed()

	/**
	 * Build a schema-aware anonymous ObjectService stub.
	 *
	 * @param array<string,array<int,mixed>> $bySchema Records keyed by schema slug.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $bySchema): object {
		return new class($bySchema) {
			/**
			 * Records keyed by schema slug.
			 *
			 * @var array<string,array<int,mixed>>
			 */
			private array $bySchema;

			/**
			 * The schema selected by the last setSchema() call.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,mixed>> $bySchema Records keyed by schema slug.
			 */
			public function __construct(array $bySchema) {
				$this->bySchema = $bySchema;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — records the active schema.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return the stubbed records for the active schema, honouring an
			 * `id` / `invoiceId` filter when present.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $params = []): array {
				$records = ($this->bySchema[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if (is_array($filters) === false || $filters === []) {
					return $records;
				}

				return array_values(
					array_filter(
						$records,
						static function ($record) use ($filters) {
							if (is_array($record) === false) {
								return false;
							}

							foreach ($filters as $key => $value) {
								if (($record[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()
		};
	}//end buildObjectServiceStub()
}//end class
