<?php

/**
 * PassThroughBilling
 *
 * Turns the pass-through receipts, mileage and per diem items a user ticked
 * into expense lines for the billing model, each with its cost and the markup
 * locked when its claim was submitted (an item submitted before the lock
 * existed is locked once, here). After an invoice is posted it moves every
 * claim whose pass-through items all sit on posted invoices to invoiced.
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
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Billing;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Lifecycle\Action\LockPassThroughMarkupAction;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\PassThroughMarkupResolver;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pass-through expense items on an invoice, and the claims they close.
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-3.1
 */
class PassThroughBilling {

	/**
	 * The claim transition that records a pass-through claim as billed.
	 *
	 * @var string
	 */
	public const MARK_INVOICED = 'markInvoiced';

	/**
	 * The claim state from which markInvoiced can be taken.
	 *
	 * @var string
	 */
	private const POSTED_CLAIM = 'posted';

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
	 * @param RegisterLocation       $location      Where the register lives.
	 * @param ObjectTransitionRunner $transitions   Runs the claim's markInvoiced transition.
	 * @param LoggerInterface        $logger        For a claim that could not be marked invoiced.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $location,
		private readonly ObjectTransitionRunner $transitions,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The engine input of the ticked items: one row per pass-through item of
	 * the administration and the customer, with its cost and locked markup.
	 * An item of another administration or customer, a reimbursable item and
	 * an unknown id are skipped, the same as an unknown claim id.
	 *
	 * @param array<int,array{schema:string,id:string,disbursement:bool}> $refs             The ticked items.
	 * @param string                                                       $administrationId The caller's administration.
	 * @param string                                                       $customerId       The invoiced customer.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-3.1
	 */
	public function expenseRows(array $refs, string $administrationId, string $customerId): array {
		$rows = [];
		foreach ($refs as $ref) {
			$item = $this->billableItem(ref: $ref, administrationId: $administrationId, customerId: $customerId);
			if ($item === null) {
				continue;
			}

			$rows[] = $this->expenseRow(item: $this->locked(item: $item, schema: $ref['schema']), ref: $ref);
		}

		return $rows;
	}//end expenseRows()

	/**
	 * After an invoice is posted, take markInvoiced on every posted claim of
	 * its items whose pass-through items now all sit on posted invoices
	 * (REQ-STEB-002). A claim the engine refuses is logged and left.
	 *
	 * @param array<string,mixed> $invoice The posted BillableInvoice.
	 *
	 * @return list<string> The claims marked invoiced.
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-3.3
	 */
	public function markBilledClaims(array $invoice): array {
		$refs = (array)($invoice['expenseItemRefs'] ?? []);
		$claimIds = array_values(array_unique(array_filter(array_map(static fn ($ref): string => (string)($ref['claimId'] ?? ''), $refs))));
		if ($claimIds === []) {
			return [];
		}

		$administrationId = (string)($invoice['administrationId'] ?? '');
		$billed = $this->postedItemIds(administrationId: $administrationId, invoice: $invoice);
		$marked = [];
		foreach ($claimIds as $claimId) {
			$claim = $this->findIn(schema: 'ExpenseClaimEntry', id: $claimId, administrationId: $administrationId);
			if ($claim === null || (string)($claim['state'] ?? '') !== self::POSTED_CLAIM
				|| $this->allPassThroughBilled(claim: $claim, administrationId: $administrationId, billed: $billed) === false
			) {
				continue;
			}

			try {
				$this->transitions->run(objectId: $claimId, action: self::MARK_INVOICED);
				$marked[] = $claimId;
			} catch (Throwable $e) {
				$this->logger->warning(
					'[PassThroughBilling] A fully billed claim could not be marked invoiced: '.$e->getMessage(),
					['claimId' => $claimId, 'invoiceId' => ($invoice['id'] ?? null)]
				);
			}
		}

		return $marked;
	}//end markBilledClaims()

	/**
	 * The item a reference names, when it is a pass-through item of the
	 * administration and the customer.
	 *
	 * @param array{schema:string,id:string,disbursement:bool} $ref              The reference.
	 * @param string                                           $administrationId The administration.
	 * @param string                                           $customerId       The customer.
	 *
	 * @return array<string,mixed>|null
	 */
	private function billableItem(array $ref, string $administrationId, string $customerId): ?array {
		if (isset(LockPassThroughMarkupAction::ITEM_SOURCES[$ref['schema']]) === false) {
			return null;
		}

		$item = $this->findIn(schema: $ref['schema'], id: $ref['id'], administrationId: $administrationId);
		if ($item === null || ($item['settlementMode'] ?? null) !== 'pass-through'
			|| (string)($item['linkedCustomerId'] ?? '') !== $customerId
		) {
			return null;
		}

		return $item;
	}//end billableItem()

