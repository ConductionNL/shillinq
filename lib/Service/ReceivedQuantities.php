<?php

/**
 * Received quantities
 *
 * Accumulates the accepted quantity per purchase-order line (in integer
 * thousandths, so multipleOf 0.001 stays bit-exact) across the receipt lines
 * of goods or service receipts, and derives from it the receipt lifecycle a
 * PurchaseOrder moves to: fully_received when every line is fully accepted,
 * partial_received when any line has an accepted quantity.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Accepted quantities per PO line and the receipt lifecycle they imply.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */
final class ReceivedQuantities {
	/**
	 * Accepted thousandths per PO-line id.
	 *
	 * @var array<string,int>
	 */
	private array $acceptedByPoLine = [];

	/**
	 * Add one receipt line's accepted quantity to its PO line; a receipt
	 * line without a PO line is ignored.
	 *
	 * @param string $poLineId         The PO line the receipt line targets.
	 * @param mixed  $quantityAccepted The accepted quantity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function add(string $poLineId, mixed $quantityAccepted): void {
		if ($poLineId === '') {
			return;
		}

		$thousandths = $this->thousandths(value: (float)$quantityAccepted);
		$this->acceptedByPoLine[$poLineId] = (($this->acceptedByPoLine[$poLineId] ?? 0) + $thousandths);
	}//end add()

	/**
	 * The statusCode the PurchaseOrder moves to, or null when it keeps its
	 * current one (no PO lines, nothing accepted, or no change).
	 *
	 * @param array<string,mixed>            $po      The PurchaseOrder.
	 * @param array<int,array<string,mixed>> $poLines Its lines.
	 *
	 * @return string|null The new statusCode, or null.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function lifecycleFor(array $po, array $poLines): ?string {
		if ($poLines === []) {
			return null;
		}

		$current = ($po['statusCode'] ?? '');
		$lifecycle = ($this->receivedLifecycle(poLines: $poLines) ?? $current);
		if ($lifecycle === $current) {
			return null;
		}

		return $lifecycle;
	}//end lifecycleFor()

	/**
	 * fully_received when every line is fully accepted, partial_received when
	 * any line has an accepted quantity, otherwise null.
	 *
	 * @param array<int,array<string,mixed>> $poLines The PO lines.
	 *
	 * @return string|null The lifecycle, or null.
	 */
	private function receivedLifecycle(array $poLines): ?string {
		$allFullyReceived = true;
		$anyReceived = false;
		foreach ($poLines as $line) {
			$lineId = (string)($line['id'] ?? ($line['@self']['id'] ?? ''));
			$acceptedAmount = (int)($this->acceptedByPoLine[$lineId] ?? 0);
			$anyReceived = ($anyReceived || $acceptedAmount > 0);
			if ($acceptedAmount < $this->thousandths(value: (float)($line['quantityOrdered'] ?? 0))) {
				$allFullyReceived = false;
			}
		}

		if ($allFullyReceived === true) {
			return 'fully_received';
		}

		if ($anyReceived === true) {
			return 'partial_received';
		}

		return null;
	}//end receivedLifecycle()

	/**
	 * Convert a quantity to integer thousandths (HALF_UP).
	 *
	 * @param float $value Quantity value.
	 *
	 * @return int Thousandths.
	 */
	private function thousandths(float $value): int {
		return (int)round(($value * 1000.0), 0, PHP_ROUND_HALF_UP);
	}//end thousandths()
}//end class
