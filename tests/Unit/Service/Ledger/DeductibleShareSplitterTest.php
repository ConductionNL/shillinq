<?php

/**
 * Unit tests for DeductibleShareSplitter.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Ledger
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

namespace OCA\Shillinq\Tests\Unit\Service\Ledger;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Ledger\DeductibleShareSplitter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The deductible percentage of a line and its two shares (REQ-TVRD-004).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(DeductibleShareSplitter::class)]
final class DeductibleShareSplitterTest extends TestCase {

	/**
	 * The filters the account read was asked for.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * A splitter over the given accounts of administration adm-1.
	 *
	 * @param list<array<string,mixed>> $accounts The Account rows.
	 * @param bool                      $fail     Whether the account read throws.
	 *
	 * @return DeductibleShareSplitter
	 */
	private function splitter(array $accounts, bool $fail = false): DeductibleShareSplitter {
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnSelf();
		$service->method('findAll')->willReturnCallback(
			function (array $config) use ($accounts, $fail): array {
				$this->queries[] = $config;
				if ($fail === true) {
					throw new RuntimeException('register down');
				}

				return $accounts;
			}
		);

		return new DeductibleShareSplitter(objectService: $service, register: 'shillinq', administrationId: 'adm-1');
	}//end splitter()

	/**
	 * The car account of the design's seed data.
	 *
	 * @return array<string,mixed>
	 */
	private static function car(): array {
		return ['accountNumber' => '4520', 'deductiblePercentage' => 70, 'privateShareAccountNumber' => '1810'];
	}//end car()

	/**
	 * A cost line of EUR 100.00 on 4520.
	 *
	 * @param array<string,mixed> $extra Extra fields.
	 *
	 * @return array<string,mixed>
	 */
	private static function costLine(array $extra = []): array {
		return array_merge(['accountNumber' => '4520', 'side' => 'debit', 'cents' => 10000, 'description' => 'Brandstof'], $extra);
	}//end costLine()

	/**
	 * The line's own percentage wins, then the account's, then 100; the
	 * accounts are read once, for the splitter's administration.
	 *
	 * @return void
	 */
	public function testThePercentageComesFromTheLineThenTheAccountThenHundred(): void {
		$splitter = $this->splitter([self::car()]);

		self::assertSame(100.0, $splitter->percentage(['accountNumber' => '4520', 'deductiblePercentage' => 100]));
		self::assertSame(70.0, $splitter->percentage(['accountNumber' => '4520']));
		self::assertSame(70.0, $splitter->percentage(['accountNumber' => '4520', 'deductiblePercentage' => '']));
		self::assertSame(100.0, $splitter->percentage(['accountNumber' => '4300']));
		self::assertCount(1, $this->queries);
		self::assertSame(['administrationId' => 'adm-1'], $this->queries[0]['filters']);
	}//end testThePercentageComesFromTheLineThenTheAccountThenHundred()

	/**
	 * A percentage outside 0 to 100, or not a number, is refused by account.
	 *
	 * @return void
	 */
	public function testAPercentageOutOfRangeIsRefused(): void {
		$splitter = $this->splitter([]);

		foreach ([120, -1, 'veel'] as $value) {
			try {
				$splitter->percentage(['accountNumber' => '4520', 'deductiblePercentage' => $value]);
				self::fail('Accepted ' . json_encode($value));
			} catch (RuntimeException $e) {
				self::assertStringContainsString('account 4520 must be a number from 0 to 100', $e->getMessage());
			}
		}
	}//end testAPercentageOutOfRangeIsRefused()

	/**
	 * When the accounts cannot be read, a line counts as fully deductible.
	 *
	 * @return void
	 */
	public function testAnUnreadableChartCountsAsFullyDeductible(): void {
		self::assertSame(100.0, $this->splitter([self::car()], fail: true)->percentage(['accountNumber' => '4520']));
	}//end testAnUnreadableChartCountsAsFullyDeductible()

	/**
	 * At 100 the line comes back unchanged with its whole base and VAT.
	 *
	 * @return void
	 */
	public function testAFullyDeductibleLineIsNotSplit(): void {
		$split = $this->splitter([self::car()])->split(costLine: self::costLine(), percentage: 100.0, vatCents: 2100.0);

		self::assertSame(['lines' => [self::costLine()], 'base' => 10000, 'vat' => 2100.0, 'privateVat' => 0], $split);
	}//end testAFullyDeductibleLineIsNotSplit()

	/**
	 * At 70: 7000 deductible with 1470 VAT, 3000 plus 630 VAT to 1810,
	 * marked non-deductible.
	 *
	 * @return void
	 */
	public function testSeventyPercentSplitsBaseAndVat(): void {
		$split = $this->splitter([self::car()])->split(costLine: self::costLine(), percentage: 70.0, vatCents: 2100.0);

		self::assertSame(7000, $split['base']);
		self::assertSame(1470.0, $split['vat']);
		self::assertSame(630, $split['privateVat']);
		self::assertSame(self::costLine(['cents' => 7000]), $split['lines'][0]);
		self::assertSame(
			[
				'accountNumber' => '1810',
				'side' => 'debit',
				'cents' => 3630,
				'description' => 'Brandstof (non-deductible 30%)',
				'taxTreatment' => 'nonDeductible',
			],
			$split['lines'][1]
		);
	}//end testSeventyPercentSplitsBaseAndVat()

	/**
	 * Odd cents: the shares always add up to the line and its VAT.
	 *
	 * @return void
	 */
	public function testTheSharesAddUpToTheLine(): void {
		$split = $this->splitter([self::car()])->split(costLine: self::costLine(['cents' => 3333]), percentage: 33.5, vatCents: 699.93);

		self::assertSame(1117, $split['base']);
		self::assertSame(465, $split['privateVat']);
		self::assertSame(3333 + 465 - 1117, $split['lines'][1]['cents']);
		self::assertEqualsWithDelta(699.93 - 465, (float)$split['vat'], 0.0001);
		self::assertSame('Brandstof (non-deductible 66.5%)', $split['lines'][1]['description']);
	}//end testTheSharesAddUpToTheLine()

	/**
	 * Without VAT on the line only the base is split.
	 *
	 * @return void
	 */
	public function testALineWithoutVatSplitsItsBase(): void {
		$split = $this->splitter([self::car()])->split(costLine: self::costLine(), percentage: 70.0, vatCents: null);

		self::assertNull($split['vat']);
		self::assertSame(0, $split['privateVat']);
		self::assertSame(3000, $split['lines'][1]['cents']);
	}//end testALineWithoutVatSplitsItsBase()

	/**
	 * A base counted in a return box (reverse charge) keeps its stamps on the
	 * private share, so the box still holds the whole base; an ordinary
	 * purchase base carries none.
	 *
	 * @return void
	 */
	public function testABoxedBaseKeepsItsStampsOnThePrivateShare(): void {
		$stamps = ['vatTariffCode' => 'reverse-charge', 'vatReturnBox' => '2a', 'vatAmountKind' => 'base'];
		$boxed = $this->splitter([self::car()])->split(costLine: self::costLine($stamps), percentage: 70.0, vatCents: null);
		self::assertSame($stamps, array_intersect_key($boxed['lines'][1], $stamps));

		$plain = $this->splitter([self::car()])->split(
			costLine: self::costLine(['vatTariffCode' => 'high', 'vatReturnBox' => null, 'vatAmountKind' => 'base']),
			percentage: 70.0,
			vatCents: 2100.0
		);
		self::assertArrayNotHasKey('vatTariffCode', $plain['lines'][1]);
	}//end testABoxedBaseKeepsItsStampsOnThePrivateShare()

	/**
	 * Below 100 with no private-share account the split is refused by name.
	 *
	 * @return void
	 */
	public function testAShareWithoutAPrivateAccountIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Account 4520 is 70% deductible but names no private-share account');

		$this->splitter([['accountNumber' => '4520', 'deductiblePercentage' => 70]])->split(costLine: self::costLine(), percentage: 70.0, vatCents: 2100.0);
	}//end testAShareWithoutAPrivateAccountIsRefused()
}//end class
