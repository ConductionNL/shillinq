<?php

/**
 * Billable Lines Service
 *
 * The time entries and expense claims one customer can be invoiced for,
 * inside one administration. A time entry is the customer's through its
 * project; an expense claim through the customers it is passed on to.
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
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\BillableLinesServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Throwable;

/**
 * Reads the billable lines of a customer, scoped to an administration.
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */
class BillableLinesService implements BillableLinesServiceInterface {

	/**
	 * The most rows read per schema.
	 *
	 * @var int
	 */
	private const LIMIT = 2000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig             $appConfig     The register slug.
	 * @param BillableRateService    $billableRates Prices each hour from the assignment's rate card.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly BillableRateService $billableRates,
	) {
	}//end __construct()

	/**
	 * The customer's time entries and passed-on expense claims in the period.
	 *
	 * Each time entry carries the resolution of its billable rate under
	 * `rate`: `status` rated with `rate`, `unit`, `tier` and `value`, or
	 * `status` no-rate with `reason` and `message` (sales-billable-rate-per-hour
	 * REQ-BRPH-005), so the hours can be chosen knowing what each is worth.
	 *
	 * @param string $administrationId The administration, resolved on the server.
	 * @param string $customerId       The customer.
	 * @param string $projectId        One of the customer's projects, or '' for all of them.
	 * @param string $fromDate         The first day (YYYY-MM-DD), or ''.
	 * @param string $toDate           The last day (YYYY-MM-DD), or ''.
	 *
	 * @return array{timeEntries: list<array<string,mixed>>, expenses: list<array<string,mixed>>}
	 *
	 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
	 */
	public function forCustomer(string $administrationId, string $customerId, string $projectId, string $fromDate, string $toDate): array {
		if ($administrationId === '' || $customerId === '') {
			return ['timeEntries' => [], 'expenses' => []];
		}

		$projects = $this->customerProjectIds(administrationId: $administrationId, customerId: $customerId);
		if ($projectId !== '') {
			$projects = array_values(array_intersect($projects, [$projectId]));
		}

		$timeEntries = $this->billableTimeEntries(administrationId: $administrationId, projects: $projects, fromDate: $fromDate, toDate: $toDate);
		$expenses = $this->passedOnExpenses(administrationId: $administrationId, customerId: $customerId, fromDate: $fromDate, toDate: $toDate);

		return ['timeEntries' => $timeEntries, 'expenses' => $expenses];
	}//end forCustomer()

	/**
	 * The time entries on the projects in the period, by date.
	 *
	 * @param string $administrationId The administration.
	 * @param list<string> $projects The project ids.
	 * @param string $fromDate The first day (YYYY-MM-DD), or ''.
	 * @param string $toDate The last day (YYYY-MM-DD), or ''.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
	 */
	private function billableTimeEntries(string $administrationId, array $projects, string $fromDate, string $toDate): array {
		$timeEntries = array_values(
			array_filter(
				$this->rows(schema: 'UrenRegistratie', administrationId: $administrationId),
				fn (array $entry): bool => in_array((string)($entry['projectId'] ?? ''), $projects, true) === true
					&& $this->inPeriod(date: (string)($entry['date'] ?? ''), fromDate: $fromDate, toDate: $toDate) === true
			)
		);
		usort($timeEntries, static fn (array $a, array $b): int => strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')));
		$timeEntries = array_map(
			fn (array $entry): array => $entry + ['rate' => $this->rateOf(entry: $entry, administrationId: $administrationId, customerId: $customerId)],
			$timeEntries
		);

		return $timeEntries;
	}//end billableTimeEntries()

	/**
	 * The expense claims passed on to the customer in the period.
	 *
	 * @param string $administrationId The administration.
	 * @param string $customerId The customer.
	 * @param string $fromDate The first day (YYYY-MM-DD), or ''.
	 * @param string $toDate The last day (YYYY-MM-DD), or ''.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
	 */
	private function passedOnExpenses(string $administrationId, string $customerId, string $fromDate, string $toDate): array {
		$expenses = array_values(
			array_filter(
				$this->rows(schema: 'ExpenseClaimEntry', administrationId: $administrationId),
				fn (array $claim): bool => $this->isPassedOnTo(claim: $claim, customerId: $customerId) === true
					&& $this->inPeriod(
						date: (string)($claim['submittedDate'] ?? ($claim['toDate'] ?? ($claim['fromDate'] ?? ''))),
						fromDate: $fromDate,
						toDate: $toDate
					) === true
			)
		);

		return $expenses;
	}//end passedOnExpenses()

	/**
	 * The ids among those given that are not the customer's billable lines in
	 * the administration: unknown, of another administration, or of another
	 * customer.
	 *
	 * @param string       $administrationId The administration, resolved on the server.
	 * @param string       $customerId       The customer.
	 * @param list<string> $timeEntryIds     Time entry ids.
	 * @param list<string> $expenseIds       Expense claim ids.
	 *
	 * @return list<string> The foreign ids, time entries first.
	 *
	 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
	 */
	public function foreignLineIds(string $administrationId, string $customerId, array $timeEntryIds, array $expenseIds): array {
		$projects = $this->customerProjectIds(administrationId: $administrationId, customerId: $customerId);

		$foreign = [];
		foreach ($timeEntryIds as $id) {
			$entry = $this->findIn(schema: 'UrenRegistratie', id: (string)$id, administrationId: $administrationId);
			if ($entry === null || in_array((string)($entry['projectId'] ?? ''), $projects, true) === false) {
				$foreign[] = (string)$id;
			}
		}

		foreach ($expenseIds as $id) {
			$claim = $this->findIn(schema: 'ExpenseClaimEntry', id: (string)$id, administrationId: $administrationId);
			if ($claim === null || $this->isPassedOnTo(claim: $claim, customerId: $customerId) === false) {
				$foreign[] = (string)$id;
			}
		}

		return $foreign;
	}//end foreignLineIds()

	/**
	 * The billable rate one time entry resolves to, without an audit record.
	 *
	 * @param array<string,mixed> $entry            The time entry.
	 * @param string              $administrationId The administration.
	 * @param string              $customerId       The customer, for the client tier.
	 *
	 * @return array<string,mixed> The resolution, without its record id.
	 *
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-3.2
	 */
	private function rateOf(array $entry, string $administrationId, string $customerId): array {
		$answer = $this->billableRates->preview(
			hour: [
				'personId' => (string)($entry['personId'] ?? ''),
				'projectId' => (string)($entry['projectId'] ?? ''),
				'date' => substr((string)($entry['date'] ?? ''), 0, 10),
				'hours' => (float)($entry['hours'] ?? 0),
				'administrationId' => $administrationId,
				'clientId' => $customerId,
			]
		);
		unset($answer['recordId']);

		return $answer;
	}//end rateOf()

	/**
	 * The ids of the customer's projects in the administration.
	 *
	 * @param string $administrationId The administration.
	 * @param string $customerId       The customer.
	 *
	 * @return list<string>
	 */
	private function customerProjectIds(string $administrationId, string $customerId): array {
		if ($administrationId === '' || $customerId === '') {
			return [];
		}

		$ids = [];
		foreach ($this->rows(schema: 'engagement', administrationId: $administrationId) as $project) {
			if ((string)($project['customerId'] ?? '') === $customerId && (string)($project['id'] ?? '') !== '') {
				$ids[] = (string)$project['id'];
			}
		}

		return $ids;
	}//end customerProjectIds()

	/**
	 * Whether an expense claim is passed on to the customer.
	 *
	 * @param array<string,mixed> $claim      The claim.
	 * @param string              $customerId The customer.
	 *
	 * @return bool
	 */
	private function isPassedOnTo(array $claim, string $customerId): bool {
		$customers = $claim['passThroughCustomerIds'] ?? [];
		if (is_array($customers) === false || $customerId === '') {
			return false;
		}

		return in_array($customerId, array_map('strval', $customers), true);
	}//end isPassedOnTo()

	/**
	 * Whether a date lies in the period, both ends included; an open end does not limit.
	 *
	 * @param string $date     The date; a longer ISO string is cut to the day.
	 * @param string $fromDate The first day, or ''.
	 * @param string $toDate   The last day, or ''.
	 *
	 * @return bool
	 */
	private function inPeriod(string $date, string $fromDate, string $toDate): bool {
		$day = substr($date, 0, 10);
		if ($day === '') {
			return false;
		}

		return ($fromDate === '' || $day >= $fromDate) && ($toDate === '' || $day <= $toDate);
	}//end inPeriod()

	/**
	 * The rows of one schema in the administration, each with its id.
	 *
	 * @param string $schema           The schema slug.
	 * @param string $administrationId The administration.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function rows(string $schema, string $administrationId): array {
		try {
			$found = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => ['administrationId' => $administrationId], 'limit' => self::LIMIT]);
		} catch (Throwable $e) {
			return [];
		}

		$rows = [];
		foreach ($found as $candidate) {
			$row = ObjectIdentifier::recordWithId(candidate: $candidate);
			if ($row !== null && (string)($row['administrationId'] ?? '') === $administrationId) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()

	/**
	 * One row by id, only when it belongs to the administration.
	 *
	 * @param string $schema           The schema slug.
	 * @param string $id               The id.
	 * @param string $administrationId The administration.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findIn(string $schema, string $id, string $administrationId): ?array {
		try {
			$scoped = $this->objectService->setRegister($this->register())->setSchema($schema);
			$row = ObjectIdentifier::findOne(scoped: $scoped, id: $id);
		} catch (Throwable $e) {
			return null;
		}

		if ($row === null || $administrationId === '' || (string)($row['administrationId'] ?? '') !== $administrationId) {
			return null;
		}

		return $row;
	}//end findIn()

	/**
	 * The register slug holding shillinq's objects.
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
