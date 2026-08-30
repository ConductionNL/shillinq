<?php

/**
 * Quote -> Order -> Invoice Lifecycle Guard
 *
 * ADR-031 exception-path lifecycle guards for the Sales Funnel (Q2C) registers
 * (bookkeeping-quote-order-invoice, T3). The bulk of the quote-to-cash model is
 * declarative (schema metadata + x-openregister-lifecycle +
 * x-openregister-calculations / -aggregations). A small set of preconditions
 * require cross-line / cross-schema lookups that OpenRegister's declarative
 * `requires:` clause cannot yet express; those are referenced from the schema
 * lifecycle transitions and implemented here:
 *
 *  - canSendQuote():          a Quote must carry a customer and at least one
 *                             QuoteLine before it may be sent (REQ-QOI-001).
 *  - canAcceptQuote():        an accepted Quote must record an acceptance channel
 *                             so the acceptance is auditable (REQ-QOI-001, design D1).
 *  - canConfirmOrder():       a SalesOrder may not be confirmed while the customer
 *                             is under a block-order / block-delivery credit hold
 *                             (REQ-QOI-004 / design D9).
 *  - canConfirmDelivery():    a Delivery may not be confirmed while the customer is
 *                             under a block-delivery credit hold, every line must
 *                             reference an order line and a positive quantity
 *                             (REQ-QOI-005 / design D3/D9), AND — per
 *                             inventory-sales-issue-cogs-trigger REQ-005 — every
 *                             stock-tracked line's quantityShipped must not exceed
 *                             available InventoryStock at the resolved warehouse,
 *                             unless the administration's InventoryGLConfig sets
 *                             allowNegativeStockOnDispatch.
 *  - canCancelDelivery():     a Delivery may only be cancelled before it has shipped
 *                             (inventory-sales-issue-cogs-trigger REQ-006).
 *  - canIssueInvoice():       an Invoice may only be issued with a sequential number,
 *                             at least one line, and a balanced net + vat = gross total
 *                             (REQ-QOI-005 / Belastingdienst).
 *  - canIssueCreditNote():    a CreditNote must reference a source invoice, carry a
 *                             reason, and a positive amount (REQ-QOI-010 / design D10).
 *  - canReleaseCreditHold():  a CreditHold release must record who released it and a
 *                             reason — the audit trail (REQ-QOI-010 / design D9).
 *
 * Every public guard is wrapped by evaluate(), which fails closed: any exception
 * or malformed input denies the transition (CWE-863).
 *
 * ADR-031 exception reason: array-membership / cross-schema completeness checks are
 * not yet expressible in the declarative lifecycle DSL. When the engine gains those
 * capabilities, replace these references with declarative conditions and delete this
 * file. ADR-022: object reads use the real OpenRegister ObjectService API
 * (setRegister/setSchema/findAll) only — never findObject/findObjects/createFromArray.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-quote-order-invoice/specs/bookkeeping-quote-order-invoice/spec.md
 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use Closure;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for the Sales Funnel (Q2C) registers.
 *
 * Referenced from the register.d fragment schema lifecycle transitions
 * (Quote, SalesOrder, Delivery, Invoice, CreditNote, CreditHold) as
 * OCA\Shillinq\Lifecycle\QuoteOrderInvoiceGuard::<method>. Every guard fails
 * closed: any exception or malformed input denies the transition (CWE-863).
 *
 * @spec openspec/changes/bookkeeping-quote-order-invoice/specs/bookkeeping-quote-order-invoice/spec.md
 */
class QuoteOrderInvoiceGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug + thresholds.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Returns true iff a Quote may be sent (draft -> sent).
	 *
	 * REQ-QOI-001: a quote must carry a customer reference and at least one
	 * QuoteLine before it leaves draft.
	 *
	 * @param string $quoteId The Quote id (call-signature parity).
	 * @param array<string,mixed>|null $object The quote being transitioned.
	 *
	 * @return bool True when the quote may be sent.
	 *
	 * @spec openspec/changes/bookkeeping-quote-order-invoice/specs/bookkeeping-quote-order-invoice/spec.md
	 */
	public function canSendQuote(string $quoteId, ?array $object = null): bool {
		return $this->evaluate(
			context: 'send-quote',
			id: $quoteId,
			check: function () use ($quoteId, $object): bool {
				$quote = $this->resolveObject(schema: 'Quote', id: $quoteId, object: $object);
				if ($quote === null || trim((string)($quote['customerReference'] ?? '')) === '') {
					return false;
				}

				return count($this->findChildren(schema: 'QuoteLine', field: 'quote', parentId: $quoteId)) >= 1;
			}
		);

	}//end canSendQuote()

	/**
	 * Returns true iff a Quote may be accepted (sent -> accepted).
	 *
	 * REQ-QOI-001 / design D1: acceptance must record a channel (in-app link,
	 * e-signature, or manual back-office) so the acceptance is auditable.
	 *
	 * @param string $quoteId The Quote id (call-signature parity).
	 * @param array<string,mixed>|null $object The quote being transitioned.
	 *
	 * @return bool True when the quote may be accepted.
	 *
	 * @spec openspec/changes/bookkeeping-quote-order-invoice/specs/bookkeeping-quote-order-invoice/spec.md
	 */
	public function canAcceptQuote(string $quoteId, ?array $object = null): bool {
		return $this->evaluate(
			context: 'accept-quote',
			id: $quoteId,
			check: function () use ($quoteId, $object): bool {
				$quote = $this->resolveObject(schema: 'Quote', id: $quoteId, object: $object);
				if ($quote === null) {
					return false;
				}

				$allowed = ['in-app-link', 'e-signature', 'manual-backoffice'];
				return in_array((string)($quote['acceptanceChannel'] ?? ''), $allowed, true);
			}
		);

	}//end canAcceptQuote()

	/**
	 * Returns true iff a SalesOrder may be confirmed (draft -> confirmed).
	 *
	 * REQ-QOI-004 / design D9: a customer under an active block-order or
	 * block-delivery credit hold cannot have orders confirmed.
	 *
	 * @param string $orderId The SalesOrder id (call-signature parity).
	 * @param array<string,mixed>|null $object The order being transitioned.
	 *
	 * @return bool True when the order may be confirmed.
	 *
	 * @spec openspec/changes/bookkeeping-quote-order-invoice/specs/bookkeeping-quote-order-invoice/spec.md
	 */
	public function canConfirmOrder(string $orderId, ?array $object = null): bool {
		return $this->evaluate(
			context: 'confirm-order',
			id: $orderId,
			check: function () use ($orderId, $object): bool {
				$order = $this->resolveObject(schema: 'SalesOrder', id: $orderId, object: $object);
				$customer = trim((string)($order['customerReference'] ?? ''));
				if ($order === null || $customer === '') {
					return false;
				}

				return $this->customerIsBlocked(
					customerReference: $customer,
					blockingSeverities: ['block-order', 'block-delivery']
				) === false;
			}
		);

	}//end canConfirmOrder()

	/**
	 * Returns true iff a Delivery may be confirmed (draft -> confirmed).
	 *
	 * REQ-QOI-005 / design D3/D9: every delivery line must reference an order line
	 * and carry a positive shipped quantity, and the customer must not be under an
	 * active block-delivery credit hold. Per
	 * inventory-sales-issue-cogs-trigger REQ-005: every stock-tracked line's
	 * quantityShipped MUST NOT exceed available InventoryStock at the resolved
	 * warehouse, unless the administration's InventoryGLConfig sets
	 * allowNegativeStockOnDispatch=true.
	 *
	 * @param string $deliveryId The Delivery id (call-signature parity).
	 * @param array<string,mixed>|null $object The delivery being transitioned.
	 *
	 * @return bool True when the delivery may be confirmed.
	 *
	 * @spec openspec/changes/bookkeeping-quote-order-invoice/specs/bookkeeping-quote-order-invoice/spec.md
	 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
	 */
	public function canConfirmDelivery(string $deliveryId, ?array $object = null): bool {
		return $this->evaluate(
			context: 'confirm-delivery',
			id: $deliveryId,
			check: function () use ($deliveryId, $object): bool {
				$delivery = $this->resolveObject(schema: 'Delivery', id: $deliveryId, object: $object);
				if ($delivery === null || $this->deliveryLinesComplete(delivery: $delivery) === false) {
					return false;
				}

				$order = $this->resolveObject(
					schema: 'SalesOrder',
					id: (string)($delivery['sourceOrderReference'] ?? ''),
					object: null
				);
				$customer = trim((string)($order['customerReference'] ?? ''));
				if ($order === null || $customer === '') {
					return false;
				}

				if ($this->customerIsBlocked(
					customerReference: $customer,
					blockingSeverities: ['block-delivery']
				) === true
				) {
					return false;
				}

				return $this->deliveryStockAvailable(delivery: $delivery);
			}
		);

	}//end canConfirmDelivery()

	/**
	 * Returns true iff a Delivery may be cancelled (draft|confirmed -> cancelled).
	 *
	 * Per inventory-sales-issue-cogs-trigger REQ-006: a Delivery may only be
	 * cancelled before it has shipped — once `shipped`, any issued StockMove
	 * must be reversed through a CreditNote / RMA flow instead (out of this
	 * capability's scope).
	 *
	 * @param string $deliveryId The Delivery id (call-signature parity).
	 * @param array<string,mixed>|null $object The delivery being transitioned.
	 *
	 * @return bool True when the delivery may be cancelled.
	 *
	 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
	 */
	public function canCancelDelivery(string $deliveryId, ?array $object = null): bool {
		return $this->evaluate(
			context: 'cancel-delivery',
			id: $deliveryId,
			check: function () use ($deliveryId, $object): bool {
				$delivery = $this->resolveObject(schema: 'Delivery', id: $deliveryId, object: $object);
				if ($delivery === null) {
					return false;
				}

				return (string)($delivery['status'] ?? '') !== 'shipped';
			}
		);

	}//end canCancelDelivery()

	/**
	 * Returns true iff an Invoice may be issued (draft -> sent).
	 *
	 * REQ-QOI-005 / Belastingdienst: an invoice must carry a sequential number,
	 * at least one InvoiceLine, and a balanced net + vat = gross total (to the
	 * cent) before it is issued.
	 *
	 * @param string $invoiceId The Invoice id (call-signature parity).
	 * @param array<string,mixed>|null $object The invoice being transitioned.
	 *
	 * @return bool True when the invoice may be issued.
	 *
	 * @spec openspec/changes/bookkeeping-quote-order-invoice/specs/bookkeeping-quote-order-invoice/spec.md
	 */
	public function canIssueInvoice(string $invoiceId, ?array $object = null): bool {
		return $this->evaluate(
			context: 'issue-invoice',
			id: $invoiceId,
			check: function () use ($invoiceId, $object): bool {
				$invoice = $this->resolveObject(schema: 'Invoice', id: $invoiceId, object: $object);
				if ($invoice === null || trim((string)($invoice['invoiceNumber'] ?? '')) === '') {
					return false;
				}

				if (count($this->findChildren(schema: 'InvoiceLine', field: 'invoice', parentId: $invoiceId)) < 1) {
					return false;
				}

				$net = (float)($invoice['netAmount'] ?? 0);
				$vat = (float)($invoice['vatAmount'] ?? 0);
				$gross = (float)($invoice['grossAmount'] ?? 0);

				return abs(($net + $vat) - $gross) < 0.01;
			}
		);

	}//end canIssueInvoice()

	/**
	 * Returns true iff a CreditNote may be issued (draft -> issued).
	 *
	 * REQ-QOI-010 / design D10: a credit note must reference a source invoice,
	 * carry a reason code, and a positive amount before it offsets AR.
	 *
	 * @param string $creditNoteId The CreditNote id (call-signature parity).
	 * @param array<string,mixed>|null $object The credit note being transitioned.
	 *
	 * @return bool True when the credit note may be issued.
	 *
	 * @spec openspec/changes/bookkeeping-quote-order-invoice/specs/bookkeeping-quote-order-invoice/spec.md
	 */
	public function canIssueCreditNote(string $creditNoteId, ?array $object = null): bool {
		return $this->evaluate(
			context: 'issue-credit-note',
			id: $creditNoteId,
			check: function () use ($creditNoteId, $object): bool {
				$creditNote = $this->resolveObject(schema: 'CreditNote', id: $creditNoteId, object: $object);
				if ($creditNote === null) {
					return false;
				}

				return trim((string)($creditNote['sourceInvoiceReference'] ?? '')) !== ''
					&& trim((string)($creditNote['reason'] ?? '')) !== ''
					&& (float)($creditNote['totalAmount'] ?? 0) > 0;
			}
		);

	}//end canIssueCreditNote()

	/**
	 * Returns true iff a CreditHold may be released (active -> released).
	 *
	 * REQ-QOI-010 / design D9: a release must record who released it and a
	 * release reason — the audit trail.
	 *
	 * @param string $holdId The CreditHold id (call-signature parity).
	 * @param array<string,mixed>|null $object The hold being transitioned.
	 *
	 * @return bool True when the hold may be released.
	 *
	 * @spec openspec/changes/bookkeeping-quote-order-invoice/specs/bookkeeping-quote-order-invoice/spec.md
	 */
	public function canReleaseCreditHold(string $holdId, ?array $object = null): bool {
		return $this->evaluate(
			context: 'release-credit-hold',
			id: $holdId,
			check: function () use ($holdId, $object): bool {
				$hold = $this->resolveObject(schema: 'CreditHold', id: $holdId, object: $object);
				if ($hold === null) {
					return false;
				}

				return trim((string)($hold['releasedBy'] ?? '')) !== ''
					&& trim((string)($hold['releaseReason'] ?? '')) !== '';
			}
		);

	}//end canReleaseCreditHold()

	/**
	 * Run a guard check, failing closed: any exception denies the transition
	 * (CWE-863). Centralises the try/catch + logging shared by every guard.
	 *
	 * @param string $context Short slug used in the fail-closed log line.
	 * @param string $id The object id under transition (logged on failure).
	 * @param Closure $check The predicate returning the allow/deny decision.
	 *
	 * @return bool The check result, or false when the check throws.
	 */
	private function evaluate(string $context, string $id, Closure $check): bool {
		try {
			return (bool)$check();
		} catch (\Throwable $e) {
			$this->logger->error(
				'QuoteOrderInvoiceGuard: ' . $context . ' check failed — denying transition (fail-closed)',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return false;
		}

	}//end evaluate()

	/**
	 * Returns true iff every delivery line references an order line and carries a
	 * positive shipped quantity (REQ-QOI-005).
	 *
	 * @param array<string,mixed> $delivery The delivery being validated.
	 *
	 * @return bool True when all lines are complete.
	 */
	private function deliveryLinesComplete(array $delivery): bool {
		$lines = ($delivery['lines'] ?? []);
		if (is_array($lines) === false || count($lines) < 1) {
			return false;
		}

		foreach ($lines as $line) {
			if (is_array($line) === false
				|| trim((string)($line['orderLineReference'] ?? '')) === ''
				|| (float)($line['quantityShipped'] ?? 0) <= 0
			) {
				return false;
			}
		}

		return true;
	}//end deliveryLinesComplete()

	/**
	 * Returns true iff every stock-tracked line of the delivery has sufficient
	 * available InventoryStock at its resolved warehouse, or the administration
	 * has opted into allowNegativeStockOnDispatch (inventory-sales-issue-cogs-trigger
	 * REQ-005). Non-stock-tracked lines (no matching InventoryStock row for their
	 * SKU in this administration) are always permitted — they are service lines.
	 *
	 * @param array<string,mixed> $delivery The delivery being confirmed.
	 *
	 * @return bool True when every stock-tracked line clears its availability check.
	 */
	private function deliveryStockAvailable(array $delivery): bool {
		$administrationId = trim((string)($delivery['administrationId'] ?? ''));
		$lines = ($delivery['lines'] ?? []);
		if (is_array($lines) === false) {
			return true;
		}

		$allowNegative = $this->allowNegativeStockOnDispatch(administrationId: $administrationId);

		foreach ($lines as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$sku = trim((string)($line['productReference'] ?? ''));
			$quantity = (float)($line['quantityShipped'] ?? 0);
			if ($sku === '' || $quantity <= 0) {
				continue;
			}

			$stockRows = $this->findChildren(schema: 'InventoryStock', field: 'sku', parentId: $sku);
			$stockRows = array_values(
				array_filter(
					$stockRows,
					static fn (array $row): bool => ((string)($row['administrationId'] ?? '')) === $administrationId
				)
			);

			if (count($stockRows) === 0) {
				// Not stock-tracked (service line) — nothing to check.
				continue;
			}

			$locationId = trim(
				(string)($line['sourceLocationId'] ?? ($delivery['sourceLocationId'] ?? ''))
			);

			$available = $this->availableForLocation(rows: $stockRows, locationId: $locationId);

			if ($available < $quantity && $allowNegative === false) {
				return false;
			}
		}//end foreach

		return true;
	}//end deliveryStockAvailable()

	/**
	 * Resolve available quantity (quantity - reservedQuantity) for the given
	 * InventoryStock rows. When $locationId is non-empty, only that location's
	 * row is considered (0 when no row exists there); otherwise the row with the
	 * largest available quantity is used — the same fallback documented in
	 * inventory-sales-issue-cogs-trigger REQ-003.
	 *
	 * @param array<int,array<string,mixed>> $rows Candidate InventoryStock rows.
	 * @param string $locationId Preferred location, or '' for best-of.
	 *
	 * @return float Available quantity.
	 */
	private function availableForLocation(array $rows, string $locationId): float {
		if ($locationId !== '') {
			foreach ($rows as $row) {
				if ((string)($row['locationId'] ?? '') === $locationId) {
					return ((float)($row['quantity'] ?? 0) - (float)($row['reservedQuantity'] ?? 0));
				}
			}

			return 0.0;
		}

		$best = 0.0;
		foreach ($rows as $index => $row) {
			$candidate = ((float)($row['quantity'] ?? 0) - (float)($row['reservedQuantity'] ?? 0));
			if ($index === 0 || $candidate > $best) {
				$best = $candidate;
			}
		}

		return $best;
	}//end availableForLocation()

	/**
	 * Resolve InventoryGLConfig.allowNegativeStockOnDispatch for the administration,
	 * defaulting to false (block) per REQ-005 / REQ-IST-013 intent.
	 *
	 * @param string $administrationId Tenant scope.
	 *
	 * @return bool True when negative stock on dispatch is explicitly allowed.
	 */
	private function allowNegativeStockOnDispatch(string $administrationId): bool {
		if ($administrationId === '') {
			return false;
		}

		$configs = $this->findChildren(
			schema: 'InventoryGLConfig',
			field: 'administrationId',
			parentId: $administrationId
		);

		foreach ($configs as $config) {
			if (((bool)($config['allowNegativeStockOnDispatch'] ?? false)) === true) {
				return true;
			}
		}

		return false;
	}//end allowNegativeStockOnDispatch()

	/**
	 * Returns true iff the customer has any active CreditHold whose severity is in
	 * the supplied blocking set (REQ-QOI-010 / design D9).
	 *
	 * @param string $customerReference The customer FK to check.
	 * @param array<string> $blockingSeverities The severities that block the action.
	 *
	 * @return bool True when a blocking hold is active.
	 */
	private function customerIsBlocked(string $customerReference, array $blockingSeverities): bool {
		$holds = $this->findChildren(
			schema: 'CreditHold',
			field: 'customerReference',
			parentId: $customerReference
		);

		foreach ($holds as $hold) {
			if ((string)($hold['status'] ?? 'active') === 'active'
				&& in_array((string)($hold['severity'] ?? ''), $blockingSeverities, true) === true
			) {
				return true;
			}
		}

		return false;
	}//end customerIsBlocked()

	/**
	 * Resolve the object under transition, preferring the supplied in-flight
	 * object and falling back to an ObjectService lookup by id (ADR-022 real API).
	 *
	 * @param string $schema The OpenRegister schema slug to query.
	 * @param string $id The object id to look up if no object given.
	 * @param array<string,mixed>|null $object The in-flight object, if provided by the engine.
	 *
	 * @return array<string,mixed>|null The resolved object, or null when unavailable.
	 */
	private function resolveObject(string $schema, string $id, ?array $object): ?array {
		if ($object !== null) {
			return $object;
		}

		if ($id === '') {
			return null;
		}

		$results = $this->objectService()
			->setRegister($this->resolveRegister())
			->setSchema($schema)
			->findAll(['filters' => ['id' => $id]]);

		$first = ($this->toArrayList(results: $results)[0] ?? null);
		return $first;
	}//end resolveObject()

	/**
	 * Find child objects of a given schema whose $field matches $parentId
	 * (ADR-022 real ObjectService API).
	 *
	 * @param string $schema The child schema slug.
	 * @param string $field The FK field on the child pointing at the parent.
	 * @param string $parentId The parent id to match.
	 *
	 * @return array<int,array<string,mixed>> The matching child objects.
	 */
	private function findChildren(string $schema, string $field, string $parentId): array {
		if ($parentId === '') {
			return [];
		}

		$results = $this->objectService()
			->setRegister($this->resolveRegister())
			->setSchema($schema)
			->findAll(['filters' => [$field => $parentId]]);

		return $this->toArrayList(results: $results);
	}//end findChildren()

	/**
	 * Reduce a raw ObjectService result set to a list of array records.
	 *
	 * @param iterable<mixed> $results The raw ObjectService result set.
	 *
	 * @return array<int,array<string,mixed>> The array records only.
	 */
	private function toArrayList(iterable $results): array {
		$list = [];
		foreach ($results as $result) {
			if (is_array($result) === true) {
				$list[] = $result;
			}
		}

		return $list;
	}//end toArrayList()

	/**
	 * Lazily resolve the OpenRegister ObjectService from the container.
	 *
	 * @return object The OpenRegister ObjectService.
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = trim($this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq'));
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
