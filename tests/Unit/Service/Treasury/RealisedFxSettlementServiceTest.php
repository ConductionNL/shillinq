<?php

/**
 * Unit tests for `RealisedFxSettlementService` — realised FX gain/loss posted
 * when a foreign-currency ARInvoice settles at a rate different from the
 * invoice-date rate it was booked at (REQ-MC-010).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Treasury
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ar-billing-completeness/specs/bookkeeping-multi-currency/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Treasury;

use OCA\Shillinq\Service\Treasury\RealisedFxSettlementService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for `RealisedFxSettlementService::postRealisedFxOnSettlement()`.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RealisedFxSettlementServiceTest extends TestCase {

	/**
	 * In-memory fake ObjectService supporting the fluent single-argument
	 * saveObject shape this service consumes (mirrors InvoiceGenerationService),
	 * keyed per schema so ARInvoice, Administration, FxRate, GLTransaction and
	 * RealisedFxPosting are independent within one test.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Subject under test.
	 *
	 * @var RealisedFxSettlementService
	 */
	private RealisedFxSettlementService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = new class {

			/**
			 * Per-schema fixture rows the fake responds to `findAll()` with.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $fixtures = [];

			/**
			 * Objects saved during the run, keyed by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $saved = [];

			/**
			 * Schema selected by the most recent `setSchema()` call.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Fluent register selector (no-op fake).
			 *
			 * @param string $register Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema selector — remembers the schema.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return the fixture rows for the selected schema, applying equality filters.
			 *
			 * @param array<string,mixed> $options Query options (`filters` supported).
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $options): array {
				$rows = $this->fixtures[$this->schema] ?? [];
				$filters = $options['filters'] ?? [];
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Record the saved object under the currently selected schema.
			 *
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->saved[$this->schema][] = $object;
				return $object;
			}//end saveObject()
		};

		$this->objectService->fixtures = [
			'Administration' => [['id' => 'adm-holding-nl', 'functionalCurrency' => 'EUR']],
			'FxRate' => [],
		];

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $default
		);

		$this->service = new RealisedFxSettlementService(
			$container,
			$appConfig,
			new NullLogger()
		);
	}//end setUp()

	/**
	 * Build a settled foreign-currency ARInvoice fixture.
	 *
	 * @param string $currency Invoice currency.
	 * @param float $gross Gross amount in the foreign currency.
	 * @param float|null $bookedRate Rate booked on the invoice (or null).
	 * @param string $invoiceDate ISO invoice date.
	 *
	 * @return array<string,mixed>
	 */
	private function invoice(string $currency, float $gross, ?float $bookedRate, string $invoiceDate = '2026-01-10'): array {
		return [
			'id' => 'arinv-usd-0007',
			'invoiceNumber' => 'AR-2026-0007',
			'administrationId' => 'adm-holding-nl',
			'currency' => $currency,
			'grossAmount' => $gross,
			'invoiceDate' => $invoiceDate,
			'fxRate' => $bookedRate,
		];
	}//end invoice()

	/**
	 * Assert a persisted GLTransaction is self-balancing at the expected amount.
	 *
	 * @param array<string,mixed> $journal The saved GLTransaction.
	 * @param int $expectedCents Expected debit == credit total.
	 * @param string $debitAccount Account carrying the debit.
	 * @param string $creditAccount Account carrying the credit.
	 *
	 * @return void
	 */
	private function assertBalanced(array $journal, int $expectedCents, string $debitAccount, string $creditAccount): void {
		self::assertTrue($journal['isBalanced']);

		$debitTotal = 0;
		$creditTotal = 0;
		$debitOn = [];
		$creditOn = [];
		foreach ($journal['postings'] as $posting) {
			$debitTotal += (int)$posting['debitCents'];
			$creditTotal += (int)$posting['creditCents'];
			if ((int)$posting['debitCents'] > 0) {
				$debitOn[$posting['accountNumber']] = (int)$posting['debitCents'];
			}

			if ((int)$posting['creditCents'] > 0) {
				$creditOn[$posting['accountNumber']] = (int)$posting['creditCents'];
			}
		}

		// The balance invariant: debit == credit == the realised magnitude.
		self::assertSame($creditTotal, $debitTotal, 'GLTransaction must balance (debit == credit)');
		self::assertSame($expectedCents, $debitTotal);
		self::assertSame($expectedCents, $debitOn[$debitAccount] ?? -1);
		self::assertSame($expectedCents, $creditOn[$creditAccount] ?? -1);
	}//end assertBalanced()

	/**
	 * REQ-MC-010: a USD invoice booked at 0.90 that settles when the dollar has
	 * strengthened to 0.93 posts a realised GAIN — debit AR-control, credit the
	 * realised-gain account, balanced at |100000 x (0.93-0.90)| = €3000.
	 *
	 * @return void
	 */
	public function testForeignInvoicePaidAtStrongerRatePostsRealisedGain(): void {
		$report = $this->service->postRealisedFxOnSettlement(
			invoice: $this->invoice('USD', 100000.0, 0.90),
			settlementRate: 0.93,
			settlementDate: '2026-04-15'
		);

		self::assertTrue($report['posted']);
		self::assertSame('gain', $report['direction']);
		self::assertSame(300000, $report['realisedCents']);

		self::assertCount(1, $this->objectService->saved['GLTransaction'] ?? []);
		$this->assertBalanced(
			$this->objectService->saved['GLTransaction'][0],
			300000,
			'1130',
			'8022'
		);

		self::assertCount(1, $this->objectService->saved['RealisedFxPosting'] ?? []);
		$posting = $this->objectService->saved['RealisedFxPosting'][0];
		self::assertSame('gain', $posting['direction']);
		self::assertSame(300000, $posting['realisedDeltaCents']);
		self::assertSame(0.90, $posting['invoiceRate']);
		self::assertSame(0.93, $posting['paymentRate']);
		self::assertSame('8022', $posting['gainLossGLAccount']);
		self::assertSame('SYSTEM:RealisedFxSettlementService', $posting['postedBy']);
	}//end testForeignInvoicePaidAtStrongerRatePostsRealisedGain()

	/**
	 * REQ-MC-010: the same invoice booked at 0.93 that settles when the dollar
	 * has weakened to 0.90 posts a realised LOSS — debit the realised-loss
	 * account, credit AR-control, balanced at €3000. Both directions proven.
	 *
	 * @return void
	 */
	public function testForeignInvoicePaidAtWeakerRatePostsRealisedLoss(): void {
		$report = $this->service->postRealisedFxOnSettlement(
			invoice: $this->invoice('USD', 100000.0, 0.93),
			settlementRate: 0.90,
			settlementDate: '2026-05-15'
		);

		self::assertTrue($report['posted']);
		self::assertSame('loss', $report['direction']);
		self::assertSame(-300000, $report['realisedCents']);

		self::assertCount(1, $this->objectService->saved['GLTransaction'] ?? []);
		$this->assertBalanced(
			$this->objectService->saved['GLTransaction'][0],
			300000,
			'8023',
			'1130'
		);

		$posting = $this->objectService->saved['RealisedFxPosting'][0];
		self::assertSame('loss', $posting['direction']);
		self::assertSame(-300000, $posting['realisedDeltaCents']);
		self::assertSame('8023', $posting['gainLossGLAccount']);
	}//end testForeignInvoicePaidAtWeakerRatePostsRealisedLoss()

	/**
	 * When the invoice carries no booked fxRate, both rates are resolved from
	 * the FxRate register at the invoice date and the settlement date.
	 *
	 * @return void
	 */
	public function testRatesResolvedFromFxRateRegisterWhenNotBooked(): void {
		$this->objectService->fixtures['FxRate'] = [
			['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'date' => '2026-01-10', 'rate' => 0.90],
			['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'date' => '2026-04-15', 'rate' => 0.93],
		];

		$report = $this->service->postRealisedFxOnSettlement(
			invoice: $this->invoice('USD', 100000.0, null, '2026-01-10'),
			settlementRate: null,
			settlementDate: '2026-04-15'
		);

		self::assertTrue($report['posted']);
		self::assertSame('gain', $report['direction']);
		self::assertSame(300000, $report['realisedCents']);
		self::assertSame(0.90, $report['invoiceRate']);
		self::assertSame(0.93, $report['paymentRate']);
	}//end testRatesResolvedFromFxRateRegisterWhenNotBooked()

	/**
	 * A functional-currency (EUR) invoice has no FX exposure — nothing posts.
	 *
	 * @return void
	 */
	public function testFunctionalCurrencyInvoicePostsNothing(): void {
		$report = $this->service->postRealisedFxOnSettlement(
			invoice: $this->invoice('EUR', 100000.0, 1.0),
			settlementRate: 1.0,
			settlementDate: '2026-04-15'
		);

		self::assertFalse($report['posted']);
		self::assertSame('same-currency', $report['reason']);
		self::assertArrayNotHasKey('GLTransaction', $this->objectService->saved);
		self::assertArrayNotHasKey('RealisedFxPosting', $this->objectService->saved);
	}//end testFunctionalCurrencyInvoicePostsNothing()

	/**
	 * When neither a booked rate nor any FxRate snapshot is available the
	 * settlement is left unposted (fail-open) rather than throwing.
	 *
	 * @return void
	 */
	public function testNoResolvableRatePostsNothing(): void {
		$report = $this->service->postRealisedFxOnSettlement(
			invoice: $this->invoice('USD', 100000.0, null, '2026-01-10'),
			settlementRate: null,
			settlementDate: '2026-04-15'
		);

		self::assertFalse($report['posted']);
		self::assertSame('no-rate', $report['reason']);
		self::assertArrayNotHasKey('GLTransaction', $this->objectService->saved);
	}//end testNoResolvableRatePostsNothing()

	/**
	 * A settlement at exactly the invoice-date rate has zero realised movement
	 * and posts nothing.
	 *
	 * @return void
	 */
	public function testZeroMovementPostsNothing(): void {
		$report = $this->service->postRealisedFxOnSettlement(
			invoice: $this->invoice('USD', 100000.0, 0.91),
			settlementRate: 0.91,
			settlementDate: '2026-04-15'
		);

		self::assertFalse($report['posted']);
		self::assertSame('no-fx-movement', $report['reason']);
		self::assertArrayNotHasKey('GLTransaction', $this->objectService->saved);
	}//end testZeroMovementPostsNothing()
}//end class
