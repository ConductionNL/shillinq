<?php

/**
 * Gap-filling unit tests for InvoiceGuard.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\InvoiceGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the id-only lookup paths, the empty-id refusals, malformed source-id
 * and line shapes, the empty register-slug fallback and the cancel fail-closed
 * path of InvoiceGuard.
 *
 * @covers \OCA\Shillinq\Lifecycle\InvoiceGuard
 */
final class InvoiceGuardGapsTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Every findAll() call as [schema, params].
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $calls = [];

	/**
	 * Registers passed to setRegister().
	 *
	 * @var array<int,string>
	 */
	private array $registers = [];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the guard over a configured register slug.
	 *
	 * @param string $register The register slug the app config answers.
	 *
	 * @return InvoiceGuard The guard.
	 */
	private function guard(string $register = 'books'): InvoiceGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'register' ? $register : $default
		);

		return new InvoiceGuard(appConfig: $config, logger: $this->logger, objectService: $this->objects);
	}//end guard()

	/**
	 * Seed the object service with rows per schema and record every read.
	 *
	 * @param array<string,array<int,mixed>|\Throwable> $bySchema Rows (or a throwable) per schema.
	 *
	 * @return void
	 */
	private function seed(array $bySchema): void {
		$schema = '';
		$this->objects->method('setRegister')->willReturnCallback(
			function (string|int $register): ObjectServiceInterface {
				$this->registers[] = (string)$register;
				return $this->objects;
			}
		);
		$this->objects->method('setSchema')->willReturnCallback(
			function (string|int $slug) use (&$schema): ObjectServiceInterface {
				$schema = (string)$slug;
				return $this->objects;
			}
		);
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config = []) use (&$schema, $bySchema): array {
				$this->calls[] = [$schema, $config];
				$rows = ($bySchema[$schema] ?? []);
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return $rows;
			}
		);
	}//end seed()

	/**
	 * No object and no id: post is refused without touching the store.
	 *
	 * @return void
	 */
	public function testPostWithoutObjectOrIdIsRefusedWithoutLookup(): void {
		$this->objects->expects($this->never())->method('findAll');

		self::assertFalse($this->guard()->canPost(invoiceId: '', object: null));
	}//end testPostWithoutObjectOrIdIsRefusedWithoutLookup()

	/**
	 * An id-only post reads the invoice by id, then its lines by invoiceId, and
	 * refuses an invoice without a billing model.
	 *
	 * @return void
	 */
	public function testIdOnlyPostReadsInvoiceAndLinesAndRefusesMissingBillingModel(): void {
		$this->seed(
			[
				'Invoice'     => [['id' => 'inv-7', 'state' => 'draft']],
				'InvoiceLine' => [['id' => 'l1', 'invoiceId' => 'inv-7', 'sourceType' => 'time_entry']],
			]
		);

		self::assertFalse($this->guard()->canPost(invoiceId: 'inv-7'));
		self::assertSame(
			[
				['Invoice', ['filters' => ['id' => 'inv-7']]],
				['InvoiceLine', ['filters' => ['invoiceId' => 'inv-7']]],
			],
			$this->calls
		);
		self::assertSame(['books', 'books'], $this->registers);
	}//end testIdOnlyPostReadsInvoiceAndLinesAndRefusesMissingBillingModel()

	/**
	 * An id-only post of a complete fixed-fee invoice read from the store posts.
	 *
	 * @return void
	 */
	public function testIdOnlyPostOfCompleteInvoicePosts(): void {
		$this->seed(
			[
				'Invoice'     => [['id' => 'inv-8', 'billingModel' => 'fixed_fee']],
				'InvoiceLine' => [['id' => 'l1', 'invoiceId' => 'inv-8', 'sourceType' => 'fixed_fee']],
			]
		);

		self::assertTrue($this->guard()->canPost(invoiceId: 'inv-8'));
		self::assertCount(2, $this->calls);
	}//end testIdOnlyPostOfCompleteInvoicePosts()

	/**
	 * A lookup whose first row is not an array resolves to nothing: refused.
	 *
	 * @return void
	 */
	public function testLookupWithNonArrayFirstRowIsRefused(): void {
		$this->seed(['Invoice' => ['not-a-row', ['id' => 'inv-9', 'billingModel' => 'fixed_fee']]]);

		self::assertFalse($this->guard()->canPost(invoiceId: 'inv-9'));
		self::assertSame([['Invoice', ['filters' => ['id' => 'inv-9']]]], $this->calls);
	}//end testLookupWithNonArrayFirstRowIsRefused()

	/**
	 * An inline invoice with lines but an empty billing model is refused.
	 *
	 * @return void
	 */
	public function testInlineInvoiceWithEmptyBillingModelIsRefused(): void {
		$this->objects->expects($this->never())->method('findAll');

		$invoice = ['id' => 'inv-1', 'billingModel' => '', 'lines' => [['sourceType' => 'fixed_fee']]];

		self::assertFalse($this->guard()->canPost(invoiceId: 'inv-1', object: $invoice));
	}//end testInlineInvoiceWithEmptyBillingModelIsRefused()

	/**
	 * Without inline lines and without an id there is nothing to look up: no
	 * lines, so the post is refused without a store read.
	 *
	 * @return void
	 */
	public function testInlineInvoiceWithoutLinesAndWithoutIdIsRefused(): void {
		$this->objects->expects($this->never())->method('findAll');

		self::assertFalse($this->guard()->canPost(invoiceId: '', object: ['billingModel' => 'fixed_fee']));
	}//end testInlineInvoiceWithoutLinesAndWithoutIdIsRefused()

	/**
	 * Non-array timeEntryIds / expenseIds collapse to no sources, so the
	 * double-invoicing check is skipped and the store is never read.
	 *
	 * @return void
	 */
	public function testNonArraySourceIdListsSkipDeduplication(): void {
		$this->objects->expects($this->never())->method('findAll');

		$invoice = [
			'id'           => 'inv-2',
			'billingModel' => 't_and_m',
			'timeEntryIds' => 'time-1',
			'expenseIds'   => 42,
			'lines'        => [['sourceType' => 'time_entry']],
		];

		self::assertTrue($this->guard()->canPost(invoiceId: 'inv-2', object: $invoice));
	}//end testNonArraySourceIdListsSkipDeduplication()

	/**
	 * Malformed (non-array) lines are skipped when hunting for a completed
	 * milestone line; a later valid one still satisfies the model.
	 *
	 * @return void
	 */
	public function testMilestoneSkipsNonArrayLines(): void {
		$invoice = [
			'id'           => 'inv-ms',
			'billingModel' => 'milestone',
			'lines'        => [
				'garbage',
				['sourceType' => 'milestone', 'modelSpecificFields' => ['milestoneCompletedAt' => '2026-05-01']],
			],
		];

		self::assertTrue($this->guard()->canPost(invoiceId: 'inv-ms', object: $invoice));
	}//end testMilestoneSkipsNonArrayLines()

	/**
	 * Only non-array lines on a milestone invoice: no completed milestone, refused.
	 *
	 * @return void
	 */
	public function testMilestoneWithOnlyNonArrayLinesIsRefused(): void {
		$invoice = ['id' => 'inv-ms2', 'billingModel' => 'milestone', 'lines' => ['garbage', 7]];

		self::assertFalse($this->guard()->canPost(invoiceId: 'inv-ms2', object: $invoice));
	}//end testMilestoneWithOnlyNonArrayLinesIsRefused()

	/**
	 * An empty configured register slug falls back to `shillinq`.
	 *
	 * @return void
	 */
	public function testEmptyRegisterSlugFallsBackToShillinq(): void {
		$this->seed(['Invoice' => [['id' => 'inv-3', 'state' => 'draft']]]);

		self::assertTrue($this->guard(register: '')->canCancel(invoiceId: 'inv-3'));
		self::assertSame(['shillinq'], $this->registers);
	}//end testEmptyRegisterSlugFallsBackToShillinq()

	/**
	 * Cancel without object or id is refused without a store read.
	 *
	 * @return void
	 */
	public function testCancelWithoutObjectOrIdIsRefused(): void {
		$this->objects->expects($this->never())->method('findAll');

		self::assertFalse($this->guard()->canCancel(invoiceId: ''));
	}//end testCancelWithoutObjectOrIdIsRefused()

	/**
	 * A failing lookup during cancel fails closed and logs the cause.
	 *
	 * @return void
	 */
	public function testCancelLookupFailureFailsClosedAndLogs(): void {
		$this->seed(['Invoice' => new RuntimeException('store down')]);

		$this->logger->expects($this->once())->method('error')->with(
			'InvoiceGuard: cancel check failed — denying cancel transition (fail-closed)',
			['invoiceId' => 'inv-x', 'exception' => 'store down']
		);

		self::assertFalse($this->guard()->canCancel(invoiceId: 'inv-x'));
	}//end testCancelLookupFailureFailsClosedAndLogs()
}//end class
