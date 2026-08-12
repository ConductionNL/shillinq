<?php

/**
 * Hrmq cost-rate adapter
 *
 * Resolves the employer cost per hour for a set of people from hrmq, so
 * {@see SubjectCostAggregator} can price an hour set. This is the "adapter
 * wires here without touching the policy" half that GrotendeelsCriteriumService
 * anticipates, per hydra ADR-081.
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
 *
 * @spec openspec/specs/subject-cost-aggregation/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ask hrmq what an hour of each person's time costs the employer.
 *
 * IN-PROCESS, NOT OVER HTTP. hrmq exposes POST /api/employees/cost-rate, and
 * calling it would mean a loopback request that has to carry the user's
 * session to keep the RBAC it depends on. Resolving hrmq's service through the
 * container keeps the caller's ambient RBAC without inventing an auth hop, and
 * hrmq's own Employee / EmploymentContract objects are read through
 * OpenRegister exactly as any other register is.
 *
 * ⚠️ EVERY hrmq REFERENCE IS LAZY AND GUARDED, and that is not defensive
 * habit — it is a defect this fleet has already paid for. openregister
 * declared `OCA\OpenRegister\ContextChat\ContentProvider` in a constructor
 * typehint; `SimpleContainer::resolve()` reflects each parameter type, that
 * reflection IS the class load, and on a server without the dependency it
 * threw on every `occ` invocation and every object write. So:
 *
 *   - no hrmq type appears in this constructor, or anywhere resolved eagerly;
 *   - the class name is a plain STRING, never `SomeClass::class`, so nothing
 *     is resolved at compile time either;
 *   - absence is a normal state. shillinq does not declare hrmq as a
 *     dependency, and an instance may legitimately run without it.
 *
 * When hrmq is absent this returns an EMPTY map. That is deliberate and it is
 * safe, because SubjectCostAggregator refuses to publish a total when any
 * person is unpriced: the case shows "hours known, cost unavailable" rather
 * than a plausible, too-low number.
 *
 * @spec openspec/specs/subject-cost-aggregation/spec.md
 */
class HrmqCostRateAdapter {

	/**
	 * The hrmq cost-rate service, as a string so nothing resolves at compile time.
	 *
	 * @var string
	 */
	private const HRMQ_COST_RATE_SERVICE = 'OCA\\Hrmq\\Service\\EmployeeCostRateService';

	/**
	 * The register hrmq stores its Employee / EmploymentContract objects in.
	 *
	 * @var string
	 */
	private const HRMQ_REGISTER = 'hrmq';

	/**
	 * Wire collaborators.
	 *
	 * @param ContainerInterface $container Container, for the lazy hrmq + ObjectService resolves.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve cost rates for the given people.
	 *
	 * A person hrmq cannot price is OMITTED from the map rather than mapped to
	 * zero. Zero is a number the aggregator would happily multiply, producing
	 * a total that silently excludes that person's cost; an absent key makes
	 * the aggregator withhold the total instead, which is the whole point of
	 * its refusal rule.
	 *
	 * @param array<int, string> $personIds Person ids to price.
	 * @param string $period Costing period `YYYY-MM`.
	 * @param array<int, array<string, mixed>> $additions Shillinq's ledger-derived additions per ADR-081.
	 *
	 * @return array<string, int> personId => employer cost in cents per hour. Empty when hrmq is unavailable.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-cost-is-published-only-when-every-hour-in-it-could-be-priced
	 */
	public function ratesFor(array $personIds, string $period = '', array $additions = []): array {
		$service = $this->costRateService();
		if ($service === null) {
			return [];
		}

		$rates = [];
		foreach (array_unique($personIds) as $personId) {
			$personId = trim((string)$personId);
			if ($personId === '') {
				continue;
			}

			$employee = $this->hrmqObject(schema: 'Employee', id: $personId);
			if ($employee === null) {
				continue;
			}

			try {
				$resolved = $service->resolve(
					employee: $employee,
					contract: $this->activeContract(employeeId: $personId),
					period: $period,
					extraAdditions: $additions
				);
			} catch (Throwable $e) {
				// An indefensible composition (override with no reason, an
				// addition with no basis) is hrmq refusing to guess. Omit the
				// person so the aggregator withholds the total, and say why.
				$this->logger->warning(
					'HrmqCostRateAdapter: hrmq refused a cost rate',
					['personId' => $personId, 'reason' => $e->getMessage()]
				);
				continue;
			}

			$cents = ($resolved['totalCentsPerHour'] ?? null);
			if (is_int($cents) === true && $cents >= 0) {
				$rates[$personId] = $cents;
			}
		}//end foreach

		return $rates;
	}//end ratesFor()

