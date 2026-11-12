<?php

/**
 * Shillinq Realised FX Settlement Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Posts the REALISED foreign-exchange gain or loss that arises when a
 * foreign-currency AR invoice is settled at a payment-date rate that differs
 * from the invoice-date rate it was booked at (REQ-MC-010).
 *
 * shillinq already carries multi-currency AR (`ARInvoice.currency`), a daily
 * `FxRate` register (REQ-MC-002) and PERIOD-END UNREALISED revaluation of open
 * balances (`FxRevaluationService`, change fx-period-end-revaluation). What was
 * missing is the settlement-time REALISED leg: when the cash actually lands,
 * the functional-currency value received (`grossAmount x paymentRate`) differs
 * from the functional-currency value the receivable was booked at
 * (`grossAmount x invoiceRate`), and that difference is a realised FX gain or
 * loss that MUST hit the P&L. Before this change no code posted it, so a
 * EUR-functional administration invoicing in USD and collecting at a stronger
 * dollar silently understated income.
 *
 * The posting is a self-balancing two-line `GLTransaction` (the same shape
 * `InvoiceGenerationService::postInvoice()` emits): the realised difference is
 * debited/credited between the AR-control clearing account and the realised FX
 * gain/loss account, so debit == credit == |difference| on its own. A parallel
 * `RealisedFxPosting` audit record mirrors `FxRevaluationPosting` for the
 * unrealised leg.
 *
 * Per ADR-031 this is the imperative-orchestration exception design.md
 * documents: a cross-schema read (`Administration.functionalCurrency`), an
 * external rate lookup against the `FxRate` register at two distinct dates, and
 * a balanced GL emission — none expressible as a single OR calculation formula.
 * It consumes only the real OpenRegister ObjectService API (ADR-022).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Treasury
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Treasury;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Settlement-time realised FX gain/loss posting (ADR-031 exception).
 *
 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * Pre-existing debt (issue #506): posting this many required GL fields is
 * inherent to the domain; early-return refactor deferred pending full
 * behavioral verification of each branch.
 */
class RealisedFxSettlementService {
	/**
	 * Marker used for the postedBy field on RealisedFxPosting records.
	 *
	 * @var string
	 */
	public const SYSTEM_ACTOR = 'SYSTEM:RealisedFxSettlementService';

	/**
	 * Fallback functional currency when Administration.functionalCurrency
	 * cannot be resolved.
	 *
	 * @var string
	 */
	private const DEFAULT_FUNCTIONAL_CURRENCY = 'EUR';

	/**
	 * Documented IAppConfig defaults for GL account attribution (design.md
	 * "Realised FX GL account configuration"). Deliberately distinct from the
	 * unrealised 8020/8021 pair so realised and unrealised FX never conflate.
	 *
	 * @var string
	 */
	private const DEFAULT_REALISED_GAIN_ACCOUNT = '8022';
	private const DEFAULT_REALISED_LOSS_ACCOUNT = '8023';
	private const DEFAULT_AR_CONTROL_ACCOUNT = '1130';

	/**
	 * The two realised-FX posting lines per direction: which account, which
	 * side carries the magnitude (1) and the line description.
	 *
	 * @var array<string,array<int,array{account:string,debit:int,credit:int,description:string}>>
	 */
	private const REALISED_FX_LINES = [
		'gain' => [
			['account' => 'arControl', 'debit' => 1, 'credit' => 0, 'description' => 'AR control — realised FX gain on settlement'],
			['account' => 'gainLoss', 'debit' => 0, 'credit' => 1, 'description' => 'Realised FX gain'],
		],
		'loss' => [
			['account' => 'gainLoss', 'debit' => 1, 'credit' => 0, 'description' => 'Realised FX loss'],
			['account' => 'arControl', 'debit' => 0, 'credit' => 1, 'description' => 'AR control — realised FX loss on settlement'],
		],
	];

	/**
	 * The warning logged when a save of a schema fails.
	 *
	 * @var array<string,string>
	 */
	private const SAVE_FAILED_MESSAGE = [
		'GLTransaction' => 'RealisedFxSettlementService: realised-FX GLTransaction save failed (continuing)',
		'RealisedFxPosting' => 'RealisedFxSettlementService: RealisedFxPosting save failed (continuing)',
	];

