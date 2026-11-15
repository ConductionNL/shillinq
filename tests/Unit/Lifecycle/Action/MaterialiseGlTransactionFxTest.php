<?php

/**
 * Unit tests for booking foreign-currency postings at the booking-date rate
 * in MaterialiseGlTransactionAction (banking-fx-at-booking).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/banking-fx-at-booking/specs/bookkeeping-multi-currency/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction;
use OCA\Shillinq\Service\Treasury\FxAtBookingResolver;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The handler writing to an in-memory store, with the resolver over the
 * design's seed rates.
 */
final class MaterialiseGlTransactionFxTest extends TestCase {
	/**
	 * The store the handler writes to.
	 *
	 * @var InMemoryObjectStore
	 */
	private InMemoryObjectStore $store;

	/**
	 * The handler, with USD 0.9150 on 2026-09-25 and GBP 1.1830 on 2026-09-18.
	 *
	 * @return MaterialiseGlTransactionAction
	 */
	private function action(): MaterialiseGlTransactionAction {
		$this->store = new InMemoryObjectStore();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);
		$rates = new InMemoryObjectServiceStub(
			[
				'FxRate' => [
					[
						'id' => 'fx-usd', 'transactionCurrency' => 'USD', 'baseCurrency' => 'EUR',
						'date' => '2026-09-25', 'source' => 'manual', 'rate' => 0.9150, 'manualOverrideReason' => 'ECB-koers overgenomen',
					],
					[
						'id' => 'fx-gbp', 'transactionCurrency' => 'GBP', 'baseCurrency' => 'EUR',
						'date' => '2026-09-18', 'source' => 'ecb', 'rate' => 1.1830,
					],
				],
				'Administration' => [['id' => 'adm-1', 'functionalCurrency' => 'EUR']],
			]
		);

