<?php

/**
 * Financial Dashboard Service
 *
 * Server-side data layer for the Wave-4 financial dashboard endpoints
 * (financial-series + financial-summary). Replaces the client-side
 * useFinancialData.js fetch layer: each schema the dashboard consumes
 * (Account, GLTransaction, GLLine, UrenRegistratie, ARInvoice,
 * APTransaction) is read once per request via OpenRegister's ObjectService —
 * WITHOUT a limit, so ALL matching objects contribute (the client's
 * 2000-object `_limit` truncation is the bug this fixes). A failing schema
 * resolves to an empty list (and is logged) so one missing schema cannot
 * blank the whole dashboard, mirroring the client's per-schema resilience.
 *
 * Reads are delegated to OpenRegister's ObjectService, which enforces RBAC
 * and multitenancy (ADR-005, ADR-022) — the endpoints see exactly the
 * objects the authenticated user could already fetch through
 * /apps/openregister/api/objects. All arithmetic lives in the pure
 * FinancialSeriesCalculator so the numbers match the client-side widgets.
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
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Assembles the financial-series and financial-summary endpoint payloads.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */
class FinancialDashboardService {
	/**
	 * Trailing months used when no explicit from/to range is given
	 * (mirrors the client widgets' FALLBACK_MONTHS).
	 *
	 * @var int
	 */
	private const FALLBACK_MONTHS = 12;

	/**
	 * Schemas the monthly series endpoint consumes, keyed by the bag key the
	 * calculator expects (mirrors useFinancialData.js SCHEMAS).
	 *
	 * @var array<string,string>
	 */
	private const SERIES_SCHEMAS = [
		'accounts' => 'Account',
		'transactions' => 'GLTransaction',
		'lines' => 'GLLine',
		'hourEntries' => 'UrenRegistratie',
	];

	/**
	 * Schemas the KPI summary endpoint consumes (the series schemas plus the
	 * open AR/AP sources).
	 *
	 * @var array<string,string>
	 */
	private const SUMMARY_SCHEMAS = [
		'accounts' => 'Account',
		'transactions' => 'GLTransaction',
		'lines' => 'GLLine',
		'hourEntries' => 'UrenRegistratie',
		'arInvoices' => 'ARInvoice',
		'apTransactions' => 'APTransaction',
	];

	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched lazily.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param FinancialSeriesCalculator $calculator Pure financial-series arithmetic (port of financialSeries.js).
	 * @param LoggerInterface $logger Nextcloud logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly FinancialSeriesCalculator $calculator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Monthly financial series over the resolved month window: turnover,
	 * costs, margin (EUR + %), billable vs non-billable hours, billable
	 * percentage and realized cashflow, one entry per month key.
	 *
	 * @param string|null $from Optional ISO-8601 lower bound.
	 * @param string|null $to Optional ISO-8601 upper bound.
	 * @param DateTimeImmutable|null $now Reference date (defaults to the current time).
	 *
	 * @return array<string,mixed> The series payload (months + nine parallel arrays).
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function series(?string $from, ?string $to, ?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable());
		$months = $this->resolveMonths(from: $from, to: $to, now: $now);
		$data = $this->fetchSchemas(schemas: self::SERIES_SCHEMAS);

		$series = $this->calculator->monthlyFinancialSeries(
			input: [
				'accounts' => $data['accounts'],
				'transactions' => $data['transactions'],
				'lines' => $data['lines'],
				'months' => $months,
			]
		);
		$billable = $this->calculator->billableSeries(entries: $data['hourEntries'], months: $months);