	/**
	 * Construct the realised FX settlement service.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug + GL account overrides.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Post the realised FX gain/loss for a settled foreign-currency AR invoice
	 * (REQ-MC-010). Idempotent-friendly and fail-open: any resolution gap
	 * (same currency, missing rate, zero movement) returns a `posted:false`
	 * report and posts nothing — it never throws into the settlement caller.
	 *
	 * @param array<string,mixed> $invoice The settled ARInvoice record (carries currency,
	 *                                     grossAmount, invoiceDate, administrationId, and
	 *                                     optionally a booked fxRate).
	 * @param float|null $settlementRate Explicit payment-date rate (foreign -> functional);
	 *                                   when null the FxRate register is queried at $settlementDate.
	 * @param string|null $settlementDate ISO settlement date; defaults to today (UTC).
	 *
	 * @return array{posted:bool,direction:?string,realisedCents:int,reason:?string,invoiceRate:?float,paymentRate:?float}
	 *
	 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
	 */
	public function postRealisedFxOnSettlement(
		array $invoice,
		?float $settlementRate = null,
		?string $settlementDate = null,
	): array {
		$run = $this->startRun(invoice: $invoice, settlementDate: $settlementDate);

		$reason = $this->unpostableReason(run: $run, settlementRate: $settlementRate);
		if ($reason !== null) {
			return self::report(run: $run, reason: $reason);
		}

		$this->chooseAccounts(run: $run);

		$this->persistGlTransaction(run: $run);
		$this->persistRealisedFxPosting(run: $run);

		return self::report(run: $run, reason: null);
	}//end postRealisedFxOnSettlement()

	/**
	 * Start the run from the settled invoice: its currency, administration,
	 * date and amount, and the administration's functional currency.
	 *
	 * @param array<string,mixed> $invoice        The settled ARInvoice record.
	 * @param string|null         $settlementDate ISO settlement date; null for today.
	 *
	 * @return RealisedFxSettlementRun
	 */
	private function startRun(array $invoice, ?string $settlementDate): RealisedFxSettlementRun {
		$run = new RealisedFxSettlementRun(
			invoice: $invoice,
			administrationId: (string)($invoice['administrationId'] ?? '')
		);
		$run->foreignCurrency = strtoupper((string)($invoice['currency'] ?? self::DEFAULT_FUNCTIONAL_CURRENCY));
		$run->invoiceDate = (string)($invoice['invoiceDate'] ?? '');
		$run->grossAmount = (float)($invoice['grossAmount'] ?? 0.0);
		$run->requestedSettlementDate = $settlementDate;

		$run->functionalCurrency = $this->functionalCurrencyFor(administrationId: $run->administrationId);

		return $run;
	}//end startRun()

	/**
	 * Why nothing is posted, or null when there is a realised difference to post.
	 *
	 * @param RealisedFxSettlementRun $run            The run.
	 * @param float|null              $settlementRate Explicit payment-date rate, or null.
	 *
	 * @return string|null 'same-currency', 'zero-amount', 'no-rate', 'no-fx-movement' or null.
	 */
	private function unpostableReason(RealisedFxSettlementRun $run, ?float $settlementRate): ?string {
		$reason = self::currencyReason(run: $run);
		if ($reason !== null) {
			return $reason;
		}

		return $this->rateReason(run: $run, settlementRate: $settlementRate);
	}//end unpostableReason()

	/**
	 * 'same-currency' for an invoice in the functional (or no) currency,
	 * 'zero-amount' for a zero gross amount, else null.
	 *
	 * @param RealisedFxSettlementRun $run The run.
	 *
	 * @return string|null
	 */
	private static function currencyReason(RealisedFxSettlementRun $run): ?string {
		if ($run->foreignCurrency === '' || $run->foreignCurrency === $run->functionalCurrency) {
			return 'same-currency';
		}

		if ($run->grossAmount === 0.0) {
			return 'zero-amount';
		}

		return null;
	}//end currencyReason()

	/**
	 * Resolve both rates and the realised difference; 'no-rate' when a rate is
	 * missing (logged), 'no-fx-movement' when the difference rounds to zero
	 * cents, else null.
	 *
	 * @param RealisedFxSettlementRun $run            The run.
	 * @param float|null              $settlementRate Explicit payment-date rate, or null.
	 *
	 * @return string|null
	 */
	private function rateReason(RealisedFxSettlementRun $run, ?float $settlementRate): ?string {
		$this->resolveRates(run: $run, settlementRate: $settlementRate);
		if (self::ratesMissing(run: $run) === true) {
			$this->logMissingRates(run: $run);
			return 'no-rate';
		}

		// Realised difference in functional currency: foreign amount valued at
		// the payment-date rate minus the same amount valued at the invoice-date
		// rate. Positive = gain (foreign currency strengthened), negative = loss.
		$realisedDiff = ($run->grossAmount * ($run->paymentRate - $run->invoiceRate));
		$run->diffCents = (int)round($realisedDiff * 100);
		if ($run->diffCents === 0) {
			return 'no-fx-movement';
		}

		return null;
	}//end rateReason()