		return new MaterialiseGlTransactionAction(
			$this->store->mock($this),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			null,
			new FxAtBookingResolver($rates, $appConfig)
		);

	}//end action()

	/**
	 * A two-line journal entry on Sunday 2026-09-27.
	 *
	 * @param string              $currency The entry's currency.
	 * @param float               $amount   The amount on both lines.
	 * @param array<string,mixed> $typed    Extra members on both lines (a typed rate).
	 *
	 * @return array<string,mixed>
	 */
	private static function entry(string $currency, float $amount, array $typed = []): array {
		return [
			'id' => 'je-1',
			'journalNumber' => 'MEM-2026-0927',
			'entryDate' => '2026-09-27',
			'description' => 'Supplier invoice',
			'administrationId' => 'adm-1',
			'currency' => $currency,
			'lines' => [
				['accountNumber' => '4000', 'side' => 'debit', 'amount' => $amount] + $typed,
				['accountNumber' => '1600', 'side' => 'credit', 'amount' => $amount] + $typed,
			],
		];

	}//end entry()

	/**
	 * Post an entry.
	 *
	 * @param array<string,mixed> $entry The entry.
	 *
	 * @return void
	 */
	private function post(array $entry): void {
		$this->action()->execute($entry, [], ['sourceSchema' => 'JournalEntry'], MaterialiseGlTransactionAction::class);

	}//end post()

	/**
	 * A USD entry on a Sunday is booked at Friday's rate with the full snapshot (REQ-BFX-001).
	 *
	 * @return void
	 */
	public function testAUsdEntryOnASundayIsBookedAtFridaysRate(): void {
		$this->post(self::entry('usd', 1000.0));

		$lines = $this->store->savedOf('GLLine');
		$this->assertCount(2, $lines);
		$this->assertSame(915.0, $lines[0]['amount']);
		$this->assertSame('EUR', $lines[0]['currency']);
		$this->assertSame(100000, $lines[0]['transactionAmount']);
		$this->assertSame('USD', $lines[0]['transactionCurrency']);
		$this->assertSame(91500, $lines[0]['baseCurrencyAmount']);
		$this->assertSame('EUR', $lines[0]['baseCurrency']);
		$this->assertSame(0.915, $lines[0]['fxRate']);
		$this->assertSame('manual', $lines[0]['fxRateSource']);
		$this->assertSame('2026-09-25', $lines[0]['fxRateDate']);
		$this->assertSame(['debit', 'credit'], array_column($lines, 'side'));
		$this->assertSame('EUR', $this->store->savedOf('GLTransaction')[0]['currency']);

		$line = $lines[0];
		// The store's ids are not uuids; the snapshot fields are what is checked here.
		unset($line['id']);
		$line['transactionId'] = '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';
		$this->assertSame([], RegisterSchema::errors(slug: 'GLLine', object: $line), 'the booked line validates against the merged register');

	}//end testAUsdEntryOnASundayIsBookedAtFridaysRate()

	/**
	 * A GBP entry whose newest rate is nine days old is refused and nothing is written (REQ-BFX-002).
	 *
	 * @return void
	 */
	public function testAGbpEntryWithAStaleRateIsRefused(): void {
		try {
			$this->post(self::entry('GBP', 500.0));
			$this->fail('the stale GBP rate was used');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('GBP to EUR', $e->getMessage());
			$this->assertStringContainsString('2026-09-27', $e->getMessage());
			$this->assertStringContainsString('FX rates', $e->getMessage());
		}

		$this->assertSame([], $this->store->savedOf('GLTransaction'));
		$this->assertSame([], $this->store->savedOf('GLLine'));

	}//end testAGbpEntryWithAStaleRateIsRefused()

	/**
	 * A typed rate with a reason wins and is recorded as manual on the booking date (REQ-BFX-003).
	 *
	 * @return void
	 */
	public function testATypedRateWinsAndIsRecordedAsManual(): void {
		$this->post(self::entry('GBP', 20000.0, ['fxRate' => 0.9102, 'fxRateReason' => 'Termijncontract ING 2026-114']));

		$lines = $this->store->savedOf('GLLine');
		$this->assertSame(18204.0, $lines[0]['amount']);
		$this->assertSame(1820400, $lines[1]['baseCurrencyAmount']);
		$this->assertSame(0.9102, $lines[0]['fxRate']);
		$this->assertSame('manual', $lines[0]['fxRateSource']);
		$this->assertSame('2026-09-27', $lines[0]['fxRateDate']);
		$this->assertArrayNotHasKey('fxRateReason', $lines[0]);

	}//end testATypedRateWinsAndIsRecordedAsManual()

	/**
	 * A typed rate without a reason is refused.
	 *
	 * @return void
	 */
	public function testATypedRateWithoutAReasonIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The exchange rate typed on line 1 needs a reason.');
		$this->post(self::entry('USD', 100.0, ['fxRate' => 0.9, 'fxRateReason' => ' ']));

	}//end testATypedRateWithoutAReasonIsRefused()

	/**
	 * A typed rate that is not a positive number is refused.
	 *
	 * @return void
	 */
	public function testATypedRateThatIsNotPositiveIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The exchange rate typed on line 1 must be a positive number.');
		$this->post(self::entry('USD', 100.0, ['fxRate' => 0, 'fxRateReason' => 'agreed']));

	}//end testATypedRateThatIsNotPositiveIsRefused()

	/**
	 * Rounding that unbalances the entry in base currency refuses it, naming the difference.
	 *
	 * @return void
	 */
	public function testRoundingThatUnbalancesTheEntryIsRefused(): void {
		$entry = self::entry('USD', 0.0);
		$entry['lines'] = [
			['accountNumber' => '4000', 'side' => 'debit', 'amount' => 0.03, 'fxRate' => 0.5, 'fxRateReason' => 'test'],
			['accountNumber' => '4010', 'side' => 'debit', 'amount' => 0.03, 'fxRate' => 0.5, 'fxRateReason' => 'test'],
			['accountNumber' => '1600', 'side' => 'credit', 'amount' => 0.06, 'fxRate' => 0.5, 'fxRateReason' => 'test'],
		];

		try {
			$this->post($entry);
			$this->fail('an unbalanced base entry was posted');
		} catch (RuntimeException $e) {
			$this->assertSame(
				'Supplier invoice does not balance in EUR after booking at the exchange rate: debit 0.04, credit 0.03, difference 0.01. '
				. 'Type the rate on the lines or correct the amounts.',
				$e->getMessage()
			);
		}

		$this->assertSame([], $this->store->savedOf('GLLine'));

	}//end testRoundingThatUnbalancesTheEntryIsRefused()

	/**
	 * An entry in the base currency, or without a currency, posts as before: no snapshot.
	 *
	 * @return void
	 */
	public function testABaseCurrencyEntryCarriesNoSnapshot(): void {
		$this->post(self::entry('EUR', 250.0, ['fxRate' => 2.0]));

		$lines = $this->store->savedOf('GLLine');
		$this->assertSame(250.0, $lines[0]['amount']);
		$this->assertSame('EUR', $lines[0]['currency']);
		$this->assertArrayNotHasKey('fxRate', $lines[0]);
		$this->assertArrayNotHasKey('transactionCurrency', $lines[0]);

		$entry = self::entry('', 250.0);
		unset($entry['currency']);
		$this->post($entry);
		$this->assertArrayNotHasKey('fxRate', $this->store->savedOf('GLLine')[0]);

	}//end testABaseCurrencyEntryCarriesNoSnapshot()
}//end class
