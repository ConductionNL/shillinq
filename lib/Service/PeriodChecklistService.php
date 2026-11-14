<?php

/**
 * Period Checklist Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Resolves, reopens and adds the close checklist items a FiscalPeriod carries in
 * `taskChecklistItems` (ledger-period-end REQ-LPE-002). Resolving records who
 * and when; reopening clears both. Items are frozen once the period is closed
 * or locked for audit, and every change needs the period-closer role, the role
 * that runs the close itself.
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
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-period-close/spec.md#requirement-a-person-resolves-reopens-and-adds-checklist-items-req-lpe-002
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PeriodCloseServiceInterface;
use OCA\Shillinq\Kernel\Exception\PeriodCloseException;
use OCA\Shillinq\Lifecycle\Action\InstantiateCloseChecklistAction;
use OCP\IGroupManager;

/**
 * Changes the checklist items of a period that is still open or closing.
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-period-close/spec.md#requirement-a-person-resolves-reopens-and-adds-checklist-items-req-lpe-002
 */
class PeriodChecklistService {

	/**
	 * The role that may change checklist items.
	 */
	public const ROLE = 'period-closer';

	/**
	 * The states in which items can no longer change.
	 */
	public const FROZEN_STATES = ['closed', 'audit-locked'];

	/**
	 * The longest description an added item may carry.
	 */
	public const MAX_DESCRIPTION = 500;

