<?php

/**
 * Chosen Candidate
 *
 * The PO line and optional GRN line an operator chose to resolve an
 * ambiguous multi-PO match.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The operator's chosen (PO line, GRN line) tuple, read from the request.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 */
final class ChosenCandidate {
	/**
	 * The chosen PO line id, trimmed.
	 *
	 * @var string
	 */
	public readonly string $poLineId;

	/**
	 * The chosen GRN line id, trimmed ('' when none).
	 *
	 * @var string
	 */
	public readonly string $grnLineId;

	/**
	 * Read the chosen tuple.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->poLineId = trim((string)$request->getParam('chosenPoLineId', ''));
		$this->grnLineId = trim((string)$request->getParam('chosenGrnLineId', ''));

	}//end __construct()

	/**
	 * Whether a well-formed PO line id is given.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	public function hasPoLine(): bool {
		return $this->poLineId !== '' && preg_match(InvoiceLineScope::ID_PATTERN, $this->poLineId) === 1;
	}//end hasPoLine()

	/**
	 * Whether the GRN line id is absent or well-formed.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	public function grnLineWellFormed(): bool {
		return $this->grnLineId === '' || preg_match(InvoiceLineScope::ID_PATTERN, $this->grnLineId) === 1;
	}//end grnLineWellFormed()
}//end class
