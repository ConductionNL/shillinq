<?php

/**
 * Reorder Proposal Service
 *
 * The daily reorder pass (REQ-IRA-011): every active InventoryReorderRule is
 * compared with the stock record it watches. Stock at or below the rule's
 * reorder point raises a ReorderProposal (the low-stock alert) and, when the
 * rule names a preferred supplier, a draft PurchaseOrder for the reorder
 * quantity that waits for the buyer. At most one proposal per rule is open:
 * while it is open the pass writes nothing more for that rule, and once the
 * stock is back above the reorder point the pass resolves it, so the next dip
 * proposes again.
 *
 * The pass runs as the system (cron has no user), so it reads every
 * administration's rules; everything else it reads or writes for a rule is
 * filtered on that rule's own administration.
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
 * @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/specs/inventory-reorder-automation/spec.md (REQ-IRA-011)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Compares reorder rules with their stock and proposes purchases.
 *
 * @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/specs/inventory-reorder-automation/spec.md (REQ-IRA-011)
 */
class ReorderProposalService {
	/**
	 * Records read per page.
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Proposal status while the draft order waits for the buyer.
	 */
	public const STATUS_OPEN = 'open';

	/**
	 * Proposal status once the stock is back above the reorder point.
	 */
	public const STATUS_RESOLVED = 'resolved';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService  OpenRegister's object service.
	 * @param PurchaseOrderService   $purchaseOrders Numbers the proposed draft order.
	 * @param IAppConfig             $appConfig      Register slug.
	 * @param LoggerInterface        $logger         Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly PurchaseOrderService $purchaseOrders,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the pass over every active reorder rule, as the system.
	 *
	 * @param DateTimeImmutable $now The moment of the run.
	 *
	 * @return array{rules:int,proposed:int,resolved:int,unchanged:int,skipped:int,failed:int} What the pass did.
	 *
	 * @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/specs/inventory-reorder-automation/spec.md (REQ-IRA-011)
	 */
	public function proposeAll(DateTimeImmutable $now): array {
		return $this->objectService->runAsSystem(
			function () use ($now): array {
				$report = ['rules' => 0, 'proposed' => 0, 'resolved' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];

				$byAdministration = [];
				foreach ($this->page(schema: 'InventoryReorderRule', filters: ['lifecycleState' => 'active']) as $rule) {
					$report['rules']++;
					// A rule without an administration has no scope to act in, and
					// one without an id cannot be tied to its one open proposal.
					$administrationId = trim((string)($rule['administrationId'] ?? ''));
					if ($administrationId === '' || (string)($rule['id'] ?? '') === '') {
						$report['skipped']++;
						continue;
					}

					$byAdministration[$administrationId][] = $rule;
				}

				foreach ($byAdministration as $administrationId => $rules) {
					foreach ($this->proposeForAdministration(administrationId: (string)$administrationId, rules: $rules, now: $now) as $outcome) {
						$report[$outcome]++;
					}
				}

				return $report;
			}
		);
	}//end proposeAll()

	/**
	 * Run the pass for the rules of one administration, against that
	 * administration's own stock and open proposals.
	 *
	 * @param string                         $administrationId The administration.
	 * @param array<int,array<string,mixed>> $rules            Its active rules.
	 * @param DateTimeImmutable              $now              The moment of the run.
	 *
	 * @return array<int,string> One outcome per rule: proposed, resolved, unchanged, skipped or failed.
	 */
	private function proposeForAdministration(string $administrationId, array $rules, DateTimeImmutable $now): array {
		$stock = [];
		foreach ($this->page(schema: 'InventoryStock', filters: ['administrationId' => $administrationId]) as $record) {
			$stock[(string)$record['id']] = $record;
		}

		$open = [];
		$openFilters = ['administrationId' => $administrationId, 'statusCode' => self::STATUS_OPEN];
		foreach ($this->page(schema: 'ReorderProposal', filters: $openFilters) as $proposal) {
			$open[(string)($proposal['reorderRuleId'] ?? '')] = $proposal;
		}

		$outcomes = [];
		foreach ($rules as $rule) {
			$ruleId = (string)$rule['id'];
			try {
				$outcomes[] = $this->proposeForRule(
					rule: $rule,
					stock: ($stock[(string)($rule['inventoryStockId'] ?? '')] ?? null),
					openProposal: ($open[$ruleId] ?? null),
					now: $now
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'ReorderProposalService: reorder rule failed',
					['reorderRuleId' => $ruleId, 'exception' => $e->getMessage()]
				);
				$outcomes[] = 'failed';
			}
		}

		return $outcomes;
	}//end proposeForAdministration()

