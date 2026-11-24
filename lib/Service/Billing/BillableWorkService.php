<?php

/**
 * BillableWorkService
 *
 * Lists the work a customer can still be invoiced for: the unbilled hours of
 * one of its projects, read from the hours source, and the unbilled
 * pass-through receipts, mileage and per diem items of its approved or posted
 * expense claims. Nothing a draft or posted invoice already references is
 * offered again.
 *
 * @category  Service
 * @package   OCA\Shillinq\Service\Billing
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Billing;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\BillableWorkServiceInterface;
use OCA\Shillinq\Service\InvoiceDeduplicationService;
use OCA\Shillinq\Service\RateCardResolver;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Override;
use Throwable;

/**
 * The unbilled hours and pass-through expenses of one customer (design D1).
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.2
 */
class BillableWorkService implements BillableWorkServiceInterface {

	/**
	 * The claim states whose pass-through items can be billed.
	 *
	 * @var list<string>
	 */
	private const BILLABLE_CLAIM_STATES = ['approved', 'posted'];

	/**
	 * Per item schema, the field holding its cost and the one holding its date
	 * (the same fields the markup is locked on at submit).
	 *
	 * @var array<string, array{amount: string, date: string}>
	 */
	private const ITEM_FIELDS = [
		'Receipt' => ['amount' => 'amountInBaseCurrency', 'date' => 'receiptDate'],
		'MileageEntry' => ['amount' => 'totalAmount', 'date' => 'journeyDate'],
		'PerDiem' => ['amount' => 'allowanceAmount', 'date' => 'travelStartDate'],
	];

