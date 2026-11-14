<?php

/**
 * VatReturnChecks over the books of a Q3 2026 return: each check passes on
 * clean books and fails, naming what is wrong, on books with that defect.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Standards\Checks\VatReturnChecks;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The six checks, pass and fail.
 *
 * @covers \OCA\Shillinq\Standards\Checks\VatReturnChecks
 * @uses   \OCA\Shillinq\Service\Vat\VatLineStamper
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 * @uses   \OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub
 */
final class VatReturnChecksTest extends TestCase {

	/**
	 * The Q3 2026 return of Bakkerij De Korenbloem.
	 *
	 * @var array<string, mixed>
	 */
	private const RETURN = [
		'id' => 'ret-q3',
		'returnNumber' => 'NL-2026-Q3',
		'administrationId' => 'adm-kb',
		'startDate' => '2026-07-01',
		'endDate' => '2026-09-30',
		'statusCode' => 'draft',
		'totalVATCollected' => 900.0,
		'totalVATPaid' => 840.0,
	];

	/**
	 * Clean books: a sale of EUR 10,000 at 9% and a purchase of EUR 4,000 at 21%.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function books(): array {
		return [
			'VatTariff' => [
				['id' => 'vt-1', 'code' => 'V9', 'ratePercentage' => 9],
				['id' => 'vt-2', 'code' => 'I21', 'ratePercentage' => 21],
				['id' => 'vt-3', 'code' => 'RC0', 'ratePercentage' => 0],
			],
			'GLTransaction' => [
				['id' => 't-1', 'administrationId' => 'adm-kb', 'journalNumber' => 'VK-1', 'postingDate' => '2026-08-01', 'state' => 'posted'],
				['id' => 't-2', 'administrationId' => 'adm-kb', 'journalNumber' => 'IK-1', 'postingDate' => '2026-08-02', 'state' => 'posted'],
				['id' => 't-0', 'administrationId' => 'adm-kb', 'journalNumber' => 'VK-0', 'postingDate' => '2026-06-30', 'state' => 'draft'],
			],
			'GLLine' => [
				self::line('t-1', '8001', 'credit', 10000.0, 'V9', '1b', 'base'),
				self::line('t-1', '2110', 'credit', 900.0, 'V9', '1b', 'vat'),
				self::line('t-1', '1300', 'debit', 10900.0),
				self::line('t-2', '4000', 'debit', 4000.0, 'I21', '5b', 'base'),
				self::line('t-2', '1230', 'debit', 840.0, 'I21', '5b', 'vat'),
				self::line('t-2', '1600', 'credit', 4840.0),
			],
			'BtwAangifte' => [self::RETURN],
		];
	}//end books()

	/**
	 * A GL line.
	 *
	 * @param string      $transactionId The transaction.
	 * @param string      $account       The account number.
	 * @param string      $side          debit or credit.
	 * @param float       $amount        The amount.
	 * @param string|null $code          The VAT tariff.
	 * @param string|null $box           The return box.
	 * @param string|null $kind          base or vat.
	 *
	 * @return array<string, mixed>
	 */
	private static function line(string $transactionId, string $account, string $side, float $amount, ?string $code = null, ?string $box = null, ?string $kind = null): array {
		static $n = 0;
		$n++;

		return array_filter(
			[
				'id' => 'l-'.$n,
				'administrationId' => 'adm-kb',
				'transactionId' => $transactionId,
				'accountNumber' => $account,
				'side' => $side,
				'amount' => $amount,
				'vatTariffCode' => $code,
				'vatReturnBox' => $box,
				'vatAmountKind' => $kind,
			],
			static fn ($value): bool => $value !== null
		);
	}//end line()

	/**
	 * The checks over these books, by id.
	 *
	 * @param array<string, list<array<string, mixed>>> $books  The register.
	 * @param array<string, mixed>                      $return The return.
	 *
	 * @return array<string, array{id: string, severity: string, passed: bool, message: string}>
	 */
	private function results(array $books, array $return = self::RETURN): array {
		$results = $this->checks(new InMemoryObjectServiceStub($books))->run($return);

		return array_column($results, null, 'id');
	}//end run()

