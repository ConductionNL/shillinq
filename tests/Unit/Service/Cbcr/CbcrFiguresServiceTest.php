<?php

/**
 * Unit tests for CbcrFiguresService: the CbC figures of an entity from its
 * books, and their sum per jurisdiction.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Cbcr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/specs/bookkeeping-cbcr-pillar2/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Cbcr;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Shillinq\Kernel\Contracts\HrmqCostRateAdapterInterface;
use OCA\Shillinq\Service\Cbcr\CbcrFiguresService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The figures service over an in-memory register holding the Voorbeeld group.
 */
#[CoversClass(CbcrFiguresService::class)]
#[UsesClass(ObjectIdentifier::class)]
final class CbcrFiguresServiceTest extends TestCase {
	/**
	 * The in-memory register.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * A posted ledger line.
	 *
	 * @param string      $id          Line id.
	 * @param string      $transaction Transaction id.
	 * @param string      $account     Account number.
	 * @param string      $side        debit or credit.
	 * @param float       $amount      Amount.
	 * @param string      $class       pnl or balance.
	 * @param string|null $invoice     The AR invoice the line posts, if any.
	 * @param bool        $counts      Whether the line counts in the result.
	 *
	 * @return array<string,mixed>
	 */
	private static function line(
		string $id,
		string $transaction,
		string $account,
		string $side,
		float $amount,
		string $class,
		?string $invoice = null,
		bool $counts = true
	): array {
		$line = [
			'id' => $id, 'transactionId' => $transaction, 'accountNumber' => $account, 'side' => $side, 'amount' => $amount,
			'signedAmount' => $amount, 'accountClass' => $class, 'countsInResult' => $counts,
			'administrationId' => 'adm-productie',
		];
		if ($side === 'debit') {
			$line['signedAmount'] = -$amount;
		}

		if ($invoice !== null) {
			$line['subLedgerType'] = 'ar';
			$line['subLedgerRef']  = $invoice;
		}

		return $line;
	}//end line()

