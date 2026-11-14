<?php

/**
 * Landed Cost Request
 *
 * The input of POST /api/inventory/landed-cost as read from the request.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The receipt, cost and allocation basis of one landed-cost allocation.
 *
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 */
final class LandedCostRequest {
	/**
	 * Allowed shape of an administration or receipt reference.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-:\\/ ]{1,128}$/';

	/**
	 * The administration, trimmed.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The goods receipt, trimmed.
	 *
	 * @var string
	 */
	public readonly string $receiptReference;

	/**
	 * The landed cost in cents.
	 *
	 * @var integer
	 */
	public readonly int $landedCostCents;

	/**
	 * 'value' (default) or 'quantity' as sent, trimmed.
	 *
	 * @var string
	 */
	public readonly string $basis;

	/**
	 * Read the allocation from the request.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->administrationId = trim((string)$request->getParam('administration_id', ''));
		$this->receiptReference = trim((string)$request->getParam('receipt_reference', ''));
		$this->landedCostCents = (int)$request->getParam('landed_cost_cents', 0);
		$this->basis = trim((string)$request->getParam('basis', 'value'));

	}//end __construct()

	/**
	 * Whether the administration and the receipt are given.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function isComplete(): bool {
		return $this->administrationId !== '' && $this->receiptReference !== '';

	}//end isComplete()

	/**
	 * Whether the ids have their shape, the cost is positive and the basis known.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function isWellFormed(): bool {
		return preg_match(self::ID_PATTERN, $this->administrationId) === 1
			&& preg_match(self::ID_PATTERN, $this->receiptReference) === 1
			&& $this->landedCostCents > 0
			&& in_array($this->basis, ['value', 'quantity'], true) === true;

	}//end isWellFormed()
}//end class