	/**
	 * Resolve the invoice-date rate, the settlement date and the payment-date
	 * rate (the explicit one, else the FxRate register at the settlement date).
	 *
	 * @param RealisedFxSettlementRun $run            The run.
	 * @param float|null              $settlementRate Explicit payment-date rate, or null.
	 *
	 * @return void
	 */
	private function resolveRates(RealisedFxSettlementRun $run, ?float $settlementRate): void {
		$run->invoiceRate = $this->invoiceDateRate(run: $run);

		$run->settlementDate = ($run->requestedSettlementDate ?? (new DateTimeImmutable())->format('Y-m-d'));
		$run->paymentRate = $settlementRate;
		if ($run->paymentRate === null) {
			$run->paymentRate = $this->resolveRate(
				foreignCurrency: $run->foreignCurrency,
				functionalCurrency: $run->functionalCurrency,
				onDate: $run->settlementDate
			);
		}
	}//end resolveRates()

	/**
	 * Log that the run is skipped for want of both rates.
	 *
	 * @param RealisedFxSettlementRun $run The run, with its rates resolved.
	 *
	 * @return void
	 */
	private function logMissingRates(RealisedFxSettlementRun $run): void {
		$this->logger->info(
			'RealisedFxSettlementService: could not resolve both invoice-date and payment-date rates — skipping',
			[
				'foreignCurrency' => $run->foreignCurrency,
				'invoiceRate' => $run->invoiceRate,
				'paymentRate' => $run->paymentRate,
			]
		);
	}//end logMissingRates()

	/**
	 * Whether either rate is missing or not positive.
	 *
	 * @param RealisedFxSettlementRun $run The run, with its rates resolved.
	 *
	 * @return bool
	 */
	private static function ratesMissing(RealisedFxSettlementRun $run): bool {
		return $run->invoiceRate === null || $run->invoiceRate <= 0.0 || $run->paymentRate === null || $run->paymentRate <= 0.0;
	}//end ratesMissing()

	/**
	 * Choose the direction, the realised gain or loss account and the
	 * AR-control account, and stamp the posting time.
	 *
	 * @param RealisedFxSettlementRun $run The run, with its difference computed.
	 *
	 * @return void
	 */
	private function chooseAccounts(RealisedFxSettlementRun $run): void {
		$this->chooseDirection(run: $run);
		$this->chooseArControlAccount(run: $run);
	}//end chooseAccounts()

	/**
	 * Set the direction and the realised gain or loss account: 'loss' on the
	 * loss account unless the difference is positive, then 'gain' on the gain
	 * account.
	 *
	 * @param RealisedFxSettlementRun $run The run, with its difference computed.
	 *
	 * @return void
	 */
	private function chooseDirection(RealisedFxSettlementRun $run): void {
		$run->direction = 'loss';
		$run->realisedGainLossAccount = $this->glAccount(
			key: 'fx_realised_loss_account',
			default: self::DEFAULT_REALISED_LOSS_ACCOUNT
		);
		if ($run->diffCents > 0) {
			$run->direction = 'gain';
			$run->realisedGainLossAccount = $this->glAccount(
				key: 'fx_realised_gain_account',
				default: self::DEFAULT_REALISED_GAIN_ACCOUNT
			);
		}

		$run->postedCents = $run->diffCents;
	}//end chooseDirection()

	/**
	 * Set the AR-control clearing account and the posting timestamp.
	 *
	 * @param RealisedFxSettlementRun $run The run.
	 *
	 * @return void
	 */
	private function chooseArControlAccount(RealisedFxSettlementRun $run): void {
		$run->arControlAccount = $this->glAccount(
			key: 'fx_ar_control_account',
			default: self::DEFAULT_AR_CONTROL_ACCOUNT
		);
		$run->asOf = new DateTimeImmutable();
	}//end chooseArControlAccount()

