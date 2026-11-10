<?php

/**
 * Administration Results Service
 *
 * Runs the declared OpenRegister aggregations behind the commitments and
 * segment result screens, always narrowed to one administration, and lists
 * the commitment lines and fiscal periods of that administration.
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
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Administration-scoped commitment and segment results.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
class AdministrationResultsService {

	/**
	 * The segment types and the GLLine aggregation declared for each.
	 *
	 * @var array<string, string>
	 */
	public const SEGMENT_AGGREGATIONS = [
		'costCenter'          => 'byCostCenter',
		'costCenterHierarchy' => 'byCostCenterHierarchy',
		'costObject'          => 'byCostObject',
		'project'             => 'byProject',
		'analyticalDimension' => 'byAnalyticalDimension',
	];

	/**
	 * The commitment line properties a drill-down may filter on.
	 *
	 * @var list<string>
	 */
	public const COMMITMENT_LINE_FILTERS = ['programme', 'costCentre', 'financialYear', 'generalLedgerAccount'];

	/**
	 * Page size for the object lists.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * OpenRegister's aggregation runner, resolved at runtime.
	 *
	 * @var string
	 */
	private const AGGREGATION_RUNNER = 'OCA\OpenRegister\Service\Aggregation\AggregationRunner';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface     $container     Resolves OpenRegister's aggregation runner.
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param SettingsService        $settings      Resolves the register slug.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
	) {

	}//end __construct()

	/**
	 * Authorised, committed and realised amounts per budget line of one administration.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, mixed> The aggregation envelope.
	 *
	 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
	 */
	public function commitmentsPerBudgetLine(string $administrationId): array {
		return $this->aggregate(
			schema: 'CommitmentLine',
			name: 'committedVsRealisedPerBudgetLine',
			filter: ['administrationId' => $administrationId]
		);

	}//end commitmentsPerBudgetLine()

	/**
	 * The commitment lines of one administration matching a budget line.
	 *
	 * @param string                $administrationId The administration.
	 * @param array<string, string> $budgetLine       Values for COMMITMENT_LINE_FILTERS; others are ignored.
	 *
	 * @return list<array<string, mixed>> The lines.
	 *
	 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
	 */
	public function commitmentLines(string $administrationId, array $budgetLine): array {
		$filters = [];
		foreach (self::COMMITMENT_LINE_FILTERS as $property) {
			$value = trim((string)($budgetLine[$property] ?? ''));
			if ($value !== '') {
				$filters[$property] = $value;
			}
		}

		$filters['administrationId'] = $administrationId;

		return $this->rows(schema: 'CommitmentLine', filters: $filters);

	}//end commitmentLines()

	/**
	 * Revenue, costs and result per segment of one administration.
	 *
	 * @param string $administrationId The administration.
	 * @param string $segment          A key of SEGMENT_AGGREGATIONS.
	 * @param string $periodId         A FiscalPeriod periodId, or '' for every period.
	 *
	 * @return array<string, mixed> The aggregation envelope.
	 *
	 * @throws InvalidArgumentException On an unknown segment.
	 *
	 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
	 */
	public function segmentResults(string $administrationId, string $segment, string $periodId): array {
		$name = (self::SEGMENT_AGGREGATIONS[$segment] ?? null);
		if ($name === null) {
			throw new InvalidArgumentException('Unknown segment: '.$segment);
		}

		$filter = ['administrationId' => $administrationId];
		if ($periodId !== '') {
			$filter['periodId'] = $periodId;
		}

		return $this->aggregate(schema: 'GLLine', name: $name, filter: $filter);

	}//end segmentResults()

	/**
	 * The fiscal periods of one administration.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return list<array{periodId: string, name: string, startDate: string}> The periods.
	 *
	 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
	 */
	public function fiscalPeriods(string $administrationId): array {
		$periods = [];
		foreach ($this->rows(schema: 'FiscalPeriod', filters: ['administrationId' => $administrationId]) as $period) {
			$periodId = (string)($period['periodId'] ?? '');
			if ($periodId === '') {
				continue;
			}

			$periods[] = [
				'periodId'  => $periodId,
				'name'      => (string)($period['name'] ?? ''),
				'startDate' => (string)($period['startDate'] ?? ''),
			];
		}

		return $periods;

	}//end fiscalPeriods()

	/**
	 * Run a declared aggregation narrowed by the given filter.
	 *
	 * @param string               $schema The schema the aggregation is declared on.
	 * @param string               $name   The aggregation name.
	 * @param array<string, mixed> $filter The narrowing filter; it can add constraints, never relax declared ones.
	 *
	 * @return array<string, mixed> The aggregation envelope.
	 *
	 * @throws RuntimeException When OpenRegister's runner is unavailable.
	 */
	private function aggregate(string $schema, string $name, array $filter): array {
		$runner = $this->container->get(self::AGGREGATION_RUNNER);
		if (is_object($runner) === false || method_exists($runner, 'run') === false) {
			throw new RuntimeException('OpenRegister aggregation runner is unavailable');
		}

		$result = $runner->run(
			registerRef: $this->settings->getRegisterSlug(),
			schemaRef: $schema,
			name: $name,
			extraFilter: $filter
		);
		if (is_array($result) === false) {
			return [];
		}

		return $result;

	}//end aggregate()

	/**
	 * Every row of a schema matching the filters, read in pages.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Equality filters.
	 *
	 * @return list<array<string, mixed>> The rows as payload arrays.
	 */
	private function rows(string $schema, array $filters): array {
		$rows   = [];
		$offset = 0;
		do {
			$page = $this->objectService
				->setRegister($this->settings->getRegisterSlug())
				->setSchema($schema)
				->findAll(['limit' => self::PAGE_SIZE, 'offset' => $offset, 'filters' => $filters]);
			foreach ($page as $row) {
				if ($row instanceof JsonSerializable) {
					$row = $row->jsonSerialize();
				}

				if (is_array($row) === true) {
					$rows[] = $row;
				}
			}

			$offset  += self::PAGE_SIZE;
			$fullPage = (count($page) >= self::PAGE_SIZE);
		} while ($fullPage === true);

		return $rows;

	}//end rows()
}//end class
