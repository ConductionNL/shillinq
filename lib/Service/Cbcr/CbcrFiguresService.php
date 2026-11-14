<?php

/**
 * Shillinq CbC Figures Service
 *
 * Reads the country-by-country figures of one group entity for one fiscal
 * year from the posted ledger lines of its administration, stores them per
 * entity, and sums them per jurisdiction (design D2, REQ-CBC-002).
 *
 * Figures from the books, per CbC category on the account:
 *  - revenue: credit-positive result on `revenue` accounts; a line is related
 *    when its counterparty stands for another group entity (the entity's
 *    `counterpartyRefs`) or its transaction is an intercompany entry;
 *    `revenue-excluded` (distributions from group entities) is left out;
 *  - profit before tax: the year's result without the current and deferred
 *    income tax accounts;
 *  - tax accrued: debit movement on `income-tax-current` accounts;
 *  - tax paid: debit movement on `income-tax-payable` accounts in a
 *    transaction with no line on another income tax account;
 *  - stated capital, accumulated earnings, tangible assets: the balance at
 *    year end; while the year is not closed accumulated earnings add the
 *    year's result;
 *  - deferred tax expense, eligible payroll: the year's debit movement;
 *  - employees: headcount at year end from humaniq, else the entered number.
 * Result figures convert at the year's average rate and balance figures at
 * the closing rate of the group's scope assessment (`fxRates`, one
 * `{average, closing}` per currency, in presentation currency per unit).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Cbcr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Cbcr;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\HrmqCostRateAdapterInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Per-entity CbC figures from the books, and their sum per jurisdiction.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.3
 */
class CbcrFiguresService {
	/**
	 * The CbC country code for an entity with no tax residency (OECD stateless).
	 *
	 * @var string
	 */
	public const STATELESS = 'X5';

	/**
	 * The figures summed per jurisdiction.
	 *
	 * @var array<int,string>
	 */
	public const FIGURES = [
		'unrelatedPartyRevenue',
		'relatedPartyRevenue',
		'profitBeforeTax',
		'incomeTaxPaidCash',
		'incomeTaxAccrued',
		'statedCapital',
		'accumulatedEarnings',
		'numberOfEmployees',
		'tangibleAssetsOtherThanCash',
	];

	/**
	 * Result figures, converted at the average rate; the others at the closing rate.
	 *
	 * @var array<int,string>
	 */
	private const RESULT_FIGURES = [
		'unrelatedPartyRevenue',
		'relatedPartyRevenue',
		'profitBeforeTax',
		'incomeTaxPaidCash',
		'incomeTaxAccrued',
		'deferredTaxExpense',
		'eligiblePayroll',
	];

	/**
	 * The income tax categories a payment must not share a transaction with.
	 *
	 * @var array<int,string>
	 */
	private const TAX_EXPENSE = ['income-tax-current', 'income-tax-deferred'];

	/**
	 * How many rows one read looks at.
	 *
	 * @var int
	 */
	private const ROW_LIMIT = 100000;

