<?php

/**
 * Unit tests for the VAT return checks (REQ-TVRB-001).
 *
 * Each check is shown passing and failing on a quarter posted through the real
 * posting mapper and prepared by the real VATReturnService, so the checks read
 * ledger lines and returns as they are written.
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
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Vat;

use OCA\Shillinq\Service\Vat\VatReturnCheckService;
use OCA\Shillinq\Service\VATReturnService;
use OCA\Shillinq\Standards\Checks\VatReturnChecks;
use OCA\Shillinq\Standards\RuleEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Every check passes on a clean quarter and fails, naming what to fix, when
 * its condition is broken.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VatReturnCheckServiceTest extends TestCase {
	use VatQuarterBooks;


	/**
	 * The store the return service and the checks read.
	 *
	 * @var InMemoryObjectServiceStub|null
	 */
	private ?InMemoryObjectServiceStub $store = null;

	/**
	 * Reset the rule engine's memo, so the new provider is discovered.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		RuleEngine::reset();
	}//end setUp()

	/**
	 * Prepare the Q3 2026 return over the rows and run its checks.
	 *
	 * @param array<string,list<array<string,mixed>>> $rows Rows per schema.
	 *
	 * @return array<string,array<string,mixed>> Check results by rule id.
	 */
	private function checksOf(array $rows): array {
		$saved = [];
		$this->store = new InMemoryObjectServiceStub($rows, $saved, true);
		$prepared = (new VATReturnService(appConfig: $this->defaultsConfig(), logger: new NullLogger(), objectService: $this->store))
			->createReturn(administrationId: 'adm-kb', period: 'quarter', periodYear: 2026, periodNumber: 3, regime: 'standard');

		$byId = [];
		$service = new VatReturnCheckService(objectService: $this->store, appConfig: $this->defaultsConfig());
		foreach ($service->run(returnId: (string)$prepared['id']) as $check) {
			$byId[$check['id']] = $check;
		}

		return $byId;
	}//end checksOf()

	/**
	 * The clean Korenbloem quarter passes all six checks, and the result says
	 * which checks block filing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
	 */
	public function testACleanQuarterPassesEveryCheck(): void {
		$checks = $this->checksOf($this->posted([$this->sale(), $this->purchase()]));

		self::assertSame(VatReturnChecks::RULE_IDS, array_keys($checks));
		foreach ($checks as $id => $check) {
			self::assertTrue($check['passed'], $id . ': ' . implode(', ', $check['offenders']));
			self::assertSame([], $check['offenders']);
		}

		self::assertTrue($checks[VatReturnChecks::LINE_BOX]['blocking']);
		self::assertFalse($checks[VatReturnChecks::RATE_PER_LINE]['blocking']);
		self::assertTrue($checks[VatReturnChecks::ACCOUNT_MOVEMENT]['blocking']);
		self::assertFalse($checks[VatReturnChecks::NO_DRAFTS]['blocking']);
		self::assertTrue($checks[VatReturnChecks::PREVIOUS_FILED]['blocking']);
		self::assertTrue($checks[VatReturnChecks::REVERSE_CHARGE_PAIR]['blocking']);
	}//end testACleanQuarterPassesEveryCheck()

	/**
	 * The spec's scenario: a posted purchase line with input VAT but no tariff
	 * fails "every VAT line has a box" and names the transaction.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
	 */
	public function testAnInputVatLineWithoutABoxFailsAndNamesTheTransaction(): void {
		$rows = $this->withEntry(
			$this->posted([$this->sale(), $this->purchase()]),
			'MEM-77',
			[
				['accountNumber' => '7200', 'side' => 'debit', 'amount' => 100.0],
				['accountNumber' => '1230', 'side' => 'debit', 'amount' => 21.0],
				['accountNumber' => '1600', 'side' => 'credit', 'amount' => 121.0],
			]
		);

		$check = $this->checksOf($rows)[VatReturnChecks::LINE_BOX];

		self::assertFalse($check['passed']);
		self::assertSame(['MEM-77'], $check['offenders']);
	}//end testAnInputVatLineWithoutABoxFailsAndNamesTheTransaction()

	/**
	 * VAT more than a cent off base times rate fails the rate check; the stray
	 * cent of a rounded invoice does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
	 */
	public function testVatOffTheRateFailsTheRateCheck(): void {
		$cent = $this->sale('ar-kb-2', [
			'invoiceNumber' => '2026-0302', 'grossAmount' => 40.34, 'netAmount' => 33.33, 'vatAmount' => 7.01,
			'invoiceLines' => [['itemName' => 'Taart', 'netAmount' => 33.33, 'vatCategory' => 'S', 'vatRate' => 21]],
		]);
		self::assertTrue($this->checksOf($this->posted([$this->sale(), $cent]))[VatReturnChecks::RATE_PER_LINE]['passed']);

		$off = $this->sale('ar-kb-3', [
			'invoiceNumber' => '2026-0303', 'grossAmount' => 125.0, 'netAmount' => 100.0, 'vatAmount' => 25.0,
			'invoiceLines' => [['itemName' => 'Taart', 'netAmount' => 100.0, 'vatCategory' => 'S', 'vatRate' => 21]],
		]);
		$check = $this->checksOf($this->posted([$this->sale(), $off]))[VatReturnChecks::RATE_PER_LINE];

		self::assertFalse($check['passed']);
		self::assertCount(1, $check['offenders']);
		self::assertStringContainsString('(high)', $check['offenders'][0]);
	}//end testVatOffTheRateFailsTheRateCheck()

	/**
	 * VAT booked on the output VAT account outside any invoice makes the
	 * account disagree with the return.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
	 */
	public function testAVatAccountThatDisagreesWithTheReturnFails(): void {
		$rows = $this->withEntry(
			$this->posted([$this->sale(), $this->purchase()]),
			'MEM-78',
			[
				['accountNumber' => '1300', 'side' => 'debit', 'amount' => 50.0],
				['accountNumber' => '2110', 'side' => 'credit', 'amount' => 50.0],
			]
		);

		$check = $this->checksOf($rows)[VatReturnChecks::ACCOUNT_MOVEMENT];

		self::assertFalse($check['passed']);
		self::assertSame(['2110: EUR 950.00 on the account, EUR 900.00 owed in the return'], $check['offenders']);
	}//end testAVatAccountThatDisagreesWithTheReturnFails()

	/**
	 * A draft sales invoice and a draft journal entry dated in the period fail
	 * the drafts check; a draft dated after the period does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
	 */
	public function testDraftsDatedInThePeriodFailTheDraftCheck(): void {
		$rows = $this->posted([$this->sale(), $this->purchase()]);
		$rows['ARInvoice'] = [
			['id' => 'ar-d1', 'invoiceNumber' => 'CONCEPT-1', 'invoiceDate' => '2026-09-20', 'administrationId' => 'adm-kb', 'lifecycleState' => 'draft'],
			['id' => 'ar-d2', 'invoiceNumber' => 'CONCEPT-2', 'invoiceDate' => '2026-10-02', 'administrationId' => 'adm-kb', 'lifecycleState' => 'draft'],
		];
		$rows = $this->withEntry($rows, 'MEM-79', [['accountNumber' => '8000', 'side' => 'credit', 'amount' => 10.0]], 'draft');

		$check = $this->checksOf($rows)[VatReturnChecks::NO_DRAFTS];

		self::assertFalse($check['passed']);
		self::assertSame(['Journal entry MEM-79', 'Sales invoice CONCEPT-1'], $check['offenders']);
	}//end testDraftsDatedInThePeriodFailTheDraftCheck()

	/**
	 * The previous quarter's return must be submitted: a draft Q2 fails, a
	 * missing Q2 after an earlier return fails, a submitted Q2 passes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
	 */
	public function testThePreviousReturnMustBeSubmitted(): void {
		$books = $this->posted([$this->sale(), $this->purchase()]);
		$return = static fn (string $id, int $quarter, string $status): array => [
			'id' => $id, 'returnNumber' => 'NL-2026-Q' . $quarter, 'period' => 'quarter', 'periodYear' => 2026, 'periodNumber' => $quarter,
			'startDate' => sprintf('2026-%02d-01', (($quarter - 1) * 3 + 1)), 'administrationId' => 'adm-kb', 'statusCode' => $status,
		];

		$draft = $books + ['BtwAangifte' => [$return('q2', 2, 'draft')]];
		$check = $this->checksOf($draft)[VatReturnChecks::PREVIOUS_FILED];
		self::assertFalse($check['passed']);
		self::assertSame(['NL-2026-Q2 is still a draft'], $check['offenders']);

		$missing = $books + ['BtwAangifte' => [$return('q1', 1, 'filed')]];
		self::assertSame(['No return for 2026 Q2'], $this->checksOf($missing)[VatReturnChecks::PREVIOUS_FILED]['offenders']);

		$submitted = $books + ['BtwAangifte' => [$return('q2', 2, 'submitted')]];
		self::assertTrue($this->checksOf($submitted)[VatReturnChecks::PREVIOUS_FILED]['passed']);
	}//end testThePreviousReturnMustBeSubmitted()

	/**
	 * A reverse-charged purchase whose VAT is not booked fails the pair check;
	 * the same purchase with its VAT owed in 2a and deducted in 5b passes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
	 */
	public function testReverseChargeVatMustBeOwedAndDeducted(): void {
		$subcontract = [
			[
				'id' => 'ap-rc-1', 'invoiceNumber' => 'INK-RC1', 'invoiceDate' => '2026-08-10', 'administrationId' => 'adm-kb',
				'totalAmount' => 2000.0, 'taxAmount' => 0.0,
				'lines' => [['accountNumber' => '7100', 'amount' => 2000.0, 'description' => 'Onderaanneming', 'taxCode' => 'reverse-charge']],
				'state' => 'posted',
			],
			'APInvoice',
		];
		$rows = $this->posted([$this->sale(), $subcontract]);
		$transaction = '';
		foreach ($rows['GLLine'] as $line) {
			if (($line['vatTariffCode'] ?? '') === 'reverse-charge') {
				$transaction = (string)$line['transactionId'];
			}
		}

		self::assertNotSame('', $transaction);
		$check = $this->checksOf($rows)[VatReturnChecks::REVERSE_CHARGE_PAIR];
		self::assertFalse($check['passed']);
		self::assertCount(1, $check['offenders']);

		$rows['GLLine'][] = [
			'id' => 'rc-owed', 'transactionId' => $transaction, 'lineNumber' => 90, 'accountNumber' => '2110', 'side' => 'credit', 'amount' => 420.0,
			'currency' => 'EUR', 'administrationId' => 'adm-kb', 'vatTariffCode' => 'reverse-charge', 'vatReturnBox' => '2a', 'vatAmountKind' => 'vat',
		];
		$rows['GLLine'][] = [
			'id' => 'rc-deducted', 'transactionId' => $transaction, 'lineNumber' => 91, 'accountNumber' => '1230', 'side' => 'debit', 'amount' => 420.0,
			'currency' => 'EUR', 'administrationId' => 'adm-kb', 'vatTariffCode' => 'reverse-charge', 'vatReturnBox' => '5b', 'vatAmountKind' => 'vat',
		];

		$checks = $this->checksOf($rows);
		self::assertTrue($checks[VatReturnChecks::REVERSE_CHARGE_PAIR]['passed']);
		self::assertTrue($checks[VatReturnChecks::ACCOUNT_MOVEMENT]['passed'], implode(', ', $checks[VatReturnChecks::ACCOUNT_MOVEMENT]['offenders']));
	}//end testReverseChargeVatMustBeOwedAndDeducted()

	/**
	 * Without the facts of a period (the generic rule audit), a return check
	 * is not counted as a violation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
	 */
	public function testTheGenericAuditWithoutFactsReportsNothing(): void {
		self::assertSame([], RuleEngine::evaluate('BtwAangifte', ['id' => 'x'], ['jurisdiction' => 'NL']));
		$foreign = ['jurisdiction' => 'DE', 'vatReturnFacts' => ['drafts' => ['Sales invoice 1']]];
		self::assertSame([], RuleEngine::evaluate('BtwAangifte', ['id' => 'x'], $foreign));
	}//end testTheGenericAuditWithoutFactsReportsNothing()
}//end class