	/**
	 * The most rows read per schema.
	 *
	 * @var int
	 */
	private const LIMIT = 2000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface      $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig                  $appConfig     The register slug.
	 * @param BillableHoursSource         $hoursSource   Where approved, billable hours come from.
	 * @param InvoiceDeduplicationService $dedup         The rule that keeps a source line on one invoice.
	 * @param RateCardResolver            $rates         Prices the hours on the chosen rate card.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly BillableHoursSource $hoursSource,
		private readonly InvoiceDeduplicationService $dedup,
		private readonly RateCardResolver $rates,
	) {
	}//end __construct()

	/**
	 * The unbilled hours of the project and the unbilled pass-through expenses of the customer.
	 *
	 * @param string $administrationId The administration, resolved and checked on the server.
	 * @param string $customerId       The customer.
	 * @param string $projectId        One of the customer's projects, or '' for no hours.
	 * @param string $from             The first day, `Y-m-d`, or ''.
	 * @param string $to               The last day, `Y-m-d`, or ''.
	 * @param string $rateCardId       The rate card that prices the hours, or '' for none.
	 *
	 * @return array{hours: array{available: bool, reason: string, entries: list<array<string,mixed>>}, expenses: list<array<string,mixed>>}
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/specs/invoice-from-time-and-expense/spec.md#requirement-the-generate-page-lists-unbilled-hours-to-tick-req-steb-001
	 */
	#[Override]
	public function billableWork(
		string $administrationId,
		string $customerId,
		string $projectId,
		string $from,
		string $to,
		string $rateCardId='',
	): array {
		if ($administrationId === '' || $customerId === '') {
			return ['hours' => ['available' => true, 'reason' => '', 'entries' => []], 'expenses' => []];
		}

		return [
			'hours' => $this->hours(
				administrationId: $administrationId,
				customerId: $customerId,
				projectId: $projectId,
				from: $from,
				to: $to,
				rateCardId: $rateCardId
			),
			'expenses' => $this->expenses(administrationId: $administrationId, customerId: $customerId),
		];
	}//end billableWork()

	/**
	 * The project's unbilled hours, priced on the rate card.
	 *
	 * @param string $administrationId The administration.
	 * @param string $customerId       The customer.
	 * @param string $projectId        The project.
	 * @param string $from             The first day.
	 * @param string $to               The last day.
	 * @param string $rateCardId       The rate card, or ''.
	 *
	 * @return array{available: bool, reason: string, entries: list<array<string,mixed>>}
	 */
	private function hours(string $administrationId, string $customerId, string $projectId, string $from, string $to, string $rateCardId): array {
		// A project of another customer, or of another administration, offers no hours.
		$ownProject = $this->isCustomerProject(administrationId: $administrationId, customerId: $customerId, projectId: $projectId);
		if ($projectId === '' || $ownProject === false) {
			return ['available' => true, 'reason' => '', 'entries' => []];
		}

		$read = $this->hoursSource->unbilled(projectId: $projectId, from: $from, to: $to);
		if (($read['available'] ?? false) !== true) {
			return ['available' => false, 'reason' => (string)($read['reason'] ?? ''), 'entries' => []];
		}

		$hours = (array)($read['hours'] ?? []);
		$billed = $this->billedIds(administrationId: $administrationId, timeEntryIds: array_column($hours, 'id'), expenseIds: [], key: 'timeEntryIds');
		$entries = [];
		foreach ($hours as $hour) {
			if (in_array((string)($hour['id'] ?? ''), $billed, true) === false) {
				$entries[] = $this->pricedHour(hour: $hour, administrationId: $administrationId, rateCardId: $rateCardId);
			}
		}

		return ['available' => true, 'reason' => '', 'entries' => $entries];
	}//end hours()

	/**
	 * One hour entry with the rate and amount of the rate card, when one is chosen.
	 *
	 * @param array<string,mixed> $hour             The hour entry.
	 * @param string              $administrationId The administration.
	 * @param string              $rateCardId       The rate card, or ''.
	 *
	 * @return array<string,mixed>
	 */
	private function pricedHour(array $hour, string $administrationId, string $rateCardId): array {
		$hour['rate'] = null;
		$hour['amount'] = null;
		if ($rateCardId === '') {
			return $hour;
		}

		$rate = $this->rates->resolveRate(
			rateCardId: $rateCardId,
			resourceType: (string)($hour['resourceType'] ?? ''),
			date: (string)($hour['date'] ?? ''),
			administrationId: $administrationId
		);
		$hour['rate'] = round(((int)$rate['rateCents']) / 100, 2);
		$hour['currency'] = (string)$rate['currency'];
		$hour['amount'] = round($hour['rate'] * (float)($hour['hours'] ?? 0), 2);

		return $hour;
	}//end pricedHour()

	/**
	 * The customer's unbilled pass-through items of approved or posted claims.
	 *
	 * @param string $administrationId The administration.
	 * @param string $customerId       The customer.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/specs/invoice-from-time-and-expense/spec.md#requirement-the-generate-page-lists-unbilled-pass-through-expenses-to-tick-req-steb-002
	 */
	private function expenses(string $administrationId, string $customerId): array {
		$claims = [];
		foreach ($this->rows(schema: 'ExpenseClaimEntry', administrationId: $administrationId) as $claim) {
			if (in_array((string)($claim['state'] ?? ''), self::BILLABLE_CLAIM_STATES, true) === true) {
				$claims[(string)$claim['id']] = $claim;
			}
		}

		$items = [];
		foreach (self::ITEM_FIELDS as $schema => $source) {
			foreach ($this->rows(schema: $schema, administrationId: $administrationId) as $row) {
				$claimId = (string)($row['claimId'] ?? '');
				if (isset($claims[$claimId]) === true && ($row['settlementMode'] ?? null) === 'pass-through'
					&& (string)($row['linkedCustomerId'] ?? '') === $customerId
				) {
					$items[] = $this->expenseItem(row: $row, schema: $schema, source: $source, claim: $claims[$claimId]);
				}
			}
		}

		$billed = $this->billedIds(administrationId: $administrationId, timeEntryIds: [], expenseIds: array_column($items, 'id'), key: 'expenseIds');

		return array_values(array_filter($items, static fn (array $item): bool => in_array($item['id'], $billed, true) === false));
	}//end expenses()

	/**
	 * One pass-through item: its cost, the markup locked at submit, and the billed amount.
	 *
	 * @param array<string,mixed>                                           $row    The item.
	 * @param string                                                        $schema `Receipt`, `MileageEntry` or `PerDiem`.
	 * @param array{amount: string, date: string}                          $source Where the item keeps its cost and date.
	 * @param array<string,mixed>                                           $claim  The item's claim.
	 *
	 * @return array<string,mixed>
	 */
	private function expenseItem(array $row, string $schema, array $source, array $claim): array {
		$cost = round((float)($row[$source['amount']] ?? 0), 2);
		$markup = round((float)($row['markupAmountCalculated'] ?? 0), 2);

		return [
			'id' => (string)$row['id'],
			'kind' => $schema,
			'claimId' => (string)$claim['id'],
			'claimNumber' => (string)($claim['claimNumber'] ?? ''),
			'date' => (string)($row[$source['date']] ?? ''),
			'description' => (string)($row['description'] ?? ''),
			'cost' => $cost,
			'markupRate' => (float)($row['markupRateApplied'] ?? 0),
			'markup' => $markup,
			'amount' => round($cost + $markup, 2),
		];
	}//end expenseItem()

	/**
	 * The ids among those given that a draft or posted invoice already references.
	 *
	 * @param string       $administrationId The administration.
	 * @param list<string> $timeEntryIds     Hour ids.
	 * @param list<string> $expenseIds       Expense item ids.
	 * @param string       $key              `timeEntryIds` or `expenseIds`: which overlap to read.
	 *
	 * @return list<string>
	 */
	private function billedIds(string $administrationId, array $timeEntryIds, array $expenseIds, string $key): array {
		$found = $this->dedup->deduplicateSourceIds(
			administrationId: $administrationId,
			timeEntryIds: array_map('strval', $timeEntryIds),
			expenseIds: array_map('strval', $expenseIds)
		);
		$billed = [];
		foreach ($found['conflicts'] as $conflict) {
			$billed = array_merge($billed, $conflict[$key]);
		}

		return array_values(array_unique($billed));
	}//end billedIds()

	/**
	 * Whether the project is one of the customer's in the administration.
	 *
	 * @param string $administrationId The administration.
	 * @param string $customerId       The customer.
	 * @param string $projectId        The project.
	 *
	 * @return bool
	 */
	private function isCustomerProject(string $administrationId, string $customerId, string $projectId): bool {
		foreach ($this->rows(schema: 'engagement', administrationId: $administrationId) as $project) {
			if ((string)$project['id'] === $projectId) {
				return (string)($project['customerId'] ?? '') === $customerId;
			}
		}

		return false;
	}//end isCustomerProject()

	/**
	 * The rows of a schema in the administration, each with its id.
	 *
	 * @param string $schema           The schema slug.
	 * @param string $administrationId The administration.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function rows(string $schema, string $administrationId): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		if ($register === '') {
			$register = AppIdentity::APP_ID;
		}

		try {
			$found = $this->objectService
				->setRegister($register)
				->setSchema($schema)
				->findAll(['filters' => ['administrationId' => $administrationId], 'limit' => self::LIMIT]);
		} catch (Throwable) {
			return [];
		}

		$rows = [];
		foreach ($found as $candidate) {
			$row = ObjectIdentifier::recordWithId(candidate: $candidate);
			// The filter is the store's; the check is ours, so a row of another administration never passes.
			if ($row !== null && (string)($row['administrationId'] ?? '') === $administrationId) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()
}//end class
