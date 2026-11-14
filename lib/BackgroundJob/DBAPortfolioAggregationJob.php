<?php

/**
 * Shillinq DBA monthly portfolio-aggregation background job.
 *
 * Computes DBAPortfolioRisico per active onderneming (REQ-DBA-005/006): aggregates
 * omzet-concentratie (12-mnd rolling), langjarige relaties, exclusiviteit-patronen
 * and multiple-engagement-zelfde-concern signals.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\BackgroundJob\Support\RunsJobAsSystem;
use OCA\Shillinq\Enums\DBAConstants;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Monthly DBA portfolio aggregation.
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class DBAPortfolioAggregationJob extends TimedJob {
	use RunsJobAsSystem;
	/**
	 * Interval between job runs: 30 days in seconds.
	 */
	private const INTERVAL_SECONDS = 2592000;

	/**
	 * The concentration of a portfolio without revenue.
	 *
	 * @var array{largestCustomer: null, revenueShare12m: float, thresholdHigh: float, status: string}
	 */
	private const NO_CONCENTRATION = [
		'largestCustomer' => null,
		'revenueShare12m' => 0.0,
		'thresholdHigh' => DBAConstants::CONCENTRATIE_DREMPEL_HOOG,
		'status' => 'VEILIG',
	];

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Nextcloud time factory.
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Compute the concentratie-aggregate for a set of opdrachten (REQ-DBA-005).
	 *
	 * @param array<int,array<string,mixed>> $assignments Per-opdracht rows containing
	 *                                                   `klantId` and `gerealiseerdeOmzet`
	 *                                                   (eurocenten).
	 *
	 * @return array<string,mixed> concentratie object { grootsteKlant, aandeelOmzet12mnd, drempelHoog, status }.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function computeConcentratie(array $assignments): array {
		$revenue = new DBACustomerRevenue(assignments: $assignments);
		if ($revenue->isEmpty() === true) {
			return self::NO_CONCENTRATION;
		}

		[$largestCustomer, $largestAmount] = $revenue->largest();
		$share = $largestAmount / $revenue->total;

		return [
			'largestCustomer' => $largestCustomer,
			'revenueShare12m' => round($share, 4),
			'thresholdHigh' => DBAConstants::CONCENTRATIE_DREMPEL_HOOG,
			'status' => $this->concentrationStatus(share: $share),
		];
	}//end computeConcentratie()

	/**
	 * Compute langjarige-relaties (REQ-DBA-005).
	 *
	 * @param array<int,array<string,mixed>> $assignments Per-opdracht rows with
	 *                                                   `klantId`, `startDatum`,
	 *                                                   `gerealiseerdeOmzet`.
	 * @param DateTimeImmutable $now Reference "now".
	 *
	 * @return array<int,array<string,mixed>> List of langjarige relaties.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function computeLangjarigeRelaties(array $assignments, DateTimeImmutable $now): array {
		$total = $this->totalRevenue(assignments: $assignments);
		if ($total <= 0) {
			return [];
		}

		$relationships = new DBAMultiYearRelationship(now: $now, total: $total);
		$result = [];
		foreach ($this->oldestStartPerCustomer(assignments: $assignments) as $customerId => $row) {
			$relationship = $relationships->describe(customerId: (string)$customerId, row: $row);
			if ($relationship !== null) {
				$result[] = $relationship;
			}
		}

		return $result;
	}//end computeLangjarigeRelaties()

	/**
	 * Compute overall risico-band from concentratie + langjarige relaties.
	 *
	 * @param array<string,mixed> $concentration The concentratie block.
	 * @param array<int,array<string,mixed>> $multiYearRelationships List of langjarige relaties.
	 *
	 * @return string LAAG / MIDDEN / HOOG.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function computeOverallRisico(array $concentration, array $multiYearRelationships): string {
		$status = (string)($concentration['status'] ?? 'VEILIG');
		if ($status === 'CRITICAL' || count($multiYearRelationships) >= 2) {
			return 'HIGH';
		}

		if ($status === 'WARNING' || count($multiYearRelationships) === 1) {
			return 'MEDIUM';
		}

		return 'LOW';
	}//end computeOverallRisico()

	/**
	 * Run the job inside OpenRegister's system context.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enforced-schema-authorization/specs/schema-authorization/spec.md
	 */
	protected function run(mixed $argument): void {
		$this->asSystem(work: function () use ($argument): void {
			$this->runAsJob(argument: $argument);
		});
	}//end run()

	/**
	 * Execute the aggregation pass.
	 *
	 * @param mixed $argument Not used; required by TimedJob.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	private function runAsJob(mixed $argument): void {
		$this->logger->info('Shillinq: DBAPortfolioAggregationJob started');

		$objectService = $this->openRegister();
		if ($objectService === null) {
			return;
		}

		$this->aggregate(run: new DBAJobRun(objectService: $objectService, appConfig: $this->appConfig));
	}//end runAsJob()

	/**
	 * Count exclusive relations (klanten that account for 100% of omzet) (REQ-DBA-005).
	 *
	 * @param array<int,array<string,mixed>> $assignments Per-opdracht rows.
	 *
	 * @return int The count of exclusive relations.
	 */
	private function countExclusief(array $assignments): int {
		$revenue = new DBACustomerRevenue(assignments: $assignments);
		if ($revenue->isEmpty() === true) {
			return 0;
		}

		return $revenue->exclusiveCount();
	}//end countExclusief()

	/**
	 * The concentration status of the largest customer's share.
	 *
	 * @param float|int $share The share of the largest customer.
	 *
	 * @return string
	 */
	private function concentrationStatus(float|int $share): string {
		if ($share >= DBAConstants::CONCENTRATIE_DREMPEL_KRITIEK) {
			return 'CRITICAL';
		}

		if ($share >= DBAConstants::CONCENTRATIE_DREMPEL_HOOG) {
			return 'WARNING';
		}

		return 'VEILIG';

	}//end concentrationStatus()

	/**
	 * Per customer, the oldest start date and the revenue counted from it.
	 *
	 * An older start replaces the customer's row (and its revenue); a later
	 * one adds its revenue.
	 *
	 * @param array<int,array<string,mixed>> $assignments The assignments.
	 *
	 * @return array<string|int,array{start: DateTimeImmutable, revenue: int}>
	 */
	private function oldestStartPerCustomer(array $assignments): array {
		$perCustomer = [];
		foreach ($assignments as $assignment) {
			$start = $this->startDate(assignment: $assignment);
			if ($start === null) {
				continue;
			}

			$customerId = (string)$assignment['customerId'];
			$perCustomer[$customerId] = self::keepOldest(
				current: ($perCustomer[$customerId] ?? null),
				candidate: ['start' => $start, 'revenue' => (int)($assignment['realisedRevenue'] ?? 0)],
			);
		}

		return $perCustomer;

	}//end oldestStartPerCustomer()

	/**
	 * Keep the row with the older start; a later start adds its revenue to the
	 * current row.
	 *
	 * @param array{start: DateTimeImmutable, revenue: int}|null $current The customer's row so far.
	 * @param array{start: DateTimeImmutable, revenue: int} $candidate The assignment's start and revenue.
	 *
	 * @return array{start: DateTimeImmutable, revenue: int}
	 */
	private static function keepOldest(?array $current, array $candidate): array {
		if ($current === null || $current['start'] > $candidate['start']) {
			return $candidate;
		}

		$current['revenue'] += $candidate['revenue'];
		return $current;

	}//end keepOldest()

	/**
	 * The total realised revenue of the assignments.
	 *
	 * @param array<int,array<string,mixed>> $assignments The assignments.
	 *
	 * @return int
	 */
	private function totalRevenue(array $assignments): int {
		$total = 0;
		foreach ($assignments as $assignment) {
			$total += (int)($assignment['realisedRevenue'] ?? 0);
		}

		return $total;

	}//end totalRevenue()

	/**
	 * The start of an assignment with a customer, null without either or when
	 * the date cannot be parsed.
	 *
	 * @param array<string,mixed> $assignment The assignment.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function startDate(array $assignment): ?DateTimeImmutable {
		$startStr = (string)($assignment['startDate'] ?? '');
		if ((string)($assignment['customerId'] ?? '') === '' || $startStr === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($startStr);
		} catch (Throwable) {
			return null;
		}

	}//end startDate()

	/**
	 * OpenRegister's object service, null (logged) when it is not installed.
	 *
	 * @return object|null
	 */
	private function openRegister(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq DBAPortfolioAggregationJob: OpenRegister not available, skipping.',
				['exception' => $e->getMessage()]
			);
			return null;
		}

	}//end openRegister()

	/**
	 * Write one portfolio per enterprise and log how many were written.
	 *
	 * @param DBAJobRun $run The run.
	 *
	 * @return void
	 */
	private function aggregate(DBAJobRun $run): void {
		$rows = $this->assignments(run: $run);
		if ($rows === null) {
			return;
		}

		foreach ($this->perEnterprise(run: $run, rows: $rows) as $assignments) {
			$this->writePortfolio(run: $run, assignments: $assignments);
		}

		$this->logger->info(
			sprintf('Shillinq DBAPortfolioAggregationJob: wrote %d portfolio records', $run->written)
		);

	}//end aggregate()

	/**
	 * Write the DBAPortfolioRisico of one enterprise; a failing write is logged.
	 *
	 * @param DBAJobRun $run The run.
	 * @param array<int,array<string,mixed>> $assignments The enterprise's assignments.
	 *
	 * @return void
	 */
	private function writePortfolio(DBAJobRun $run, array $assignments): void {
		try {
			$run->scoped(schema: 'DBAPortfolioRisico')->saveObject($this->portfolio(run: $run, assignments: $assignments));
			$run->written++;
		} catch (Throwable $e) {
			$this->logger->error(
				'Shillinq DBAPortfolioAggregationJob: failed to write portfolio',
				['enterpriseId' => self::enterpriseOf(assignments: $assignments), 'exception' => $e->getMessage()]
			);
		}

	}//end writePortfolio()

	/**
	 * The DBAPortfolioRisico object of one enterprise.
	 *
	 * @param DBAJobRun $run The run.
	 * @param array<int,array<string,mixed>> $assignments The enterprise's assignments.
	 *
	 * @return array<string,mixed>
	 */
	private function portfolio(DBAJobRun $run, array $assignments): array {
		$concentration = $this->computeConcentratie(assignments: $assignments);
		$langjarig = $this->computeLangjarigeRelaties(assignments: $assignments, now: $run->now);
		return [
			'administrationId' => (string)($assignments[0]['administrationId'] ?? ''),
			'enterpriseId' => self::enterpriseOf(assignments: $assignments),
			'levelDate' => $run->now->format('Y-m-d'),
			'activeAssignments' => count($assignments),
			'concentration' => $concentration,
			'multiYearRelationships' => $langjarig,
			'exclusiveRelationships' => $this->countExclusief(assignments: $assignments),
			'overallRisk' => $this->computeOverallRisico(concentration: $concentration, multiYearRelationships: $langjarig),
		];

	}//end portfolio()

	/**
	 * The enterprise a group of assignments belongs to.
	 *
	 * @param array<int,array<string,mixed>> $assignments The group, never empty.
	 *
	 * @return string
	 */
	private static function enterpriseOf(array $assignments): string {
		return (string)$assignments[0]['enterpriseId'];

	}//end enterpriseOf()

	/**
	 * Every assignment, null (logged) when they cannot be read.
	 *
	 * @param DBAJobRun $run The run.
	 *
	 * @return iterable<mixed>|null
	 */
	private function assignments(DBAJobRun $run): ?iterable {
		try {
			return $run->scoped(schema: 'DBAOpdracht')->findAll(['limit' => 5000]);
		} catch (Throwable $e) {
			$this->logger->error(
				'Shillinq DBAPortfolioAggregationJob: failed to fetch opdrachten',
				['exception' => $e->getMessage()]
			);
			return null;
		}

	}//end assignments()

	/**
	 * The assignments grouped by enterprise; rows without one are skipped.
	 *
	 * @param DBAJobRun $run The run.
	 * @param iterable<mixed> $rows The assignments as the service returned them.
	 *
	 * @return array<string|int,array<int,array<string,mixed>>>
	 */
	private function perEnterprise(DBAJobRun $run, iterable $rows): array {
		$perOnderneming = [];
		foreach ($rows as $row) {
			$assignment = $run->record(entity: $row);
			$ondernemingId = (string)($assignment['enterpriseId'] ?? '');
			if ($assignment === null || $ondernemingId === '') {
				continue;
			}

			$perOnderneming[$ondernemingId] ??= [];
			$perOnderneming[$ondernemingId][] = $assignment;
		}

		return $perOnderneming;

	}//end perEnterprise()

}//end class
