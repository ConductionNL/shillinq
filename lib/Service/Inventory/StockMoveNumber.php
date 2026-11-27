<?php

/**
 * A StockMove movement number in the form the StockMove schema accepts.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Inventory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-stock-movement-ledger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Inventory;

/**
 * Turns a derived movement number into one the StockMove schema accepts
 * (`^[A-Z0-9][A-Z0-9\-_]{0,31}$`: upper case, at most 32 characters).
 *
 * The derived numbers carry hex digests, administration ids and line ids,
 * which are lower case or too long; OpenRegister then refuses the StockMove
 * and the issue or receipt is lost. The result stays deterministic, so the
 * (administrationId, movementNumber) uniqueness still turns a repeat into a
 * no-op: a number that is too long keeps its first 23 characters and ends
 * in eight characters of its own digest.
 *
 * @spec openspec/specs/inventory-stock-movement-ledger/spec.md
 */
final class StockMoveNumber {

	/**
	 * The longest number the schema accepts.
	 */
	private const MAX_LENGTH = 32;

	/**
	 * The number in the accepted form.
	 *
	 * @param string $candidate The derived number.
	 *
	 * @return string The number, upper case, only A-Z, 0-9, '-' and '_', starting
	 *                with a letter or digit, at most 32 characters.
	 *
	 * @spec openspec/specs/inventory-stock-movement-ledger/spec.md
	 */
	public function normalise(string $candidate): string {
		$number = ltrim(strtoupper((string)preg_replace('/[^A-Za-z0-9_-]+/', '-', $candidate)), '-_');
		if ($number === '') {
			$number = 'SM';
		}

		if (strlen($number) <= self::MAX_LENGTH) {
			return $number;
		}

		return substr($number, 0, (self::MAX_LENGTH - 9)) . '-' . strtoupper(substr(md5($number), 0, 8));
	}//end normalise()
}//end class