	/**
	 * The checks over an object service.
	 *
	 * @param object $objects The object service.
	 *
	 * @return VatReturnChecks
	 */
	private function checks(object $objects): VatReturnChecks {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);

		return new VatReturnChecks(
			objectService: $objects,
			registerLocation: $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']),
			appConfig: $config,
			l10n: $l10n
		);
	}//end checks()

	/**
	 * The ids of the failing blocking checks.
	 *
	 * @param list<array<string, mixed>> $results The run.
	 *
	 * @return list<string>
	 */
	private static function failingBlocking(array $results): array {
		return array_values(array_map(
			static fn (array $result): string => $result['id'],
			array_filter($results, static fn (array $result): bool => $result['severity'] === VatReturnChecks::BLOCKING && $result['passed'] === false)
		));
	}//end failingBlocking()

	/**
	 * Clean books pass every check, in the declared order and severities.
	 *
	 * @return void
	 */
	public function testCleanBooksPassEveryCheck(): void {
		$results = $this->checks(new InMemoryObjectServiceStub(self::books()))->run(self::RETURN);

		$this->assertSame(array_keys(VatReturnChecks::CHECKS), array_column($results, 'id'));
		$this->assertSame(array_values(VatReturnChecks::CHECKS), array_column($results, 'severity'));
		$this->assertSame(array_fill(0, 6, true), array_column($results, 'passed'));
		$this->assertSame([], self::failingBlocking($results));
	}//end testCleanBooksPassEveryCheck()

	/**
	 * A posted input-VAT line without a tariff fails the box check, naming its transaction, and blocks.
	 *
	 * @return void
	 */
	public function testAVatLineWithoutABoxFailsAndBlocks(): void {
		$books = self::books();
		$books['GLTransaction'][] = ['id' => 't-7', 'administrationId' => 'adm-kb', 'journalNumber' => 'MEM-7', 'postingDate' => '2026-09-15', 'state' => 'posted'];
		$books['GLLine'][] = self::line('t-7', '1230', 'debit', 0.0);
		$books['GLLine'][] = self::line('t-7', '1230', 'credit', 0.0);

		$results = $this->results($books);

		$this->assertFalse($results['vat-line-has-box']['passed']);
		$this->assertSame('These transactions have a VAT line without a box: MEM-7', $results['vat-line-has-box']['message']);
		$this->assertTrue($results['vat-accounts-match-return']['passed']);
		$this->assertSame(['vat-line-has-box'], self::failingBlocking(array_values($results)));
	}//end testAVatLineWithoutABoxFailsAndBlocks()

	/**
	 * VAT more than a cent off base times rate fails the rate check, which only warns.
	 *
	 * @return void
	 */
	public function testVatOffTheRateIsAWarning(): void {
		$books = self::books();
		$books['GLLine'][1]['amount'] = 899.98;
		$books['GLLine'][2]['amount'] = 10899.98;
		$return = array_merge(self::RETURN, ['totalVATCollected' => 899.98]);

		$results = $this->results($books, $return);

		$this->assertFalse($results['vat-equals-base-times-rate']['passed']);
		$this->assertSame('warning', $results['vat-equals-base-times-rate']['severity']);
		$this->assertSame('The VAT posted differs from the base times the rate on: VK-1 (V9)', $results['vat-equals-base-times-rate']['message']);
		$this->assertSame([], self::failingBlocking(array_values($results)));

		$books['GLLine'][1]['amount'] = 899.99;
		$this->assertTrue($this->results($books, $return)['vat-equals-base-times-rate']['passed']);
	}//end testVatOffTheRateIsAWarning()

	/**
	 * A return whose totals differ from the VAT accounts' movement fails, naming the account and both amounts.
	 *
	 * @return void
	 */
	public function testTotalsThatDifferFromTheVatAccountsFail(): void {
		$results = $this->results(self::books(), array_merge(self::RETURN, ['totalVATPaid' => 800.0]));

		$this->assertFalse($results['vat-accounts-match-return']['passed']);
		$this->assertSame(
			'The VAT accounts do not match the return: account 1230 moved EUR 840.00, the return says EUR 800.00',
			$results['vat-accounts-match-return']['message']
		);
	}//end testTotalsThatDifferFromTheVatAccountsFail()

	/**
	 * A draft dated in the period fails the unposted check; one outside the period does not count.
	 *
	 * @return void
	 */
	public function testADraftInThePeriodIsNamed(): void {
		$books = self::books();
		$books['GLTransaction'][] = ['id' => 't-8', 'administrationId' => 'adm-kb', 'journalNumber' => 'VK-8', 'postingDate' => '2026-09-30', 'state' => 'draft'];
		$books['GLTransaction'][] = ['id' => 't-9', 'administrationId' => 'adm-kb', 'journalNumber' => 'VK-9', 'postingDate' => '2026-09-01', 'state' => 'cancelled'];

		$results = $this->results($books);

		$this->assertFalse($results['no-unposted-documents']['passed']);
		$this->assertSame('These documents in the period are not posted: VK-8', $results['no-unposted-documents']['message']);
	}//end testADraftInThePeriodIsNamed()

	/**
	 * The previous period's return must be filed; a missing one fails only when an earlier return exists.
	 *
	 * @return void
	 */
	public function testThePreviousReturnMustBeFiled(): void {
		$books = self::books();
		$books['BtwAangifte'][] = ['id' => 'ret-q2', 'returnNumber' => 'NL-2026-Q2', 'administrationId' => 'adm-kb', 'endDate' => '2026-06-30', 'statusCode' => 'draft'];
		$this->assertSame('The return NL-2026-Q2 of the previous period is not filed yet.', $this->results($books)['previous-return-filed']['message']);

		$books['BtwAangifte'][1]['statusCode'] = 'verified';
		$this->assertTrue($this->results($books)['previous-return-filed']['passed']);

		$books['BtwAangifte'][1]['endDate'] = '2026-03-31';
		$results = $this->results($books);
		$this->assertFalse($results['previous-return-filed']['passed']);
		$this->assertSame('There is no return for the period ending 2026-06-30.', $results['previous-return-filed']['message']);
	}//end testThePreviousReturnMustBeFiled()

	/**
	 * Reverse-charged VAT owed in 4b must also be deducted in 5b.
	 *
	 * @return void
	 */
	public function testReverseChargedVatMustBeOwedAndDeducted(): void {
		$books = self::books();
		$books['GLTransaction'][] = ['id' => 't-4', 'administrationId' => 'adm-kb', 'journalNumber' => 'IK-4', 'postingDate' => '2026-09-01', 'state' => 'posted'];
		$books['GLLine'][] = self::line('t-4', '4000', 'debit', 1000.0, 'RC0', '4b', 'base');
		$books['GLLine'][] = self::line('t-4', '2111', 'credit', 210.0, 'RC0', '4b', 'vat');

		$results = $this->results($books);
		$this->assertFalse($results['reverse-charge-owed-and-deductible']['passed']);
		$this->assertSame('Reverse-charged VAT is not both owed and deducted on: IK-4', $results['reverse-charge-owed-and-deductible']['message']);

		$books['GLLine'][] = self::line('t-4', '1231', 'debit', 210.0, 'RC0', '5b', 'vat');
		$this->assertTrue($this->results($books)['reverse-charge-owed-and-deductible']['passed']);
	}//end testReverseChargedVatMustBeOwedAndDeducted()

	/**
	 * Books that cannot be read fail every check, so nothing is submitted on checks that did not run.
	 *
	 * @return void
	 */
	public function testUnreadableBooksFailEveryCheck(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willThrowException(new RuntimeException('database gone'));

		$results = $this->checks($objects)->run(self::RETURN);

		$this->assertSame(array_fill(0, 6, false), array_column($results, 'passed'));
		$this->assertSame('The books of this period could not be read: database gone', $results[0]['message']);
	}//end testUnreadableBooksFailEveryCheck()
}//end class
