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
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Throwable;

/**
 * Reads the billable lines of a customer, scoped to an administration.
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */
class BillableLinesService {

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
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The customer's time entries and passed-on expense claims in the period.
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

		$timeEntries = array_values(
			array_filter(
				$this->rows(schema: 'UrenRegistratie', administrationId: $administrationId),
				fn (array $entry): bool => in_array((string)($entry['projectId'] ?? ''), $projects, true) === true
					&& $this->inPeriod(date: (string)($entry['date'] ?? ''), fromDate: $fromDate, toDate: $toDate) === true
			)
		);
		usort($timeEntries, static fn (array $a, array $b): int => strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')));

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

		return ['timeEntries' => $timeEntries, 'expenses' => $expenses];
	}//end forCustomer()

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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
