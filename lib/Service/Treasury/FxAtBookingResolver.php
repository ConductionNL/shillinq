<?php

/**
 * Shillinq FX-at-booking resolver
 *
 * Chooses the exchange rate a foreign-currency ledger line is booked at:
 * the newest FxRate for the currency pair dated on or before the booking
 * date, an administration-scoped rate over a shared one on the same date,
 * and ecb over bank-feed over manual on the same date and scope. A rate
 * older than the allowed age (app config `fx_max_rate_age_days`, five
 * calendar days by default, which covers weekends and ECB holidays) is not
 * used: the post is refused rather than booked at a guessed rate.
 *
 * The multiplication from transaction to base cents lives here too, so the
 * rounding rule is in one place.
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
 * @spec openspec/changes/banking-fx-at-booking/specs/bookkeeping-multi-currency/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Treasury;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use RuntimeException;
use Throwable;

/**
 * Resolves the booking-date exchange rate of a foreign-currency line.
 *
 * @spec openspec/changes/banking-fx-at-booking/specs/bookkeeping-multi-currency/spec.md
 */
class FxAtBookingResolver {

	/**
	 * App config key: how many calendar days old a rate may be.
	 *
	 * @var string
	 */
	public const CFG_MAX_RATE_AGE_DAYS = 'fx_max_rate_age_days';

	/**
	 * The default maximum rate age, in calendar days.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_RATE_AGE_DAYS = 5;

	/**
	 * The base currency when the administration names none.
	 *
	 * @var string
	 */
	public const DEFAULT_BASE_CURRENCY = 'EUR';

	/**
	 * Source precedence on the same date and scope: lower wins.
	 *
	 * @var array<string,int>
	 */
	private const SOURCE_RANK = [
		'ecb' => 0,
		'bank-feed' => 1,
		'manual' => 2,
	];

	/**
	 * Where the refusal tells the user to enter a rate.
	 *
	 * @var string
	 */
	private const RATES_PAGE = 'Bookkeeping > Multi-currency > FX rates';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object surface.
	 * @param IAppConfig             $appConfig     App config (register slug, maximum rate age).
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The administration's base (functional) currency, EUR when it names none.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return string ISO 4217 code.
	 *
	 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-2.1
	 */
	public function baseCurrencyFor(string $administrationId): string {
		try {
			$administration = ObjectIdentifier::findOne(
				scoped: $this->objectService->setRegister($this->register())->setSchema('Administration'),
				id: $administrationId,
				fallbackProperty: 'administrationCode'
			);
		} catch (Throwable) {
			$administration = null;
		}

		$currency = strtoupper(trim((string)($administration['functionalCurrency'] ?? '')));
		if ($currency === '') {
			return self::DEFAULT_BASE_CURRENCY;
		}

		return $currency;

	}//end baseCurrencyFor()

	/**
	 * The rate a line in the transaction currency is booked at on the date.
	 *
	 * @param string $transactionCurrency ISO 4217 code of the line.
	 * @param string $baseCurrency        ISO 4217 code of the administration.
	 * @param string $date                The booking date, Y-m-d.
	 * @param string $administrationId    The administration.
	 *
	 * @return array{rate:float,source:string,date:string}
	 *
	 * @throws RuntimeException When no rate is on or before the date, or the newest is too old.
	 *
	 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-1.1
	 */
	public function resolve(string $transactionCurrency, string $baseCurrency, string $date, string $administrationId): array {
		$pair = $transactionCurrency . ' to ' . $baseCurrency;
		$best = null;
		foreach ($this->candidates(transactionCurrency: $transactionCurrency, baseCurrency: $baseCurrency) as $rate) {
			if ($rate['date'] > $date || ($rate['administrationId'] !== '' && $rate['administrationId'] !== $administrationId)) {
				continue;
			}

			if ($best === null || $this->ranksAbove(candidate: $rate, best: $best) === true) {
				$best = $rate;
			}
		}

		if ($best === null) {
			throw new RuntimeException(
				sprintf('No %s exchange rate exists on or before %s. Enter one under %s, or type the rate on the line.', $pair, $date, self::RATES_PAGE)
			);
		}

		$age = $this->daysBetween(from: $best['date'], until: $date);
		$max = $this->maxAgeDays();
		if ($age > $max) {
			throw new RuntimeException(
				sprintf(
					'The newest %s exchange rate is dated %s, %d days before the booking date %s (at most %d allowed). '
					. 'Enter a rate under %s, or type the rate on the line.',
					$pair,
					$best['date'],
					$age,
					$date,
					$max,
					self::RATES_PAGE
				)
			);
		}

		return ['rate' => $best['rate'], 'source' => $best['source'], 'date' => $best['date']];

	}//end resolve()

