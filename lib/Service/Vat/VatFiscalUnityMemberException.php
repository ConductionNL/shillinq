<?php

/**
 * Shillinq VatFiscalUnityMemberException
 *
 * Thrown when a return is prepared for a member administration of a VAT
 * fiscal unity in a period the unity covers: the representative files for
 * the unity (REQ-TVRB-003). The message names the unity.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Vat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-5.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Vat;

use RuntimeException;

/**
 * A member of a VAT fiscal unity may not prepare its own return.
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-5.1
 */
class VatFiscalUnityMemberException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $unityName The name of the unity that files for the member.
	 */
	public function __construct(
		public readonly string $unityName,
	) {
		parent::__construct(
			message: 'This administration is a member of the VAT fiscal unity ' . $unityName
			. ' in this period; the representative files the return for the unity.'
		);
	}//end __construct()
}//end class
