<?php

/**
 * Unit tests for VatLineBackfill and its repair step.
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
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Vat;

use OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction;
use OCA\Shillinq\Repair\StampVatReturnBoxes;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Vat\VatLineBackfill;
use OCA\Shillinq\Tests\Unit\Lifecycle\Action\InMemoryObjectStore;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The backfill stamps posted lines once: from their tariff code, else from
 * the account's VAT settings, and names the lines it cannot resolve.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VatLineBackfillTest extends TestCase {

	/**
	 * Every write the store received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub|null
	 */
	private ?InMemoryObjectServiceStub $store = null;

	/**
	 * The BTW tariffs the app seeds.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function tariffs(): array {
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/seeds/btw-tariffs-2026.json'), true);
		return $seed['tariffs'];
	}//end tariffs()

	/**
	 * Post documents through the real mapper and return the rows it wrote.
	 *
	 * @param list<array{0: array<string,mixed>, 1: string}> $documents   Each document and its source schema.
	 * @param bool                                           $withTariffs Whether the tariffs exist when posting.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	private function posted(array $documents, bool $withTariffs): array {
		$store = new InMemoryObjectStore();
		if ($withTariffs === true) {
			$store->rows['VatTariff'] = $this->tariffs();
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$action = new MaterialiseGlTransactionAction($store->mock($this), $appConfig, $this->createMock(LoggerInterface::class));
		foreach ($documents as [$document, $sourceSchema]) {
			$action->execute($document, [], ['sourceSchema' => $sourceSchema], MaterialiseGlTransactionAction::class);
		}

		return ['GLTransaction' => $store->savedOf('GLTransaction'), 'GLLine' => $store->savedOf('GLLine')];
	}//end posted()

	/**
	 * A sale at two rates and a split purchase, as the design's examples.
	 *
	 * @return list<array{0: array<string,mixed>, 1: string}>
	 */
	private function documents(): array {
		return [
			[
				[
					'id' => 'ar-9', 'invoiceNumber' => '2026-0090', 'invoiceDate' => '2026-08-12', 'periodId' => '2026-08',
					'administrationId' => 'adm-1', 'currency' => 'EUR', 'grossAmount' => 1755.0, 'netAmount' => 1500.0, 'vatAmount' => 255.0,
					'invoiceLines' => [
						['itemName' => 'Taarten', 'netAmount' => 500.0, 'vatCategory' => 'S', 'vatRate' => 9],
						['itemName' => 'Cursus', 'netAmount' => 1000.0, 'vatCategory' => 'S', 'vatRate' => 21],
					],
					'lifecycleState' => 'issued',
				],
				'ARInvoice',
			],
			[
				[
					'id' => 'ap-9', 'invoiceNumber' => 'INK-90', 'invoiceDate' => '2026-08-02', 'administrationId' => 'adm-1',
					'totalAmount' => 7020.0, 'taxAmount' => 1020.0,
					'lines' => [
						['accountNumber' => '7000', 'amount' => 4000.0, 'description' => 'Oven', 'taxCode' => 'BTW21'],
						['accountNumber' => '7010', 'amount' => 2000.0, 'description' => 'Meel', 'taxCode' => 'low'],
					],
					'state' => 'posted',
				],
				'APInvoice',
			],
		];
	}//end documents()

	/**
	 * The backfill over a seed, with a logger.
	 *
	 * @param array<string,list<array<string,mixed>>> $seed   Rows per schema.
	 * @param LoggerInterface|null                     $logger The logger.
	 *
	 * @return VatLineBackfill
	 */
	private function backfill(array $seed, ?LoggerInterface $logger = null): VatLineBackfill {
		$this->saved = [];
		$this->store = new InMemoryObjectServiceStub($seed + ['VatTariff' => $this->tariffs()], $this->saved, true);
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		return new VatLineBackfill($this->store, $settings, $appConfig, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end backfill()

	/**
	 * The stamps of the store's lines as [account, tariff, box, kind], in line order.
	 *
	 * @return list<array{0: string, 1: string|null, 2: string|null, 3: string|null}>
	 */
	private function stamps(): array {
		$out = [];
		foreach ($this->store->setSchema('GLLine')->findAll() as $row) {
			$line  = $row->getObject();
			$out[] = [$line['accountNumber'], ($line['vatTariffCode'] ?? null), ($line['vatReturnBox'] ?? null), ($line['vatAmountKind'] ?? null)];
		}

		return $out;
	}//end stamps()

	/**
	 * The same stamps for a list of rows.
	 *
	 * @param list<array<string,mixed>> $lines The lines.
	 *
	 * @return list<array{0: string, 1: string|null, 2: string|null, 3: string|null}>
	 */
	private function stampsOf(array $lines): array {
		return array_map(
			static fn (array $l): array => [$l['accountNumber'], ($l['vatTariffCode'] ?? null), ($l['vatReturnBox'] ?? null), ($l['vatAmountKind'] ?? null)],
			$lines
		);
	}//end stampsOf()

	/**
	 * Lines posted while the tariffs were not there carry their tariff and
	 * kind but no box. The backfill gives them the stamps the mapper writes
	 * when the tariffs exist, and a second run writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.3
	 */
	public function testLinesWithATariffGetTheirBoxOnceAndASecondRunWritesNothing(): void {
		$sale = [$this->documents()[0]];
		$backfill = $this->backfill($this->posted($sale, false));

		$first = $backfill->run();

		self::assertSame($this->stampsOf($this->posted($sale, true)['GLLine']), $this->stamps());
		self::assertSame(4, $first['stamped']);
		self::assertSame(0, $first['unresolved']);

		$writes = count($this->saved);
		$second = $backfill->run();
		self::assertSame(0, $second['stamped']);
		self::assertSame($writes, count($this->saved));
	}//end testLinesWithATariffGetTheirBoxOnceAndASecondRunWritesNothing()

	/**
	 * Lines posted before any stamp existed are resolved from the account's
	 * VAT settings: a revenue or expense account by its rate, and a line on
	 * the output or input VAT account by the one tariff of its transaction's
	 * base lines. The stamps equal what the mapper writes today, and every
	 * patched line validates against the merged register.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.3
	 */
	public function testUnstampedLinesAreResolvedFromTheAccountsVatSettings(): void {
		$rows = $this->posted([$this->documents()[1]], true);
		$expected = $this->stampsOf($rows['GLLine']);
		foreach ($rows['GLLine'] as $index => $line) {
			unset($line['vatTariffCode'], $line['vatReturnBox'], $line['vatAmountKind']);
			$rows['GLLine'][$index] = $line;
		}

		$rows['Account'] = [
			['id' => 'acc-7000', 'accountNumber' => '7000', 'accountType' => 'expenses', 'vatApplicable' => true, 'vatRate' => 21, 'administrationId' => 'adm-1'],
			['id' => 'acc-7010', 'accountNumber' => '7010', 'accountType' => 'expenses', 'vatApplicable' => true, 'vatRate' => 9, 'administrationId' => 'adm-1'],
		];

		$result = $this->backfill($rows)->run();

		// The input VAT lines sit on one account for two tariffs: each takes the tariff only when its
		// transaction's base lines have one. This purchase has two, so its VAT lines cannot be resolved.
		self::assertSame(
			[
				['7000', 'high', null, 'base'],
				['7010', 'low', null, 'base'],
				['1230', null, null, null],
				['1230', null, null, null],
				['2000', null, null, null],
			],
			$this->stamps()
		);
		self::assertSame(2, $result['stamped']);
		self::assertSame(2, $result['unresolved']);
		self::assertSame(array_slice($expected, 0, 2), array_slice($this->stamps(), 0, 2));
	}//end testUnstampedLinesAreResolvedFromTheAccountsVatSettings()

	/**
	 * A sale at one rate, posted before stamps: the revenue line takes the
	 * tariff of its account's rate and the output VAT line takes the same
	 * tariff, both in box 1b, as the mapper writes them today. The line on the
	 * receivables account is in no box and is not touched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.3
	 */
	public function testAVatLineTakesTheOneTariffOfItsTransaction(): void {
		$sale = [
			[
				'id' => 'ar-kb-1', 'invoiceNumber' => '2026-0301', 'invoiceDate' => '2026-08-14',
				'administrationId' => 'adm-1', 'currency' => 'EUR', 'grossAmount' => 10900.0, 'netAmount' => 10000.0, 'vatAmount' => 900.0,
				'invoiceLines' => [['itemName' => 'Brood', 'netAmount' => 10000.0, 'vatCategory' => 'S', 'vatRate' => 9]],
				'lifecycleState' => 'issued',
			],
			'ARInvoice',
		];
		$rows = $this->posted([$sale], true);
		$expected = $this->stampsOf($rows['GLLine']);
		foreach ($rows['GLLine'] as $index => $line) {
			unset($line['vatTariffCode'], $line['vatReturnBox'], $line['vatAmountKind']);
			$rows['GLLine'][$index] = $line;
		}

		$rows['Account'] = [
			['id' => 'acc-8000', 'accountNumber' => '8000', 'accountType' => 'revenue', 'vatApplicable' => true, 'vatRate' => 9, 'administrationId' => 'adm-1'],
			['id' => 'acc-1100', 'accountNumber' => '1100', 'accountType' => 'assets', 'vatApplicable' => false, 'administrationId' => 'adm-1'],
		];

		$result = $this->backfill($rows)->run();

		self::assertSame($expected, $this->stamps());
		self::assertSame([['1100', null, null, null], ['8000', 'low', '1b', 'base'], ['2110', 'low', '1b', 'vat']], $expected);
		self::assertSame(['stamped' => 2, 'unresolved' => 0], $result);
		foreach ($this->store->setSchema('GLLine')->findAll() as $row) {
			$line = $row->getObject();
			unset($line['id']);
			$line['transactionId'] = '0f8fad5b-d9cb-469f-a165-70867728950e';
			self::assertSame([], RegisterSchema::errors(slug: 'GLLine', object: $line));
		}
	}//end testAVatLineTakesTheOneTariffOfItsTransaction()

	/**
	 * A line it cannot resolve is logged by transaction, line and account,
	 * and a draft transaction is not touched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.3
	 */
	public function testALineThatCannotBeResolvedIsLoggedAndADraftIsLeftAlone(): void {
		$rows = [
			'GLTransaction' => [
				['id' => 'tx-1', 'state' => 'posted', 'administrationId' => 'adm-1', 'postingDate' => '2026-08-01', 'sourceReference' => 'JournalEntry:je-1'],
				['id' => 'tx-2', 'state' => 'draft', 'administrationId' => 'adm-1', 'postingDate' => '2026-08-01'],
			],
			'GLLine' => [
				['id' => 'l1', 'transactionId' => 'tx-1', 'accountNumber' => '8100', 'side' => 'credit', 'amount' => 100.0, 'administrationId' => 'adm-1'],
				['id' => 'l2', 'transactionId' => 'tx-1', 'accountNumber' => '1100', 'side' => 'debit', 'amount' => 100.0, 'administrationId' => 'adm-1'],
				['id' => 'l3', 'transactionId' => 'tx-2', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 50.0, 'administrationId' => 'adm-1'],
			],
			'Account' => [
				['id' => 'acc-8100', 'accountNumber' => '8100', 'accountType' => 'revenue', 'vatApplicable' => true, 'administrationId' => 'adm-1'],
				['id' => 'acc-8000', 'accountNumber' => '8000', 'accountType' => 'revenue', 'vatApplicable' => true, 'vatRate' => 21, 'administrationId' => 'adm-1'],
			],
		];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			self::stringContains('cannot resolve'),
			self::callback(static fn (array $c): bool => $c['transactionId'] === 'tx-1' && $c['lineId'] === 'l1' && $c['accountNumber'] === '8100')
		);

		$result = $this->backfill($rows, $logger)->run();

		self::assertSame(['stamped' => 0, 'unresolved' => 1], $result);
		self::assertSame([], $this->saved);
	}//end testALineThatCannotBeResolvedIsLoggedAndADraftIsLeftAlone()

	/**
	 * The repair step runs the backfill and reports the counts; a failure is
	 * reported and does not block the upgrade.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.3
	 */
	public function testTheRepairStepReportsWhatItStamped(): void {
		$backfill = $this->backfill($this->posted([$this->documents()[0]], false));
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('4 ledger line(s)'));

		(new StampVatReturnBoxes($backfill, $this->createMock(LoggerInterface::class)))->run($output);
	}//end testTheRepairStepReportsWhatItStamped()
}//end class