	/**
	 * Transaction cents in base cents at a rate, rounded half up to whole cents.
	 *
	 * @param int   $cents The amount in transaction-currency cents.
	 * @param float $rate  The rate, transaction to base.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-2.1
	 */
	public static function toBaseCents(int $cents, float $rate): int {
		return (int)round(($cents * $rate), 0, PHP_ROUND_HALF_UP);

	}//end toBaseCents()

	/**
	 * Whether a candidate rate beats the best so far.
	 *
	 * Newer date first; on the same date an administration-scoped rate over a
	 * shared one; then ecb over bank-feed over manual.
	 *
	 * @param array{rate:float,source:string,date:string,administrationId:string} $candidate The candidate.
	 * @param array{rate:float,source:string,date:string,administrationId:string} $best      The best so far.
	 *
	 * @return bool
	 */
	private function ranksAbove(array $candidate, array $best): bool {
		if ($candidate['date'] !== $best['date']) {
			return $candidate['date'] > $best['date'];
		}

		$candidateScoped = ($candidate['administrationId'] !== '');
		$bestScoped      = ($best['administrationId'] !== '');
		if ($candidateScoped !== $bestScoped) {
			return $candidateScoped;
		}

		return (self::SOURCE_RANK[$candidate['source']] ?? 3) < (self::SOURCE_RANK[$best['source']] ?? 3);

	}//end ranksAbove()

	/**
	 * The usable FxRate rows of the pair: a positive rate and a date.
	 *
	 * @param string $transactionCurrency ISO 4217 code of the line.
	 * @param string $baseCurrency        ISO 4217 code of the administration.
	 *
	 * @return array<int,array{rate:float,source:string,date:string,administrationId:string}>
	 */
	private function candidates(string $transactionCurrency, string $baseCurrency): array {
		$rows = $this->objectService->setRegister($this->register())->setSchema('FxRate')->findAll(
			[
				'filters' => ['transactionCurrency' => $transactionCurrency, 'baseCurrency' => $baseCurrency],
				'limit' => 10000,
			]
		);

		$rates = [];
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record === null
				|| (string)($record['transactionCurrency'] ?? '') !== $transactionCurrency
				|| (string)($record['baseCurrency'] ?? '') !== $baseCurrency
			) {
				continue;
			}

			$rate = (float)($record['rate'] ?? 0);
			$date = substr((string)($record['date'] ?? ''), 0, 10);
			if ($rate <= 0.0 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
				continue;
			}

			$rates[] = [
				'rate' => $rate,
				'source' => (string)($record['source'] ?? 'manual'),
				'date' => $date,
				'administrationId' => (string)($record['administrationId'] ?? ''),
			];
		}//end foreach

		return $rates;

	}//end candidates()

	/**
	 * Calendar days from one date to a later one.
	 *
	 * @param string $from  The earlier date, Y-m-d.
	 * @param string $until The later date, Y-m-d.
	 *
	 * @return int
	 */
	private function daysBetween(string $from, string $until): int {
		return (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($until))->days;

	}//end daysBetween()

	/**
	 * The configured maximum rate age; a missing or non-positive setting is the default.
	 *
	 * @return int
	 */
	private function maxAgeDays(): int {
		$days = $this->appConfig->getValueInt(AppIdentity::APP_ID, self::CFG_MAX_RATE_AGE_DAYS, self::DEFAULT_MAX_RATE_AGE_DAYS);
		if ($days <= 0) {
			return self::DEFAULT_MAX_RATE_AGE_DAYS;
		}

		return $days;

	}//end maxAgeDays()

	/**
	 * The register slug, as the posting handler reads it.
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
