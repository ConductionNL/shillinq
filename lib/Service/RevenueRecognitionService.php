<?php

/**
 * Revenue Recognition Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ADR-031 exception service for the order-revenue-recognition chain. Computes
 * recognized RECURRING revenue for a runtime reporting period [from, to] as the
 * whole-month-overlap-prorated, frequency-normalized sum of a SalesOrder's
 * RECURRING SalesOrderLines (IFRS 15 over-time recognition), and the separate
 * one-off figure (POINT_IN_TIME recognized in full when recognitionDate ∈ period;
 * OVER_TIME prorated across the line's own term). ONE_OFF lines are NEVER folded
 * into the recurring figure. An annualized ARR run-rate is derived alongside.
 *
 * The metric is an ADR-031 exception because OpenRegister's declarative
 * calculations / aggregations grammar cannot express interval-overlap proration
 * parameterized by a runtime reporting window; the head change's design.md proves
 * the missing primitives. The arithmetic is confined here (mirroring the
 * EmuCalculator guard precedent) — no per-row overlap reducer is added to OR core.
 *
 * Reads go exclusively through OpenRegister's ObjectService (ADR-022 — no
 * app-owned tables, no direct SQL), filtered by administrationId so OR enforces
 * multitenancy and no cross-administration read is possible. Money is handled in
 * integer euro-cents internally to avoid IEEE-754 drift and rounded once at the
 * boundary.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Computes recognized recurring revenue for a runtime period over SalesOrderLines.
 *
 * The arithmetic helpers (frequencyFactor / monthlyRate / overlapMonths) are pure
 * and side-effect free so the unit tests exercise them deterministically (no clock,
 * no I/O). The ObjectService read is the only external dependency and is filtered by
 * administrationId (ADR-005 / ADR-022).
 *
 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-1
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class RevenueRecognitionService {

	/**
	 * Per-month normalization factors keyed by frequentie enum.
	 *
	 * @var array<string,float>
	 */
	private const FREQUENCY_FACTORS = [
		'MONTHLY' => 1.0,
		'QUARTERLY' => (1.0 / 3.0),
		'ANNUALLY' => (1.0 / 12.0),
		'WEEKLY' => (52.0 / 12.0),
		'FORTNIGHTLY' => (26.0 / 12.0),
	];

	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched lazily.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute recognized recurring revenue for one administration + period (REQ engine-001..004).
	 *
	 * Returns recognized RECURRING revenue (whole-month overlap × frequency-normalized
	 * monthly rate), the separate one-off figure, the annualized ARR run-rate, the
	 * currency and the count of RECURRING lines folded. Empty input yields a clean zero,
	 * never an exception. A RECURRING line with a null/unknown frequentie contributes 0
	 * and is logged (fail-closed).
	 *
	 * @param string $administrationId Administration scope (validated + RBAC-enforced by the caller).
	 * @param string $from ISO period start (YYYY-MM-DD).
	 * @param string $to ISO period end (YYYY-MM-DD).
	 *
	 * @return array{recognized:float, oneOff:float, arr:float, currency:string, lineCount:int}
	 *
	 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-1
	 */
	public function computeRecurring(string $administrationId, string $from, string $to): array {
		$run = new RecognitionRun();
		$run->period = $this->term(start: $from, end: $to);
		$run->orders = $this->fetchOrders(administrationId: $administrationId);
		foreach ($this->fetchLines(administrationId: $administrationId) as $line) {
			$this->foldLine(run: $run, line: $line);
		}

		return [
			'recognized' => $this->fromCents(cents: $run->recognizedCents),
			'oneOff' => $this->fromCents(cents: $run->oneOffCents),
			'arr' => $this->fromCents(cents: ($run->arrCents * 12)),
			'currency' => $run->currency,
			'lineCount' => $run->lineCount,
		];
	}//end computeRecurring()

	/**
	 * Fold one line into the run: take its order's currency, resolve its term
	 * and add it as RECURRING or ONE_OFF revenue (any other nature adds nothing).
	 *
	 * @param RecognitionRun      $run  The run.
	 * @param array<string,mixed> $line One SalesOrderLine.
	 *
	 * @return void
	 */
	private function foldLine(RecognitionRun $run, array $line): void {
		$order = ($run->orders[(string)($line['orderId'] ?? '')] ?? []);
		$run->currency = ($this->orderCurrency(order: $order) ?? $run->currency);
		$item = new RecognitionLine();
		$item->line = $line;
		$item->term = $this->effectiveTerm(line: $line, order: $order, periodTo: $run->period->end);
		$nature = (string)($line['nature'] ?? '');
		if ($nature === 'RECURRING') {
			$this->foldRecurring(run: $run, item: $item);
		}

		if ($nature === 'ONE_OFF') {
			$run->oneOffCents += $this->oneOffCents(item: $item, period: $run->period);
		}
	}//end foldLine()

	/**
	 * An order's currency, or null when it names none.
	 *
	 * @param array<string,mixed> $order The parent SalesOrder (may be empty).
	 *
	 * @return string|null
	 */
	private function orderCurrency(array $order): string|null {
		if (isset($order['currency']) === true && (string)$order['currency'] !== '') {
			return (string)$order['currency'];
		}

		return null;
	}//end orderCurrency()

	/**
	 * Add a RECURRING line: its monthly rate times the months of overlap, and
	 * — when its term contains the period end — to the ARR run-rate.
	 *
	 * @param RecognitionRun  $run  The run.
	 * @param RecognitionLine $item The line and its term.
	 *
	 * @return void
	 */
	private function foldRecurring(RecognitionRun $run, RecognitionLine $item): void {
		$monthlyRateCents = $this->monthlyRateCents(line: $item->line);
		$run->recognizedCents += ($monthlyRateCents * $this->overlapMonths(term: $item->term, period: $run->period));
		$run->lineCount++;
		if ($this->contains(term: $item->term, date: $run->period->end) === true) {
			$run->arrCents += $monthlyRateCents;
		}
	}//end foldRecurring()

	/**
	 * A date interval.
	 *
	 * @param string $start Interval start (ISO date), or ''.
	 * @param string $end   Interval end (ISO date), or ''.
	 *
	 * @return RecognitionTerm
	 */
	private function term(string $start, string $end): RecognitionTerm {
		$term = new RecognitionTerm();
		$term->start = $start;
		$term->end = $end;

		return $term;
	}//end term()

	/**
	 * Fetch the administration's SalesOrders keyed by orderId (ADR-022).
	 *
	 * Returns an empty map when the schema/register is absent (the service fail-closes
	 * to zero rather than throwing when the head's schemas are not yet present).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,array<string,mixed>> orderId => SalesOrder object.
	 */
	private function fetchOrders(string $administrationId): array {
		$orders = $this->findAll(schema: 'SalesOrder', administrationId: $administrationId);
		$byOrder = [];
		foreach ($orders as $order) {
			$orderId = (string)($order['orderId'] ?? '');
			if ($orderId !== '') {
				$byOrder[$orderId] = $order;
			}
		}

		return $byOrder;
	}//end fetchOrders()

	/**
	 * Fetch the administration's SalesOrderLines (ADR-022).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,array<string,mixed>> SalesOrderLine objects.
	 */
	private function fetchLines(string $administrationId): array {
		return $this->findAll(schema: 'SalesOrderLine', administrationId: $administrationId);
	}//end fetchLines()

	/**
	 * Read all objects of a schema for an administration via OpenRegister's ObjectService.
	 *
	 * Fail-closed: any read failure (schema absent before the head merges, OR
	 * unavailable) is logged and treated as "no objects" → the caller computes a clean
	 * zero instead of surfacing an error.
	 *
	 * @param string $schema Schema slug to read.
	 * @param string $administrationId Administration scope filter.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, string $administrationId): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService->setRegister($this->register())->setSchema($schema)->findAll(['filters' => ['administrationId' => $administrationId]]);

			return array_values((array)$rows);
		} catch (\Throwable $e) {
			$context = ['schema' => $schema, 'administrationId' => $administrationId, 'exception' => $e->getMessage()];
			$this->logger->warning('RevenueRecognitionService: SalesOrder data unavailable; treating as empty', $context);

			return [];
		}

	}//end findAll()

	/**
	 * Resolve the effective [termStart, termEnd] of a line, inheriting the order term.
	 *
	 * Null line bounds inherit the parent order's bounds; an open-ended termEnd (null on
	 * both line and order) extends to the period end for the overlap computation.
	 *
	 * @param array<string,mixed> $line The SalesOrderLine.
	 * @param array<string,mixed> $order The parent SalesOrder (may be empty).
	 * @param string $periodTo ISO period end used to bound an indefinite term.
	 *
	 * @return RecognitionTerm [termStart, termEnd] as ISO dates.
	 */
	private function effectiveTerm(array $line, array $order, string $periodTo): RecognitionTerm {
		$termStart = $this->firstDate(values: [($line['termStart'] ?? null), ($order['termStart'] ?? null)]);
		$termEnd = $this->firstDate(values: [($line['termEnd'] ?? null), ($order['termEnd'] ?? null)]);

		if ($termEnd === '') {
			// Indefinite term: extend to the reporting window end (report-bounded).
			$termEnd = $periodTo;
		}

		return $this->term(start: $termStart, end: $termEnd);
	}//end effectiveTerm()

	/**
	 * Return the first non-empty ISO date from a list of candidate values.
	 *
	 * @param array<int,mixed> $values Candidate values (line bound, order bound).
	 *
	 * @return string The first non-empty date, or '' when none is set.
	 */
	private function firstDate(array $values): string {
		foreach ($values as $value) {
			$date = trim((string)($value ?? ''));
			if ($date !== '') {
				return $date;
			}
		}

		return '';
	}//end firstDate()

	/**
	 * Compute a recurring line's monthly rate in integer euro-cents.
	 *
	 * The monthly rate = amount × frequencyFactor(frequentie). A null/unknown frequentie
	 * is a data error → contributes 0 and is logged (fail-closed; never throws).
	 *
	 * @param array<string,mixed> $line The RECURRING SalesOrderLine.
	 *
	 * @return int Monthly rate in whole cents.
	 */
	private function monthlyRateCents(array $line): int {
		$frequency = (string)($line['frequency'] ?? '');
		if (isset(self::FREQUENCY_FACTORS[$frequency]) === false) {
			$this->logger->warning(
				'RevenueRecognitionService: RECURRING line with null/unknown frequentie contributes 0',
				[
					'lineId' => (string)($line['lineId'] ?? ''),
					'frequency' => $frequency,
				]
			);

			return 0;
		}

		$amountCents = $this->toCents(amount: ($line['amount'] ?? 0));

		return (int)round($amountCents * self::FREQUENCY_FACTORS[$frequency]);
	}//end monthlyRateCents()

	/**
	 * Recognize a one-off line in integer cents for the period (separate from recurring).
	 *
	 * POINT_IN_TIME → full amount when recognitionDate ∈ [from, to], else 0.
	 * OVER_TIME → amount × overlapMonths(term, period) / totalTermMonths(term); 0 when the
	 * total term length is 0 (degenerate).
	 *
	 * @param RecognitionLine $item The ONE_OFF line and its effective term.
	 * @param RecognitionTerm $period The reporting period.
	 *
	 * @return int Recognized one-off amount in whole cents.
	 */
	private function oneOffCents(RecognitionLine $item, RecognitionTerm $period): int {
		if ((string)($item->line['recognitionMethod'] ?? '') === 'POINT_IN_TIME') {
			return $this->pointInTimeCents(line: $item->line, period: $period);
		}

		// OVER_TIME: prorate across the line's own term.
		$totalTerm = $this->overlapMonths(term: $item->term, period: $item->term);
		if ($totalTerm <= 0) {
			return 0;
		}

		$amountCents = $this->toCents(amount: ($item->line['amount'] ?? 0));

		return (int)round(($amountCents * $this->overlapMonths(term: $item->term, period: $period)) / $totalTerm);
	}//end oneOffCents()

	/**
	 * A POINT_IN_TIME line's full amount when its recognitionDate lies in the
	 * period, else 0.
	 *
	 * @param array<string,mixed> $line   The ONE_OFF SalesOrderLine.
	 * @param RecognitionTerm     $period The reporting period.
	 *
	 * @return int Whole cents.
	 */
	private function pointInTimeCents(array $line, RecognitionTerm $period): int {
		$recognitionDate = trim((string)($line['recognitionDate'] ?? ''));
		if ($recognitionDate !== '' && $recognitionDate >= $period->start && $recognitionDate <= $period->end) {
			return $this->toCents(amount: ($line['amount'] ?? 0));
		}

		return 0;
	}//end pointInTimeCents()

	/**
	 * Count whole calendar months of intersection between a term and a period (D5).
	 *
	 * Whole-month proration: a term touching any part of a calendar month counts that
	 * whole month. Returns 0 when the intervals do not overlap or a bound is missing.
	 *
	 * @param RecognitionTerm $term The term.
	 * @param RecognitionTerm $period The period.
	 *
	 * @return int Whole calendar months of overlap (>= 0).
	 */
	private function overlapMonths(RecognitionTerm $term, RecognitionTerm $period): int {
		if ($this->isUnbounded(term: $term) === true || $this->isUnbounded(term: $period) === true) {
			return 0;
		}

		return $this->monthsSpanned(term: $this->term(start: max($term->start, $period->start), end: min($term->end, $period->end)));
	}//end overlapMonths()

	/**
	 * Whether an interval misses a bound.
	 *
	 * @param RecognitionTerm $term The interval.
	 *
	 * @return boolean
	 */
	private function isUnbounded(RecognitionTerm $term): bool {
		return $term->start === '' || $term->end === '';
	}//end isUnbounded()

	/**
	 * Whole calendar months an interval touches; 0 when it is empty or a bound
	 * does not parse.
	 *
	 * @param RecognitionTerm $term The intersection.
	 *
	 * @return int
	 */
	private function monthsSpanned(RecognitionTerm $term): int {
		$start = $this->yearMonth(date: $term->start);
		$end = $this->yearMonth(date: $term->end);
		if ($term->start > $term->end || $start === null || $end === null) {
			return 0;
		}

		return max(0, ((($end['year'] - $start['year']) * 12) + ($end['month'] - $start['month']) + 1));
	}//end monthsSpanned()

	/**
	 * Whether an ISO date falls within [start, end] inclusive.
	 *
	 * @param RecognitionTerm $term The interval.
	 * @param string $date Candidate date (ISO date).
	 *
	 * @return bool True when start <= date <= end and all bounds are present.
	 */
	private function contains(RecognitionTerm $term, string $date): bool {
		if ($date === '' || $this->isUnbounded(term: $term) === true) {
			return false;
		}

		return ($date >= $term->start && $date <= $term->end);
	}//end contains()

	/**
	 * Parse the year + month from an ISO date (YYYY-MM-DD).
	 *
	 * @param string $date ISO date string.
	 *
	 * @return array{year:int,month:int}|null Parsed components, or null when unparseable.
	 */
	private function yearMonth(string $date): ?array {
		if (preg_match('/^(\d{4})-(\d{2})/', $date, $matches) !== 1) {
			return null;
		}

		return [
			'year' => (int)$matches[1],
			'month' => (int)$matches[2],
		];

	}//end yearMonth()

	/**
	 * Convert a money amount to integer cents.
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole cents.
	 */
	private function toCents(mixed $amount): int {
		return (int)round((float)($amount ?? 0) * 100);
	}//end toCents()

	/**
	 * Convert integer cents back to a float money amount.
	 *
	 * @param int $cents Amount in whole cents.
	 *
	 * @return float Money amount.
	 */
	private function fromCents(int $cents): float {
		return ($cents / 100);
	}//end fromCents()

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
