<?php

/**
 * Edge cases of VatLineBackfill over hand-built ledger rows: the amount kind of
 * a coded line, account-derived tariffs (fractional, zero and unusable rates,
 * non-P&L accounts), configured VAT accounts, empty stamp fields and rows the
 * backfill must skip.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Vat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Vat;

use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Vat\VatLineBackfill;
use OCA\Shillinq\Service\Vat\VatLineStamper;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * VatLineBackfill edge cases.
 *
 * @covers \OCA\Shillinq\Service\Vat\VatLineBackfill
 * @uses   \OCA\Shillinq\Service\Vat\VatLineStamper
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 * @uses   \OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches
 */
final class VatLineBackfillEdgeCaseTest extends TestCase {

	/**
	 * Captured saves.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The store of the last backfill.
	 *
	 * @var InMemoryObjectServiceStub|null
	 */
	private ?InMemoryObjectServiceStub $store = null;

	/**
	 * The seeded 2026 VAT tariffs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function tariffs(): array {
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/seeds/btw-tariffs-2026.json'), true);
		return $seed['tariffs'];

	}//end tariffs()

	/**
	 * Build a backfill over the given rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $seed Rows per schema
	 * @param LoggerInterface|null $logger Optional logger
	 * @param array<string, string> $config App config values by key
	 *
	 * @return VatLineBackfill
	 */
	private function backfill(array $seed, ?LoggerInterface $logger = null, array $config = []): VatLineBackfill {
		$this->saved = [];
		$this->store = new InMemoryObjectServiceStub($seed + ['VatTariff' => $this->tariffs()], $this->saved, true);
		$settings = $this->createStub(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new VatLineBackfill($this->store, $settings, $appConfig, ($logger ?? $this->createMock(LoggerInterface::class)));

	}//end backfill()

	/**
	 * The stamp fields of every line, keyed by line id.
	 *
	 * @return array<string, array{0: mixed, 1: mixed, 2: mixed}>
	 */
	private function stamps(): array {
		$out = [];
		foreach ($this->store->setSchema('GLLine')->findAll() as $row) {
			$line = $row->getObject();
			$out[(string)$line['id']] = [($line['vatTariffCode'] ?? null), ($line['vatReturnBox'] ?? null), ($line['vatAmountKind'] ?? null)];
		}

		return $out;

	}//end stamps()

	/**
	 * The return box the real stamper assigns.
	 *
	 * @param string $code Tariff code
	 * @param string $kind base|vat
	 * @param bool $purchase Purchase side
	 *
	 * @return string|null
	 */
	private function box(string $code, string $kind, bool $purchase): ?string {
		$stamper = new VatLineStamper(new InMemoryObjectServiceStub(['VatTariff' => $this->tariffs()], $sink, true), 'shillinq');
		return ($stamper->stamp(code: $code, kind: $kind, purchase: $purchase)['vatReturnBox'] ?? null);

	}//end box()

	/**
	 * A posted transaction.
	 *
	 * @param string $id The id
	 * @param string $sourceReference The source reference
	 *
	 * @return array<string, mixed>
	 */
	private static function transaction(string $id, string $sourceReference = 'JournalEntry:je-1'): array {
		return ['id' => $id, 'state' => 'posted', 'administrationId' => 'adm-1', 'postingDate' => '2026-08-01', 'sourceReference' => $sourceReference];

	}//end transaction()

	/**
	 * A ledger line.
	 *
	 * @param string $id The id
	 * @param string $transactionId The transaction
	 * @param string $accountNumber The account
	 * @param array<string, mixed> $extra Extra fields
	 *
	 * @return array<string, mixed>
	 */
	private static function line(string $id, string $transactionId, string $accountNumber, array $extra = []): array {
		return array_merge(
			['id' => $id, 'transactionId' => $transactionId, 'accountNumber' => $accountNumber, 'side' => 'debit', 'amount' => 10.0, 'administrationId' => 'adm-1'],
			$extra
		);

	}//end line()

	/**
	 * A VAT-applicable account.
	 *
	 * @param string $number The number
	 * @param string $type The account type
	 * @param mixed $rate The VAT rate
	 *
	 * @return array<string, mixed>
	 */
	private static function account(string $number, string $type, mixed $rate): array {
		return [
			'id' => 'acc-' . $number, 'accountNumber' => $number, 'accountType' => $type, 'vatApplicable' => true, 'vatRate' => $rate,
			'administrationId' => 'adm-1',
		];

	}//end account()

	/**
	 * A coded line without a kind is a VAT line on a VAT account and a base line elsewhere; an invoice source sets the side.
	 *
	 * @return void
	 */
	public function testCodedLineWithoutKindTakesItFromTheAccount(): void {
		$result = $this->backfill(
			[
				'GLTransaction' => [self::transaction('tx-1', 'APInvoice:ap-1')],
				'GLLine' => [
					self::line('l1', 'tx-1', '7000', ['vatTariffCode' => 'high']),
					self::line('l2', 'tx-1', '1230', ['vatTariffCode' => 'high']),
					self::line('l3', 'tx-1', '2110', ['vatTariffCode' => 'high', 'vatAmountKind' => 'base']),
				],
			]
		)->run();

		// l3 already carries its tariff and kind, and a purchase base line has no box: nothing to write.
		$this->assertSame(['stamped' => 2, 'unresolved' => 0], $result);
		$stamps = $this->stamps();
		$this->assertSame(['high', $this->box('high', 'base', true), 'base'], $stamps['l1']);
		$this->assertSame(['high', $this->box('high', 'vat', true), 'vat'], $stamps['l2']);
		$this->assertSame(['high', null, 'base'], $stamps['l3']);
		$this->assertNull($stamps['l1'][1]);
		$this->assertNotNull($stamps['l2'][1]);

	}//end testCodedLineWithoutKindTakesItFromTheAccount()

	/**
	 * Without an invoice source the side follows the VAT account: input VAT is purchase, output VAT is sale.
	 *
	 * @return void
	 */
	public function testSideFollowsTheVatAccountWithoutInvoiceSource(): void {
		$this->backfill(
			[
				'GLTransaction' => [self::transaction('tx-1')],
				'GLLine' => [
					self::line('l1', 'tx-1', '1230', ['vatTariffCode' => 'low']),
					self::line('l2', 'tx-1', '2110', ['vatTariffCode' => 'low']),
				],
			]
		)->run();

		$stamps = $this->stamps();
		$this->assertSame($this->box('low', 'vat', true), $stamps['l1'][1]);
		$this->assertSame($this->box('low', 'vat', false), $stamps['l2'][1]);

	}//end testSideFollowsTheVatAccountWithoutInvoiceSource()

	/**
	 * A sales-invoice source makes a coded line a sale; an uncoded VAT line takes the one base
	 * tariff but keeps the side of its VAT account.
	 *
	 * @return void
	 */
	public function testSalesInvoiceSourceAndSingleBaseTariff(): void {
		$result = $this->backfill(
			[
				'GLTransaction' => [self::transaction('tx-1', 'ARInvoice:ar-1')],
				'GLLine' => [
					self::line('l1', 'tx-1', '8000', ['vatTariffCode' => 'low']),
					self::line('l2', 'tx-1', '1230'),
				],
			]
		)->run();

		$this->assertSame(['stamped' => 2, 'unresolved' => 0], $result);
		$stamps = $this->stamps();
		$this->assertSame(['low', $this->box('low', 'base', false), 'base'], $stamps['l1']);
		$this->assertSame(['low', $this->box('low', 'vat', true), 'vat'], $stamps['l2']);

	}//end testSalesInvoiceSourceAndSingleBaseTariff()

	/**
	 * Configured VAT account numbers replace the defaults.
	 *
	 * @return void
	 */
	public function testConfiguredVatAccountsReplaceTheDefaults(): void {
		$this->backfill(
			[
				'GLTransaction' => [self::transaction('tx-1')],
				'GLLine' => [
					self::line('l1', 'tx-1', '1500', ['vatTariffCode' => 'high']),
					self::line('l2', 'tx-1', '1230', ['vatTariffCode' => 'high']),
				],
			],
			null,
			['ledger_input_vat_account' => ' 1500 ']
		)->run();

		$stamps = $this->stamps();
		$this->assertSame('vat', $stamps['l1'][2]);
		$this->assertSame('base', $stamps['l2'][2]);

	}//end testConfiguredVatAccountsReplaceTheDefaults()

	/**
	 * A fractional rate is read as a percentage; a zero rate is a tariff only on purchases.
	 *
	 * @return void
	 */
	public function testAccountRatesFractionalAndZero(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('cannot resolve'), $this->callback(static fn (array $c): bool => $c['lineId'] === 'l4'));

		$result = $this->backfill(
			[
				'GLTransaction' => [self::transaction('tx-1'), self::transaction('tx-2')],
				'GLLine' => [
					self::line('l1', 'tx-1', '8000'),
					self::line('l2', 'tx-1', '7000'),
					self::line('l3', 'tx-1', '7100'),
					self::line('l4', 'tx-2', '8100'),
				],
				'Account' => [
					self::account('8000', 'revenue', 0.21),
					self::account('7000', 'expenses', '9.00'),
					self::account('7100', 'expenses', 0),
					self::account('8100', 'revenue', 0),
				],
			],
			$logger
		)->run();

		$this->assertSame(['stamped' => 3, 'unresolved' => 1], $result);
		$stamps = $this->stamps();
		$this->assertSame(['high', $this->box('high', 'base', false), 'base'], $stamps['l1']);
		$this->assertSame(['low', $this->box('low', 'base', true), 'base'], $stamps['l2']);
		$this->assertSame(['zero', $this->box('zero', 'base', true), 'base'], $stamps['l3']);
		$this->assertSame([null, null, null], $stamps['l4']);

	}//end testAccountRatesFractionalAndZero()