	/**
	 * The Voorbeeld group: holding, Productie (books here) and GmbH (entered).
	 *
	 * @param string $productieCurrency The functional currency of Productie's administration.
	 * @param array  $fxRates           The fxRates on the 2026 assessment.
	 *
	 * @return CbcrFiguresService
	 */
	private function service(string $productieCurrency = 'EUR', array $fxRates = []): CbcrFiguresService {
		$m = 1000000.0;
		$this->store = new InMemoryObjectServiceStub(
			[
				'GroupEntityRegistry' => [
					[
						'id' => 'ge-holding', 'entityName' => 'Voorbeeld Groep Holding B.V.', 'taxResidency' => 'NL',
						'administrationId' => 'adm-holding', 'bizActivities' => ['CBC511'],
					],
					[
						'id' => 'ge-productie', 'entityName' => 'Voorbeeld Productie B.V.', 'taxResidency' => 'NL', 'ultimateParentEntity' => 'ge-holding',
						'administrationId' => 'adm-productie', 'bizActivities' => ['CBC504'],
					],
					[
						'id' => 'ge-gmbh', 'entityName' => 'Voorbeeld GmbH', 'jurisdiction' => 'DE', 'ultimateParentEntity' => 'ge-holding',
						'counterpartyRefs' => ['cust-gmbh'], 'bizActivities' => ['CBC505'],
					],
				],
				'Administration' => [
					['id' => 'adm-holding', 'functionalCurrency' => 'EUR'],
					['id' => 'adm-productie', 'functionalCurrency' => $productieCurrency],
				],
				'GroupScopeAssessment' => [
					[
						'id' => 'gsa-2026', 'ultimateParentEntity' => 'ge-holding', 'fiscalYear' => 2026,
						'fiscalYearStart' => '2026-01-01', 'fiscalYearEnd' => '2026-12-31', 'fxRates' => $fxRates,
					],
				],
				'Account' => [
					['id' => 'acc-8000', 'accountNumber' => '8000', 'accountType' => 'revenue', 'cbcrCategory' => 'revenue'],
					['id' => 'acc-8100', 'accountNumber' => '8100', 'accountType' => 'revenue', 'cbcrCategory' => 'revenue-excluded'],
					['id' => 'acc-4000', 'accountNumber' => '4000', 'accountType' => 'expense', 'cbcrCategory' => 'none'],
					['id' => 'acc-4500', 'accountNumber' => '4500', 'accountType' => 'expense'],
					['id' => 'acc-9500', 'accountNumber' => '9500', 'accountType' => 'expense', 'cbcrCategory' => 'income-tax-current'],
					['id' => 'acc-1700', 'accountNumber' => '1700', 'accountType' => 'liability', 'cbcrCategory' => 'income-tax-payable'],
					['id' => 'acc-0500', 'accountNumber' => '0500', 'accountType' => 'equity', 'cbcrCategory' => 'stated-capital'],
					['id' => 'acc-0600', 'accountNumber' => '0600', 'accountType' => 'equity', 'cbcrCategory' => 'accumulated-earnings'],
					['id' => 'acc-0100', 'accountNumber' => '0100', 'accountType' => 'asset', 'cbcrCategory' => 'tangible-asset'],
					['id' => 'acc-1000', 'accountNumber' => '1000', 'accountType' => 'asset', 'cbcrCategory' => 'none'],
					['id' => 'acc-1300', 'accountNumber' => '1300', 'accountType' => 'asset', 'cbcrCategory' => 'none'],
				],
				'ARInvoice' => [
					['id' => 'inv-third', 'customerId' => 'cust-third'],
					['id' => 'inv-gmbh', 'customerId' => 'cust-gmbh'],
				],
				'GLTransaction' => [
					['id' => 't-open', 'postingDate' => '2025-01-01', 'state' => 'posted', 'administrationId' => 'adm-productie'],
					['id' => 't-third', 'postingDate' => '2026-03-31', 'state' => 'posted', 'administrationId' => 'adm-productie'],
					['id' => 't-gmbh', 'postingDate' => '2026-06-30', 'state' => 'posted', 'administrationId' => 'adm-productie'],
					['id' => 't-cost', 'postingDate' => '2026-09-30', 'state' => 'posted', 'administrationId' => 'adm-productie'],
					['id' => 't-tax', 'postingDate' => '2026-12-31', 'state' => 'posted', 'administrationId' => 'adm-productie'],
					['id' => 't-paid', 'postingDate' => '2026-07-01', 'state' => 'posted', 'administrationId' => 'adm-productie'],
					['id' => 't-dividend', 'postingDate' => '2026-05-01', 'state' => 'posted', 'administrationId' => 'adm-productie'],
					['id' => 't-reversed', 'postingDate' => '2026-04-01', 'state' => 'reversed', 'administrationId' => 'adm-productie'],
					['id' => 't-next', 'postingDate' => '2027-01-05', 'state' => 'posted', 'administrationId' => 'adm-productie'],
				],
				'GLLine' => [
					self::line('l1', 't-open', '0500', 'credit', (10 * $m), 'balance'),
					self::line('l2', 't-open', '0600', 'credit', (30 * $m), 'balance'),
					self::line('l3', 't-open', '0100', 'debit', (50 * $m), 'balance'),
					self::line('l4', 't-open', '1000', 'credit', (10 * $m), 'balance'),
					self::line('l5', 't-third', '8000', 'credit', (300 * $m), 'pnl', 'inv-third'),
					self::line('l6', 't-third', '1300', 'debit', (300 * $m), 'balance', 'inv-third'),
					self::line('l7', 't-gmbh', '8000', 'credit', (40 * $m), 'pnl', 'inv-gmbh'),
					self::line('l8', 't-gmbh', '1300', 'debit', (40 * $m), 'balance', 'inv-gmbh'),
					self::line('l9', 't-cost', '4000', 'debit', (250 * $m), 'pnl'),
					self::line('l10', 't-cost', '4500', 'debit', (1 * $m), 'pnl'),
					self::line('l11', 't-cost', '1000', 'credit', (251 * $m), 'balance'),
					self::line('l12', 't-tax', '9500', 'debit', (20 * $m), 'pnl'),
					self::line('l13', 't-tax', '1700', 'credit', (20 * $m), 'balance'),
					self::line('l14', 't-paid', '1700', 'debit', (15 * $m), 'balance'),
					self::line('l15', 't-paid', '1000', 'credit', (15 * $m), 'balance'),
					self::line('l16', 't-dividend', '8100', 'credit', (5 * $m), 'pnl'),
					self::line('l17', 't-dividend', '1000', 'debit', (5 * $m), 'balance'),
					self::line('l18', 't-reversed', '8000', 'credit', (999 * $m), 'pnl', null, false),
					self::line('l19', 't-next', '8000', 'credit', (7 * $m), 'pnl'),
				],
				'FiscalYear' => [['id' => 'fy-2026', 'administrationId' => 'adm-productie', 'endDate' => '2026-12-31', 'state' => 'open']],
				'Employee' => [
					['id' => 'e1', 'administrationId' => 'adm-productie', 'startDate' => '2019-02-01'],
					['id' => 'e2', 'administrationId' => 'adm-productie', 'startDate' => '2024-05-01', 'endDate' => '2027-03-31'],
					['id' => 'e3', 'administrationId' => 'adm-productie', 'startDate' => '2026-01-01'],
					['id' => 'e4', 'administrationId' => 'adm-productie', 'startDate' => '2020-01-01', 'endDate' => '2026-06-30'],
					['id' => 'e5', 'administrationId' => 'adm-productie', 'startDate' => '2027-01-01'],
					['id' => 'e6', 'administrationId' => 'adm-elders', 'startDate' => '2020-01-01'],
				],
			]
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$humaniq = $this->createMock(HrmqCostRateAdapterInterface::class);
		$humaniq->method('registerSlug')->willReturn('humaniq');

		return new CbcrFiguresService($this->store, $appConfig, $humaniq, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * "Related revenue is split out from the books": EUR 300M to third parties
	 * and EUR 40M to the customer standing for the GmbH; dividends are left
	 * out, a reversed line and a line after year end do not count, and the
	 * unmapped expense account is named.
	 *
	 * @return void
	 */
	public function testTheVoorbeeldProductieFiguresComeFromTheBooks(): void {
		$figures = $this->service()->readFromBooks('ge-productie', 2026, new DateTimeImmutable('2027-02-01T08:00:00+00:00'));

		$m = 1000000.0;
		$this->assertSame(
			[
				'entity' => 'ge-productie', 'fiscalYear' => 2026, 'source' => 'books', 'currency' => 'EUR',
				'unrelatedPartyRevenue' => (300 * $m), 'relatedPartyRevenue' => (40 * $m),
				'profitBeforeTax' => (94 * $m), 'incomeTaxPaidCash' => (15 * $m), 'incomeTaxAccrued' => (20 * $m),
				'statedCapital' => (10 * $m), 'accumulatedEarnings' => (104 * $m), 'tangibleAssetsOtherThanCash' => (50 * $m),
				'deferredTaxExpense' => 0.0, 'eligiblePayroll' => 0.0,
				'numberOfEmployees' => 3, 'employeeMethod' => 'humaniq', 'yearClosed' => false,
				'unmappedAccounts' => ['4500'],
				'topCounterparties' => [
					['ref' => 'cust-third', 'amount' => (300 * $m), 'related' => false],
					['ref' => 'cust-gmbh', 'amount' => (40 * $m), 'related' => true],
				],
				'computedAt' => '2027-02-01T08:00:00+00:00',
			],
			array_diff_key($figures, ['id' => true])
		);
		$this->assertSame('CbcrEntityFigures', $this->store->saved[0]['schema']);
	}//end testTheVoorbeeldProductieFiguresComeFromTheBooks()

	/**
	 * Reading twice updates the one record in place, and an intercompany
	 * transaction counts as related whoever the customer is.
	 *
	 * @return void
	 */
	public function testARereadUpdatesInPlaceAndAnIntercompanyEntryIsRelated(): void {
		$service = $this->service();
		$first   = $service->readFromBooks('ge-productie', 2026);
		$this->store->setSchema('GLTransaction');
		$this->store->saveObject([
			'id' => 't-third', 'postingDate' => '2026-03-31', 'state' => 'posted',
			'administrationId' => 'adm-productie', 'journalType' => 'intercompany',
		]);

		$second = $service->readFromBooks('ge-productie', 2026);

		$this->assertSame($first['id'], $second['id']);
		$this->assertSame([0.0, 340000000.0], [$second['unrelatedPartyRevenue'], $second['relatedPartyRevenue']]);
		$this->store->setSchema('CbcrEntityFigures');
		$this->assertCount(1, $this->store->findAll());
	}//end testARereadUpdatesInPlaceAndAnIntercompanyEntryIsRelated()

	/**
	 * A closed year does not add the result to accumulated earnings.
	 *
	 * @return void
	 */
	public function testAClosedYearKeepsTheBookedEarnings(): void {
		$service = $this->service();
		$this->store->setSchema('FiscalYear');
		$this->store->saveObject(['id' => 'fy-2026', 'administrationId' => 'adm-productie', 'endDate' => '2026-12-31', 'state' => 'closed']);

		$figures = $service->readFromBooks('ge-productie', 2026);

		$this->assertTrue($figures['yearClosed']);
		$this->assertSame(30000000.0, $figures['accumulatedEarnings']);
	}//end testAClosedYearKeepsTheBookedEarnings()

	/**
	 * A foreign administration converts results at the average and balances
	 * at the closing rate, and refuses without the rates.
	 *
	 * @return void
	 */
	public function testAForeignCurrencyConvertsAtTheAverageAndClosingRate(): void {
		$figures = $this->service('USD', ['USD' => ['average' => 0.9, 'closing' => 0.8]])->readFromBooks('ge-productie', 2026);

		$this->assertSame(270000000.0, $figures['unrelatedPartyRevenue']);
		$this->assertSame(8000000.0, $figures['statedCapital']);
		$this->assertSame('EUR', $figures['currency']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Enter the average and closing USD to EUR rate for the year on the scope assessment first.');
		$this->service('USD')->readFromBooks('ge-productie', 2026);
	}//end testAForeignCurrencyConvertsAtTheAverageAndClosingRate()

	/**
	 * An entity without books here is refused with a pointer to entering figures.
	 *
	 * @return void
	 */
	public function testAnEntityWithoutBooksIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Voorbeeld GmbH keeps no books in Shillinq; enter or import its figures instead.');
		$this->service()->readFromBooks('ge-gmbh', 2026);
	}//end testAnEntityWithoutBooksIsRefused()

	/**
	 * "A reconciled summary is left alone": the DE summary stays as it was,
	 * the NL summary sums Productie and names the holding without figures.
	 *
	 * @return void
	 */
	public function testSummariseSkipsAReconciledSummaryAndSumsTheRest(): void {
		$service = $this->service();
		$service->readFromBooks('ge-productie', 2026);
		$this->store->setSchema('CbcrJurisdictionSummary');
		$this->store->saveObject([
			'id' => 'sum-de', 'ultimateParentEntity' => 'ge-holding', 'period' => 'FY2026',
			'jurisdiction' => 'DE', 'state' => 'reconciled', 'unrelatedPartyRevenue' => 1.0,
		]);
		$this->store->setSchema('CbcrEntityFigures');
		$this->store->saveObject([
			'entity' => 'ge-gmbh', 'fiscalYear' => 2026, 'source' => 'entered',
			'unrelatedPartyRevenue' => 60000000.0, 'employeeMethod' => 'entered',
		]);

		$outcome = $service->summarise('ge-holding', 2026);

		$this->assertSame(['DE'], $outcome['skipped']);
		$this->assertSame(['ge-holding'], $outcome['withoutFigures']);
		$this->assertCount(1, $outcome['summaries']);
		$nl = $outcome['summaries'][0];
		$this->assertSame(['ge-holding', 'FY2026', 'NL', 'draft'], [$nl['ultimateParentEntity'], $nl['period'], $nl['jurisdiction'], $nl['state']]);
		$this->assertSame([300000000.0, 40000000.0, 3], [$nl['unrelatedPartyRevenue'], $nl['relatedPartyRevenue'], $nl['numberOfEmployees']]);
		$this->assertSame(['CBC504', 'CBC511'], $nl['mainBusinessActivities']);
		$this->assertSame('Headcount at year end from humaniq: Voorbeeld Productie B.V.', $nl['employeeMethodologyNote']);
		$this->store->setSchema('CbcrJurisdictionSummary');
		$this->assertSame(1.0, $this->store->findAll(['filters' => ['jurisdiction' => 'DE']])[0]['unrelatedPartyRevenue']);
	}//end testSummariseSkipsAReconciledSummaryAndSumsTheRest()

	/**
	 * "CbCR-aggregatie voor jurisdictie DE, FY2026": three German entities sum
	 * to EUR 173M unrelated and EUR 42M related, with their activities joined;
	 * an entity with no residency goes under the stateless code.
	 *
	 * @return void
	 */
	public function testThreeGermanEntitiesSumIntoOneSummary(): void {
		$service = $this->service();
		$this->store->setSchema('GroupEntityRegistry');
		$this->store->saveObject([
			'id' => 'ge-de2', 'entityName' => 'Voorbeeld Vertrieb GmbH', 'taxResidency' => 'DE',
			'ultimateParentEntity' => 'ge-holding', 'bizActivities' => ['CBC505', 'CBC506'],
		]);
		$this->store->saveObject([
			'id' => 'ge-de3', 'entityName' => 'Voorbeeld Service GmbH', 'taxResidency' => 'de',
			'ultimateParentEntity' => 'ge-holding', 'bizActivities' => ['CBC501'],
		]);
		$this->store->saveObject(['id' => 'ge-nowhere', 'entityName' => 'Voorbeeld Trust', 'ultimateParentEntity' => 'ge-holding']);
		$this->store->setSchema('CbcrEntityFigures');
		foreach ([['ge-gmbh', 120, 30, 10], ['ge-de2', 45, 12, 4], ['ge-de3', 8, 0, 1]] as [$entity, $unrelated, $related, $tax]) {
			$this->store->saveObject(
				[
					'entity' => $entity, 'fiscalYear' => 2026, 'source' => 'entered', 'unrelatedPartyRevenue' => ($unrelated * 1000000.0),
					'relatedPartyRevenue' => ($related * 1000000.0), 'incomeTaxAccrued' => ($tax * 1000000.0), 'numberOfEmployees' => 10,
				]
			);
		}

		$summaries = $service->summarise('ge-holding', 2026)['summaries'];

		$this->assertSame(['DE', 'NL', 'X5'], array_column($summaries, 'jurisdiction'));
		$de = $summaries[0];
		$this->assertSame([
			173000000.0, 42000000.0, 15000000.0,
			30], [$de['unrelatedPartyRevenue'], $de['relatedPartyRevenue'], $de['incomeTaxAccrued'],
			$de['numberOfEmployees'],
		]);
		$this->assertSame(['CBC501', 'CBC505', 'CBC506'], $de['mainBusinessActivities']);
	}//end testThreeGermanEntitiesSumIntoOneSummary()
}//end class