	/**
	 * Compare one rule with its stock and write what follows.
	 *
	 * @param array<string,mixed>      $rule         The active rule.
	 * @param array<string,mixed>|null $stock        Its stock record in the same administration, if any.
	 * @param array<string,mixed>|null $openProposal Its open proposal, if any.
	 * @param DateTimeImmutable        $now          The moment of the run.
	 *
	 * @return string proposed, resolved, unchanged or skipped.
	 */
	private function proposeForRule(array $rule, ?array $stock, ?array $openProposal, DateTimeImmutable $now): string {
		if ($stock === null) {
			return 'skipped';
		}

		$onHand       = (float)($stock['quantityOnHand'] ?? 0);
		$reorderPoint = (float)($rule['reorderPoint'] ?? 0);

		if ($onHand > $reorderPoint) {
			if ($openProposal === null) {
				return 'unchanged';
			}

			$openProposal['statusCode'] = self::STATUS_RESOLVED;
			$openProposal['resolvedAt'] = $now->format(DATE_ATOM);
			$this->save(schema: 'ReorderProposal', object: $openProposal);
			return 'resolved';
		}

		if ($openProposal !== null) {
			return 'unchanged';
		}

		$reorderQuantity = (float)($rule['reorderQuantity'] ?? 0);
		if ($reorderQuantity <= 0.0 || $this->isSnoozed(rule: $rule, now: $now) === true) {
			return 'skipped';
		}

		$this->raiseProposal(rule: $rule, stock: $stock, now: $now);
		return 'proposed';
	}//end proposeForRule()

	/**
	 * Save the draft order (when the rule names a supplier) and the open
	 * proposal that alerts on the low stock.
	 *
	 * @param array<string,mixed> $rule  The rule.
	 * @param array<string,mixed> $stock Its stock record.
	 * @param DateTimeImmutable   $now   The moment of the run.
	 *
	 * @return void
	 */
	private function raiseProposal(array $rule, array $stock, DateTimeImmutable $now): void {
		$administrationId = (string)$rule['administrationId'];
		$supplierId       = trim((string)($rule['supplierId'] ?? ''));
		$onHand           = (float)($stock['quantityOnHand'] ?? 0);
		$reorderPoint     = (float)($rule['reorderPoint'] ?? 0);
		$reorderQuantity  = (float)$rule['reorderQuantity'];
		$productId        = (string)($stock['productId'] ?? '');
		$locationCode     = (string)($stock['locationCode'] ?? '');

		$purchaseOrderId  = null;
		$proposedSupplier = null;
		if ($supplierId !== '') {
			$proposedSupplier = $supplierId;
			$purchaseOrderId  = $this->save(
				schema: 'PurchaseOrder',
				object: $this->draftPurchaseOrder(
					administrationId: $administrationId,
					supplierId: $supplierId,
					productId: $productId,
					quantity: $reorderQuantity,
					unitPrice: (float)($stock['unitCost'] ?? 0),
					createdAt: $now->format(DATE_ATOM),
					notes: sprintf(
						'Proposed by reorder rule %s: %s on hand at %s, reorder point %s.',
						(string)$rule['id'],
						$this->number(value: $onHand),
						$locationCode,
						$this->number(value: $reorderPoint)
					)
				)
			);
		}

		$this->save(
			schema: 'ReorderProposal',
			object: [
				'reorderRuleId' => (string)$rule['id'],
				'inventoryStockId' => (string)$stock['id'],
				'productId' => $productId,
				'locationCode' => $locationCode,
				'quantityOnHand' => $onHand,
				'reorderPoint' => $reorderPoint,
				'reorderQuantity' => $reorderQuantity,
				'supplierId' => $proposedSupplier,
				'purchaseOrderId' => $purchaseOrderId,
				'raisedAt' => $now->format(DATE_ATOM),
				'statusCode' => self::STATUS_OPEN,
				'administrationId' => $administrationId,
			]
		);
	}//end raiseProposal()