	/**
	 * Constructor.
	 *
	 * @param PeriodCloseServiceInterface $periods       Reads the period, scoped to the administration.
	 * @param ObjectServiceInterface      $objectService Stores the period.
	 * @param RegisterLocation            $settings      The register the period lives in.
	 * @param IGroupManager               $groupManager  Resolves the period-closer role.
	 */
	public function __construct(
		private readonly PeriodCloseServiceInterface $periods,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $settings,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Mark an item resolved by the user, now.
	 *
	 * @param string $periodId         The period id or business periodId.
	 * @param string $administrationId The caller's administration.
	 * @param string $itemId           The item.
	 * @param string $userId           The acting user.
	 *
	 * @return array<string,mixed> The stored period.
	 *
	 * @throws PeriodCloseException On a forbidden / not-found / frozen period or an unknown item.
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-period-close/spec.md#requirement-a-person-resolves-reopens-and-adds-checklist-items-req-lpe-002
	 */
	public function resolveItem(string $periodId, string $administrationId, string $itemId, string $userId): array {
		return $this->changeItem(
			periodId: $periodId,
			administrationId: $administrationId,
			itemId: $itemId,
			userId: $userId,
			change: fn (array $item): array => array_merge(
				$item,
				['resolved' => true, 'resolvedAt' => $this->now(), 'resolvedBy' => $userId]
			)
		);
	}//end resolveItem()

	/**
	 * Mark a resolved item open again, clearing who resolved it and when.
	 *
	 * @param string $periodId         The period id or business periodId.
	 * @param string $administrationId The caller's administration.
	 * @param string $itemId           The item.
	 * @param string $userId           The acting user.
	 *
	 * @return array<string,mixed> The stored period.
	 *
	 * @throws PeriodCloseException On a forbidden / not-found / frozen period or an unknown item.
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-period-close/spec.md#requirement-a-person-resolves-reopens-and-adds-checklist-items-req-lpe-002
	 */
	public function reopenItem(string $periodId, string $administrationId, string $itemId, string $userId): array {
		return $this->changeItem(
			periodId: $periodId,
			administrationId: $administrationId,
			itemId: $itemId,
			userId: $userId,
			change: static fn (array $item): array => array_merge(
				$item,
				['resolved' => false, 'resolvedAt' => null, 'resolvedBy' => null]
			)
		);
	}//end reopenItem()

	/**
	 * Append an open item to the period's checklist.
	 *
	 * @param string $periodId         The period id or business periodId.
	 * @param string $administrationId The caller's administration.
	 * @param string $category         One of InstantiateCloseChecklistAction::CATEGORIES.
	 * @param string $description      What has to be done.
	 * @param string $userId           The acting user.
	 *
	 * @return array<string,mixed> The stored period.
	 *
	 * @throws PeriodCloseException On a forbidden / not-found / frozen period or an invalid item.
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-period-close/spec.md#requirement-a-person-resolves-reopens-and-adds-checklist-items-req-lpe-002
	 */
	public function addItem(string $periodId, string $administrationId, string $category, string $description, string $userId): array {
		$description = trim($description);
		$category    = trim($category);
		if ($description === '' || mb_strlen($description) > self::MAX_DESCRIPTION) {
			throw new PeriodCloseException(
				message: 'A checklist item needs a description of at most ' . self::MAX_DESCRIPTION . ' characters',
				status: PeriodCloseServiceInterface::ERR_VALIDATION
			);
		}

		if (in_array($category, InstantiateCloseChecklistAction::CATEGORIES, true) === false) {
			throw new PeriodCloseException(
				message: 'Unknown checklist category',
				status: PeriodCloseServiceInterface::ERR_VALIDATION
			);
		}

		$record = $this->changeablePeriod(periodId: $periodId, administrationId: $administrationId, userId: $userId);
		$items  = self::items(record: $record);

		$items[] = [
			'id' => self::nextId(items: $items),
			'category' => $category,
			'description' => $description,
			'resolved' => false,
			'resolvedAt' => null,
			'resolvedBy' => null,
		];

		return $this->store(record: $record, items: $items);
	}//end addItem()

	/**
	 * Apply a change to one item of a changeable period and store it.
	 *
	 * @param string   $periodId         The period.
	 * @param string   $administrationId The administration.
	 * @param string   $itemId           The item.
	 * @param string   $userId           The acting user.
	 * @param callable $change           fn(array $item): array.
	 *
	 * @return array<string,mixed> The stored period.
	 *
	 * @throws PeriodCloseException On a refusal.
	 */
	private function changeItem(string $periodId, string $administrationId, string $itemId, string $userId, callable $change): array {
		$record = $this->changeablePeriod(periodId: $periodId, administrationId: $administrationId, userId: $userId);
		$items  = self::items(record: $record);

		foreach ($items as $index => $item) {
			if ((string)($item['id'] ?? '') === $itemId) {
				$items[$index] = $change($item);
				return $this->store(record: $record, items: $items);
			}
		}

		throw new PeriodCloseException(
			message: 'Checklist item not found',
			status: PeriodCloseServiceInterface::ERR_NOT_FOUND
		);
	}//end changeItem()

	/**
	 * The period, when it exists in scope, the user holds the role and it is not frozen.
	 *
	 * The role is checked before the period is read, so a caller without it
	 * learns nothing about which periods exist.
	 *
	 * @param string $periodId         The period.
	 * @param string $administrationId The administration.
	 * @param string $userId           The acting user.
	 *
	 * @return array<string,mixed> The period.
	 *
	 * @throws PeriodCloseException On a refusal.
	 */
	private function changeablePeriod(string $periodId, string $administrationId, string $userId): array {
		if ($this->hasRole(userId: $userId) === false) {
			throw new PeriodCloseException(
				message: 'You do not have permission to perform this action',
				status: PeriodCloseServiceInterface::ERR_FORBIDDEN
			);
		}

		$record = $this->periods->getPeriodForClose(periodId: $periodId, administrationId: $administrationId);
		if ($record === null) {
			throw new PeriodCloseException(message: 'Period not found', status: PeriodCloseServiceInterface::ERR_NOT_FOUND);
		}

		if (in_array((string)($record['state'] ?? ''), self::FROZEN_STATES, true) === true) {
			throw new PeriodCloseException(
				message: 'The checklist of a closed or audit-locked period cannot change',
				status: PeriodCloseService::ERR_INVALID_STATE
			);
		}

		return $record;
	}//end changeablePeriod()

	/**
	 * Whether the user holds the period-closer role or is a Nextcloud admin.
	 *
	 * @param string $userId The user.
	 *
	 * @return bool
	 */
	private function hasRole(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		return $this->groupManager->isAdmin($userId) === true || $this->groupManager->isInGroup($userId, self::ROLE) === true;
	}//end hasRole()

	/**
	 * The period's items, keeping only well-formed ones.
	 *
	 * @param array<string,mixed> $record The period.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function items(array $record): array {
		$items = ($record[InstantiateCloseChecklistAction::ITEMS] ?? []);
		if (is_array($items) === false) {
			return [];
		}

		return array_values(array_filter($items, 'is_array'));
	}//end items()

	/**
	 * An id no item carries yet: `item-<n>`, n counting up from the item count.
	 *
	 * @param list<array<string,mixed>> $items The items.
	 *
	 * @return string
	 */
	private static function nextId(array $items): string {
		$taken = array_map(static fn (array $item): string => (string)($item['id'] ?? ''), $items);
		$next  = count($items) + 1;
		while (in_array('item-' . $next, $taken, true) === true) {
			$next++;
		}

		return 'item-' . $next;
	}//end nextId()

	/**
	 * Store the period with its new items.
	 *
	 * @param array<string,mixed>       $record The period.
	 * @param list<array<string,mixed>> $items  Its items.
	 *
	 * @return array<string,mixed> The stored period.
	 */
	private function store(array $record, array $items): array {
		$record[InstantiateCloseChecklistAction::ITEMS] = $items;
		$this->objectService->saveObject(
			object: $record,
			register: $this->settings->getRegisterSlug(),
			schema: 'FiscalPeriod',
		);

		return $record;
	}//end store()

	/**
	 * The current time in ISO-8601.
	 *
	 * @return string
	 */
	private function now(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}//end now()
}//end class