	/**
	 * The settlement report of the run.
	 *
	 * @param RealisedFxSettlementRun $run    The run.
	 * @param string|null             $reason Why nothing was posted, null when posted.
	 *
	 * @return array{posted:bool,direction:?string,realisedCents:int,reason:?string,invoiceRate:?float,paymentRate:?float}
	 */
	private static function report(RealisedFxSettlementRun $run, ?string $reason): array {
		return [
			'posted' => $reason === null,
			'direction' => $run->direction,
			'realisedCents' => $run->postedCents,
			'reason' => $reason,
			'invoiceRate' => $run->invoiceRate,
			'paymentRate' => $run->paymentRate,
		];
	}//end report()

	/**
	 * Build and persist the balanced two-line realised-FX GLTransaction.
	 *
	 * The entry stands alone: for a gain the AR-control clearing is debited and
	 * the realised-gain account credited; for a loss the realised-loss account
	 * is debited and AR-control credited. Either way debit == credit ==
	 * |difference|, and `isBalanced` is asserted before persistence.
	 *
	 * @param RealisedFxSettlementRun $run The run, with direction and accounts chosen.
	 *
	 * @return void
	 */
	private function persistGlTransaction(RealisedFxSettlementRun $run): void {
		$postings = self::realisedFxPostings(run: $run);
		[$debitTotal, $creditTotal] = self::postingTotals(postings: $postings);

		// Balance invariant — a realised-FX entry that is not self-balancing is
		// a bug, never persisted (design.md "balanced GL invariant").
		if ($debitTotal !== $creditTotal) {
			$this->logger->error(
				'RealisedFxSettlementService: refusing to post an unbalanced realised-FX GLTransaction',
				['debitTotal' => $debitTotal, 'creditTotal' => $creditTotal]
			);
			return;
		}

		$this->saveOrWarn(schema: 'GLTransaction', object: $this->realisedFxJournal(run: $run, postings: $postings));
	}//end persistGlTransaction()

	/**
	 * The debit and credit totals of $postings, in cents.
	 *
	 * @param array<int,array<string,mixed>> $postings The posting lines.
	 *
	 * @return array{0:int,1:int}
	 */
	private static function postingTotals(array $postings): array {
		$debitTotal = 0;
		$creditTotal = 0;
		foreach ($postings as $posting) {
			$debitTotal += (int)$posting['debitCents'];
			$creditTotal += (int)$posting['creditCents'];
		}

		return [$debitTotal, $creditTotal];
	}//end postingTotals()

	/**
	 * The two posting lines of the run's direction, from REALISED_FX_LINES.
	 *
	 * @param RealisedFxSettlementRun $run The run, with direction and accounts chosen.
	 *
	 * @return array<int,array{accountNumber:string,debitCents:int,creditCents:int,description:string}>
	 */
	private static function realisedFxPostings(RealisedFxSettlementRun $run): array {
		$accounts = [
			'arControl' => $run->arControlAccount,
			'gainLoss' => $run->realisedGainLossAccount,
		];
		$magnitudeCents = abs($run->diffCents);

		$postings = [];
		foreach (self::REALISED_FX_LINES[$run->direction] as $line) {
			$postings[] = [
				'accountNumber' => $accounts[$line['account']],
				'debitCents' => ($magnitudeCents * $line['debit']),
				'creditCents' => ($magnitudeCents * $line['credit']),
				'description' => $line['description'],
			];
		}

		return $postings;
	}//end realisedFxPostings()

	/**
	 * The realised-FX GLTransaction record carrying $postings.
	 *
	 * @param RealisedFxSettlementRun        $run      The run.
	 * @param array<int,array<string,mixed>> $postings The balanced posting lines.
	 *
	 * @return array<string,mixed>
	 */
	private function realisedFxJournal(RealisedFxSettlementRun $run, array $postings): array {
		$invoiceId = $this->invoiceIdentity(invoice: $run->invoice);

		return [
			'administrationId' => $run->administrationId,
			'description' => sprintf(
				'Realised FX %s on settlement of invoice %s',
				$run->direction,
				(string)($run->invoice['invoiceNumber'] ?? $invoiceId)
			),
			'journalDate' => $run->settlementDate,
			'isBalanced' => true,
			'invoiceId' => $invoiceId,
			'journalType' => 'realised-fx',
			'postings' => $postings,
		];
	}//end realisedFxJournal()

