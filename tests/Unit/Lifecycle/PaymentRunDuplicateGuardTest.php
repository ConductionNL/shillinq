<?php

/**
 * Unit tests for PaymentRunDuplicateGuard.
 *
 * Proves the server-side duplicate-payment control on the `PaymentRun.export`
 * transition (payment-control-guards, REQ-PCG-001): a batch whose line settles
 * an AP invoice that is ALREADY PAID, or that is ALREADY QUEUED in another open
 * or executed payment batch, is REJECTED before the SEPA file is written; a
 * clean batch is ALLOWED; and every indeterminate case (unidentifiable batch, a
 * line without an apTransactionRef, a lookup failure) fails CLOSED.
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
 * @spec openspec/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\PaymentRunDuplicateGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PaymentRunDuplicateGuard.
 */
class PaymentRunDuplicateGuardTest extends TestCase {

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
	 * @var PaymentRunDuplicateGuard
	 */
	private PaymentRunDuplicateGuard $guard;

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
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->guard = new PaymentRunDuplicateGuard(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Wire the container to return a schema-aware ObjectService stub.
	 *
	 * @param array<string, array<int, mixed>> $recordsBySchema Records keyed by schema slug.
	 *
	 * @return void
	 */
	private function withRecords(array $recordsBySchema): void {
		$this->container->method('get')->willReturn(GuardObjectServiceStub::make($recordsBySchema));

	}//end withRecords()

	/**
	 * Build a PaymentRun payload with one line settling the given invoice ref.
	 *
	 * @param string $id The batch id.
	 * @param string $apRef The apTransactionRef the single line settles.
	 *
	 * @return array<string, mixed>
	 */
	private function batch(string $id, string $apRef): array {
		return [
			'id' => $id,
			'administrationId' => 'adm-1',
			'lifecycleState' => 'approved',
			'paymentLines' => [['apTransactionRef' => $apRef, 'amount' => 100.00]],
		];
	}//end batch()

	/**
	 * THE control: an invoice already queued in another batch blocks export.
	 *
	 * @return void
	 */
	public function testAlreadyBatchedInvoiceIsRejected(): void {
		$this->withRecords(
			[
				'APTransaction' => [['id' => 'ap-9', 'state' => 'issued']],
				'PaymentRun' => [
					['id' => 'pr-1', 'lifecycleState' => 'approved', 'paymentLines' => [['apTransactionRef' => 'ap-9']]],
					['id' => 'pr-2', 'lifecycleState' => 'approved', 'paymentLines' => [['apTransactionRef' => 'ap-9']]],
				],
			]
		);

		$result = $this->guard->check($this->batch('pr-1', 'ap-9'), 'export', 'alice');

		self::assertFalse($result->isAllowed());
		self::assertSame(PaymentRunDuplicateGuard::MESSAGE_ALREADY_BATCHED, $result->getMessage());

	}//end testAlreadyBatchedInvoiceIsRejected()

	/**
	 * An invoice that is already paid blocks export (double-payment prevention).
	 *
	 * @return void
	 */
	public function testAlreadyPaidInvoiceIsRejected(): void {
		$this->withRecords(
			[
				'APTransaction' => [['id' => 'ap-9', 'state' => 'paid']],
				'PaymentRun' => [['id' => 'pr-1', 'lifecycleState' => 'approved', 'paymentLines' => [['apTransactionRef' => 'ap-9']]]],
			]
		);

		$result = $this->guard->check($this->batch('pr-1', 'ap-9'), 'export', 'alice');

		self::assertFalse($result->isAllowed());
		self::assertSame(PaymentRunDuplicateGuard::MESSAGE_ALREADY_PAID, $result->getMessage());

	}//end testAlreadyPaidInvoiceIsRejected()

	/**
	 * A clean batch — invoice unpaid and in no other batch — is ALLOWED.
	 *
	 * @return void
	 */
	public function testCleanBatchIsAllowed(): void {
		$this->withRecords(
			[
				'APTransaction' => [['id' => 'ap-9', 'state' => 'issued']],
				'PaymentRun' => [['id' => 'pr-1', 'lifecycleState' => 'approved', 'paymentLines' => [['apTransactionRef' => 'ap-9']]]],
			]
		);

		$result = $this->guard->check($this->batch('pr-1', 'ap-9'), 'export', 'alice');

		self::assertTrue($result->isAllowed());
		self::assertNull($result->getMessage());

	}//end testCleanBatchIsAllowed()

	/**
	 * The batch being exported never counts as a duplicate of itself.
	 *
	 * @return void
	 */
	public function testOwnBatchDoesNotSelfBlock(): void {
		// Only pr-1 (the batch under export) holds ap-9 — must not self-block.
		$this->withRecords(
			[
				'APTransaction' => [['id' => 'ap-9', 'state' => 'issued']],
				'PaymentRun' => [['id' => 'pr-1', 'lifecycleState' => 'approved', 'paymentLines' => [['apTransactionRef' => 'ap-9']]]],
			]
		);

		$result = $this->guard->check($this->batch('pr-1', 'ap-9'), 'export', 'alice');

		self::assertTrue($result->isAllowed());

	}//end testOwnBatchDoesNotSelfBlock()

	/**
	 * An invoice matched by uuid (not the primary id) is still detected as paid.
	 *
	 * @return void
	 */
	public function testPaidInvoiceMatchedByUuid(): void {
		$this->withRecords(
			[
				'APTransaction' => [['id' => 'internal-1', 'uuid' => 'ap-uuid-9', 'state' => 'paid']],
				'PaymentRun' => [['id' => 'pr-1', 'lifecycleState' => 'approved', 'paymentLines' => [['apTransactionRef' => 'ap-uuid-9']]]],
			]
		);

		$result = $this->guard->check($this->batch('pr-1', 'ap-uuid-9'), 'export', 'alice');

		self::assertFalse($result->isAllowed());
		self::assertSame(PaymentRunDuplicateGuard::MESSAGE_ALREADY_PAID, $result->getMessage());

	}//end testPaidInvoiceMatchedByUuid()

	/**
	 * A reconciled (terminal) other batch does not occupy the invoice.
	 *
	 * @return void
	 */
	public function testReconciledOtherBatchDoesNotBlock(): void {
		$this->withRecords(
			[
				'APTransaction' => [['id' => 'ap-9', 'state' => 'issued']],
				'PaymentRun' => [
					['id' => 'pr-1', 'lifecycleState' => 'approved', 'paymentLines' => [['apTransactionRef' => 'ap-9']]],
					['id' => 'pr-0', 'lifecycleState' => 'reconciled', 'paymentLines' => [['apTransactionRef' => 'ap-9']]],
				],
			]
		);

		$result = $this->guard->check($this->batch('pr-1', 'ap-9'), 'export', 'alice');

		self::assertTrue($result->isAllowed());

	}//end testReconciledOtherBatchDoesNotBlock()

	/**
	 * An unidentifiable batch (no id) is blocked (fail-closed).
	 *
	 * @return void
	 */
	public function testMissingObjectIdIsBlocked(): void {
		$this->container->expects($this->never())->method('get');

		$result = $this->guard->check(['paymentLines' => [['apTransactionRef' => 'ap-9']]], 'export', 'alice');

		self::assertFalse($result->isAllowed());
		self::assertSame(PaymentRunDuplicateGuard::MESSAGE_NO_OBJECT, $result->getMessage());

	}//end testMissingObjectIdIsBlocked()

	/**
	 * A line without an apTransactionRef cannot be checked — fail closed.
	 *
	 * @return void
	 */
	public function testLineWithoutRefIsBlocked(): void {
		$this->container->expects($this->never())->method('get');

		$object = [
			'id' => 'pr-1',
			'paymentLines' => [['amount' => 100.00]],
		];

		$result = $this->guard->check($object, 'export', 'alice');

		self::assertFalse($result->isAllowed());
		self::assertSame(PaymentRunDuplicateGuard::MESSAGE_INDETERMINATE, $result->getMessage());

	}//end testLineWithoutRefIsBlocked()

	/**
	 * A batch with no lines cannot double-pay anything — ALLOWED.
	 *
	 * @return void
	 */
	public function testEmptyLinesAllowed(): void {
		$result = $this->guard->check(['id' => 'pr-1', 'paymentLines' => []], 'export', 'alice');

		self::assertTrue($result->isAllowed());

	}//end testEmptyLinesAllowed()

	/**
	 * A thrown lookup fails closed (denies), never silently proceeds.
	 *
	 * @return void
	 */
	public function testLookupFailureFailsClosed(): void {
		$throwing = new class {

			/**
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string, mixed> $params Query params.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('object backend unavailable');
			}
		};
		$this->container->method('get')->willReturn($throwing);

		$result = $this->guard->check($this->batch('pr-1', 'ap-9'), 'export', 'alice');

		self::assertFalse($result->isAllowed());
		self::assertSame(PaymentRunDuplicateGuard::MESSAGE_INDETERMINATE, $result->getMessage());

	}//end testLookupFailureFailsClosed()
}//end class
