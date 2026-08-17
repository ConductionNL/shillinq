<?php

/**
 * Unit tests for JournalEntryGuard.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-journal-entries/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\JournalEntryGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for JournalEntryGuard.
 *
 * Covers REQ-JE-007 (balanced post precondition) and REQ-JE-010 (void
 * requires a reversed materialised GLTransaction).
 */
class JournalEntryGuardTest extends TestCase {

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
	 * @var JournalEntryGuard
	 */
	private JournalEntryGuard $guard;

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

		$this->guard = new JournalEntryGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->buildObjectServiceStub(records: [])),
		);

	}//end setUp()

	/**
	 * Point the guard at the given duck-typed ObjectService store.
	 *
	 * The store is a constructor dependency since ADR-084, so the guard has to
	 * be rebuilt whenever a test seeds different records.
	 *
	 * @param object $store The in-memory ObjectService double.
	 *
	 * @return void
	 */
	private function wireObjectService(object $store): void {
		$this->container->method('get')->willReturn($store);

		$this->guard = new JournalEntryGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end wireObjectService()

	/**
	 * A balanced 2-line journal supplied inline returns true per REQ-JE-007.
	 *
	 * @return void
	 */
	public function testBalancedInlineJournalCanPost(): void {
		$object = [
			'lines' => [
				['accountNumber' => '4500', 'side' => 'debit',  'amount' => 25.00],
				['accountNumber' => '1000', 'side' => 'credit', 'amount' => 25.00],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canPost(journalEntryId: 'je-1', object: $object));

	}//end testBalancedInlineJournalCanPost()

	/**
	 * An unbalanced journal (debit 100, credit 99.99) cannot post per REQ-JE-007.
	 *
	 * @return void
	 */
	public function testUnbalancedJournalCannotPost(): void {
		$object = [
			'lines' => [
				['accountNumber' => '4500', 'side' => 'debit',  'amount' => 100.00],
				['accountNumber' => '1000', 'side' => 'credit', 'amount' => 99.99],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canPost(journalEntryId: 'je-2', object: $object));

	}//end testUnbalancedJournalCannotPost()

	/**
	 * Float rounding handled via integer-cent arithmetic per REQ-JE-007.
	 *
	 * @return void
	 */
	public function testFloatRoundingHandledByIntegerCents(): void {
		$object = [
			'lines' => [
				['accountNumber' => '4500', 'side' => 'debit',  'amount' => 0.1],
				['accountNumber' => '4600', 'side' => 'debit',  'amount' => 0.2],
				['accountNumber' => '1000', 'side' => 'credit', 'amount' => 0.3],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canPost(journalEntryId: 'je-3', object: $object));

	}//end testFloatRoundingHandledByIntegerCents()

	/**
	 * A single-line journal cannot post — a balanced posting needs N >= 2 lines.
	 *
	 * @return void
	 */
	public function testSingleLineJournalCannotPost(): void {
		$object = [
			'lines' => [
				['accountNumber' => '4500', 'side' => 'debit', 'amount' => 25.00],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canPost(journalEntryId: 'je-4', object: $object));

	}//end testSingleLineJournalCannotPost()

	/**
	 * A zero-total balanced journal (0 debit = 0 credit) cannot post — REQ-JE-007
	 * requires a non-empty posting, unlike an empty GL transaction.
	 *
	 * @return void
	 */
	public function testZeroTotalJournalCannotPost(): void {
		$object = [
			'lines' => [
				['accountNumber' => '4500', 'side' => 'debit',  'amount' => 0.0],
				['accountNumber' => '1000', 'side' => 'credit', 'amount' => 0.0],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canPost(journalEntryId: 'je-zero', object: $object));

	}//end testZeroTotalJournalCannotPost()

	/**
	 * A line with an unknown side fails closed per REQ-JE-007 / CWE-863.
	 *
	 * @return void
	 */
	public function testUnknownSideFailsClosed(): void {
		$object = [
			'lines' => [
				['accountNumber' => '4500', 'side' => 'sideways', 'amount' => 25.00],
				['accountNumber' => '1000', 'side' => 'credit',   'amount' => 25.00],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canPost(journalEntryId: 'je-5', object: $object));

	}//end testUnknownSideFailsClosed()

	/**
	 * A negative-amount line fails closed per REQ-JE-007.
	 *
	 * @return void
	 */
	public function testNegativeAmountFailsClosed(): void {
		$object = [
			'lines' => [
				['accountNumber' => '4500', 'side' => 'debit',  'amount' => -25.00],
				['accountNumber' => '1000', 'side' => 'credit', 'amount' => -25.00],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canPost(journalEntryId: 'je-neg', object: $object));

	}//end testNegativeAmountFailsClosed()

	/**
	 * Voiding a journal whose materialised GLTransaction is reversed is allowed (REQ-JE-010).
	 *
	 * @return void
	 */
	public function testCanVoidWhenGlTransactionReversed(): void {
		$this->wireObjectService(
			store: $this->buildObjectServiceStub(records: [['id' => 'gl-1', 'state' => 'reversed']])
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canVoid(journalEntryId: 'je-6', object: ['glTransactionId' => 'gl-1']));

	}//end testCanVoidWhenGlTransactionReversed()

	/**
	 * Voiding fails when the materialised GLTransaction is still posted (REQ-JE-010).
	 *
	 * @return void
	 */
	public function testCannotVoidWhenGlTransactionNotReversed(): void {
		$this->wireObjectService(
			store: $this->buildObjectServiceStub(records: [['id' => 'gl-1', 'state' => 'posted']])
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canVoid(journalEntryId: 'je-7', object: ['glTransactionId' => 'gl-1']));

	}//end testCannotVoidWhenGlTransactionNotReversed()

	/**
	 * Voiding fails when no GLTransaction was ever materialised (REQ-JE-010).
	 *
	 * @return void
	 */
	public function testCannotVoidWithoutMaterialisedTransaction(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canVoid(journalEntryId: 'je-8', object: ['glTransactionId' => '']));

	}//end testCannotVoidWithoutMaterialisedTransaction()

	/**
	 * An exception in the void path fails closed (returns false, logs error).
	 *
	 * @return void
	 */
	public function testVoidExceptionFailsClosed(): void {
		$this->wireObjectService(store: $this->buildFailingObjectServiceStub());

		$this->logger->expects($this->once())->method('error');

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canVoid(journalEntryId: 'je-9', object: ['glTransactionId' => 'gl-9']));

	}//end testVoidExceptionFailsClosed()

	/**
	 * Build an anonymous ObjectService stub returning the given records from findAll().
	 *
	 * @param array<mixed> $records Records to return.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $records): object {
		return new class($records) {
			/**
			 * Records to return from findAll().
			 *
			 * @var array<mixed>
			 */
			private array $records;

			/**
			 * Constructor.
			 *
			 * @param array<mixed> $records Records to return.
			 */
			public function __construct(array $records) {
				$this->records = $records;
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
			 * Return all stubbed records.
			 *
			 * @param array<string,mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return $this->records;
			}//end findAll()
		};
	}//end buildObjectServiceStub()

	/**
	 * Build an ObjectService store that refuses every read.
	 *
	 * Since the store is injected rather than pulled from the container, an
	 * unavailable OpenRegister is modelled by a store that throws.
	 *
	 * @return object
	 */
	private function buildFailingObjectServiceStub(): object {
		return new class {

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
			 * Refuse the read, as an unavailable ObjectService would.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('ObjectService unavailable');
			}//end findAll()
		};
	}//end buildFailingObjectServiceStub()
}//end class
