<?php

/**
 * Purchase Order Transition
 *
 * One purchase order move the caller may make — send after approval,
 * transmit over Peppol, or transmit as PDF by e-mail — with the answer and
 * log line a failure of it gives.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * An authorised purchase-order transition.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-002
 */
final class PurchaseOrderTransition {
	/**
	 * Per transition: the log line and the error answered when it fails unexpectedly.
	 *
	 * @var array<string, array{log: string, error: string}>
	 */
	private const FAILURES = [
		'send' => ['log' => 'PurchaseOrderController: failed to advance purchase order', 'error' => 'Could not advance purchase order'],
		'peppol' => ['log' => 'PurchaseOrderController: Peppol transmission failed', 'error' => 'Could not transmit purchase order'],
		'email' => ['log' => 'PurchaseOrderController: PDF+email transmission failed', 'error' => 'Could not transmit purchase order'],
	];

	/**
	 * The purchase order.
	 *
	 * @var string
	 */
	public readonly string $purchaseOrderId;

	/**
	 * The authorised administration.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The transition: send, peppol or email.
	 *
	 * @var string
	 */
	public readonly string $transition;

	/**
	 * Name one transition.
	 *
	 * @param string $purchaseOrderId  The purchase order.
	 * @param string $administrationId The authorised administration.
	 * @param string $transition       send, peppol or email.
	 */
	public function __construct(string $purchaseOrderId, string $administrationId, string $transition) {
		$this->purchaseOrderId = $purchaseOrderId;
		$this->administrationId = $administrationId;
		$this->transition = $transition;

	}//end __construct()

	/**
	 * The log line of an unexpected failure.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-002
	 */
	public function failureLog(): string {
		return self::FAILURES[$this->transition]['log'];
	}//end failureLog()

	/**
	 * The error answered on an unexpected failure.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-002
	 */
	public function failureError(): string {
		return self::FAILURES[$this->transition]['error'];
	}//end failureError()

	/**
	 * The order and administration as log context, with the failure.
	 *
	 * @param \Throwable $exception The failure.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-002
	 */
	public function logContext(\Throwable $exception): array {
		return [
			'purchaseOrderId' => $this->purchaseOrderId,
			'administrationId' => $this->administrationId,
			'exception' => $exception->getMessage(),
		];
	}//end logContext()
}//end class
