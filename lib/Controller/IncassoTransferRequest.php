<?php

/**
 * Incasso Transfer Request
 *
 * The invoice, customer and dunning run of the incasso dossier and transfer
 * endpoints as read from the request.
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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * Identifies the invoice, customer and run a stage-5 dossier is for.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */
final class IncassoTransferRequest {
	/**
	 * The invoice, '' when not given.
	 *
	 * @var string
	 */
	public readonly string $invoiceId;

	/**
	 * The customer, '' when not given.
	 *
	 * @var string
	 */
	public readonly string $customerId;

	/**
	 * The dunning run, '' when not given.
	 *
	 * @var string
	 */
	public readonly string $dunningRunId;

	/**
	 * Read the identifiers from the request.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->invoiceId = (string)$request->getParam('invoiceId', '');
		$this->customerId = (string)$request->getParam('customerId', '');
		$this->dunningRunId = (string)$request->getParam('dunningRunId', '');

	}//end __construct()
}//end class