	/**
	 * Persist one RealisedFxPosting audit record (mirrors FxRevaluationPosting).
	 *
	 * @param RealisedFxSettlementRun $run The run, with direction and accounts chosen.
	 *
	 * @return void
	 */
	private function persistRealisedFxPosting(RealisedFxSettlementRun $run): void {
		$posting = [
			...$this->postingInvoiceSide(run: $run),
			...self::postingSettlementSide(run: $run),
		];

		$this->saveOrWarn(schema: 'RealisedFxPosting', object: $posting);
	}//end persistRealisedFxPosting()

	/**
	 * The invoice half of the RealisedFxPosting: scope, invoice, currencies,
	 * amount and both rates.
	 *
	 * @param RealisedFxSettlementRun $run The run.
	 *
	 * @return array<string,mixed>
	 */
	private function postingInvoiceSide(RealisedFxSettlementRun $run): array {
		return [
			'administrationId' => $run->administrationId,
			'invoiceId' => $this->invoiceIdentity(invoice: $run->invoice),
			'invoiceNumber' => (string)($run->invoice['invoiceNumber'] ?? ''),
			'foreignCurrency' => $run->foreignCurrency,
			'functionalCurrency' => $run->functionalCurrency,
			'foreignAmount' => $run->grossAmount,
			'invoiceRate' => $run->invoiceRate,
			'paymentRate' => $run->paymentRate,
		];
	}//end postingInvoiceSide()

	/**
	 * The settlement half of the RealisedFxPosting: difference, direction,
	 * accounts, date and who posted it when.
	 *
	 * @param RealisedFxSettlementRun $run The run.
	 *
	 * @return array<string,mixed>
	 */
	private static function postingSettlementSide(RealisedFxSettlementRun $run): array {
		return [
			'realisedDeltaCents' => $run->diffCents,
			'direction' => $run->direction,
			'gainLossGLAccount' => $run->realisedGainLossAccount,
			'arControlGLAccount' => $run->arControlAccount,
			'settlementDate' => $run->settlementDate,
			'postedAt' => $run->asOf?->format(DateTimeInterface::ATOM),
			'postedBy' => self::SYSTEM_ACTOR,
		];
	}//end postingSettlementSide()

