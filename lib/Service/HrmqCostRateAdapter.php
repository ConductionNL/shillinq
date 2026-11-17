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

use OCA\Shillinq\Kernel\Contracts\HrmqCostRateAdapterInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
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
class HrmqCostRateAdapter implements HrmqCostRateAdapterInterface {

	/**
	 * The cost-rate service, newest name first, as strings so nothing resolves
	 * at compile time.
	 *
	 * BOTH NAMES, AND THIS IS NOT BELT-AND-BRACES. The app renamed hrmq to
	 * humaniq and its namespace moved with it, so the single old name here
	 * matched nothing: `class_exists()` answered false on every instance and
	 * `ratesFor()` returned an empty map before doing any work. A duck-typed
	 * lookup pointed at a name nothing answers to is a silent no-op, never an
	 * error. shillinq and humaniq also update independently, so an old humaniq
	 * beside a new shillinq is a real state rather than a hypothetical.
	 *
	 * @var array<int, string>
	 */
	private const COST_RATE_SERVICES = [
		'OCA\\Humaniq\\Service\\EmployeeCostRateService',
		'OCA\\Hrmq\\Service\\EmployeeCostRateService',
	];

	/**
	 * The register slugs the Employee / EmploymentContract objects may live in,
	 * newest first.
	 *
	 * The humaniq#160 change renamed the register slug `hrmq` to `humaniq` and shipped
	 * `MigrateRegisterSlug` to rename the row in place, so an upgraded instance
	 * carries only the new one. The old name stays here for the same
	 * independent-update reason as the service names above.
	 *
	 * @var array<int, string>
	 */
	private const REGISTERS = ['humaniq', 'hrmq'];

	/**
	 * The register slug that answered, cached per request once resolved.
	 *
	 * @var string|null
	 */
	private ?string $resolvedRegister = null;

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

		$run = new CostRateRun();
		$run->service = $service;
		$run->period = $period;
		$run->additions = $additions;

