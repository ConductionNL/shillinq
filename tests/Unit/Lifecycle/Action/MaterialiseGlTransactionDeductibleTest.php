<?php

/**
 * Unit tests for the deductible-share split in MaterialiseGlTransactionAction.
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
 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A cost line below 100 percent deductible posts in two parts and still balances (REQ-TVRD-004).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class MaterialiseGlTransactionDeductibleTest extends TestCase {

	/**
	 * The store behind the ObjectService mock.
	 *
	 * @var InMemoryObjectStore
	 */
	private InMemoryObjectStore $store;

	/**
	 * The action over a store with the seeded tariffs and Bakkerij De
	 * Korenbloem's accounts: 4520 Autokosten at 70 percent with its private
	 * share to 1810, and 4300 fully deductible.
	 *
	 * @param array<string,mixed> $car Overrides for account 4520.
	 *
	 * @return MaterialiseGlTransactionAction
	 */
	private function action(array $car = []): MaterialiseGlTransactionAction {
		$this->store = new InMemoryObjectStore();
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/seeds/btw-tariffs-2026.json'), true);
		$this->store->rows['VatTariff'] = $seed['tariffs'];
		$this->store->rows['Account'] = [
			array_merge(
				[
					'accountNumber' => '4520',
					'name' => 'Autokosten',
					'administrationId' => 'adm-1',
					'deductiblePercentage' => 70,
					'privateShareAccountNumber' => '1810',
				],
				$car
			),
			['accountNumber' => '4300', 'name' => 'Kantoorkosten', 'administrationId' => 'adm-1'],
			[
				'accountNumber' => '4520',
				'name' => 'Autokosten',
				'administrationId' => 'adm-2',
				'deductiblePercentage' => 50,
				'privateShareAccountNumber' => '1820',
			],
		];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		return new MaterialiseGlTransactionAction($this->store->mock($this), $appConfig, $this->createMock(LoggerInterface::class));
	}//end action()

	/**
	 * The seed fuel invoice: EUR 100.00 plus EUR 21.00 VAT on 4520.
	 *
	 * @param array<string,mixed> $line Overrides for the cost line.
	 *
	 * @return array<string,mixed>
	 */
	private function fuelInvoice(array $line = []): array {
		return [
			'id' => 'ap-fuel', 'invoiceNumber' => 'TANK-0042', 'invoiceDate' => '2026-03-02', 'administrationId' => 'adm-1',
			'vendorId' => 'payee-tank', 'totalAmount' => 121.0, 'taxAmount' => 21.0,
			'lines' => [array_merge(['accountNumber' => '4520', 'amount' => 100.0, 'description' => 'Brandstof', 'taxCode' => 'BTW21'], $line)],
			'state' => 'issued',
		];
	}//end fuelInvoice()

	/**
	 * The posted lines as [account, side, amount, taxTreatment].
	 *
	 * @return list<array{0: string, 1: string, 2: float, 3: string|null}>
	 */
	private function posted(): array {
		return array_map(
			static fn (array $l): array => [$l['accountNumber'], $l['side'], $l['amount'], ($l['taxTreatment'] ?? null)],
			$this->store->savedOf('GLLine')
		);
	}//end posted()

	/**
	 * The spec's scenario: 4520 debit 70.00, input VAT 14.70, 1810 36.30
	 * non-deductible, creditor credit 121.00.
	 *
	 * @return void
	 */
	public function testAFuelBillIsSplitSeventyToThirty(): void {
		$this->action()->execute(
			new LifecycleActionContext(
				objectData: $this->fuelInvoice(),
				previousData: [],
				parameters: ['sourceSchema' => 'APTransaction'],
				actionName: MaterialiseGlTransactionAction::class,
			),
		);

		self::assertSame(
			[
				['4520', 'debit', 70.0, null],
				['1810', 'debit', 36.3, 'nonDeductible'],
				['1230', 'debit', 14.7, null],
				['2000', 'credit', 121.0, null],
			],
			$this->posted()
		);
	}//end testAFuelBillIsSplitSeventyToThirty()

	/**
	 * The input VAT line keeps its tariff and box, at the deductible amount only.
	 *
	 * @return void
	 */
	public function testOnlyTheDeductibleVatCountsInBox5b(): void {
		$this->action()->execute(
			new LifecycleActionContext(
				objectData: $this->fuelInvoice(),
				previousData: [],
				parameters: ['sourceSchema' => 'APInvoice'],
				actionName: MaterialiseGlTransactionAction::class,
			),
		);

		$vat = array_values(array_filter($this->store->savedOf('GLLine'), static fn (array $l): bool => ($l['vatAmountKind'] ?? '') === 'vat'));
		self::assertCount(1, $vat);
		self::assertSame([14.7, 'high', '5b'], [$vat[0]['amount'], $vat[0]['vatTariffCode'], $vat[0]['vatReturnBox']]);

		$private = $this->store->savedOf('GLLine')[1];
		self::assertArrayNotHasKey('vatAmountKind', $private, 'The private share is not a base or VAT amount of the return.');
	}//end testOnlyTheDeductibleVatCountsInBox5b()

	/**
	 * A line override of 100 wins over the account's 70: the line posts in
	 * full with EUR 21.00 input VAT.
	 *
	 * @return void
	 */
	public function testALineOverrideWinsOverTheAccount(): void {
		$this->action()->execute(
			new LifecycleActionContext(
				objectData: $this->fuelInvoice(['deductiblePercentage' => 100]),
				previousData: [],
				parameters: ['sourceSchema' => 'APTransaction'],
				actionName: MaterialiseGlTransactionAction::class,
			),
		);

		self::assertSame(
			[['4520', 'debit', 100.0, null], ['1230', 'debit', 21.0, null], ['2000', 'credit', 121.0, null]],
			$this->posted()
		);
	}//end testALineOverrideWinsOverTheAccount()

	/**
	 * A line override below the account's percentage splits at the line's.
	 *
	 * @return void
	 */
	public function testALineOverrideCanLowerTheShare(): void {
		$this->action()->execute(
			new LifecycleActionContext(
				objectData: $this->fuelInvoice(['deductiblePercentage' => 0]),
				previousData: [],
				parameters: ['sourceSchema' => 'APTransaction'],
				actionName: MaterialiseGlTransactionAction::class,
			),
		);

		self::assertSame([['1810', 'debit', 121.0, 'nonDeductible'], ['2000', 'credit', 121.0, null]], $this->posted());
	}//end testALineOverrideCanLowerTheShare()

	/**
	 * The account is read in the source's own administration.
	 *
	 * @return void
	 */
	public function testTheAccountOfTheSourcesAdministrationDecides(): void {
		$invoice = $this->fuelInvoice();
		$invoice['administrationId'] = 'adm-2';
		$this->action()->execute(
			new LifecycleActionContext(
				objectData: $invoice,
				previousData: [],
				parameters: ['sourceSchema' => 'APTransaction'],
				actionName: MaterialiseGlTransactionAction::class,
			),
		);

		self::assertSame(
			[
				['4520', 'debit', 50.0, null],
				['1820', 'debit', 60.5, 'nonDeductible'],
				['1230', 'debit', 10.5, null],
				['2000', 'credit', 121.0, null],
			],
			$this->posted()
		);
	}//end testTheAccountOfTheSourcesAdministrationDecides()

	/**
	 * A mixed invoice splits only the partly deductible line; the other
	 * line keeps its full VAT.
	 *
	 * @return void
	 */
	public function testOnlyThePartlyDeductibleLineIsSplit(): void {
		$invoice = $this->fuelInvoice();
		$invoice['lines'][] = ['accountNumber' => '4300', 'amount' => 50.0, 'description' => 'Papier', 'taxCode' => 'BTW21'];
		$invoice['totalAmount'] = 181.5;
		$invoice['taxAmount'] = 31.5;
		$this->action()->execute(
			new LifecycleActionContext(
				objectData: $invoice,
				previousData: [],
				parameters: ['sourceSchema' => 'APTransaction'],
				actionName: MaterialiseGlTransactionAction::class,
			),
		);

		self::assertSame(
			[
				['4520', 'debit', 70.0, null],
				['1810', 'debit', 36.3, 'nonDeductible'],
				['4300', 'debit', 50.0, null],
				['1230', 'debit', 25.2, null],
				['2000', 'credit', 181.5, null],
			],
			$this->posted()
		);
	}//end testOnlyThePartlyDeductibleLineIsSplit()

	/**
	 * An account below 100 without a private-share account refuses the
	 * posting by name and writes nothing.
	 *
	 * @return void
	 */
	public function testAShareWithNowhereToGoIsRefused(): void {
		$action = $this->action(['privateShareAccountNumber' => '']);

		try {
			$action->execute(
				new LifecycleActionContext(
					objectData: $this->fuelInvoice(),
					previousData: [],
					parameters: ['sourceSchema' => 'APTransaction'],
					actionName: MaterialiseGlTransactionAction::class,
				),
			);
			self::fail('The posting was not refused.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('Account 4520 is 70% deductible but names no private-share account', $e->getMessage());
		}

		self::assertSame([], $this->store->savedOf('GLTransaction'));
		self::assertSame([], $this->store->savedOf('GLLine'));
	}//end testAShareWithNowhereToGoIsRefused()

	/**
	 * A journal line with a tax code moves the private VAT out of input VAT;
	 * the entry still balances.
	 *
	 * @return void
	 */
	public function testAJournalLineWithATaxCodeMovesThePrivateVat(): void {
		$entry = [
			'id' => 'je-fuel', 'journalNumber' => 'MEM-2026-0007', 'entryDate' => '2026-03-02', 'administrationId' => 'adm-1',
			'description' => 'Tankbon contant', 'state' => 'posted',
			'lines' => [
				[
					'accountNumber' => '4520',
					'side' => 'debit',
					'amount' => 100.0,
					'description' => 'Brandstof',
					'taxCode' => 'high',
					'costCenterCode' => 'KP-01',
				],
				['accountNumber' => '1230', 'side' => 'debit', 'amount' => 21.0, 'description' => 'Voorbelasting'],
				['accountNumber' => '1000', 'side' => 'credit', 'amount' => 121.0, 'description' => 'Kas'],
			],
		];
		$this->action()->execute(
			new LifecycleActionContext(
				objectData: $entry,
				previousData: [],
				parameters: ['sourceSchema' => 'JournalEntry', 'keepBalanced' => true],
				actionName: MaterialiseGlTransactionAction::class,
			),
		);

		self::assertSame(
			[
				['4520', 'debit', 70.0, null],
				['1810', 'debit', 36.3, 'nonDeductible'],
				['1230', 'credit', 6.3, null],
				['1230', 'debit', 21.0, null],
				['1000', 'credit', 121.0, null],
			],
			$this->posted()
		);
		$lines = $this->store->savedOf('GLLine');
		self::assertSame('KP-01', $lines[1]['costCenterCode'], 'The private share keeps the line\'s cost centre.');
		self::assertSame(['high', '5b', 'vat'], [$lines[2]['vatTariffCode'], $lines[2]['vatReturnBox'], $lines[2]['vatAmountKind']]);
	}//end testAJournalLineWithATaxCodeMovesThePrivateVat()

	/**
	 * A journal line without a tax code splits its own amount only.
	 *
	 * @return void
	 */
	public function testAJournalLineWithoutATaxCodeSplitsItsAmount(): void {
		$entry = [
			'id' => 'je-km', 'journalNumber' => 'MEM-2026-0008', 'entryDate' => '2026-03-31', 'administrationId' => 'adm-1',
			'description' => 'Wegenbelasting', 'state' => 'posted',
			'lines' => [
				['accountNumber' => '4520', 'side' => 'debit', 'amount' => 33.33, 'description' => 'MRB'],
				['accountNumber' => '1100', 'side' => 'credit', 'amount' => 33.33, 'description' => 'Bank'],
			],
		];
		$this->action()->execute(
			new LifecycleActionContext(
				objectData: $entry,
				previousData: [],
				parameters: ['sourceSchema' => 'JournalEntry'],
				actionName: MaterialiseGlTransactionAction::class,
			),
		);

		self::assertSame(
			[['4520', 'debit', 23.33, null], ['1810', 'debit', 10.0, 'nonDeductible'], ['1100', 'credit', 33.33, null]],
			$this->posted()
		);
	}//end testAJournalLineWithoutATaxCodeSplitsItsAmount()
}//end class