	/**
	 * Save one object on $schema; a failure is logged as a warning and the
	 * run continues.
	 *
	 * @param string              $schema OR schema slug.
	 * @param array<string,mixed> $object The record.
	 *
	 * @return void
	 */
	private function saveOrWarn(string $schema, array $object): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService
				->setRegister($this->register())
				->setSchema($schema)
				->saveObject($object);
		} catch (Throwable $e) {
			$this->logger->warning(
				self::SAVE_FAILED_MESSAGE[$schema],
				['exception' => $e->getMessage()]
			);
		}
	}//end saveOrWarn()

	/**
	 * Resolve the invoice-date rate: prefer an fxRate booked on the invoice
	 * itself, else look it up in the FxRate register at the invoice date.
	 *
	 * @param RealisedFxSettlementRun $run The run.
	 *
	 * @return float|null The booked/looked-up rate, or null when unresolvable.
	 */
	private function invoiceDateRate(RealisedFxSettlementRun $run): ?float {
		$booked = $run->invoice['fxRate'] ?? null;
		if ($booked !== null && (float)$booked > 0.0) {
			return (float)$booked;
		}

		return $this->resolveRate(
			foreignCurrency: $run->foreignCurrency,
			functionalCurrency: $run->functionalCurrency,
			onDate: $run->invoiceDate
		);

	}//end invoiceDateRate()

	/**
	 * Look up a foreign -> functional rate in the FxRate register at (or before)
	 * a date. Prefers an exact-date row, then the most recent effective row on
	 * or before the target date (REQ-MC-002 orientation contract:
	 * baseCurrencyAmount = transactionAmount x rate).
	 *
	 * @param string $foreignCurrency transactionCurrency to price.
	 * @param string $functionalCurrency baseCurrency to price into.
	 * @param string $onDate ISO target date.
	 *
	 * @return float|null The rate, or null when no usable snapshot exists.
	 */
	private function resolveRate(string $foreignCurrency, string $functionalCurrency, string $onDate): ?float {
		if ($onDate === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister($this->register())
				->setSchema('FxRate')
				->findAll(
					[
						'filters' => [
							'transactionCurrency' => $foreignCurrency,
							'baseCurrency' => $functionalCurrency,
						],
					]
				);
		} catch (Throwable $e) {
			$this->logger->warning(
				'RealisedFxSettlementService: FxRate lookup failed',
				['foreignCurrency' => $foreignCurrency, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_array($rows) === false || $rows === []) {
			return null;
		}

		return $this->bestRateOnOrBefore(rows: $rows, onDate: $onDate);
	}//end resolveRate()

	/**
	 * The rate of the row dated exactly $onDate, else of the most recent row
	 * before it; rows without a positive rate or a date are skipped.
	 *
	 * @param array<int,mixed> $rows   FxRate rows (ObjectEntity or array).
	 * @param string           $onDate ISO target date.
	 *
	 * @return float|null
	 */
	private function bestRateOnOrBefore(array $rows, string $onDate): ?float {
		$best = null;
		$bestDate = '';
		foreach ($rows as $row) {
			$candidate = $this->rateCandidate(row: $row, onDate: $onDate);
			if ($candidate === null) {
				continue;
			}

			// Exact match wins immediately.
			if ($candidate['date'] === $onDate) {
				return $candidate['rate'];
			}

			if ($candidate['date'] > $bestDate) {
				$bestDate = $candidate['date'];
				$best = $candidate['rate'];
			}
		}

		return $best;
	}//end bestRateOnOrBefore()

	/**
	 * One FxRate row's date and rate, or null when it has no positive rate, no
	 * date, or is dated after $onDate.
	 *
	 * @param mixed  $row    Raw FxRate row.
	 * @param string $onDate ISO target date.
	 *
	 * @return array{date:string,rate:float}|null
	 */
	private function rateCandidate(mixed $row, string $onDate): ?array {
		$record = $this->asArray(row: $row);
		$date = (string)($record['date'] ?? '');
		$rate = $record['rate'] ?? null;
		if ($rate === null || (float)$rate <= 0.0 || $date === '' || $date > $onDate) {
			return null;
		}

		return ['date' => $date, 'rate' => (float)$rate];
	}//end rateCandidate()

	/**
	 * Resolve the administratie's functional currency.
	 *
	 * @param string $administrationId The administration scope.
	 *
	 * @return string ISO 4217 functional currency; 'EUR' when unresolvable.
	 */
	private function functionalCurrencyFor(string $administrationId): string {
		if ($administrationId === '') {
			return self::DEFAULT_FUNCTIONAL_CURRENCY;
		}

		try {
			// NOT findAll(['filters' => ['id' => …]]) — that addresses JSON
			// properties and the entity's `id` is not one, so it matched
			// nothing for every administration and this method silently
			// returned the EUR default for all of them, ignoring whatever
			// functionalCurrency was actually configured.
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$admin = ObjectIdentifier::findOne(
				scoped: $objectService
					->setRegister($this->register())
					->setSchema('Administration'),
				id: $administrationId,
				fallbackProperty: 'administrationCode'
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'RealisedFxSettlementService: failed to resolve Administration.functionalCurrency — defaulting to EUR',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return self::DEFAULT_FUNCTIONAL_CURRENCY;
		}

		if ($admin === null) {
			return self::DEFAULT_FUNCTIONAL_CURRENCY;
		}

		$currency = strtoupper((string)($admin['functionalCurrency'] ?? ''));
		if ($currency === '') {
			return self::DEFAULT_FUNCTIONAL_CURRENCY;
		}

		return $currency;
	}//end functionalCurrencyFor()

	/**
	 * Resolve an ARInvoice record's identity for cross-references.
	 *
	 * @param array<string,mixed> $invoice The ARInvoice record.
	 *
	 * @return string The identity (id, @self.id, or slug; '' when none).
	 */
	private function invoiceIdentity(array $invoice): string {
		$id = (string)($invoice['id'] ?? ($invoice['@self']['id'] ?? ''));
		if ($id !== '') {
			return $id;
		}

		return (string)($invoice['slug'] ?? '');
	}//end invoiceIdentity()

	/**
	 * Resolve one configurable GL account code with a documented default.
	 *
	 * @param string $key IAppConfig key.
	 * @param string $default Documented default account code.
	 *
	 * @return string The account code.
	 */
	private function glAccount(string $key, string $default): string {
		$configured = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, $default);
		if ($configured === '') {
			return $default;
		}

		return $configured;
	}//end glAccount()

	/**
	 * Normalise an OpenRegister ObjectService row (ObjectEntity or array) to a
	 * plain array, mirroring FxRevaluationService::asArray().
	 *
	 * @param mixed $row Raw row from ObjectService::findAll().
	 *
	 * @return array<string,mixed> The object as an array (empty when unusable).
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$out = $row->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$out = $row->getObject();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		return [];
	}//end asArray()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