	/**
	 * The item with its markup locked. An item submitted before the lock
	 * existed carries none; it is resolved now and written once, so a later
	 * rule change leaves it as billed.
	 *
	 * @param array<string,mixed> $item   The item.
	 * @param string              $schema Its schema.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-3.1
	 */
	private function locked(array $item, string $schema): array {
		if (array_key_exists('markupAmountCalculated', $item) === true && $item['markupAmountCalculated'] !== null) {
			return $item;
		}

		$source = LockPassThroughMarkupAction::ITEM_SOURCES[$schema];
		$category = (string)($item['category'] ?? '');
		if ($category === '') {
			$category = $source['category'];
		}

		$year = 0;
		if (preg_match('/^(\d{4})-/', (string)($item[$source['date']] ?? ''), $match) === 1) {
			$year = (int)$match[1];
		}

		$rules = $this->rows(
			schema: 'PassThroughMarkupRule',
			filters: PassThroughMarkupResolver::filters(administrationId: (string)$item['administrationId'], fiscalYear: $year)
		);
		$item = array_merge(
			$item,
			(new PassThroughMarkupResolver())->resolve(
				rules: $rules,
				customerId: (string)($item['linkedCustomerId'] ?? ''),
				category: $category,
				cost: (float)($item[$source['amount']] ?? 0.0)
			)
		);
		$this->objectService->saveObject(
			object: $item,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->location->getRegisterSlug(), schema: $schema))
		);

		return $item;
	}//end locked()

	/**
	 * One engine row: the item's id, description, cost and recharge facts.
	 *
	 * @param array<string,mixed>                              $item The locked item.
	 * @param array{schema:string,id:string,disbursement:bool} $ref  The reference that ticked it.
	 *
	 * @return array<string,mixed>
	 */
	private function expenseRow(array $item, array $ref): array {
		$source = LockPassThroughMarkupAction::ITEM_SOURCES[$ref['schema']];
		$costCents = self::cents(value: $item[$source['amount']] ?? 0);
		$rate = null;
		if (isset($item['markupRateApplied']) === true) {
			$rate = (float)$item['markupRateApplied'];
		}

		$description = trim((string)($item['description'] ?? ''));
		if ($description === '') {
			$description = $ref['schema'];
		}

		return [
			'expenseId' => $ref['id'],
			'description' => $description,
			'costAmountCents' => $costCents,
			'recharge' => [
				'schema' => $ref['schema'],
				'claimId' => (string)($item['claimId'] ?? ''),
				'costAmountCents' => $costCents,
				'markupRuleId' => $item['markupRuleId'] ?? null,
				'markupRate' => $rate,
				'markupAmountCents' => self::cents(value: $item['markupAmountCalculated'] ?? 0),
				'disbursement' => $ref['disbursement'],
			],
		];
	}//end expenseRow()

	/**
	 * Whether every pass-through item of the claim sits on a posted invoice.
	 *
	 * @param array<string,mixed> $claim            The claim.
	 * @param string              $administrationId The administration.
	 * @param array<string,true>  $billed           Item ids on posted invoices.
	 *
	 * @return bool
	 */
	private function allPassThroughBilled(array $claim, string $administrationId, array $billed): bool {
		$passThrough = 0;
		foreach (LockPassThroughMarkupAction::ITEM_SOURCES as $schema => $source) {
			foreach ((array)($claim[$source['ids']] ?? []) as $itemId) {
				$item = $this->findIn(schema: $schema, id: (string)$itemId, administrationId: $administrationId);
				if ($item === null || ($item['settlementMode'] ?? null) !== 'pass-through') {
					continue;
				}

				$passThrough++;
				if (isset($billed[(string)$itemId]) === false && isset($billed[(string)($item['id'] ?? '')]) === false) {
					return false;
				}
			}
		}

		return $passThrough > 0;
	}//end allPassThroughBilled()

	/**
	 * The item ids on the administration's posted invoices, this one included.
	 *
	 * @param string              $administrationId The administration.
	 * @param array<string,mixed> $invoice          The invoice just posted.
	 *
	 * @return array<string,true>
	 */
	private function postedItemIds(string $administrationId, array $invoice): array {
		$invoices = $this->rows(schema: 'BillableInvoice', filters: ['administrationId' => $administrationId, 'status' => 'posted']);
		$invoices[] = $invoice;
		$ids = [];
		foreach ($invoices as $posted) {
			if ((string)($posted['administrationId'] ?? '') !== $administrationId) {
				continue;
			}

			foreach ((array)($posted['expenseItemRefs'] ?? []) as $ref) {
				$ids[(string)($ref['id'] ?? '')] = true;
			}
		}

		return $ids;
	}//end postedItemIds()

	/**
	 * One record of the administration, or null when absent or another administration's.
	 *
	 * @param string $schema           The schema slug.
	 * @param string $id               The id.
	 * @param string $administrationId The administration.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findIn(string $schema, string $id, string $administrationId): ?array {
		if ($id === '' || $administrationId === '') {
			return null;
		}

		try {
			$record = ObjectIdentifier::findOne(
				scoped: $this->objectService->setRegister($this->location->getRegisterSlug())->setSchema($schema),
				id: $id
			);
		} catch (Throwable) {
			return null;
		}

		if ($record === null || (string)($record['administrationId'] ?? '') !== $administrationId) {
			return null;
		}

		return $record;
	}//end findIn()

	/**
	 * The rows of a schema matching the filters, each with its id.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		try {
			$found = $this->objectService
				->setRegister($this->location->getRegisterSlug())
				->setSchema($schema)
				->findAll(['filters' => $filters, 'limit' => self::LIMIT]);
		} catch (Throwable) {
			return [];
		}

		$rows = [];
		foreach ($found as $candidate) {
			$row = ObjectIdentifier::recordWithId(candidate: $candidate);
			if ($row !== null) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()

	/**
	 * Euros to integer cents, rounded half to even.
	 *
	 * @param mixed $value The amount in euros.
	 *
	 * @return int
	 */
	private static function cents(mixed $value): int {
		return (int)round(((float)$value) * 100, 0, PHP_ROUND_HALF_EVEN);
	}//end cents()
}//end class
