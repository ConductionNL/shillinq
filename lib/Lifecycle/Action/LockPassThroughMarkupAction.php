<?php

/**
 * Shillinq LockPassThroughMarkupAction
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lifecycle action `lock-passthrough-markup` on `ExpenseClaimEntry.submit`:
 * every pass-through receipt, mileage entry and per-diem of the claim gets the
 * markup rule that applies to it, the rate and the amount written onto it, so
 * the invoice later bills what was agreed when the claim was submitted, and a
 * rule changed afterwards leaves the claim as it was (sales-time-and-expense-
 * billing D3, REQ-STEB-004). The rule is chosen by PassThroughMarkupResolver,
 * the same priority SettlementGuard uses, so the two stay one rule.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\PassThroughMarkupResolver;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the submit-time markup onto a claim's pass-through items.
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-1.2
 */
class LockPassThroughMarkupAction implements LifecycleActionInterface {

	/**
	 * The name the action is registered under.
	 *
	 * @var string
	 */
	public const NAME = 'lock-passthrough-markup';

	/**
	 * The claim's item kinds: the claim field listing their ids, the item
	 * field holding their cost, the item field holding their date, and the
	 * category a rule is matched on when the item names none.
	 *
	 * @var array<string, array{ids:string, amount:string, date:string, category:string}>
	 */
	public const ITEM_SOURCES = [
		'Receipt' => ['ids' => 'receiptIds', 'amount' => 'amountInBaseCurrency', 'date' => 'receiptDate', 'category' => ''],
		'MileageEntry' => ['ids' => 'mileageIds', 'amount' => 'totalAmount', 'date' => 'journeyDate', 'category' => 'travel'],
		'PerDiem' => ['ids' => 'perDiemIds', 'amount' => 'allowanceAmount', 'date' => 'travelStartDate', 'category' => 'meals'],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig             $appConfig     The register slug.
	 * @param LoggerInterface        $logger        For an item that could not be locked.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Lock the markup of every pass-through item of the submitted claim.
	 *
	 * The claim itself is returned unchanged; the items are written. A
	 * reimbursable item is left alone, and so is a pass-through item that no
	 * rule applies to, apart from recording that none did (amount 0).
	 *
	 * @param array<string, mixed> $objectData   The claim being submitted.
	 * @param array<string, mixed> $previousData The claim before the transition.
	 * @param array<string, mixed> $parameters   The action parameters (none are read).
	 * @param string               $actionName   The registered action name.
	 *
	 * @return array<string, mixed> The claim, unchanged.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is OpenRegister's.
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-1.2
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$register = $this->register();
		$rules = [];
		foreach (self::ITEM_SOURCES as $schema => $source) {
			foreach ((array)($objectData[$source['ids']] ?? []) as $itemId) {
				if ((string)$itemId === '') {
					continue;
				}

				$this->lockItem(
					item: ['register' => $register, 'schema' => $schema, 'id' => (string)$itemId, 'source' => $source],
					claim: $objectData,
					rules: $rules
				);
			}
		}

		return $objectData;
	}//end execute()

	/**
	 * Lock one item, reading the administration's rules for its year once.
	 *
	 * @param array{register:string, schema:string, id:string, source:array{ids:string, amount:string, date:string, category:string}} $item  The item to lock.
	 * @param array<string, mixed>                                                                                                    $claim The claim.
	 * @param array<string, array<int, array<string, mixed>>>                                                                          $rules The rules read so far, per administration and year.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-1.2
	 */
	private function lockItem(array $item, array $claim, array &$rules): void {
		try {
			$found = ObjectIdentifier::findScoped(
				scoped: $this->objectService->setRegister($item['register'])->setSchema($item['schema']),
				id: $item['id']
			);
			if ($found === null) {
				return;
			}

			$data = (array)$found->jsonSerialize();
			if (($data['settlementMode'] ?? null) !== 'pass-through') {
				return;
			}

			$administrationId = (string)($data['administrationId'] ?? ($claim['administrationId'] ?? ''));
			$year = self::yearOf(date: (string)($data[$item['source']['date']] ?? ''));
			$key = $administrationId.'|'.$year;
			$rules[$key] ??= $this->rulesFor(register: $item['register'], administrationId: $administrationId, fiscalYear: $year);

			$category = (string)($data['category'] ?? '');
			if ($category === '') {
				$category = $item['source']['category'];
			}

			$locked = (new PassThroughMarkupResolver())->resolve(
				rules: $rules[$key],
				customerId: (string)($data['linkedCustomerId'] ?? ''),
				category: $category,
				cost: (float)($data[$item['source']['amount']] ?? 0.0)
			);

			$this->objectService->saveObject(object: array_merge($data, $locked), register: $item['register'], schema: $item['schema']);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[LockPassThroughMarkup] Could not lock the markup of a claim item: '.$e->getMessage(),
				['schema' => $item['schema'], 'itemId' => $item['id'], 'claimId' => ($claim['id'] ?? null)]
			);
		}//end try
	}//end lockItem()

	/**
	 * The administration's markup rules for a fiscal year, as plain arrays.
	 *
	 * @param string $register         The register slug.
	 * @param string $administrationId The administration.
	 * @param int    $fiscalYear       The year, 0 when the item has no date.
	 *
	 * @return array<int, array<string, mixed>> The rules.
	 */
	private function rulesFor(string $register, string $administrationId, int $fiscalYear): array {
		$rows = $this->objectService->setRegister($register)->setSchema('PassThroughMarkupRule')->findAll(
			['filters' => PassThroughMarkupResolver::filters(administrationId: $administrationId, fiscalYear: $fiscalYear)]
		);

		$rules = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rules[] = $row;
			}
		}

		return $rules;
	}//end rulesFor()

	/**
	 * The year of an item's date, or 0 when it has none.
	 *
	 * @param string $date The date (Y-m-d...).
	 *
	 * @return int The year.
	 */
	private static function yearOf(string $date): int {
		if (preg_match('/^(\d{4})-/', $date, $match) !== 1) {
			return 0;
		}

		return (int)$match[1];
	}//end yearOf()

	/**
	 * The configured register slug.
	 *
	 * @return string The slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