		return $this->ratesOver(personIds: $personIds, run: $run);
	}//end ratesFor()

	/**
	 * The rate map over the distinct people, omitting everyone hrmq cannot price.
	 *
	 * @param array<int, string> $personIds Person ids to price.
	 * @param CostRateRun        $run       Service, period and additions.
	 *
	 * @return array<string, int> personId => employer cost in cents per hour.
	 */
	private function ratesOver(array $personIds, CostRateRun $run): array {
		$rates = [];
		foreach (array_unique($personIds) as $personId) {
			$personId = trim((string)$personId);
			$cents = $this->rateFor(personId: $personId, run: $run);
			if ($cents !== null) {
				$rates[$personId] = $cents;
			}
		}

		return $rates;
	}//end ratesOver()

	/**
	 * One person's rate, or null when the id is blank, the Employee is not
	 * readable, or hrmq cannot price them.
	 *
	 * @param string      $personId Trimmed person id.
	 * @param CostRateRun $run      Service, period and additions.
	 *
	 * @return integer|null Cents per hour.
	 */
	private function rateFor(string $personId, CostRateRun $run): int|null {
		if ($personId === '') {
			return null;
		}

		$employee = $this->hrmqObject(schema: 'Employee', id: $personId);
		if ($employee === null) {
			return null;
		}

		$person = clone $run;
		$person->personId = $personId;
		$person->employee = $employee;

		return $this->resolvedCents(run: $person);
	}//end rateFor()

	/**
	 * Ask hrmq for the person's rate against their active contract.
	 *
	 * An indefensible composition (override with no reason, an addition with
	 * no basis) is hrmq refusing to guess: the person is omitted so the
	 * aggregator withholds the total, and the reason is logged.
	 *
	 * @param CostRateRun $run The run, for one person.
	 *
	 * @return integer|null Cents per hour, or null when refused or unusable.
	 */
	private function resolvedCents(CostRateRun $run): int|null {
		try {
			$contract = $this->activeContract(employeeId: $run->personId);
			$resolved = $run->service->resolve(employee: $run->employee, contract: $contract, period: $run->period, extraAdditions: $run->additions);
		} catch (Throwable $e) {
			$this->logger->warning('HrmqCostRateAdapter: hrmq refused a cost rate', ['personId' => $run->personId, 'reason' => $e->getMessage()]);
			return null;
		}

		$cents = ($resolved['totalCentsPerHour'] ?? null);
		if (is_int($cents) === true && $cents >= 0) {
			return $cents;
		}

		return null;
	}//end resolvedCents()

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
		foreach (self::COST_RATE_SERVICES as $candidate) {
			// Neither class exists in the analysis environment, which is the
			// whole point: humaniq is a sibling app that may or may not be
			// installed, and this probe is what makes its absence a normal
			// state instead of a fatal.
			// @phpstan-ignore-next-line
			if (class_exists(class: $candidate) === false) {
				continue;
			}

			try {
				return $this->container->get(id: $candidate);
			} catch (Throwable $e) {
				$this->logger->debug('HrmqCostRateAdapter: ' . $candidate . ' present but unresolvable: ' . $e->getMessage());
			}
		}

		$this->logger->debug('HrmqCostRateAdapter: humaniq is not installed, no wage rates available');
		return null;
	}//end costRateService()

	/**
	 * The register slug that answers on this instance.
	 *
	 * Probed rather than assumed, because getting it wrong costs nothing
	 * visible: a read against a register that is not there throws, the caller
	 * catches, the person is omitted, and the aggregate simply reports the
	 * cost as unavailable. That is indistinguishable from an employee who
	 * genuinely has no rate.
	 *
	 * Public because SubjectCostService reads the hour rows out of the same
	 * register and there must be exactly one place that knows where humaniq
	 * lives. Two copies of a slug list drift, and the drift is silent.
	 *
	 * @return string The register slug, defaulting to the newest name.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md
	 */
	public function registerSlug(): string {
		if ($this->resolvedRegister === null) {
			$this->resolvedRegister = ($this->answeringRegister() ?? self::REGISTERS[0]);
		}

		return $this->resolvedRegister;
	}//end registerSlug()

	/**
	 * The first candidate register the ObjectService accepts, newest first.
	 *
	 * @return string|null The slug, or null when none answers.
	 */
	private function answeringRegister(): string|null {
		foreach (self::REGISTERS as $candidate) {
			try {
				$this->container->get(id: 'OCA\\OpenRegister\\Service\\ObjectService')->setRegister($candidate);
				return $candidate;
			} catch (Throwable $e) {
				$this->logger->debug('HrmqCostRateAdapter: register ' . $candidate . ' did not resolve: ' . $e->getMessage());
			}
		}

		return null;
	}//end answeringRegister()

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
			$row = ObjectIdentifier::findScoped(
				scoped: $this->container->get(id: 'OCA\\OpenRegister\\Service\\ObjectService')->setRegister($this->registerSlug())->setSchema($schema),
				id: $id
			);
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
				->setRegister($this->registerSlug())
				->setSchema('EmploymentContract')
				->findAll(['filters' => ['employee' => $employeeId, 'status' => 'active']]);
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

		return ($this->serialisedArray(row: $row) ?? $this->entityArray(row: $row));
	}//end toArray()

	/**
	 * A row's jsonSerialize() result when it is an array, otherwise null.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function serialisedArray(mixed $row): array|null {
		if (is_object($row) === false || method_exists($row, 'jsonSerialize') === false) {
			return null;
		}

		$out = $row->jsonSerialize();
		if (is_array($out) === true) {
			return $out;
		}

		return null;
	}//end serialisedArray()

	/**
	 * A row's getObject() payload when it is an array, otherwise null.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function entityArray(mixed $row): array|null {
		if (is_object($row) === false || method_exists($row, 'getObject') === false) {
			return null;
		}

		$out = $row->getObject();
		if (is_array($out) === true) {
			return $out;
		}

		return null;
	}//end entityArray()
}//end class