	/**
	 * Records read by id in this service's life: administrations and sub-ledger documents.
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface       $objectService OpenRegister's object service.
	 * @param IAppConfig                   $appConfig     Names the register.
	 * @param HrmqCostRateAdapterInterface $humaniq       Knows which register humaniq answers on.
	 * @param LoggerInterface              $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly HrmqCostRateAdapterInterface $humaniq,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read one entity's figures for a fiscal year from its administration's books and store them.
	 *
	 * @param string                 $entityId   The GroupEntityRegistry id.
	 * @param int                    $fiscalYear The fiscal year, named by the calendar year it ends in.
	 * @param DateTimeImmutable|null $now        The moment of reading; now when null.
	 *
	 * @return array<string,mixed> The stored CbcrEntityFigures record.
	 *
	 * @throws InvalidArgumentException When the entity is unknown or keeps no books here.
	 * @throws RuntimeException         When an exchange rate the conversion needs is missing.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.3
	 */
	public function readFromBooks(string $entityId, int $fiscalYear, ?DateTimeImmutable $now = null): array {
		$entity = $this->one(schema: 'GroupEntityRegistry', id: $entityId);
		if ($entity === null) {
			throw new InvalidArgumentException('Unknown group entity: ' . $entityId);
		}

		$administrationId = trim((string)($entity['administrationId'] ?? ''));
		if ($administrationId === '') {
			throw new InvalidArgumentException(
				sprintf('%s keeps no books in Shillinq; enter or import its figures instead.', (string)($entity['entityName'] ?? $entityId))
			);
		}

		$upeId      = $this->upeOf(entity: $entity, entityId: $entityId);
		$assessment = $this->first(schema: 'GroupScopeAssessment', filters: ['ultimateParentEntity' => $upeId, 'fiscalYear' => $fiscalYear]);
		[$start, $end] = self::yearBounds(assessment: $assessment, fiscalYear: $fiscalYear);

		$currency     = $this->currencyOf(administrationId: $administrationId);
		$presentation = $this->presentationCurrency(upeId: $upeId);
		$rates        = $this->ratesFor(currency: $currency, presentation: $presentation, assessment: $assessment);

		$book    = $this->readBook(entityId: $entityId, upeId: $upeId, administrationId: $administrationId, start: $start, end: $end);
		$figures = $this->convert(figures: $book['figures'], rates: $rates);

		$existing  = $this->first(schema: 'CbcrEntityFigures', filters: ['entity' => $entityId, 'fiscalYear' => $fiscalYear]);
		$employees = $this->headcount(administrationId: $administrationId, end: $end);
		$method    = 'humaniq';
		if ($employees === null) {
			$employees = $existing['numberOfEmployees'] ?? null;
			$method    = 'entered';
		}

		$record = [
			'entity' => $entityId,
			'fiscalYear' => $fiscalYear,
			'source' => 'books',
			'currency' => $presentation,
		] + $figures + [
			'numberOfEmployees' => $employees,
			'employeeMethod' => $method,
			'yearClosed' => $book['yearClosed'],
			'unmappedAccounts' => $book['unmapped'],
			'topCounterparties' => $book['counterparties'],
			'computedAt' => ($now ?? new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		return $this->save(schema: 'CbcrEntityFigures', object: $record, uuid: self::idOf(row: $existing));
	}//end readFromBooks()

	/**
	 * Write one jurisdiction summary per tax residency as the sum of its entities' figures.
	 *
	 * A summary in state reconciled or locked is left as it is and named in
	 * `skipped`; an entity without figures for the year is named in
	 * `withoutFigures` and counts as zero.
	 *
	 * @param string $upeId      The GroupEntityRegistry id of the ultimate parent.
	 * @param int    $fiscalYear The fiscal year.
	 *
	 * @return array{summaries:array<int,array<string,mixed>>,skipped:array<int,string>,withoutFigures:array<int,string>}
	 *
	 * @throws InvalidArgumentException When the ultimate parent is unknown.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.3
	 */
	public function summarise(string $upeId, int $fiscalYear): array {
		if ($this->one(schema: 'GroupEntityRegistry', id: $upeId) === null) {
			throw new InvalidArgumentException('Unknown ultimate parent entity: ' . $upeId);
		}

		$byJurisdiction = [];
		$withoutFigures = [];
		foreach ($this->groupEntities(upeId: $upeId) as $entity) {
			$id      = (string)self::idOf(row: $entity);
			$figures = $this->first(schema: 'CbcrEntityFigures', filters: ['entity' => $id, 'fiscalYear' => $fiscalYear]);
			if ($figures === null) {
				$withoutFigures[] = $id;
			}

			$code = self::jurisdictionOf(entity: $entity);
			$byJurisdiction[$code][] = ['entity' => $entity, 'figures' => ($figures ?? [])];
		}

		ksort($byJurisdiction);
		$period    = 'FY' . $fiscalYear;
		$summaries = [];
		$skipped   = [];
		foreach ($byJurisdiction as $code => $members) {
			$existing = $this->first(
				schema: 'CbcrJurisdictionSummary',
				filters: ['ultimateParentEntity' => $upeId, 'period' => $period, 'jurisdiction' => $code]
			);
			if (in_array((string)($existing['state'] ?? ''), ['reconciled', 'locked'], true) === true) {
				$skipped[] = $code;
				continue;
			}

			$summary = ['ultimateParentEntity' => $upeId, 'period' => $period, 'jurisdiction' => $code]
				+ self::sum(members: $members)
				+ ['state' => (string)($existing['state'] ?? 'draft')];

			$summaries[] = $this->save(schema: 'CbcrJurisdictionSummary', object: $summary, uuid: self::idOf(row: $existing));
		}

		return ['summaries' => $summaries, 'skipped' => $skipped, 'withoutFigures' => $withoutFigures];
	}//end summarise()

	/**
	 * Read the ledger of one administration for a year.
	 *
	 * @param string $entityId         The entity.
	 * @param string $upeId            Its ultimate parent.
	 * @param string $administrationId Its administration.
	 * @param string $start            First day of the year, Y-m-d.
	 * @param string $end              Last day of the year, Y-m-d.
	 *
	 * @return array{figures:array<string,float>,yearClosed:bool,unmapped:array<int,string>,counterparties:array<int,array<string,mixed>>}
	 */
	private function readBook(string $entityId, string $upeId, string $administrationId, string $start, string $end): array {
		$transactions = $this->keyed(rows: $this->all(schema: 'GLTransaction', filters: ['administrationId' => $administrationId]));
		$accounts     = $this->accountIndex();
		$related      = $this->relatedRefs(upeId: $upeId, entityId: $entityId);
		$intercompany = $this->intercompanyJournals(administrationId: $administrationId);

		$lines = [];
		foreach ($this->all(schema: 'GLLine', filters: ['administrationId' => $administrationId]) as $line) {
			$transaction = ($transactions[(string)($line['transactionId'] ?? '')] ?? null);
			$date        = substr((string)($transaction['postingDate'] ?? ''), 0, 10);
			if ($date === '' || $date > $end || self::counts(line: $line, transaction: $transaction) === false) {
				continue;
			}

			$account = ($accounts[(string)($line['accountRef'] ?? '')] ?? ($accounts['#' . (string)($line['accountNumber'] ?? '')] ?? []));
			$lines[] = [
				'line' => $line,
				'transaction' => $transaction,
				'inYear' => ($date >= $start),
				'category' => self::categoryOf(account: $account),
				'number' => (string)($line['accountNumber'] ?? ($account['accountNumber'] ?? '')),
				'signed' => self::signed(line: $line),
				'pnl' => self::isResultLine(line: $line, account: $account),
			];
		}

		$taxTransactions = [];
		foreach ($lines as $entry) {
			if ($entry['inYear'] === true && in_array($entry['category'], self::TAX_EXPENSE, true) === true) {
				$taxTransactions[(string)($entry['line']['transactionId'] ?? '')] = true;
			}
		}

		$figures = array_fill_keys(
			[
				'unrelatedPartyRevenue', 'relatedPartyRevenue', 'profitBeforeTax', 'incomeTaxPaidCash', 'incomeTaxAccrued',
				'statedCapital', 'accumulatedEarnings', 'tangibleAssetsOtherThanCash', 'deferredTaxExpense', 'eligiblePayroll',
			],
			0.0
		);
		$result         = 0.0;
		$unmapped       = [];
		$counterparties = [];
		foreach ($lines as $entry) {
			$this->apply(
				entry: $entry,
				figures: $figures,
				result: $result,
				unmapped: $unmapped,
				counterparties: $counterparties,
				context: ['related' => $related, 'intercompany' => $intercompany, 'taxTransactions' => $taxTransactions]
			);
		}

		$closed = $this->yearClosed(administrationId: $administrationId, end: $end);
		if ($closed === false) {
			$figures['accumulatedEarnings'] += $result;
		}

		ksort($unmapped);
		return [
			'figures' => array_map(static fn (float $amount): float => round($amount, 2), $figures),
			'yearClosed' => $closed,
			'unmapped' => array_map('strval', array_keys($unmapped)),
			'counterparties' => self::top(counterparties: $counterparties),
		];
	}//end readBook()

	/**
	 * Add one ledger line to the figures.
	 *
	 * @param array<string,mixed>               $entry          The line with its transaction, category and sign.
	 * @param array<string,float>               $figures        The figures so far.
	 * @param float                             $result         The year's result so far.
	 * @param array<string,bool>                $unmapped       Accounts without a category so far, keyed by account number.
	 * @param array<string,array<string,mixed>> $counterparties Revenue per counterparty so far.
	 * @param array<string,array<string,bool>>  $context        Related refs, intercompany journals, tax transactions.
	 *
	 * @param-out array<array-key,bool> $unmapped
	 *
	 * @return void
	 */
	private function apply(array $entry, array &$figures, float &$result, array &$unmapped, array &$counterparties, array $context): void {
		$category = $entry['category'];
		$signed   = $entry['signed'];
		$inYear   = $entry['inYear'];
		if ($category === null && ($inYear === true || $entry['pnl'] === false) && $signed !== 0.0) {
			$unmapped[$entry['number']] = true;
		}

		if ($inYear === true && $entry['pnl'] === true) {
			$result += $signed;
			if (in_array($category, self::TAX_EXPENSE, true) === false) {
				$figures['profitBeforeTax'] += $signed;
			}
		}

		switch ($category) {
			case 'revenue':
				if ($inYear === true) {
					$this->addRevenue(entry: $entry, figures: $figures, counterparties: $counterparties, context: $context);
				}
				return;
			case 'income-tax-current':
				$figures['incomeTaxAccrued'] += self::when(applies: $inYear, amount: -$signed);
				return;
			case 'income-tax-deferred':
				$figures['deferredTaxExpense'] += self::when(applies: $inYear, amount: -$signed);
				return;
			case 'eligible-payroll':
				$figures['eligiblePayroll'] += self::when(applies: $inYear, amount: -$signed);
				return;
			case 'income-tax-payable':
				$paid = ($inYear === true && $signed < 0.0 && isset($context['taxTransactions'][(string)($entry['line']['transactionId'] ?? '')]) === false);
				$figures['incomeTaxPaidCash'] += self::when(applies: $paid, amount: -$signed);
				return;
			case 'stated-capital':
				$figures['statedCapital'] += $signed;
				return;
			case 'accumulated-earnings':
				$figures['accumulatedEarnings'] += $signed;
				return;
			case 'tangible-asset':
				$figures['tangibleAssetsOtherThanCash'] -= $signed;
				return;
		}
	}//end apply()

	/**
	 * Add a revenue line, split into related and unrelated.
	 *
	 * @param array<string,mixed>               $entry          The line.
	 * @param array<string,float>               $figures        The figures so far.
	 * @param array<string,array<string,mixed>> $counterparties Revenue per counterparty so far.
	 * @param array<string,array<string,bool>>  $context        Related refs and intercompany journals.
	 *
	 * @return void
	 */
	private function addRevenue(array $entry, array &$figures, array &$counterparties, array $context): void {
		$refs    = $this->counterpartyRefsOf(line: $entry['line']);
		$related = (($entry['transaction']['journalType'] ?? '') === 'intercompany'
			|| isset($context['intercompany'][(string)($entry['transaction']['journalEntryId'] ?? '')]) === true);
		foreach ($refs as $ref) {
			$related = ($related || isset($context['related'][$ref]) === true);
		}

		$figure = 'unrelatedPartyRevenue';
		if ($related === true) {
			$figure = 'relatedPartyRevenue';
		}

		$figures[$figure] += $entry['signed'];
		if ($refs === []) {
			return;
		}

		$key = $refs[(count($refs) - 1)];

		$counterparties[$key] ??= ['ref' => $key, 'amount' => 0.0, 'related' => $related];
		$counterparties[$key]['amount'] += $entry['signed'];
	}//end addRevenue()

	/**
	 * The counterparty refs of a line: the sub-ledger document and the customer or payee it names.
	 *
	 * @param array<string,mixed> $line The ledger line.
	 *
	 * @return array<int,string>
	 */
	private function counterpartyRefsOf(array $line): array {
		$ref = trim((string)($line['subLedgerRef'] ?? ''));
		if ($ref === '') {
			return [];
		}

		$refs   = [$ref];
		$schema = ['ar' => ['ARInvoice', 'customerId'], 'ap' => ['APInvoice', 'vendorId']][(string)($line['subLedgerType'] ?? '')] ?? null;
		if ($schema !== null) {
			$document = $this->cached(schema: $schema[0], id: $ref);
			$party    = trim((string)($document[$schema[1]] ?? ''));
			if ($party !== '') {
				$refs[] = $party;
			}
		}

		return $refs;
	}//end counterpartyRefsOf()

	/**
	 * The figures in presentation currency.
	 *
	 * @param array<string,float>                $figures The figures in the administration's currency.
	 * @param array{average:float,closing:float} $rates   The rates.
	 *
	 * @return array<string,float>
	 */
	private function convert(array $figures, array $rates): array {
		$converted = [];
		foreach ($figures as $key => $amount) {
			$rate = $rates['closing'];
			if (in_array($key, self::RESULT_FIGURES, true) === true) {
				$rate = $rates['average'];
			}

			$converted[$key] = round($amount * $rate, 2);
		}

		return $converted;
	}//end convert()

	/**
	 * The average and closing rate from the administration's currency into the presentation currency.
	 *
	 * @param string                   $currency     The administration's currency.
	 * @param string                   $presentation The group's presentation currency.
	 * @param array<string,mixed>|null $assessment   The scope assessment, for its fxRates.
	 *
	 * @return array{average:float,closing:float}
	 *
	 * @throws RuntimeException When a needed rate is missing.
	 */
	private function ratesFor(string $currency, string $presentation, ?array $assessment): array {
		if ($currency === $presentation) {
			return ['average' => 1.0, 'closing' => 1.0];
		}

		$rates   = (array)(($assessment['fxRates'] ?? [])[$currency] ?? []);
		$average = (float)($rates['average'] ?? 0);
		$closing = (float)($rates['closing'] ?? 0);
		if ($average <= 0.0 || $closing <= 0.0) {
			throw new RuntimeException(
				sprintf('Enter the average and closing %s to %s rate for the year on the scope assessment first.', $currency, $presentation)
			);
		}

		return ['average' => $average, 'closing' => $closing];
	}//end ratesFor()

	/**
	 * The sum of the members' figures, with the union of their activities.
	 *
	 * @param array<int,array{entity:array<string,mixed>,figures:array<string,mixed>}> $members The entities and their figures.
	 *
	 * @return array<string,mixed>
	 */
	private static function sum(array $members): array {
		$sum        = array_fill_keys(self::FIGURES, 0.0);
		$activities = [];
		$methods    = [];
		foreach ($members as $member) {
			foreach (self::FIGURES as $key) {
				$sum[$key] += (float)($member['figures'][$key] ?? 0);
			}

			foreach ((array)($member['entity']['bizActivities'] ?? []) as $code) {
				$activities[(string)$code] = true;
			}

			if (($member['figures']['employeeMethod'] ?? '') !== '') {
				$methods[(string)$member['figures']['employeeMethod']][] = (string)($member['entity']['entityName'] ?? '');
			}
		}

		$sum = array_map(static fn (float $amount): float => round($amount, 2), $sum);
		$sum['numberOfEmployees'] = (int)round($sum['numberOfEmployees']);
		ksort($activities);
		ksort($methods);

		$labels = ['humaniq' => 'Headcount at year end from humaniq: ', 'entered' => 'Entered: '];
		$notes  = [];
		foreach ($methods as $method => $names) {
			$notes[] = ($labels[$method] ?? 'Entered: ') . implode(', ', $names);
		}

		return $sum + ['mainBusinessActivities' => array_keys($activities), 'employeeMethodologyNote' => implode('; ', $notes)];
	}//end sum()

	/**
	 * Headcount at year end from humaniq: employees of the administration in service on the last day.
	 *
	 * @param string $administrationId The administration.
	 * @param string $end              The last day of the year.
	 *
	 * @return int|null Null when humaniq cannot be read or knows no one there.
	 */
	private function headcount(string $administrationId, string $end): ?int {
		try {
			$rows = $this->objectService->setRegister($this->humaniq->registerSlug())->setSchema('Employee')->findAll(
				['filters' => ['administrationId' => $administrationId], 'limit' => self::ROW_LIMIT]
			);
		} catch (Throwable $e) {
			$this->logger->info('CbcrFiguresService: humaniq headcount not readable', ['exception' => $e->getMessage()]);
			return null;
		}

		$count = 0;
		foreach ($rows as $row) {
			$employee = ObjectIdentifier::recordWithId(candidate: $row);
			$from     = substr((string)($employee['startDate'] ?? ''), 0, 10);
			$until    = substr((string)($employee['endDate'] ?? ''), 0, 10);
			if ($employee !== null && $from !== '' && $from <= $end && ($until === '' || $until >= $end)) {
				$count++;
			}
		}

		if ($count === 0) {
			return null;
		}

		return $count;
	}//end headcount()

	/**
	 * Whether the administration's fiscal year ending on the date is closed.
	 *
	 * @param string $administrationId The administration.
	 * @param string $end              The last day of the year.
	 *
	 * @return bool
	 */
	private function yearClosed(string $administrationId, string $end): bool {
		foreach ($this->all(schema: 'FiscalYear', filters: ['administrationId' => $administrationId]) as $year) {
			if (substr((string)($year['endDate'] ?? ''), 0, 10) === $end) {
				return (($year['state'] ?? '') === 'closed');
			}
		}

		return false;
	}//end yearClosed()

	/**
	 * The counterparty refs that stand for the other entities of the group.
	 *
	 * @param string $upeId    The ultimate parent.
	 * @param string $entityId The entity whose books are read.
	 *
	 * @return array<string,bool>
	 */
	private function relatedRefs(string $upeId, string $entityId): array {
		$refs = [];
		foreach ($this->groupEntities(upeId: $upeId) as $entity) {
			if (self::idOf(row: $entity) === $entityId) {
				continue;
			}

			foreach ((array)($entity['counterpartyRefs'] ?? []) as $ref) {
				$refs[(string)$ref] = true;
			}
		}

		return $refs;
	}//end relatedRefs()

	/**
	 * The journal entries of the administration that are one side of an intercompany entry.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string,bool>
	 */
	private function intercompanyJournals(string $administrationId): array {
		$journals = [];
		foreach (['sourceAdministrationId' => 'sourceJournalEntryId', 'destinationAdministrationId' => 'destinationJournalEntryId'] as $side => $journal) {
			foreach ($this->all(schema: 'IntercompanyJournalEntry', filters: [$side => $administrationId]) as $entry) {
				$id = trim((string)($entry[$journal] ?? ''));
				if ($id !== '') {
					$journals[$id] = true;
				}
			}
		}

		return $journals;
	}//end intercompanyJournals()

	/**
	 * The entities of the group: the ultimate parent and every entity naming it.
	 *
	 * @param string $upeId The ultimate parent.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function groupEntities(string $upeId): array {
		$entities = [];
		foreach ($this->all(schema: 'GroupEntityRegistry', filters: []) as $entity) {
			$id = self::idOf(row: $entity);
			if ($id === $upeId || (string)($entity['ultimateParentEntity'] ?? '') === $upeId) {
				$entities[] = $entity;
			}
		}

		return $entities;
	}//end groupEntities()

	/**
	 * Accounts by id and by `#accountNumber`.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function accountIndex(): array {
		$index = [];
		foreach ($this->all(schema: 'Account', filters: []) as $account) {
			$id = self::idOf(row: $account);
			if ($id !== null) {
				$index[$id] = $account;
			}

			$number = (string)($account['accountNumber'] ?? '');
			if ($number !== '' && isset($index['#' . $number]) === false) {
				$index['#' . $number] = $account;
			}
		}

		return $index;
	}//end accountIndex()

	/**
	 * The ultimate parent of an entity: the one it names, else itself.
	 *
	 * @param array<string,mixed> $entity   The entity.
	 * @param string              $entityId Its id.
	 *
	 * @return string
	 */
	private function upeOf(array $entity, string $entityId): string {
		$upe = trim((string)($entity['ultimateParentEntity'] ?? ''));
		if ($upe === '') {
			return $entityId;
		}

		return $upe;
	}//end upeOf()

	/**
	 * The group's presentation currency: the ultimate parent's administration currency, else EUR.
	 *
	 * @param string $upeId The ultimate parent.
	 *
	 * @return string
	 */
	private function presentationCurrency(string $upeId): string {
		$upe = $this->one(schema: 'GroupEntityRegistry', id: $upeId);
		$administrationId = trim((string)($upe['administrationId'] ?? ''));
		if ($administrationId === '') {
			return 'EUR';
		}

		return $this->currencyOf(administrationId: $administrationId);
	}//end presentationCurrency()

	/**
	 * An administration's functional currency, EUR when it names none.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return string
	 */
	private function currencyOf(string $administrationId): string {
		$administration = $this->cached(schema: 'Administration', id: $administrationId);
		$currency       = strtoupper(trim((string)($administration['functionalCurrency'] ?? '')));
		if ($currency === '') {
			return 'EUR';
		}

		return $currency;
	}//end currencyOf()

	/**
	 * The fiscal year's first and last day: the assessment's, else the calendar year.
	 *
	 * @param array<string,mixed>|null $assessment The scope assessment.
	 * @param int                      $fiscalYear The fiscal year.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function yearBounds(?array $assessment, int $fiscalYear): array {
		$start = substr((string)($assessment['fiscalYearStart'] ?? ''), 0, 10);
		$end   = substr((string)($assessment['fiscalYearEnd'] ?? ''), 0, 10);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) === 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) === 1) {
			return [$start, $end];
		}

		return [sprintf('%04d-01-01', $fiscalYear), sprintf('%04d-12-31', $fiscalYear)];
	}//end yearBounds()

	/**
	 * The CbC jurisdiction of an entity: its tax residency, else its jurisdiction, else stateless.
	 *
	 * @param array<string,mixed> $entity The entity.
	 *
	 * @return string
	 */
	private static function jurisdictionOf(array $entity): string {
		foreach (['taxResidency', 'jurisdiction'] as $field) {
			$code = strtoupper(trim((string)($entity[$field] ?? '')));
			if (preg_match('/^[A-Z]{2}$/', $code) === 1) {
				return $code;
			}
		}

		return self::STATELESS;
	}//end jurisdictionOf()

	/**
	 * Whether a line counts: its result stamp says so, or, unstamped, its transaction is posted.
	 *
	 * @param array<string,mixed>      $line        The line.
	 * @param array<string,mixed>|null $transaction Its transaction.
	 *
	 * @return bool
	 */
	private static function counts(array $line, ?array $transaction): bool {
		if (array_key_exists('countsInResult', $line) === true && $line['countsInResult'] !== null) {
			return ($line['countsInResult'] === true);
		}

		return (($transaction['state'] ?? '') === 'posted');
	}//end counts()

	/**
	 * The credit-positive amount of a line.
	 *
	 * @param array<string,mixed> $line The line.
	 *
	 * @return float
	 */
	private static function signed(array $line): float {
		if (is_numeric($line['signedAmount'] ?? null) === true) {
			return (float)$line['signedAmount'];
		}

		$amount = (float)($line['amount'] ?? 0);
		if (($line['side'] ?? '') === 'debit') {
			return -$amount;
		}

		return $amount;
	}//end signed()

	/**
	 * Whether a line is on a profit and loss account.
	 *
	 * @param array<string,mixed> $line    The line.
	 * @param array<string,mixed> $account Its account.
	 *
	 * @return bool
	 */
	private static function isResultLine(array $line, array $account): bool {
		if (($line['accountClass'] ?? '') !== '') {
			return ($line['accountClass'] === 'pnl');
		}

		return in_array((string)($account['accountType'] ?? ''), ['revenue', 'expense'], true);
	}//end isResultLine()

	/**
	 * The account's CbC category, null when it has none.
	 *
	 * @param array<string,mixed> $account The account.
	 *
	 * @return string|null
	 */
	private static function categoryOf(array $account): ?string {
		$category = trim((string)($account['cbcrCategory'] ?? ''));
		if ($category === '') {
			return null;
		}

		return $category;
	}//end categoryOf()

	/**
	 * The amount when it applies, else zero.
	 *
	 * @param bool  $applies Whether it applies.
	 * @param float $amount  The amount.
	 *
	 * @return float
	 */
	private static function when(bool $applies, float $amount): float {
		if ($applies === true) {
			return $amount;
		}

		return 0.0;
	}//end when()

	/**
	 * The ten counterparties with the most revenue.
	 *
	 * @param array<string,array<string,mixed>> $counterparties Revenue per counterparty.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function top(array $counterparties): array {
		$rows = array_values($counterparties);
		usort(
			$rows,
			static function (array $left, array $right): int {
				return [$right['amount'], $left['ref']] <=> [$left['amount'], $right['ref']];
			}
		);

		return array_map(
			static fn (array $row): array => ['ref' => $row['ref'], 'amount' => round($row['amount'], 2), 'related' => $row['related']],
			array_slice($rows, 0, 10)
		);
	}//end top()

	/**
	 * Rows keyed by id.
	 *
	 * @param array<int,array<string,mixed>> $rows The rows.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function keyed(array $rows): array {
		$keyed = [];
		foreach ($rows as $row) {
			$id = self::idOf(row: $row);
			if ($id !== null) {
				$keyed[$id] = $row;
			}
		}

		return $keyed;
	}//end keyed()

	/**
	 * One record by id, read once.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function cached(string $schema, string $id): ?array {
		$key = $schema . '/' . $id;
		if (array_key_exists($key, $this->cache) === false) {
			$this->cache[$key] = $this->one(schema: $schema, id: $id);
		}

		return $this->cache[$key];
	}//end cached()

	/**
	 * One record by id, null when absent or unreadable.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function one(string $schema, string $id): ?array {
		try {
			return ObjectIdentifier::findOne(scoped: $this->objectService->setRegister($this->register())->setSchema($schema), id: $id);
		} catch (Throwable) {
			return null;
		}
	}//end one()

	/**
	 * Every row of a schema matching the filters.
	 *
	 * @param string              $schema  The schema.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function all(string $schema, array $filters): array {
		$rows = $this->objectService->setRegister($this->register())->setSchema($schema)->findAll(['filters' => $filters, 'limit' => self::ROW_LIMIT]);

		$records = [];
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;
	}//end all()

	/**
	 * The first row of a schema matching the filters, or null.
	 *
	 * @param string              $schema  The schema.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function first(string $schema, array $filters): ?array {
		return ($this->all(schema: $schema, filters: $filters)[0] ?? null);
	}//end first()

	/**
	 * Save an object, in place when it has an id.
	 *
	 * @param string              $schema The schema.
	 * @param array<string,mixed> $object The object.
	 * @param string|null         $uuid   Its id, or null for a new one.
	 *
	 * @return array<string,mixed> The stored object.
	 */
	private function save(string $schema, array $object, ?string $uuid): array {
		if ($uuid !== null) {
			$object['id'] = $uuid;
		}

		$saved = $this->objectService->setRegister($this->register())->setSchema($schema)->saveObject(object: $object, uuid: $uuid);

		return (ObjectIdentifier::recordWithId(candidate: $saved) ?? []) + $object;
	}//end save()

	/**
	 * The id of a row, or null.
	 *
	 * @param array<string,mixed>|null $row The row.
	 *
	 * @return string|null
	 */
	private static function idOf(?array $row): ?string {
		$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ($row['uuid'] ?? '')));
		if ($id === '') {
			return null;
		}

		return $id;
	}//end idOf()

	/**
	 * The register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
