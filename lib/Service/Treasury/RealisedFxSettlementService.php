<?php

/**
 * Shillinq Realised FX Settlement Service
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Treasury;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\Application;
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
		$report = [
			'posted' => false,
			'direction' => null,
			'realisedCents' => 0,
			'reason' => null,
			'invoiceRate' => null,
			'paymentRate' => null,
		];

		$foreignCurrency = strtoupper((string)($invoice['currency'] ?? self::DEFAULT_FUNCTIONAL_CURRENCY));
		$administrationId = (string)($invoice['administrationId'] ?? '');
		$invoiceDate = (string)($invoice['invoiceDate'] ?? '');
		$grossAmount = (float)($invoice['grossAmount'] ?? 0.0);

		$functionalCurrency = $this->functionalCurrencyFor(administrationId: $administrationId);

		if ($foreignCurrency === '' || $foreignCurrency === $functionalCurrency) {
			$report['reason'] = 'same-currency';
			return $report;
		}

		if ($grossAmount === 0.0) {
			$report['reason'] = 'zero-amount';
			return $report;
		}

		$invoiceRate = $this->invoiceDateRate(
			invoice: $invoice,
			foreignCurrency: $foreignCurrency,
			functionalCurrency: $functionalCurrency,
			invoiceDate: $invoiceDate
		);

		$settlementDate = ($settlementDate ?? (new DateTimeImmutable())->format('Y-m-d'));
		$paymentRate = $settlementRate;
		if ($paymentRate === null) {
			$paymentRate = $this->resolveRate(
				foreignCurrency: $foreignCurrency,
				functionalCurrency: $functionalCurrency,
				onDate: $settlementDate
			);
		}

		if ($invoiceRate === null || $invoiceRate <= 0.0 || $paymentRate === null || $paymentRate <= 0.0) {
			$this->logger->info(
				'RealisedFxSettlementService: could not resolve both invoice-date and payment-date rates — skipping',
				[
					'foreignCurrency' => $foreignCurrency,
					'invoiceRate' => $invoiceRate,
					'paymentRate' => $paymentRate,
				]
			);
			$report['reason'] = 'no-rate';
			$report['invoiceRate'] = $invoiceRate;
			$report['paymentRate'] = $paymentRate;
			return $report;
		}

		$report['invoiceRate'] = $invoiceRate;
		$report['paymentRate'] = $paymentRate;

		// Realised difference in functional currency: foreign amount valued at
		// the payment-date rate minus the same amount valued at the invoice-date
		// rate. Positive = gain (foreign currency strengthened), negative = loss.
		$realisedDiff = ($grossAmount * ($paymentRate - $invoiceRate));
		$diffCents = (int)round($realisedDiff * 100);

		if ($diffCents === 0) {
			$report['reason'] = 'no-fx-movement';
			return $report;
		}

		$direction = 'loss';
		$realisedGainLossAccount = $this->glAccount(
			key: 'fx_realised_loss_account',
			default: self::DEFAULT_REALISED_LOSS_ACCOUNT
		);
		if ($diffCents > 0) {
			$direction = 'gain';
			$realisedGainLossAccount = $this->glAccount(
				key: 'fx_realised_gain_account',
				default: self::DEFAULT_REALISED_GAIN_ACCOUNT
			);
		}

		$arControlAccount = $this->glAccount(
			key: 'fx_ar_control_account',
			default: self::DEFAULT_AR_CONTROL_ACCOUNT
		);

		$magnitudeCents = abs($diffCents);
		$asOf = new DateTimeImmutable();

		$this->persistGlTransaction(
			invoice: $invoice,
			administrationId: $administrationId,
			direction: $direction,
			magnitudeCents: $magnitudeCents,
			arControlAccount: $arControlAccount,
			realisedGainLossAccount: $realisedGainLossAccount,
			settlementDate: $settlementDate
		);

		$this->persistRealisedFxPosting(
			invoice: $invoice,
			administrationId: $administrationId,
			foreignCurrency: $foreignCurrency,
			functionalCurrency: $functionalCurrency,
			grossAmount: $grossAmount,
			invoiceRate: $invoiceRate,
			paymentRate: $paymentRate,
			direction: $direction,
			realisedCents: $diffCents,
			arControlAccount: $arControlAccount,
			realisedGainLossAccount: $realisedGainLossAccount,
			settlementDate: $settlementDate,
			asOf: $asOf
		);

		$report['posted'] = true;
		$report['direction'] = $direction;
		$report['realisedCents'] = $diffCents;
		return $report;
	}//end postRealisedFxOnSettlement()

	/**
	 * Build and persist the balanced two-line realised-FX GLTransaction.
	 *
	 * The entry stands alone: for a gain the AR-control clearing is debited and
	 * the realised-gain account credited; for a loss the realised-loss account
	 * is debited and AR-control credited. Either way debit == credit ==
	 * |difference|, and `isBalanced` is asserted before persistence.
	 *
	 * @param array<string,mixed> $invoice The settled ARInvoice record.
	 * @param string $administrationId Administration scope.
	 * @param string $direction 'gain' or 'loss'.
	 * @param int $magnitudeCents Absolute realised difference in functional cents.
	 * @param string $arControlAccount AR-control clearing account code.
	 * @param string $realisedGainLossAccount Realised gain (gain) or loss (loss) account code.
	 * @param string $settlementDate ISO settlement date used as the journal date.
	 *
	 * @return void
	 */
	private function persistGlTransaction(
		array $invoice,
		string $administrationId,
		string $direction,
		int $magnitudeCents,
		string $arControlAccount,
		string $realisedGainLossAccount,
		string $settlementDate,
	): void {
		if ($direction === 'gain') {
			$postings = [
				[
					'accountNumber' => $arControlAccount,
					'debitCents' => $magnitudeCents,
					'creditCents' => 0,
					'description' => 'AR control — realised FX gain on settlement',
				],
				[
					'accountNumber' => $realisedGainLossAccount,
					'debitCents' => 0,
					'creditCents' => $magnitudeCents,
					'description' => 'Realised FX gain',
				],
			];
		} else {
			$postings = [
				[
					'accountNumber' => $realisedGainLossAccount,
					'debitCents' => $magnitudeCents,
					'creditCents' => 0,
					'description' => 'Realised FX loss',
				],
				[
					'accountNumber' => $arControlAccount,
					'debitCents' => 0,
					'creditCents' => $magnitudeCents,
					'description' => 'AR control — realised FX loss on settlement',
				],
			];
		}//end if

		$debitTotal = 0;
		$creditTotal = 0;
		foreach ($postings as $posting) {
			$debitTotal += (int)$posting['debitCents'];
			$creditTotal += (int)$posting['creditCents'];
		}

		// Balance invariant — a realised-FX entry that is not self-balancing is
		// a bug, never persisted (design.md "balanced GL invariant").
		if ($debitTotal !== $creditTotal) {
			$this->logger->error(
				'RealisedFxSettlementService: refusing to post an unbalanced realised-FX GLTransaction',
				['debitTotal' => $debitTotal, 'creditTotal' => $creditTotal]
			);
			return;
		}

		$invoiceId = $this->invoiceIdentity(invoice: $invoice);
		$journal = [
			'administrationId' => $administrationId,
			'description' => sprintf(
				'Realised FX %s on settlement of invoice %s',
				$direction,
				(string)($invoice['invoiceNumber'] ?? $invoiceId)
			),
			'journalDate' => $settlementDate,
			'isBalanced' => true,
			'invoiceId' => $invoiceId,
			'journalType' => 'realised-fx',
			'postings' => $postings,
		];

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService
				->setRegister($this->register())
				->setSchema('GLTransaction')
				->saveObject($journal);
		} catch (Throwable $e) {
			$this->logger->warning(
				'RealisedFxSettlementService: realised-FX GLTransaction save failed (continuing)',
				['exception' => $e->getMessage()]
			);
		}

	}//end persistGlTransaction()

	/**
	 * Persist one RealisedFxPosting audit record (mirrors FxRevaluationPosting).
	 *
	 * @param array<string,mixed> $invoice The settled ARInvoice record.
	 * @param string $administrationId Administration scope.
	 * @param string $foreignCurrency Invoice (foreign) currency.
	 * @param string $functionalCurrency Administration functional currency.
	 * @param float $grossAmount Foreign-currency gross amount settled.
	 * @param float $invoiceRate Rate the receivable was booked at.
	 * @param float $paymentRate Rate applied at settlement.
	 * @param string $direction 'gain' or 'loss'.
	 * @param int $realisedCents Signed realised difference in functional cents.
	 * @param string $arControlAccount AR-control clearing account code.
	 * @param string $realisedGainLossAccount Realised gain/loss account code.
	 * @param string $settlementDate ISO settlement date.
	 * @param DateTimeImmutable $asOf Run timestamp.
	 *
	 * @return void
	 */
	private function persistRealisedFxPosting(
		array $invoice,
		string $administrationId,
		string $foreignCurrency,
		string $functionalCurrency,
		float $grossAmount,
		float $invoiceRate,
		float $paymentRate,
		string $direction,
		int $realisedCents,
		string $arControlAccount,
		string $realisedGainLossAccount,
		string $settlementDate,
		DateTimeImmutable $asOf,
	): void {
		$posting = [
			'administrationId' => $administrationId,
			'invoiceId' => $this->invoiceIdentity(invoice: $invoice),
			'invoiceNumber' => (string)($invoice['invoiceNumber'] ?? ''),
			'foreignCurrency' => $foreignCurrency,
			'functionalCurrency' => $functionalCurrency,
			'foreignAmount' => $grossAmount,
			'invoiceRate' => $invoiceRate,
			'paymentRate' => $paymentRate,
			'realisedDeltaCents' => $realisedCents,
			'direction' => $direction,
			'gainLossGLAccount' => $realisedGainLossAccount,
			'arControlGLAccount' => $arControlAccount,
			'settlementDate' => $settlementDate,
			'postedAt' => $asOf->format(DateTimeInterface::ATOM),
			'postedBy' => self::SYSTEM_ACTOR,
		];

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService
				->setRegister($this->register())
				->setSchema('RealisedFxPosting')
				->saveObject($posting);
		} catch (Throwable $e) {
			$this->logger->warning(
				'RealisedFxSettlementService: RealisedFxPosting save failed (continuing)',
				['exception' => $e->getMessage()]
			);
		}

	}//end persistRealisedFxPosting()

	/**
	 * Resolve the invoice-date rate: prefer an fxRate booked on the invoice
	 * itself, else look it up in the FxRate register at the invoice date.
	 *
	 * @param array<string,mixed> $invoice The ARInvoice record.
	 * @param string $foreignCurrency Invoice currency.
	 * @param string $functionalCurrency Administration functional currency.
	 * @param string $invoiceDate ISO invoice date.
	 *
	 * @return float|null The booked/looked-up rate, or null when unresolvable.
	 */
	private function invoiceDateRate(
		array $invoice,
		string $foreignCurrency,
		string $functionalCurrency,
		string $invoiceDate,
	): ?float {
		$booked = $invoice['fxRate'] ?? null;
		if ($booked !== null && (float)$booked > 0.0) {
			return (float)$booked;
		}

		return $this->resolveRate(
			foreignCurrency: $foreignCurrency,
			functionalCurrency: $functionalCurrency,
			onDate: $invoiceDate
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

		$best = null;
		$bestDate = '';
		foreach ($rows as $row) {
			$record = $this->asArray(row: $row);
			$date = (string)($record['date'] ?? '');
			$rate = $record['rate'] ?? null;
			if ($rate === null || (float)$rate <= 0.0 || $date === '' || $date > $onDate) {
				continue;
			}

			// Exact match wins immediately.
			if ($date === $onDate) {
				return (float)$rate;
			}

			if ($date > $bestDate) {
				$bestDate = $date;
				$best = (float)$rate;
			}
		}//end foreach

		return $best;
	}//end resolveRate()

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
		$configured = $this->appConfig->getValueString(Application::APP_ID, $key, $default);
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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
