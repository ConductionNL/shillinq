<?php

/**
 * Payment Block Write Scope
 *
 * Marks the span in which PaymentBlockService writes the payment block fields,
 * so PaymentBlockLockListener can tell that write from any other one. Shared
 * per app container, so the service and the listener see the same flag.
 *
 * @category PaymentRun
 * @package  OCA\Shillinq\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentRun;

/**
 * A flag that is set only while the block service writes.
 *
 * @spec openspec/specs/payment-control-guards/spec.md
 */
class PaymentBlockWriteScope {

	/**
	 * Whether the block service is writing.
	 *
	 * @var boolean
	 */
	private bool $open = false;

	/**
	 * Run an operation with the block fields open to write.
	 *
	 * @param callable $operation The write.
	 *
	 * @return mixed What the operation returns.
	 *
	 * @spec openspec/specs/payment-control-guards/spec.md
	 */
	public function run(callable $operation): mixed {
		$this->open = true;
		try {
			return $operation();
		} finally {
			$this->open = false;
		}

	}//end run()

	/**
	 * Whether the block service is writing now.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/payment-control-guards/spec.md
	 */
	public function isOpen(): bool {
		return $this->open;

	}//end isOpen()
}//end class
