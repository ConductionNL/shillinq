<?php

/**
 * The booking rules checked on every posting a person makes.
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
 * @spec openspec/changes/ledger-booking-rules/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\PostingRefusedException;
use OCA\Shillinq\Lifecycle\PostingRestrictionGuard;
use OCA\Shillinq\Tests\Unit\Lifecycle\Action\InMemoryObjectStore;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Control accounts are closed to postings by hand, and an administration's
 * posting restrictions block the combinations they name (REQ-LBR-002,
 * REQ-LBR-005). The chart is the shipped RGS MKB one: 1100 Debiteuren,
 * 1230 BTW-vordering, 2120 Loonheffing schuld.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PostingRestrictionGuardTest extends TestCase {

	/**
	 * The store behind the ObjectService mock.
	 *
	 * @var InMemoryObjectStore
	 */
	private InMemoryObjectStore $store;

	/**
	 * Seed the chart and the Gemeente Voorbeeld restriction.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectStore();
		$this->store->rows['Account'] = [
			['id' => 'a1', 'administrationId' => 'adm-1', 'accountNumber' => '1100', 'name' => 'Debiteuren', 'controlAccountFor' => 'receivables'],
			['id' => 'a2', 'administrationId' => 'adm-1', 'accountNumber' => '1230', 'name' => 'BTW-vordering', 'controlAccountFor' => 'vat'],
			['id' => 'a3', 'administrationId' => 'adm-1', 'accountNumber' => '2120', 'name' => 'Loonheffing schuld', 'controlAccountFor' => 'payroll'],
			['id' => 'a4', 'administrationId' => 'adm-1', 'accountNumber' => '4000', 'name' => 'Personeelskosten'],
			['id' => 'a5', 'administrationId' => 'adm-1', 'accountNumber' => '1000', 'name' => 'Liquide middelen'],
			['id' => 'a6', 'administrationId' => 'adm-2', 'accountNumber' => '1100', 'name' => 'Debiteuren'],
		];
		$this->store->rows['PostingRestriction'] = [
			[
				'id' => 'pr-1',
				'administrationId' => 'adm-1',
				'accountPattern' => '4600',
				'costCenterCode' => 'KP-100',
				'projectCode' => 'P-2026-014',
				'reason' => 'Sportakkoord-subsidies lopen via Sociaal Domein, niet via bestuursondersteuning',
				'validFrom' => '2026-01-01',
				'lifecycleState' => 'active',
			],
		];
	}//end setUp()

	/**
	 * The guard over the store, translating by sprintf.
	 *
	 * @return PostingRestrictionGuard
	 */
	private function guard(): PostingRestrictionGuard {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new PostingRestrictionGuard($appConfig, $this->store->mock($this), $l10n);
	}//end guard()

	/**
	 * The refusal message of a posting, '' when it is allowed.
	 *
	 * @param list<array<string,mixed>> $lines The lines.
	 * @param string $sourceApp The sub-ledger, '' for a person.
	 * @param string $administrationId The administration.
	 * @param string $date The posting date.
	 *
	 * @return string
	 */
	private function refusal(array $lines, string $sourceApp = '', string $administrationId = 'adm-1', string $date = '2026-09-20'): string {
		try {
			$this->guard()->assertAllowed($lines, $administrationId, $date, $sourceApp);
		} catch (PostingRefusedException $e) {
			return $e->getMessage();
		}

		return '';
	}//end refusal()

	/**
	 * A memorial entry on receivables is refused, naming the account and its role.
	 *
	 * @return void
	 */
	public function testAMemorialEntryOnReceivablesIsRefused(): void {
		$message = $this->refusal(
			[
				['accountNumber' => '1100', 'side' => 'debit', 'amount' => 500.0],
				['accountNumber' => '8000', 'side' => 'credit', 'amount' => 500.0],
			]
		);

		self::assertStringContainsString('1100 Debiteuren', $message);
		self::assertStringContainsString('receivables control account', $message);
	}//end testAMemorialEntryOnReceivablesIsRefused()

	/**
	 * A bank booking may post its VAT line, not a receivables line.
	 *
	 * @return void
	 */
	public function testABankBookingMayPostVatButNotReceivables(): void {
		$vat = [
			['accountNumber' => '4000', 'side' => 'debit', 'amount' => 100.0],
			['accountNumber' => '1230', 'side' => 'debit', 'amount' => 21.0],
			['accountNumber' => '1000', 'side' => 'credit', 'amount' => 121.0],
		];
		self::assertSame('', $this->refusal($vat, 'bank'));
		self::assertStringContainsString('VAT control account', $this->refusal($vat));

		$receivables = [
			['accountNumber' => '1000', 'side' => 'debit', 'amount' => 121.0],
			['accountNumber' => '1100', 'side' => 'credit', 'amount' => 121.0],
		];
		self::assertStringContainsString('1100 Debiteuren', $this->refusal($receivables, 'bank'));
	}//end testABankBookingMayPostVatButNotReceivables()

	/**
	 * The humaniq payroll journal may post on the payroll control account.
	 *
	 * @return void
	 */
	public function testThePayrollJournalMayPostOnPayrollControlAccounts(): void {
		$lines = [
			['accountNumber' => '4000', 'side' => 'debit', 'amount' => 5000.0],
			['accountNumber' => '2120', 'side' => 'credit', 'amount' => 5000.0],
		];

		self::assertSame('', $this->refusal($lines, 'humaniq'));
		self::assertStringContainsString('payroll control account', $this->refusal($lines));
	}//end testThePayrollJournalMayPostOnPayrollControlAccounts()

	/**
	 * The control role is per administration.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationsRoleDoesNotApply(): void {
		$lines = [
			['accountNumber' => '1100', 'side' => 'debit', 'amount' => 10.0],
			['accountNumber' => '8000', 'side' => 'credit', 'amount' => 10.0],
		];

		self::assertSame('', $this->refusal($lines, '', 'adm-2'));
	}//end testAnotherAdministrationsRoleDoesNotApply()

	/**
	 * A sports subsidy on KP-100 is refused with the reason; on KP-300 it posts.
	 *
	 * @return void
	 */
	public function testABlockedCombinationIsRefusedWithItsReason(): void {
		$line = ['accountNumber' => '4600', 'side' => 'debit', 'amount' => 2500.0, 'costCenterCode' => 'KP-100', 'projectCode' => 'P-2026-014'];
		$bank = ['accountNumber' => '1000', 'side' => 'credit', 'amount' => 2500.0];

		self::assertStringContainsString(
			'Sportakkoord-subsidies lopen via Sociaal Domein, niet via bestuursondersteuning',
			$this->refusal([$line, $bank])
		);

		$line['costCenterCode'] = 'KP-300';
		self::assertSame('', $this->refusal([$line, $bank]));
	}//end testABlockedCombinationIsRefusedWithItsReason()

	/**
	 * An empty project on the restriction matches any project; a sub-account
	 * matches the account pattern.
	 *
	 * @return void
	 */
	public function testAnEmptyProjectMatchesAnyAndThePatternIsAPrefix(): void {
		$this->store->rows['PostingRestriction'][0]['projectCode'] = '';
		$line = ['accountNumber' => '46001', 'side' => 'debit', 'amount' => 10.0, 'costCenterCode' => 'KP-100', 'projectCode' => 'P-OTHER'];

		self::assertNotSame('', $this->refusal([$line]));
	}//end testAnEmptyProjectMatchesAnyAndThePatternIsAPrefix()

	/**
	 * A restriction does not block before it is valid, nor once retired.
	 *
	 * @return void
	 */
	public function testARestrictionOutsideItsDatesOrRetiredDoesNotBlock(): void {
		$line = ['accountNumber' => '4600', 'side' => 'debit', 'amount' => 10.0, 'costCenterCode' => 'KP-100', 'projectCode' => 'P-2026-014'];

		self::assertSame('', $this->refusal([$line], '', 'adm-1', '2025-12-31'));

		$this->store->rows['PostingRestriction'][0]['lifecycleState'] = 'retired';
		self::assertSame('', $this->refusal([$line]));
	}//end testARestrictionOutsideItsDatesOrRetiredDoesNotBlock()
}//end class
