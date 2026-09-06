<?php

/**
 * Subject Cost Service
 *
 * The link that was missing between the hours and the money. Reads the
 * `UrenRegistratie` rows booked against one domain object, resolves a wage
 * rate per person through {@see HrmqCostRateAdapter}, and hands both to
 * {@see SubjectCostAggregator}.
 *
 * Until this class existed the aggregator and the rate adapter were complete,
 * unit-tested and unreachable: nothing read an hour set for a subject, so
 * neither could ever be called. The capability was specified, implemented and
 * dead.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Compose an employer cost for one domain object from its booked hours.
 *
 * WHY THE ADMINISTRATION SCOPE IS APPLIED HERE AND NOT LEFT TO THE READ.
 *
 * `FinancialDashboardService` reads its schemas with an unfiltered
 * `findAll([])` and relies on a claim that OpenRegister scopes the rows; that
 * claim does not hold, which is why `FinancialDashboardController` had to
 * grow a membership guard to close the sharpest sub-case. This class does not
 * repeat that: every row is checked against the administrations the caller can
 * actually reach, and a row carrying no `administrationId` at all is excluded
 * rather than assumed to be in scope.
 *
 * An excluded row is not silently forgotten. `unscopedRowsExcluded` reports
 * how many rows were dropped for having no administration, because a subject
 * whose hours cannot be attributed is a data defect the caller should see,
 * not a smaller number it should trust.
 *
 * @spec openspec/specs/subject-cost-aggregation/spec.md
 */
class SubjectCostService {
	/**
	 * The schema holding booked hours.
	 *
	 * @var string
	 */
	private const HOURS_SCHEMA = 'UrenRegistratie';

	/**
	 * Wire collaborators.
	 *
	 * @param ContainerInterface $container Container, for the lazy ObjectService resolve.
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param HrmqCostRateAdapter $rates Wage-rate resolution over hrmq.
	 * @param SubjectCostAggregator $aggregator Pure aggregation policy.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly HrmqCostRateAdapter $rates,
		private readonly SubjectCostAggregator $aggregator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The employer cost of one domain object's booked hours.
	 *
	 * @param string $subjectApp The owning app, as written on the hour row.
	 * @param string $subjectId The domain object id, as written on the hour row.
	 * @param array<int, string>|null $administrationIds Administrations the caller
	 *     may read, or null for an unrestricted (Nextcloud admin) read.
	 * @param string $period Costing period `YYYY-MM`, or '' for the current rate.
	 *
	 * @return array{
	 *     subjectApp: string, subjectId: string, hours: float, costCents: int|null,
	 *     complete: bool, currency: string, unscopedRowsExcluded: int,
	 *     perPerson: array<int, array{personId: string, hours: float, centsPerHour: int|null, costCents: int|null}>,
	 *     unpricedPersonIds: array<int, string>
	 * } The aggregate, echoing the subject it describes.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-subject-cost-is-reachable-over-http
	 */
	public function costFor(
		string $subjectApp,
		string $subjectId,
		?array $administrationIds,
		string $period = '',
	): array {
		$rows = $this->hourRows(subjectApp: $subjectApp, subjectId: $subjectId);

		$inScope = [];
		$unscoped = 0;
		foreach ($rows as $row) {
			$administrationId = trim((string)($row['administrationId'] ?? ''));
			if ($administrationId === '') {
				// Fail closed. An hour row with no administration cannot be
				// shown to be the caller's, and guessing would hand one
				// tenant's effort to another.
				$unscoped++;
				continue;
			}

			if ($administrationIds !== null
				&& in_array($administrationId, $administrationIds, true) === false
			) {
				continue;
			}

			$inScope[] = $row;
		}//end foreach

		$personIds = [];
		foreach ($inScope as $row) {
			$personId = trim((string)($row['personId'] ?? ''));
			if ($personId !== '') {
				$personIds[] = $personId;
			}
		}

		$aggregate = $this->aggregator->aggregate(
			hourRows: $inScope,
			rates: $this->rates->ratesFor(personIds: $personIds, period: $period)
		);

		return array_merge(
			[
				'subjectApp' => $subjectApp,
				'subjectId' => $subjectId,
				'unscopedRowsExcluded' => $unscoped,
			],
			$aggregate
		);
	}//end costFor()

	/**
	 * The hour rows booked against one subject, or [] when unreadable.
	 *
	 * Both subject fields are passed as filters rather than fetching every
	 * hour row and matching in PHP: the whole point of `subjectApp` /
	 * `subjectId` is that they make an hour attributable, and a full scan
	 * would grow with the ledger rather than with the subject.
	 *
	 * @param string $subjectApp The owning app.
	 * @param string $subjectId The domain object id.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-subject-cost-is-reachable-over-http
	 */
	private function hourRows(string $subjectApp, string $subjectId): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister($this->register())
				->setSchema(self::HOURS_SCHEMA)
				->findAll(
					[
						'filters' => [
							'subjectApp' => $subjectApp,
							'subjectId' => $subjectId,
						],
					]
				);
		} catch (Throwable $e) {
			$this->logger->error(
				'SubjectCostService: could not read hours for subject',
				[
					'subjectApp' => $subjectApp,
					'subjectId' => $subjectId,
					'exception' => $e->getMessage(),
				]
			);
			return [];
		}//end try

		if (is_array($rows) === false) {
			return [];
		}

		$normalised = [];
		foreach ($rows as $row) {
			$row = $this->toArray(row: $row);
			if ($row !== null) {
				$normalised[] = $row;
			}
		}

		return $normalised;
	}//end hourRows()

	/**
	 * Normalise an ObjectService row to an array.
	 *
	 * ObjectService yields ObjectEntity objects as readily as arrays; reading
	 * one with array syntax throws "Cannot use object of type ObjectEntity as
	 * array". House idiom, as in HrmqCostRateAdapter: jsonSerialize(), then
	 * getObject().
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null The row as an array, or null.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md
	 */
	private function toArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$serialised = $row->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$object = $row->getObject();
			if (is_array($object) === true) {
				return $object;
			}
		}

		return null;
	}//end toArray()

	/**
	 * The configured register slug, defaulting to the app id.
	 *
	 * @return string The register slug.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
