<?php

/**
 * GR/IR receipt fan-out
 *
 * One fan-out of GRIRClearingService::createGRIRPosting() over the lines of
 * a just-accepted receipt: the administration, the receipt (a GRN, or a
 * service receipt normalised to its shape), the schema its lines live in and
 * the field on a line that references the receipt.
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
 * @spec openspec/specs/grir-accrual-wiring/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * A receipt whose accepted lines are posted to GR/IR.
 *
 * @spec openspec/specs/grir-accrual-wiring/spec.md
 */
final class GRIRReceiptFanOut {
	/**
	 * Hold the fan-out.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<string,mixed> $receipt The accepted receipt (GRN or SvcReceipt).
	 * @param string $lineSchema Child line schema slug.
	 * @param string $parentField FK field on the line row that references the receipt id.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly array $receipt,
		public readonly string $lineSchema,
		public readonly string $parentField,
	) {
	}//end __construct()

	/**
	 * The receipt's record id, '' when it has none.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/grir-accrual-wiring/spec.md
	 */
	public function receiptId(): string {
		return (string)($this->receipt['id'] ?? ($this->receipt['@self']['id'] ?? ''));
	}//end receiptId()
}//end class