	/**
	 * The hrmq cost-rate service, or null when hrmq is not installed.
	 *
	 * `class_exists()` on a plain string asks the autoloader and answers false
	 * rather than dying. See the class docblock for why nothing here may name
	 * an hrmq type directly.
	 *
	 * @return mixed The service, or null.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md
	 */
	private function costRateService(): mixed {
		if (class_exists(class: self::HRMQ_COST_RATE_SERVICE) === false) {
			$this->logger->debug('HrmqCostRateAdapter: hrmq is not installed — no wage rates available');
			return null;
		}

		try {
			return $this->container->get(id: self::HRMQ_COST_RATE_SERVICE);
		} catch (Throwable $e) {
			$this->logger->debug('HrmqCostRateAdapter: hrmq present but unresolvable: ' . $e->getMessage());
			return null;
		}
	}//end costRateService()

	/**
	 * Read one object from hrmq's register under the caller's RBAC.
	 *
	 * @param string $schema The hrmq schema slug.
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed>|null The object, or null when absent/unauthorised.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md
	 */
	private function hrmqObject(string $schema, string $id): ?array {
		try {
			$row = $this->container->get(id: 'OCA\\OpenRegister\\Service\\ObjectService')
				->find(id: $id, register: self::HRMQ_REGISTER, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->debug(
				'HrmqCostRateAdapter: ' . $schema . ' ' . $id . ' not readable: ' . $e->getMessage()
			);
			return null;
		}

		return $this->toArray(row: $row);
	}//end hrmqObject()

	/**
	 * The employee's active EmploymentContract, or null.
	 *
	 * Passed to hrmq explicitly rather than left for it to choose: hrmq's own
	 * docblock notes that taking the contract from the caller stops it
	 * silently costing against a different contract than the caller believes
	 * is in force.
	 *
	 * @param string $employeeId The employee id.
	 *
	 * @return array<string, mixed>|null The contract, or null.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md
	 */
	private function activeContract(string $employeeId): ?array {
		try {
			$rows = $this->container->get(id: 'OCA\\OpenRegister\\Service\\ObjectService')
				->findAll(
					register: self::HRMQ_REGISTER,
					schema: 'EmploymentContract',
					filters: ['employee' => $employeeId, 'status' => 'active']
				);
		} catch (Throwable $e) {
			$this->logger->debug('HrmqCostRateAdapter: no active contract for ' . $employeeId);
			return null;
		}

		foreach (($rows ?? []) as $row) {
			return $this->toArray(row: $row);
		}

		return null;
	}//end activeContract()

	/**
	 * Normalise an ObjectService row to an array.
	 *
	 * ObjectService yields ObjectEntity objects, not arrays — reading them
	 * with array syntax throws "Cannot use object of type ObjectEntity as
	 * array", a defect this repo has hit in VATReturnService and
	 * ComplianceDeadlineCalendarService. House idiom: jsonSerialize(), then
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
			$out = $row->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$out = $row->getObject();
			if (is_array($out) === true) {
				return $out;
			}
		}

		return null;
	}//end toArray()
}//end class