	/**
	 * The draft PurchaseOrder proposed for a buyer to complete.
	 *
	 * The pass acts as the system, so no requester, cost center, GL account or
	 * approval chain is known yet. The order stays `draft` with an empty
	 * approval chain, which PurchaseOrderService::blockSendUntilApproved()
	 * refuses to send, so nothing leaves the administration until a buyer has
	 * completed it. The number comes from the same sequence as every other
	 * order of the administration.
	 *
	 * @param string $administrationId The rule's administration.
	 * @param string $supplierId       The preferred supplier.
	 * @param string $productId        The product to reorder.
	 * @param float  $quantity         The reorder quantity.
	 * @param float  $unitPrice        The stock's unit cost, in euro.
	 * @param string $createdAt        The moment of the run.
	 * @param string $notes            Why the order was proposed.
	 *
	 * @return array<string,mixed> The PurchaseOrder record.
	 */
	private function draftPurchaseOrder(
		string $administrationId,
		string $supplierId,
		string $productId,
		float $quantity,
		float $unitPrice,
		string $createdAt,
		string $notes,
	): array {
		$unitCents  = (int)round(($unitPrice * 100), 0, PHP_ROUND_HALF_UP);
		$totalCents = (int)round(($unitCents * $quantity), 0, PHP_ROUND_HALF_UP);

		return [
			'poNumber' => $this->purchaseOrders->generatePoNumber(administrationId: $administrationId),
			'administrationId' => $administrationId,
			'supplierId' => $supplierId,
			'requesterId' => '',
			'costCenter' => '',
			'projectCode' => '',
			'requisitionId' => '',
			'frameworkAgreementId' => '',
			'lines' => [
				[
					'lineNumber' => 1,
					'productCode' => $productId,
					'quantity' => $quantity,
					'unitPrice' => ($unitCents / 100.0),
					'lineTotal' => ($totalCents / 100.0),
					'vatRate' => 0.0,
					'vatAmount' => 0.0,
					'glAccount' => '',
				],
			],
			'totalAmount' => ($totalCents / 100.0),
			'currency' => 'EUR',
			'approvalChain' => [],
			'statusCode' => 'draft',
			'createdAt' => $createdAt,
			'notes' => $notes,
		];
	}//end draftPurchaseOrder()

	/**
	 * Whether the rule's alerts are snoozed past the moment of the run.
	 *
	 * @param array<string,mixed> $rule The rule.
	 * @param DateTimeImmutable   $now  The moment of the run.
	 *
	 * @return bool
	 */
	private function isSnoozed(array $rule, DateTimeImmutable $now): bool {
		$snoozeUntil = trim((string)($rule['snoozeUntil'] ?? ''));
		if ($snoozeUntil === '') {
			return false;
		}

		return (new DateTimeImmutable($snoozeUntil)) > $now;
	}//end isSnoozed()

	/**
	 * A quantity as a person writes it: 8, not 8.0; 2.5 as 2.5.
	 *
	 * @param float $value The quantity.
	 *
	 * @return string
	 */
	private function number(float $value): string {
		return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
	}//end number()

	/**
	 * Every record of a schema matching the filters, page by page, read as the
	 * system across organisations (the filters carry the administration).
	 *
	 * @param string              $schema  The schema.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return \Generator<int,array<string,mixed>>
	 */
	private function page(string $schema, array $filters): \Generator {
		$offset = 0;
		do {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters, 'limit' => self::PAGE_SIZE, 'offset' => $offset], _rbac: false, _multitenancy: false);
			foreach ($rows as $row) {
				$record = ObjectIdentifier::recordWithId(candidate: $row);
				if ($record !== null) {
					yield $record;
				}
			}

			$offset += self::PAGE_SIZE;
			$full    = (count($rows) === self::PAGE_SIZE);
		} while ($full === true);
	}//end page()

	/**
	 * Save one record.
	 *
	 * @param string              $schema The schema.
	 * @param array<string,mixed> $object The record.
	 *
	 * @return string The saved record's id.
	 */
	private function save(string $schema, array $object): string {
		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->saveObject($object);

		return ObjectIdentifier::resolve(saved: $saved);
	}//end save()

	/**
	 * The configured register slug.
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