		return [
			'months' => $series['months'],
			'revenue' => $series['revenue'],
			'costs' => $series['costs'],
			'margin' => $series['margin'],
			'marginPct' => $series['marginPct'],
			'billableHours' => $billable['billable'],
			'nonBillableHours' => $billable['nonBillable'],
			'billablePct' => $billable['pct'],
			'cashIn' => $series['cashIn'],
			'cashOut' => $series['cashOut'],
			'cashNet' => $series['cashNet'],
		];

	}//end series()

	/**
	 * KPI summary over the resolved month window with previous-period trend
	 * values. The range-driven metrics (turnover, margin + marginPct,
	 * billableHours + billablePct) are computed for the current window AND
	 * for the window of the same length immediately before it. The
	 * point-in-time metrics (openDebtors, openCreditors, cashPosition
	 * all-time) do not vary by range — matching the client's
	 * computeKpis/computeRangeKpis split — so they appear under `current`
	 * only.
	 *
	 * @param string|null $from Optional ISO-8601 lower bound.
	 * @param string|null $to Optional ISO-8601 upper bound.
	 * @param DateTimeImmutable|null $now Reference date (defaults to the current time).
	 *
	 * @return array<string,mixed> The summary payload (months, previousMonths, current, previousPeriod).
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function summary(?string $from, ?string $to, ?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable());
		$months = $this->resolveMonths(from: $from, to: $to, now: $now);
		$previousMonths = $this->previousMonths(months: $months);
		$data = $this->fetchSchemas(schemas: self::SUMMARY_SCHEMAS);

		$current = $this->calculator->computeRangeKpis(data: $data, months: $months);
		$previous = $this->calculator->computeRangeKpis(data: $data, months: $previousMonths);
		$pointInTime = $this->calculator->computeKpis(data: $data, now: $now);

		return [
			'months' => $months,
			'previousMonths' => $previousMonths,
			'current' => [
				'turnover' => $current['turnover'],
				'margin' => $current['margin'],
				'marginPct' => $current['marginPct'],
				'billableHours' => $current['billableHours'],
				'billablePct' => $current['billablePct'],
				'openDebtors' => [
					'count' => $pointInTime['openArCount'],
					'amount' => $pointInTime['openArAmount'],
				],
				'openCreditors' => [
					'count' => $pointInTime['openApCount'],
					'amount' => $pointInTime['openApAmount'],
				],
				'cashPosition' => $pointInTime['cashPosition'],
			],
			'previousPeriod' => [
				'turnover' => $previous['turnover'],
				'margin' => $previous['margin'],
				'marginPct' => $previous['marginPct'],
				'billableHours' => $previous['billableHours'],
				'billablePct' => $previous['billablePct'],
			],
		];

	}//end summary()

	/**
	 * Month window for a from/to pair: the months spanned by the range when
	 * both bounds parse (capped at 60), otherwise the trailing 12 months
	 * ending at `now` — exactly the client widgets' fallback behaviour.
	 *
	 * @param string|null $from Optional ISO-8601 lower bound.
	 * @param string|null $to Optional ISO-8601 upper bound.
	 * @param DateTimeImmutable $now Reference date for the fallback window.
	 *
	 * @return array<int,string> Month keys, ascending.
	 */
	private function resolveMonths(?string $from, ?string $to, DateTimeImmutable $now): array {
		if ($from !== null && $from !== '' && $to !== null && $to !== '') {
			$months = $this->calculator->monthsInRange(from: $from, to: $to);
			if ($months !== []) {
				return $months;
			}
		}

		return $this->calculator->lastMonths(count: self::FALLBACK_MONTHS, now: $now);
	}//end resolveMonths()

	/**
	 * The window of the same length immediately before `months`: for
	 * ['2026-03','2026-04'] this is ['2026-01','2026-02'].
	 *
	 * @param array<int,string> $months Current month window, ascending.
	 *
	 * @return array<int,string> Previous month window, ascending.
	 */
	private function previousMonths(array $months): array {
		$count = count($months);
		if ($count === 0 || preg_match('/^(\d{4})-(\d{2})$/', $months[0], $matches) !== 1) {
			return [];
		}

		$firstIndex = ((((int)$matches[1]) * 12) + ((int)$matches[2] - 1));
		$previous = [];
		for ($i = $count; $i >= 1; $i--) {
			$index = ($firstIndex - $i);
			$previous[] = sprintf('%04d-%02d', intdiv($index, 12), (($index % 12) + 1));
		}

		return $previous;
	}//end previousMonths()

	/**
	 * Fetch every schema in the map, keyed by its calculator bag key. Each
	 * schema resolves to a list of plain object arrays; a failing schema
	 * resolves to an empty list.
	 *
	 * @param array<string,string> $schemas Bag key => schema slug.
	 *
	 * @return array<string,array<int,array<string,mixed>>> Bag key => objects.
	 */
	private function fetchSchemas(array $schemas): array {
		$data = [];
		foreach ($schemas as $key => $schema) {
			$data[$key] = $this->fetchSchema(schema: $schema);
		}

		return $data;
	}//end fetchSchemas()

	/**
	 * Fetch ALL objects of one schema via OpenRegister's ObjectService. No
	 * limit is passed, so the query is unbounded — the whole point of moving
	 * this computation server-side (the client capped at 2000 objects per
	 * schema). RBAC + multitenancy stay enforced by ObjectService itself.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return array<int,array<string,mixed>> The objects as plain arrays.
	 */
	private function fetchSchema(string $schema): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$records = $objectService
				->setRegister($this->getRegisterSlug())
				->setSchema($schema)
				->findAll([]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'FinancialDashboardService: schema fetch failed',
				[
					'schema' => $schema,
					'exception' => $e->getMessage(),
				]
			);
			return [];
		}

		$rows = [];
		foreach ($records as $record) {
			$row = $this->toArray(record: $record);
			if ($row !== null) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end fetchSchema()

	/**
	 * Normalise an OR record to an array.
	 *
	 * @param mixed $record The record (object or array).
	 *
	 * @return array<string,mixed>|null The array form, or null.
	 */
	private function toArray(mixed $record): ?array {
		if (is_object($record) === true && method_exists($record, 'jsonSerialize') === true) {
			$record = $record->jsonSerialize();
		}

		if (is_array($record) === false) {
			return null;
		}

		return $record;
	}//end toArray()

	/**
	 * Resolve OR's ObjectService, or null when OpenRegister is unavailable.
	 *
	 * @return object|null The ObjectService or null.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'FinancialDashboardService: ObjectService unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()
}//end class