	/**
	 * A VAT-applicable account outside revenue/expenses, or with an unusable rate, leaves the line unresolved.
	 *
	 * @return void
	 */
	public function testUnusableAccountsAreUnresolved(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[] = $context['lineId'];
			}
		);

		$result = $this->backfill(
			[
				'GLTransaction' => [self::transaction('tx-1')],
				'GLLine' => [
					self::line('l1', 'tx-1', '0100'),
					self::line('l2', 'tx-1', '8000'),
					self::line('l3', 'tx-1', '8200'),
					self::line('l4', 'tx-1', '9999'),
				],
				'Account' => [
					self::account('0100', 'assets', 21),
					self::account('8000', 'revenue', 'n/a'),
					self::account('8200', 'revenue', 15),
				],
			],
			$logger
		)->run();

		$this->assertSame(['stamped' => 0, 'unresolved' => 3], $result);
		$this->assertSame(['l1', 'l2', 'l3'], $logged);
		$this->assertSame([], $this->saved);

	}//end testUnusableAccountsAreUnresolved()

	/**
	 * An empty stamp field counts as missing and is filled; a complete line is not written again.
	 *
	 * @return void
	 */
	public function testEmptyStampFieldsAreFilled(): void {
		$box = $this->box('high', 'base', false);
		$result = $this->backfill(
			[
				'GLTransaction' => [self::transaction('tx-1')],
				'GLLine' => [
					self::line('l1', 'tx-1', '8000', ['vatTariffCode' => 'high', 'vatReturnBox' => '', 'vatAmountKind' => '']),
					self::line('l2', 'tx-1', '8000', ['vatTariffCode' => 'high', 'vatReturnBox' => $box, 'vatAmountKind' => 'base']),
				],
			]
		)->run();

		$this->assertSame(['stamped' => 1, 'unresolved' => 0], $result);
		$this->assertSame(['high', $box, 'base'], $this->stamps()['l1']);

	}//end testEmptyStampFieldsAreFilled()

	/**
	 * Reversed transactions are stamped too; lines of other transactions are untouched.
	 *
	 * @return void
	 */
	public function testReversedTransactionsAreStamped(): void {
		$reversed = self::transaction('tx-1');
		$reversed['state'] = 'reversed';

		$result = $this->backfill(
			[
				'GLTransaction' => [$reversed],
				'GLLine' => [
					self::line('l1', 'tx-1', '8000', ['vatTariffCode' => 'low']),
					self::line('l2', 'tx-9', '8000', ['vatTariffCode' => 'low']),
				],
			]
		)->run();

		$this->assertSame(['stamped' => 1, 'unresolved' => 0], $result);
		$this->assertSame('low', $this->stamps()['l1'][0]);
		$this->assertNull($this->stamps()['l2'][1]);

	}//end testReversedTransactionsAreStamped()
}//end class
